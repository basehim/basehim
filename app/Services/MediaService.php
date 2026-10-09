<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\MediaRepository;
use App\Core\HookRegistry;
use App\Core\Helpers;

class MediaService
{
    public function __construct(
        private MediaRepository $repo,
        private HookRegistry $hooks,
        private string $uploadPath,
        private SettingService $settings,
        private ImageProcessor $images
    ) {}

    public function find(int $id): ?array { return $this->repo->find($id); }
    public function update(int $id, array $data): int { return $this->repo->update($id, $data); }
    public function typeCounts(?string $search = null): array { return $this->repo->typeCounts($search); }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        return $this->repo->paginate($filters, $page, $perPage);
    }

    public function totalCount(): int { return $this->repo->totalCount(); }
    public function totalSize(): int { return $this->repo->totalSize(); }

    // ── Media settings ────────────────────────────────────────────────────────

    /** Resolved media settings (DB group `media`) with sensible WordPress-like defaults. */
    public function mediaSettings(): array
    {
        $g = $this->settings->getGroup('media');
        $int  = fn(string $k, int $d): int => (int) ($g[$k] ?? $d);
        $bool = function (string $k, bool $d) use ($g): bool {
            if (!array_key_exists($k, $g)) return $d;
            $v = $g[$k];
            return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
        };
        return [
            'generate_thumbnails' => $bool('generate_thumbnails', true),
            'thumb_w'      => max(1, $int('thumb_w', 150)),
            'thumb_h'      => max(1, $int('thumb_h', 150)),
            'thumb_crop'   => $bool('thumb_crop', true),
            'medium_w'     => max(1, $int('medium_w', 300)),
            'medium_h'     => max(1, $int('medium_h', 300)),
            'large_w'      => max(1, $int('large_w', 1024)),
            'large_h'      => max(1, $int('large_h', 1024)),
            'jpeg_quality' => max(1, min(100, $int('jpeg_quality', 82))),
            'convert_webp' => $bool('convert_webp', false),
            'organize_uploads' => $bool('organize_uploads', true),
            'max_upload_mb'    => max(1, $int('max_upload_mb', 64)),
            'allowed_types'    => isset($g['allowed_types']) ? (string) $g['allowed_types'] : null,
        ];
    }

    /** Named size definitions {name => [w,h,crop]} derived from {settings}. */
    public function sizeDefinitions(?array $ms = null): array
    {
        $ms = $ms ?? $this->mediaSettings();
        return [
            'thumbnail' => ['w' => $ms['thumb_w'],  'h' => $ms['thumb_h'],  'crop' => $ms['thumb_crop']],
            'medium'    => ['w' => $ms['medium_w'], 'h' => $ms['medium_h'], 'crop' => false],
            'large'     => ['w' => $ms['large_w'],  'h' => $ms['large_h'],  'crop' => false],
        ];
    }

    /** Allowed upload extensions from {settings}, falling back to config. */
    public function allowedTypes(array $configDefault): array
    {
        $raw = $this->mediaSettings()['allowed_types'];
        if (is_string($raw) && trim($raw) !== '') {
            $list = array_values(array_filter(array_map(
                fn($s) => strtolower(trim($s, " .\t")),
                explode(',', $raw)
            )));
            return $list ?: $configDefault;
        }
        return $configDefault;
    }

    /** Max upload size in bytes from {settings}, falling back to config. */
    public function maxUploadBytes(int $configDefault): int
    {
        $mb = $this->mediaSettings()['max_upload_mb'];
        return $mb > 0 ? $mb * 1024 * 1024 : $configDefault;
    }

    // ── Upload ────────────────────────────────────────────────────────────

    /** Longest stem a generated file name may have, before any "-N" suffix. */
    private const NAME_STEM_MAX = 100;

    /**
     * Upload a single file from PHP's $_FILES array entry.
     * Returns the new media row (with generated `sizes` when applicable).
     *
     * @throws \RuntimeException
     */
    public function upload(array $file, int $authorId, array $allowed, int $maxBytes, array $meta = []): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No file uploaded.');
        }
        return $this->store($file, $authorId, $allowed, $maxBytes, $meta, true);
    }

    /**
     * Add a file that is already on this server to the library.
     *
     * For code that produced the file itself — an app rendering a chart, an
     * editor saving a copy. upload() cannot take these: it insists on
     * is_uploaded_file(), which is only true for a file PHP received in the
     * current HTTP request, so every such call used to fail with
     * "No file uploaded." The source is copied, never moved, and is left for
     * the caller to clean up.
     *
     * @throws \RuntimeException
     */
    public function importFile(string $path, string $name, int $authorId, array $allowed, int $maxBytes, array $meta = []): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('File not found or not readable.');
        }
        return $this->store([
            'name'     => $name,
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($path),
        ], $authorId, $allowed, $maxBytes, $meta, false);
    }

    private function store(array $file, int $authorId, array $allowed, int $maxBytes, array $meta, bool $isHttpUpload): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . ($file['error'] ?? 'unknown'));
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('File exceeds maximum size of ' . Helpers::bytesFormat($maxBytes) . '.');
        }

        $originalName = (string) ($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new \RuntimeException('File type ".' . $ext . '" not allowed.');
        }

        // Detect MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream';
        finfo_close($finfo);

        $ms = $this->mediaSettings();

        // Build storage path: /YYYY/MM/{name}.{ext} (or flat if organising is off)
        if (!empty($ms['organize_uploads'])) {
            $relDir = date('Y') . '/' . date('m');
        } else {
            $relDir = '';
        }
        $absDir = rtrim($this->uploadPath, '/') . ($relDir !== '' ? '/' . $relDir : '');
        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                throw new \RuntimeException('Could not create upload directory.');
            }
        }

        // The uuid stays the row's identity; the file name is now readable.
        $uuid = Helpers::uuid();
        // A name that says nothing ("1000477992.jpg", "image.png", "IMG_2034")
        // is replaced by the caller's hint when there is one: the post editor
        // sends the post's title, so a photo dropped into "Best Hiking
        // Trails" is stored as best-hiking-trails.jpg (1.2.44). Meaningful
        // names are never changed, and no hint means no change.
        $nameFor = $originalName;
        $hint = trim((string) ($meta['name_hint'] ?? ''));
        if ($hint !== '' && $this->isMeaninglessName($originalName, $mime)) {
            $hintStem = $this->fileStem($hint . '.x', '');
            if ($hintStem !== '' && $hintStem !== 'file' && !$this->isMeaninglessName($hint . '.x', $mime)) {
                $nameFor = $hint . '.' . $ext;
                if (!isset($meta['title']) || trim((string) $meta['title']) === '') $meta['title'] = $hint;
            }
        }
        $safeName = $this->reserveFileName($absDir, $relDir, $nameFor, $ext, $mime);
        $absPath = $absDir . '/' . $safeName;
        $relPath = ($relDir !== '' ? $relDir . '/' : '') . $safeName;

        // reserveFileName() left an empty placeholder at $absPath; both calls
        // below replace it. On failure the placeholder is removed, so a failed
        // upload does not keep a name taken.
        $placed = $isHttpUpload
            ? @move_uploaded_file($file['tmp_name'], $absPath)
            : @copy($file['tmp_name'], $absPath);
        if (!$placed) {
            @unlink($absPath);
            throw new \RuntimeException('Could not move uploaded file.');
        }
        @chmod($absPath, 0644);

        $width = null; $height = null;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $info = @getimagesize($absPath);
            if ($info) { $width = $info[0]; $height = $info[1]; }
        }

        // Generate resized variants when enabled and supported.
        $sizes = [];
        if ($width && $height && !empty($ms['generate_thumbnails']) && $this->images->supports($mime)) {
            $sizes = $this->buildSizes($absDir, $relDir, $safeName, $mime, $ms);
        }

        $row = [
            'uuid' => $uuid,
            'author_id' => $authorId,
            'title' => $meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME),
            'alt_text' => $meta['alt_text'] ?? null,
            'caption' => $meta['caption'] ?? null,
            'mime_type' => $mime,
            'file_name' => $safeName,
            'original_name' => $originalName,
            'file_size' => $size,
            'width' => $width,
            'height' => $height,
            'storage_disk' => 'local',
            'storage_path' => $relPath,
            'url' => '/uploads/' . $relPath,
            'sizes' => $sizes ? json_encode($sizes) : null,
        ];

        try {
            $id = $this->repo->create($row);
        } catch (\Throwable $e) {
            // No row means nothing refers to these files; do not leave them
            // holding the name.
            @unlink($absPath);
            foreach ($sizes as $v) {
                if (!empty($v['file'])) @unlink($absDir . '/' . $v['file']);
            }
            throw $e;
        }
        $created = $this->repo->find($id);
        $this->hooks->doAction('media.uploaded', $created);
        return $created;
    }

    // ── File names ────────────────────────────────────────────────────────

    /**
     * Is this file name one a camera, phone, clipboard or tool made up?
     *
     *   1000477992.jpg, IMG_2034.JPG, PXL_20260101_123456.jpg, DSC0012.jpg,
     *   image.png, image (3).png, Screenshot 2026-10-09 at 13.02.11.png,
     *   WhatsApp Image 2026-10-09 at 1.02.03 PM.jpeg, untitled.png, blob,
     *   0b6ab875-1cdd-435a-b2a2-fbb147e85532.png  ->  true
     *
     *   my-awesome-photo.jpg, arduino-uno-pinout.png  ->  false
     */
    public function isMeaninglessName(string $originalName, string $mime = ''): bool
    {
        $stem = $this->fileStem($originalName, $mime);
        if ($stem === '' || $stem === 'image' || $stem === 'file') return true;
        // Only digits, dashes: camera counters, timestamps.
        if (preg_match('/^[0-9-]+$/', $stem)) return true;
        // UUIDs and long hex hashes.
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $stem)) return true;
        if (preg_match('/^[0-9a-f-]{16,}$/', $stem) && preg_match('/[0-9]/', $stem)) return true;
        // Device / app prefixes, optionally followed by numbers, dates and times.
        $prefixes = 'img|image|images|photo|pic|picture|pxl|dsc|dscn|dscf|dcim|mvimg|vid|pano|burst|'
            . 'screenshot|screen-shot|screen-recording|capture|snap|snapshot|scan|'
            . 'whatsapp-image|whatsapp-img|signal|telegram|fb-img|received|'
            . 'untitled|download|downloaded|blob|pasted|pasted-image|clipboard|file|unnamed|'
            . 'new-image|new-file|copy|export|output|final|temp|tmp|test';
        $tail = '(-(\\d+|at|am|pm|copy|edited|scaled|[0-9a-f]{6,}))*';
        return (bool) preg_match('/^(' . $prefixes . ')' . $tail . '$/', $stem)
            || (bool) preg_match('/^(img|pxl|dsc|dscn|dscf|vid|mvimg)\\d+(-\\d+)*$/', $stem);
    }

    /**
     * Turn an uploaded file's name into a URL-friendly stem.
     *
     *     "My Awesome Image.png"  →  "my-awesome-image"
     *     "Café Crème (1).JPG"    →  "cafe-creme-1"
     *
     * A name with nothing transliterable in it (Urdu, Chinese, emoji) falls
     * back to "image" or "file" rather than Helpers::slug()'s "n-a".
     */
    public function fileStem(string $originalName, string $mime = ''): string
    {
        // Strip any client-side directory, then the extension. Done by hand:
        // pathinfo() and basename() are locale-dependent and can drop leading
        // multibyte characters.
        $name = str_replace('\\', '/', $originalName);
        $slash = strrpos($name, '/');
        if ($slash !== false) $name = substr($name, $slash + 1);
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) $name = substr($name, 0, $dot);

        // Apostrophes vanish rather than splitting a word: "don't" → "dont".
        $name = str_replace(["'", '’', '`'], '', $name);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($t !== false) $name = $t;
        }
        $stem = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        if (strlen($stem) > self::NAME_STEM_MAX) {
            $stem = rtrim(substr($stem, 0, self::NAME_STEM_MAX), '-');
        }
        if ($stem === '') {
            $stem = str_starts_with($mime, 'image/') ? 'image' : 'file';
        }
        return $stem;
    }

    /**
     * Choose a free name in $absDir and claim it with an empty placeholder.
     *
     * "my-image.png" is taken first, then "my-image-2.png", "-3", and so on.
     *
     * A name is free only if its stem is unused in that directory by any file
     * or media row, whatever the extension, and none of the names its
     * thumbnails will get ("my-image-medium.webp") exist either. Stems are
     * unique regardless of extension because with WebP conversion on,
     * photo.png and photo.jpg would both write photo-medium.webp.
     *
     * The reverse also holds: "photo-thumbnail.png" is not free while
     * "photo.*" exists, since that file's thumbnail may land on it.
     *
     * Choosing and claiming happen under an exclusive lock, and the claim
     * itself is an exclusive create ('x'), so two uploads of the same name at
     * the same moment get different names. Without the lock the 'x' create
     * still prevents two originals sharing a name.
     */
    private function reserveFileName(string $absDir, string $relDir, string $originalName, string $ext, string $mime): string
    {
        $base = $this->fileStem($originalName, $mime);
        $variants = array_keys($this->sizeDefinitions());

        $lock = $this->acquireNameLock();
        try {
            $taken = $this->takenStems($absDir, $relDir, $base);

            for ($n = 1; $n <= 100000; $n++) {
                $stem = $n === 1 ? $base : $base . '-' . $n;
                if (!$this->stemIsFree($stem, $taken, $variants)) continue;

                $name = $stem . '.' . $ext;
                $h = @fopen($absDir . '/' . $name, 'x');
                if ($h === false) {
                    if (!file_exists($absDir . '/' . $name)) {
                        // Not a collision: the directory refused the write.
                        throw new \RuntimeException('Could not write to the upload directory.');
                    }
                    // Appeared since the directory was read.
                    $taken[$stem] = true;
                    continue;
                }
                fclose($h);
                return $name;
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        throw new \RuntimeException('Could not find a free file name for "' . $base . '.' . $ext . '".');
    }

    /** @param array<string,bool> $taken */
    private function stemIsFree(string $stem, array $taken, array $variants): bool
    {
        if (isset($taken[$stem])) return false;
        foreach ($variants as $v) {
            // This file's own thumbnails would overwrite something.
            if (isset($taken[$stem . '-' . $v])) return false;
            // Something else's thumbnails would overwrite this file.
            $suffix = '-' . $v;
            if (str_ends_with($stem, $suffix) && isset($taken[substr($stem, 0, -strlen($suffix))])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every stem already used in a directory: files on disk plus media rows,
     * including rows whose file has gone missing. Only names that share the
     * candidate's prefix matter for the database, which keeps the query small.
     *
     * @return array<string,bool>
     */
    private function takenStems(string $absDir, string $relDir, string $base): array
    {
        $taken = [];
        foreach (@scandir($absDir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $dot = strrpos($f, '.');
            $taken[$dot === false || $dot === 0 ? $f : substr($f, 0, $dot)] = true;
        }

        // Prefix-matched in the query because a stem's suffixed forms and the
        // owner of a "-thumbnail" name both start with a common prefix. The
        // owner of "x-thumbnail" is "x", a shorter prefix, so match on the
        // stem with any variant suffix removed.
        $prefix = $base;
        foreach (array_keys($this->sizeDefinitions()) as $v) {
            if (str_ends_with($prefix, '-' . $v)) { $prefix = substr($prefix, 0, -strlen($v) - 1); break; }
        }
        $dirPart = $relDir !== '' ? $relDir . '/' : '';
        try {
            foreach ($this->repo->storagePathsLike($dirPart . $prefix) as $p) {
                $file = substr($p, strlen($dirPart));
                if (str_contains($file, '/')) continue;
                $dot = strrpos($file, '.');
                $taken[$dot === false ? $file : substr($file, 0, $dot)] = true;
            }
        } catch (\Throwable) {
            // The directory listing alone still prevents overwriting a file.
        }
        return $taken;
    }

    /** @return resource|null */
    private function acquireNameLock()
    {
        $dir = BASEHIM_ROOT . '/storage/locks';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $h = @fopen($dir . '/media-names.lock', 'c');
        if ($h === false) return null;
        if (!flock($h, LOCK_EX)) { fclose($h); return null; }
        return $h;
    }

    // ── Thumbnail generation ──────────────────────────────────────────────────

    /**
     * Produce every configured size for one source image and return the `sizes`
     * map to store on the media row. Missing/failed variants are simply omitted.
     */
    private function buildSizes(string $absDir, string $relDir, string $srcFileName, string $mime, array $ms): array
    {
        $srcAbs  = $absDir . '/' . $srcFileName;
        $stem    = pathinfo($srcFileName, PATHINFO_FILENAME);
        $toWebp  = !empty($ms['convert_webp']) && function_exists('imagewebp');
        $outMime = $toWebp ? 'image/webp' : $mime;
        $ext     = $this->images->extensionFor($outMime);
        $quality = (int) $ms['jpeg_quality'];
        $urlBase = '/uploads/' . ($relDir !== '' ? $relDir . '/' : '');

        $out = [];
        foreach ($this->sizeDefinitions($ms) as $name => $def) {
            $variantFile = $stem . '-' . $name . '.' . $ext;
            $destAbs     = $absDir . '/' . $variantFile;
            $res = $this->images->generate(
                $srcAbs, $destAbs, $mime,
                (int) $def['w'], (int) $def['h'], (bool) $def['crop'],
                $quality, $toWebp ? 'image/webp' : null
            );
            if ($res) {
                $out[$name] = [
                    'file'   => $variantFile,
                    'width'  => $res['width'],
                    'height' => $res['height'],
                    'mime'   => $res['mime'],
                    'url'    => $urlBase . $variantFile,
                ];
            }
        }
        return $out;
    }

    /**
     * Regenerate variants for every stored image using the current settings.
     * Old variants are removed first. Returns a counts summary.
     */
    /**
     * Rebuild the thumbnails of every image in one call. Fine for the command
     * line or a small library; the admin screen uses regenerateBatch(), which
     * cannot outlast a web server's time limit.
     */
    public function regenerateAll(): array
    {
        $ms = $this->mediaSettings();
        $tot = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'variants' => 0];
        $page = 1;
        do {
            $res   = $this->repo->paginate([], $page, 50);
            $last  = (int) ($res['meta']['last_page'] ?? 1);
            foreach ($res['data'] ?? [] as $row) {
                [$outcome, $n] = $this->regenerateOne($row, $ms);
                $tot[$outcome]++;
                $tot['variants'] += $n;
            }
            $page++;
        } while ($page <= $last);
        return $tot;
    }

    /**
     * Rebuild thumbnails for the next images after $afterId, oldest first,
     * for at most $seconds (default 8) or $limit images, whichever comes first.
     *
     * With $onlyMissing, images that already have generated sizes are left
     * alone, so an interrupted run can simply be started again.
     *
     * Returns processed / skipped / failed / variants for this batch, `next`
     * (the id to continue after), `remaining` and `done`.
     */
    public function regenerateBatch(int $afterId = 0, bool $onlyMissing = true, int $limit = 50, float $seconds = 8.0): array
    {
        $ms = $this->mediaSettings();
        $started = microtime(true);
        $tot = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'variants' => 0];
        $next = $afterId;
        $visited = 0;
        while ($visited < $limit && microtime(true) - $started < $seconds) {
            $rows = $this->repo->imageBatch($next, min(10, $limit - $visited), $onlyMissing);
            if (!$rows) break;
            foreach ($rows as $row) {
                [$outcome, $n] = $this->regenerateOne($row, $ms);
                $tot[$outcome]++;
                $tot['variants'] += $n;
                $next = (int) $row['id'];
                $visited++;
                if (microtime(true) - $started >= $seconds) break;
            }
        }
        $remaining = $this->repo->imageCount($onlyMissing, $next);
        return $tot + ['next' => $next, 'remaining' => $remaining, 'done' => $remaining === 0];
    }

    /** Images the regenerate screen reports on: all, and those still without thumbnails. */
    public function imageCounts(): array
    {
        return ['images' => $this->repo->imageCount(false), 'missing' => $this->repo->imageCount(true)];
    }

    /**
     * Rebuild one image's thumbnails. Returns [outcome, variants made], the
     * outcome being processed, skipped (not a resizable image, or its file is
     * gone) or failed.
     */
    private function regenerateOne(array $row, array $ms): array
    {
        $mime = (string) ($row['mime_type'] ?? '');
        $abs  = rtrim($this->uploadPath, '/') . '/' . $row['storage_path'];
        if (!$this->images->supports($mime) || !is_file($abs)) return ['skipped', 0];

        $dir = dirname($abs);
        // Remove any previously generated variants.
        foreach ($this->decodeSizes($row['sizes'] ?? null) as $v) {
            if (!empty($v['file'])) { $f = $dir . '/' . $v['file']; if (is_file($f)) @unlink($f); }
        }
        $relDir = dirname((string) $row['storage_path']);
        $relDir = ($relDir === '.' || $relDir === '') ? '' : $relDir;
        try {
            $sizes = !empty($ms['generate_thumbnails'])
                ? $this->buildSizes($dir, $relDir, basename((string) $row['storage_path']), $mime, $ms)
                : [];
            $this->repo->update((int) $row['id'], ['sizes' => $sizes ? json_encode($sizes) : null]);
            return ['processed', count($sizes)];
        } catch (\Throwable) {
            return ['failed', 0];
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function delete(int $id): bool
    {
        $row = $this->repo->find($id);
        if (!$row) return false;

        $abs = rtrim($this->uploadPath, '/') . '/' . $row['storage_path'];
        $dir = dirname($abs);
        if (is_file($abs)) @unlink($abs);

        // Remove generated variants too, so nothing is orphaned on disk.
        foreach ($this->decodeSizes($row['sizes'] ?? null) as $v) {
            if (!empty($v['file'])) { $f = $dir . '/' . $v['file']; if (is_file($f)) @unlink($f); }
        }

        $this->repo->delete($id);
        $this->hooks->doAction('media.deleted', $row);
        return true;
    }

    private function decodeSizes($sizes): array
    {
        if (is_array($sizes)) return $sizes;
        if (is_string($sizes) && $sizes !== '') {
            $d = json_decode($sizes, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
}
