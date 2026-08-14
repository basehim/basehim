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

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Extensions that may never be uploaded, whatever the settings say.
     *
     * `allowed_types` is operator-editable data, so it was the only thing
     * standing between an upload and a PHP file inside a directory Apache
     * serves directly — one settings edit away from remote code execution.
     * This list is code and cannot be configured away.
     */
    private const NEVER_ALLOWED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx',
        'htaccess', 'htpasswd', 'ini', 'conf', 'so', 'dll', 'exe', 'com', 'bat', 'cmd',
    ];

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
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . ($file['error'] ?? 'unknown'));
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('File exceeds maximum size of ' . Helpers::bytesFormat($maxBytes) . '.');
        }

        $originalName = $file['name'] ?? 'upload';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Check EVERY dot-separated segment, not just the last one. Some server
        // configurations execute "shell.php.jpg" as PHP because they dispatch
        // on the first extension they recognise rather than the final one.
        foreach (explode('.', strtolower($originalName)) as $segment) {
            if (in_array(trim($segment), self::NEVER_ALLOWED, true)) {
                throw new \RuntimeException('That file type can never be uploaded.');
            }
        }

        if (!in_array($ext, $allowed, true)) {
            throw new \RuntimeException('File type ".' . $ext . '" not allowed.');
        }

        // Detect MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream';
        finfo_close($finfo);

        // A file claiming to be an image must actually parse as one. This
        // catches a script body wearing a .jpg extension, which matters because
        // the file is written into a web-served directory.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'], true)) {
            if (!str_starts_with($mime, 'image/') || @getimagesize($file['tmp_name']) === false) {
                throw new \RuntimeException('That file is not a valid image.');
            }
        }

        // SVG is an XML document that executes script when opened directly, and
        // it is served from the site's own origin. Strip the active parts.
        if ($ext === 'svg') {
            $svg = @file_get_contents($file['tmp_name']);
            if ($svg === false || !$this->svgIsSafe($svg)) {
                throw new \RuntimeException('That SVG contains scripting and was rejected.');
            }
        }

        $ms = $this->mediaSettings();

        // Build storage path: /YYYY/MM/{uuid}.{ext} (or flat if organising is off)
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

        $uuid = Helpers::uuid();
        $safeName = $uuid . '.' . $ext;
        $absPath = $absDir . '/' . $safeName;
        $relPath = ($relDir !== '' ? $relDir . '/' : '') . $safeName;

        if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
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

        $id = $this->repo->create($row);
        $created = $this->repo->find($id);
        $this->hooks->doAction('media.uploaded', $created);
        return $created;
    }

    /**
     * Does this SVG contain anything that can execute?
     *
     * Deliberately a rejection check rather than a rewrite: an SVG that needs
     * script is not a legitimate media upload, and silently rewriting someone's
     * artwork is worse than telling them no.
     */
    private function svgIsSafe(string $svg): bool
    {
        // Decode entities first — "&#106;avascript:" and friends survive a
        // naive substring search but are live once the parser has run.
        $probe = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = preg_replace('/[\x00-\x20]/', '', $probe) ?? $probe;
        $probe = strtolower($probe);

        $dangerous = [
            '<script', '<foreignobject', '<use', '<handler', '<set',
            'javascript:', 'data:text/html', 'vbscript:',
            '<!entity', '<!doctype', 'xlink:href=http', 'xlink:href=//',
        ];
        foreach ($dangerous as $needle) {
            if (str_contains($probe, $needle)) return false;
        }
        // Any on* event attribute.
        if (preg_match('/<[^>]*\son[a-z]+=/i', $probe)) return false;

        return true;
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
    public function regenerateAll(): array
    {
        $ms = $this->mediaSettings();
        $processed = 0; $skipped = 0; $failed = 0; $variants = 0;

        $page = 1;
        do {
            $res   = $this->repo->paginate([], $page, 50);
            $items = $res['data'] ?? [];
            $last  = (int) ($res['meta']['last_page'] ?? 1);

            foreach ($items as $row) {
                $mime = (string) ($row['mime_type'] ?? '');
                $abs  = rtrim($this->uploadPath, '/') . '/' . $row['storage_path'];
                if (!$this->images->supports($mime) || !is_file($abs)) { $skipped++; continue; }

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
                    $processed++;
                    $variants += count($sizes);
                } catch (\Throwable) {
                    $failed++;
                }
            }
            $page++;
        } while ($page <= $last);

        return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'variants' => $variants];
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
