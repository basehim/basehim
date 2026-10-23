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

    /**
     * Descriptive fields a person may edit, with how much each column holds:
     * VARCHAR(255) counts characters, TEXT counts bytes. Everything else on
     * a media row (path, mime type, size, dimensions) describes the file on
     * disk and is never taken from a request.
     */
    public const META_FIELDS = [
        'title'       => ['chars' => 255],
        'alt_text'    => ['chars' => 255],
        'caption'     => ['bytes' => 65535],
        'description' => ['bytes' => 65535],
    ];

    /**
     * Whether $user may change or delete $item: their own uploads need
     * upload_media, anyone else's need delete_media.
     *
     * This is the rule the REST API (Api\MediaController) has applied since
     * 1.35; it lives here so the admin screens and the AJAX gateway apply the
     * very same one. Before this, the admin media screens checked only that
     * the user could reach /admin/media (upload_media), so an author could
     * rename or delete an administrator's files.
     */
    public static function userCanModify(?array $user, array $item): bool
    {
        if ($user === null || empty($user['id'])) return false;
        $own = (int) ($item['author_id'] ?? 0) === (int) $user['id'];
        return \App\Http\Middleware\CheckCapability::userCan($user, $own ? 'upload_media' : 'delete_media');
    }

    /**
     * Whether $user may edit $item's descriptive fields (title, alt text,
     * caption, description): their own with upload_media; anyone else's with
     * delete_media, or with edit_others_posts.
     *
     * Looser than userCanModify() on purpose. An editor fixes alt text and
     * captions on the images in other people's posts every day, and could
     * before 1.2.46; editing a description destroys nothing, so it does not
     * need the right to delete files. Deleting someone else's file still does.
     */
    public static function userCanEdit(?array $user, array $item): bool
    {
        if ($user === null || empty($user['id'])) return false;
        $can = fn(string $cap) => \App\Http\Middleware\CheckCapability::userCan($user, $cap);
        if ((int) ($item['author_id'] ?? 0) === (int) $user['id']) return $can('upload_media');
        return $can('delete_media') || ($can('upload_media') && $can('edit_others_posts'));
    }

    /**
     * Update an item's descriptive fields.
     *
     * Takes only META_FIELDS, only string values, and cuts each to what its
     * column holds without splitting a multi-byte character (substr() could
     * leave half a character, which strict MySQL then refuses).
     *
     * @return array the fields actually written; empty when nothing usable was given
     */
    public function updateMeta(int $id, array $fields): array
    {
        $clean = [];
        foreach (self::META_FIELDS as $field => $limit) {
            if (!array_key_exists($field, $fields) || !is_string($fields[$field])) continue;
            $v = $fields[$field];
            $clean[$field] = isset($limit['chars'])
                ? mb_substr($v, 0, $limit['chars'], 'UTF-8')
                : mb_strcut($v, 0, $limit['bytes'], 'UTF-8');
        }
        if ($clean !== []) {
            $this->repo->update($id, $clean);
            // 1.2.87: the item after the change, and which fields changed.
            $row = $this->repo->find($id);
            if ($row !== null) $this->hooks->doAction('media.updated', $row, array_keys($clean));
        }
        return $clean;
    }
    public function typeCounts(?string $search = null, array $filters = []): array { return $this->repo->typeCounts($search, $filters); }

    /** Months with uploads in the site's timezone, newest first (the date filter). */
    public function months(): array
    {
        $out = [];
        foreach ($this->repo->months(\App\Core\Time::offsetSeconds()) as $m) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m', $m['value']);
            $out[] = $m + ['label' => $d ? $d->format('F Y') : $m['value']];
        }
        return $out;
    }

    /**
     * UTC bounds of a 'YYYY-MM' month in the site's timezone, for the
     * from/to list filters. Null for anything else.
     *
     * @return array{from:string,to:string}|null
     */
    public static function monthBounds(string $ym): ?array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m) || (int) $m[1] < 1970 || (int) $m[1] > 2200) return null;
        $zone = \App\Core\Time::zone();
        $start = new \DateTimeImmutable($m[1] . '-' . $m[2] . '-01 00:00:00', $zone);
        $end = $start->modify('+1 month');
        $utc = new \DateTimeZone('UTC');
        return ['from' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'to' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

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
            // Largest image accepted, in megapixels (1.2.73). 50 covers phone
            // and most camera photos; never above ImageProcessor's hard cap.
            'max_image_megapixels' => max(1.0, min(ImageProcessor::HARD_MAX_PIXELS / 1_000_000, (float) ($g['max_image_megapixels'] ?? 50))),
            'allowed_types'    => isset($g['allowed_types']) ? (string) $g['allowed_types'] : null,
        ];
    }

    /** Most generated sizes per image, whatever filters add (1.2.87). */
    public const MAX_SIZES = 12;

    /**
     * Named size definitions {name => [w,h,crop]} derived from {settings}.
     *
     * Apps can add, change or remove sizes with the `media.sizes` filter
     * (1.2.87), e.g. a 1200×630 crop for social cards:
     *
     *     $this->addFilter('media.sizes', function (array $sizes, string $mime) {
     *         $sizes['social'] = ['w' => 1200, 'h' => 630, 'crop' => true];
     *         return $sizes;
     *     }, 10, 2);
     *
     * Names are lower-case words; width and height 1–10000; at most
     * MAX_SIZES. Anything else a filter returns is ignored.
     */
    public function sizeDefinitions(?array $ms = null, string $mime = ''): array
    {
        $ms = $ms ?? $this->mediaSettings();
        $core = [
            'thumbnail' => ['w' => $ms['thumb_w'],  'h' => $ms['thumb_h'],  'crop' => $ms['thumb_crop']],
            'medium'    => ['w' => $ms['medium_w'], 'h' => $ms['medium_h'], 'crop' => false],
            'large'     => ['w' => $ms['large_w'],  'h' => $ms['large_h'],  'crop' => false],
        ];
        if (!$this->hooks->hasFilter('media.sizes')) return $core;
        $out = $this->hooks->applyFilters('media.sizes', $core, $mime);
        if (!is_array($out)) return $core;
        $clean = [];
        foreach ($out as $name => $def) {
            if (count($clean) >= self::MAX_SIZES) break;
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) || !is_array($def)) continue;
            $w = $def['w'] ?? null; $h = $def['h'] ?? null;
            if (!is_int($w) || !is_int($h) || $w < 1 || $h < 1 || $w > 10000 || $h > 10000) continue;
            $clean[$name] = ['w' => $w, 'h' => $h, 'crop' => !empty($def['crop'])];
        }
        return $clean;
    }

    /**
     * What an upload may be, after apps have had their say: the allowed
     * extensions and the size limit (1.2.87).
     *
     * Filters: `media.upload.allowed_types` (list of extensions) and
     * `media.upload.max_bytes` (int), each given the upload's $context
     * ('library', 'editor', 'ajax:media.upload', 'import', 'app:my-app', …).
     * The extensions in NEVER_ALLOWED are removed whatever a filter returns.
     *
     * @return array{types:string[],max_bytes:int}
     */
    public function uploadRules(string $context = 'library'): array
    {
        $cfg = \App\Core\Application::getInstance()->make(\App\Core\Config::class);
        return $this->filterRules(
            $this->allowedTypes((array) $cfg->get('cms.media.allowed_types', [])),
            $this->maxUploadBytes((int) $cfg->get('cms.media.max_upload_size', 64 * 1024 * 1024)),
            $context
        );
    }

    /** @return array{types:string[],max_bytes:int} */
    private function filterRules(array $types, int $maxBytes, string $context): array
    {
        $t = $this->hooks->applyFilters('media.upload.allowed_types', $types, $context);
        if (is_array($t)) {
            $t = array_values(array_unique(array_filter(array_map(
                static fn($x) => is_string($x) ? strtolower(trim($x, " .\t")) : '', $t
            ), static fn($x) => preg_match('/^[a-z0-9]{1,10}$/', $x) === 1)));
        } else {
            $t = $types;
        }
        $m = $this->hooks->applyFilters('media.upload.max_bytes', $maxBytes, $context);
        $m = is_int($m) && $m > 0 ? $m : $maxBytes;
        return ['types' => array_values(array_diff($t, self::NEVER_ALLOWED)), 'max_bytes' => $m];
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
            $list = array_values(array_diff($list, self::NEVER_ALLOWED));
            return $list ?: array_values(array_diff($configDefault, self::NEVER_ALLOWED));
        }
        return array_values(array_diff($configDefault, self::NEVER_ALLOWED));
    }

    /**
     * Extensions no setting can allow (1.2.67). storage/uploads is served
     * straight from disk by most web servers, so a .php there would run as
     * the site, and an .html would run script on the site's origin. The list
     * in Settings → Media is typed by hand; this is the floor under it.
     */
    public const NEVER_ALLOWED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'ps1', 'jar',
        'htaccess', 'htpasswd', 'ini', 'html', 'htm', 'xhtml', 'shtml', 'xht', 'hta', 'js', 'mjs', 'svgz',
    ];

    /** Raster image extensions: their content must be a raster image. */
    private const IMAGE_MIMES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'jpe' => ['image/jpeg'],
        'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'avif' => ['image/avif', 'image/heif'], 'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
    ];

    /** Content refused whatever the extension says: programs, scripts and web pages. */
    private const DANGEROUS_MIMES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/x-shellscript',
        'application/x-executable', 'application/x-dosexec', 'application/x-msdownload',
        'application/x-sharedlib', 'application/x-mach-binary', 'application/x-elf',
        'text/html', 'application/xhtml+xml', 'application/javascript', 'text/javascript',
    ];

    /**
     * Why this file's content may not be stored under this extension, or null.
     * Public so a test (or an app importing files) can ask before storing.
     */
    public static function contentProblem(string $ext, string $mime): ?string
    {
        $ext = strtolower($ext);
        if (in_array($ext, self::NEVER_ALLOWED, true)) {
            return 'Files of type ".' . $ext . '" cannot be uploaded.';
        }
        if (in_array($mime, self::DANGEROUS_MIMES, true)) {
            return 'This file looks like a program or web page, not a .' . $ext . ' file.';
        }
        // A raster image name must hold a raster image. Which one is not
        // enforced: a PNG saved as .jpg is common and harmless (browsers read
        // the content), while a page or script named .jpg is not.
        if (isset(self::IMAGE_MIMES[$ext]) && (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml')) {
            return 'This file is not a valid .' . $ext . ' image (its content does not match its name).';
        }
        return null;
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
            throw new MediaRejected('No file uploaded.');
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
            throw new MediaRejected('File not found or not readable.');
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
        // Where this upload comes from, for the media.upload.* hooks (1.2.87).
        $context = is_string($meta['context'] ?? null) && preg_match('/^[a-z0-9][a-z0-9:._-]{0,79}$/', $meta['context'])
            ? $meta['context'] : ($isHttpUpload ? 'library' : 'core:import');
        $rules = $this->filterRules($allowed, $maxBytes, $context);
        $allowed = $rules['types'];
        $maxBytes = $rules['max_bytes'];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $code = (int) ($file['error'] ?? -1);
            throw new MediaRejected(match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
                UPLOAD_ERR_PARTIAL => 'The file only partly arrived. Please try again.',
                UPLOAD_ERR_NO_FILE => 'No file was selected.',
                default => 'The server could not receive the file (upload error ' . $code . ').',
            });
        }
        // The size of what arrived, not what the browser claimed.
        $size = is_file((string) ($file['tmp_name'] ?? '')) ? (int) filesize($file['tmp_name']) : (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new MediaRejected('File exceeds maximum size of ' . Helpers::bytesFormat($maxBytes) . '.');
        }

        $originalName = (string) ($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new MediaRejected('File type ".' . $ext . '" not allowed.');
        }

        // Detect MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream';
        finfo_close($finfo);

        // The content must be what the name says (1.2.67).
        $problem = self::contentProblem($ext, $mime);
        if ($problem !== null) {
            throw new MediaRejected($problem);
        }

        // An SVG is a document that can run script when opened directly, and
        // uploads are open to authors: refuse any that could (SvgGuard).
        if ($ext === 'svg' || $ext === 'svgz' || $mime === 'image/svg+xml') {
            $raw = (string) @file_get_contents($file['tmp_name']);
            if (str_starts_with($raw, "\x1f\x8b")) {
                throw new MediaRejected('Compressed SVG (.svgz) files are not accepted. Upload the plain .svg.');
            }
            $why = SvgGuard::problem($raw);
            if ($why !== null) {
                throw new MediaRejected('This SVG was not uploaded because ' . $why . '. Save it without scripts, event handlers or embedded links and try again.');
            }
        }

        $ms = $this->mediaSettings();
        $this->checkPixels($file['tmp_name'], $mime, $ms);

        // Apps (1.2.87). What they are told about the file, read-only:
        $info = ['path' => (string) $file['tmp_name'], 'name' => $originalName, 'ext' => $ext, 'mime' => $mime, 'size' => $size,
                 'author_id' => $authorId, 'context' => $context];
        $hookCtx = ['context' => $context, 'author_id' => $authorId, 'source' => $isHttpUpload ? 'upload' : 'import'];

        // media.upload.check: refuse with a sentence for the person
        // uploading, or return null. Core's own checks above always apply.
        // Fails closed: a check that throws (a scanner that is down, say)
        // refuses the file instead of letting it through.
        $veto = $this->hooks->applyFiltersStrict('media.upload.check', null, $info, $hookCtx);
        if ($veto instanceof \Throwable) {
            throw new MediaRejected('This file could not be checked, so it was not uploaded. Try again later.');
        }
        if (is_string($veto) && trim($veto) !== '') {
            throw new MediaRejected(mb_substr(trim($veto), 0, 300));
        }

        // media.upload.file: hand back the path of a different file to store
        // instead (a compressed or converted copy the app wrote). It goes
        // through the same checks again, and must keep the extension.
        $source = (string) $file['tmp_name'];
        $replaced = $this->hooks->applyFilters('media.upload.file', $source, $info, $hookCtx);
        if (is_string($replaced) && $replaced !== $source) {
            if (!is_file($replaced) || !is_readable($replaced)) {
                throw new MediaRejected('The file could not be prepared for storing.');
            }
            $newSize = (int) filesize($replaced);
            if ($newSize <= 0 || $newSize > $maxBytes) {
                throw new MediaRejected('The prepared file exceeds the maximum size of ' . Helpers::bytesFormat($maxBytes) . '.');
            }
            $f2 = finfo_open(FILEINFO_MIME_TYPE);
            $newMime = finfo_file($f2, $replaced) ?: 'application/octet-stream';
            finfo_close($f2);
            if (($p = self::contentProblem($ext, $newMime)) !== null) throw new MediaRejected($p);
            if ($ext === 'svg' || $ext === 'svgz' || $newMime === 'image/svg+xml') {
                $raw = (string) @file_get_contents($replaced);
                if (str_starts_with($raw, "\x1f\x8b")) throw new MediaRejected('Compressed SVG (.svgz) files are not accepted.');
                if (($why = SvgGuard::problem($raw)) !== null) throw new MediaRejected('This SVG was not uploaded because ' . $why . '.');
            }
            $this->checkPixels($replaced, $newMime, $ms);
            $source = $replaced;
            $size = $newSize;
            $mime = $newMime;
            $isHttpUpload = false;   // copy the app's file; PHP removes its own temporary one
            $info['size'] = $size; $info['mime'] = $mime; $info['path'] = $source;
        }

        // Build storage path: /YYYY/MM/{name}.{ext} (or flat if organising is off)
        if (!empty($ms['organize_uploads'])) {
            $relDir = date('Y') . '/' . date('m');
        } else {
            $relDir = '';
        }
        // media.upload.directory (1.2.87): a folder under storage/uploads for
        // this file, e.g. 'products/2026'. Lower-case words and slashes, at
        // most five levels; anything else is ignored.
        $dirOut = $this->hooks->applyFilters('media.upload.directory', $relDir, $info, $hookCtx);
        if (is_string($dirOut) && $dirOut !== $relDir
            && ($dirOut === '' || preg_match('#^[a-z0-9][a-z0-9_-]{0,63}(/[a-z0-9][a-z0-9_-]{0,63}){0,4}$#', $dirOut))) {
            $relDir = $dirOut;
        }
        $absDir = rtrim($this->uploadPath, '/') . ($relDir !== '' ? '/' . $relDir : '');
        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                throw new MediaRejected('Could not create upload directory.');
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
        // media.upload.filename (1.2.87): the name to store it under. The
        // extension stays the file's own, and the name is made safe and
        // unique exactly as core's own are.
        $nameOut = $this->hooks->applyFilters('media.upload.filename', $nameFor, $info, $hookCtx);
        if (is_string($nameOut) && trim($nameOut) !== '' && $nameOut !== $nameFor) {
            $nameFor = pathinfo(basename(str_replace('\\', '/', $nameOut)), PATHINFO_FILENAME) . '.' . $ext;
        }
        $safeName = $this->reserveFileName($absDir, $relDir, $nameFor, $ext, $mime);
        $absPath = $absDir . '/' . $safeName;
        $relPath = ($relDir !== '' ? $relDir . '/' : '') . $safeName;

        // reserveFileName() left an empty placeholder at $absPath; both calls
        // below replace it. On failure the placeholder is removed, so a failed
        // upload does not keep a name taken.
        $placed = $isHttpUpload
            ? @move_uploaded_file($source, $absPath)
            : @copy($source, $absPath);
        if (!$placed) {
            @unlink($absPath);
            throw new MediaRejected('Could not move uploaded file.');
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

        // media.upload.row (1.2.87): the descriptive fields only (title, alt
        // text, caption, description). Everything that describes the file
        // stays core's.
        // The file is already in place here, so a filter that fails is
        // ignored rather than allowed to leave it behind.
        try {
            $rowOut = $this->hooks->applyFilters('media.upload.row', $row, $info, $hookCtx);
        } catch (\Throwable) {
            $rowOut = $row;
        }
        if (is_array($rowOut)) {
            foreach (self::META_FIELDS as $f => $limit) {
                if (!array_key_exists($f, $rowOut) || $rowOut[$f] === ($row[$f] ?? null)) continue;
                $v = $rowOut[$f];
                if ($v !== null && !is_string($v)) continue;
                $row[$f] = $v === null ? null : (isset($limit['chars']) ? mb_substr($v, 0, $limit['chars'], 'UTF-8') : mb_strcut($v, 0, $limit['bytes'], 'UTF-8'));
            }
        }

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
        // Second argument since 1.2.87: ['context', 'author_id', 'source'].
        $this->hooks->doAction('media.uploaded', $created, $hookCtx);
        return $created;
    }

    /**
     * Pixel limit, checked on a file before it is placed (1.2.73, BH-027).
     * A file's size says nothing about its dimensions: a 250 KB PNG can be
     * 16,000 × 16,000 pixels, about 1 GB once GD decodes it for thumbnails,
     * which ended the request on hosts where that counts against
     * memory_limit and left the file behind.
     */
    private function checkPixels(string $path, string $mime, array $ms): void
    {
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') return;
        $dim = @getimagesize($path);
        if (!$dim) return;
        $px = (int) $dim[0] * (int) $dim[1];
        $max = (int) round($ms['max_image_megapixels'] * 1_000_000);
        if ($px > $max) {
            throw new MediaRejected(sprintf(
                'This image is %d × %d pixels (%.1f megapixels); the limit is %s megapixels. Resize it and upload it again.',
                (int) $dim[0], (int) $dim[1], $px / 1_000_000, rtrim(rtrim(number_format($ms['max_image_megapixels'], 1), '0'), '.')
            ));
        }
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
        // Every name a generated size of this file could take: the sizes for
        // its own type (media.sizes may depend on it) and the general ones.
        $variants = array_keys($this->sizeDefinitions(null, $mime) + $this->sizeDefinitions());

        $lock = $this->acquireNameLock();
        try {
            $taken = $this->takenStems($absDir, $relDir, $base, $mime);

            for ($n = 1; $n <= 100000; $n++) {
                $stem = $n === 1 ? $base : $base . '-' . $n;
                if (!$this->stemIsFree($stem, $taken, $variants)) continue;

                $name = $stem . '.' . $ext;
                $h = @fopen($absDir . '/' . $name, 'x');
                if ($h === false) {
                    if (!file_exists($absDir . '/' . $name)) {
                        // Not a collision: the directory refused the write.
                        throw new MediaRejected('Could not write to the upload directory.');
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
        throw new MediaRejected('Could not find a free file name for "' . $base . '.' . $ext . '".');
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
    private function takenStems(string $absDir, string $relDir, string $base, string $mime = ''): array
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
        foreach (array_keys($this->sizeDefinitions(null, $mime) + $this->sizeDefinitions()) as $v) {
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
        foreach ($this->sizeDefinitions($ms, $mime) as $name => $def) {
            $variantFile = $stem . '-' . $name . '.' . $ext;
            $destAbs     = $absDir . '/' . $variantFile;
            // Never write over a file that exists: this item's own old sizes
            // were removed before regenerating, so anything here belongs to
            // another item (a size added since its name was reserved).
            if (file_exists($destAbs)) continue;
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
        $abs  = $this->localPath($row);
        if (!$this->images->supports($mime) || $abs === null) return ['skipped', 0];

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
            $fresh = $this->repo->find((int) $row['id']);
            if ($fresh !== null) $this->hooks->doAction('media.sizes_generated', $fresh, $sizes);
            return ['processed', count($sizes)];
        } catch (\Throwable) {
            return ['failed', 0];
        }
    }

    /**
     * An item as the admin screens receive it, with data apps add (1.2.87).
     *
     * The `media.item` filter is given the row and returns it; only what it
     * puts under `ext` is kept — an object keyed by the app's slug:
     *
     *     $this->addFilter('media.item', function (array $item) {
     *         $item['ext']['image-compressor'] = ['badge' => '−42%', 'tone' => 'green'];
     *         return $item;
     *     });
     *
     * Core fields can't be changed this way. `ext` is capped at 8 KB per item.
     * media.list, media.get and media.upload return decorated items; the
     * library and picker scripts can show `ext` through BasehimMedia.addItemBadge().
     */
    public function decorate(array $row): array
    {
        if (!$this->hooks->hasFilter('media.item')) return $row;
        try {
            $out = $this->hooks->applyFilters('media.item', $row);
        } catch (\Throwable $e) {
            return $row;
        }
        if (!is_array($out) || !isset($out['ext']) || !is_array($out['ext'])) return $row;
        $ext = [];
        foreach ($out['ext'] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $k) && (is_array($v) || is_scalar($v))) $ext[$k] = $v;
        }
        $json = json_encode($ext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($ext !== [] && is_string($json) && strlen($json) <= 8192) $row['ext'] = $ext;
        return $row;
    }

    // ── Files of existing items (1.2.87) ──────────────────────────────────────

    /**
     * Absolute path of an item's file on this server, or null when it is not
     * a regular file inside storage/uploads (missing, remote storage, or a
     * stored path that would point outside — "..", absolute paths, symlinks
     * leading elsewhere). The only way an app should find a media file.
     */
    public function localPath(array $row): ?string
    {
        if (($row['storage_disk'] ?? 'local') !== 'local') return null;
        $rel = (string) ($row['storage_path'] ?? '');
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, '\\')) return null;
        $base = realpath(rtrim($this->uploadPath, '/'));
        $abs = realpath(rtrim($this->uploadPath, '/') . '/' . $rel);
        if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs)) return null;
        return $abs;
    }

    /**
     * Rebuild one item's generated sizes with the current settings (and the
     * media.sizes filter). Returns ['status' => processed|skipped|failed,
     * 'variants' => n, 'item' => row|null].
     */
    public function regenerate(int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null || $this->localPath($row) === null) return ['status' => 'skipped', 'variants' => 0, 'item' => $row];
        [$outcome, $n] = $this->regenerateOne($row, $this->mediaSettings());
        return ['status' => $outcome, 'variants' => $n, 'item' => $this->repo->find($id)];
    }

    /**
     * Replace an item's file with $source, keeping its name, folder, URL and
     * row (1.2.87). For apps that edit, compress or convert a file in place.
     *
     * The new content goes through the upload checks for the item's own
     * extension (no script or page, a real image under an image name, SVG
     * guard, pixel limit) and must have the same MIME type as the file it
     * replaces. It is copied next to the original under a temporary name and
     * renamed over it, which is atomic on one filesystem, so a visitor never
     * sees half a file. The source is copied, never moved.
     *
     * Options:
     *   regenerate  bool  rebuild the generated sizes (default true)
     *   expect_sha1 string only replace if the current file still has this
     *               SHA-1 (it has not changed since the caller read it)
     *
     * Core does not keep a copy of the old file: an app that may need to
     * undo the change backs it up first. Fires media.file_replaced
     * ($newRow, $oldRow).
     *
     * @throws MediaRejected with a sentence for the person, on any refusal
     */
    public function replaceFile(int $id, string $source, array $opts = []): array
    {
        $row = $this->repo->find($id);
        if ($row === null) throw new MediaRejected('That media item no longer exists.');
        $target = $this->localPath($row);
        if ($target === null) throw new MediaRejected('The file for this media item is missing or not stored on this server.');
        if (!is_file($source) || !is_readable($source)) throw new MediaRejected('The replacement file is missing.');
        if (realpath($source) === $target) throw new MediaRejected('The replacement is the file itself.');
        $size = (int) filesize($source);
        if ($size <= 0) throw new MediaRejected('The replacement file is empty.');

        $ms = $this->mediaSettings();
        if ($size > $ms['max_upload_mb'] * 1024 * 1024) {
            throw new MediaRejected('The replacement exceeds the maximum size of ' . Helpers::bytesFormat($ms['max_upload_mb'] * 1024 * 1024) . '.');
        }
        if (isset($opts['expect_sha1']) && is_string($opts['expect_sha1']) && @sha1_file($target) !== $opts['expect_sha1']) {
            throw new MediaRejected('The file changed since it was read; nothing was replaced.');
        }

        // Copy first, then check the copy: what is checked is exactly what
        // gets swapped in, whatever happens to the source meanwhile.
        $stage = dirname($target) . '/.bh-replace-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($source, $stage)) {
            @unlink($stage);
            throw new MediaRejected('The replacement could not be written next to the original (is the uploads folder writable?).');
        }
        try {
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($f, $stage) ?: 'application/octet-stream';
            finfo_close($f);
            if (($p = self::contentProblem($ext, $mime)) !== null) throw new MediaRejected($p);
            if ($mime !== (string) $row['mime_type']) {
                throw new MediaRejected('The replacement is ' . $mime . ', but this item is ' . $row['mime_type'] . '. Upload it as a new file instead.');
            }
            if ($ext === 'svg' || $ext === 'svgz' || $mime === 'image/svg+xml') {
                $raw = (string) @file_get_contents($stage);
                if (str_starts_with($raw, "\x1f\x8b")) throw new MediaRejected('Compressed SVG files are not accepted.');
                if (($why = SvgGuard::problem($raw)) !== null) throw new MediaRejected('This SVG was refused because ' . $why . '.');
            }
            $this->checkPixels($stage, $mime, $ms);
        } catch (\Throwable $e) {
            @unlink($stage);
            throw $e;
        }
        @chmod($stage, 0644);
        if (!@rename($stage, $target)) {
            @unlink($stage);
            throw new MediaRejected('The file system refused to swap the files; the original is unchanged.');
        }
        clearstatcache(true, $target);

        $fields = ['file_size' => (int) filesize($target)];
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && ($dim = @getimagesize($target))) {
            $fields['width'] = (int) $dim[0];
            $fields['height'] = (int) $dim[1];
        }
        $this->repo->update($id, $fields);
        $updated = $this->repo->find($id) ?? ($fields + $row);
        if (($opts['regenerate'] ?? true) && $this->images->supports($mime)) {
            $this->regenerateOne($updated, $ms);
            $updated = $this->repo->find($id) ?? $updated;
        }
        $this->hooks->doAction('media.file_replaced', $updated, $row);
        return $updated;
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete one item: true once its row is gone.
     *
     * See deleteWithReport() for the order and for what "gone" means; this
     * keeps the boolean older callers expect.
     */
    public function delete(int $id): bool
    {
        return in_array($this->deleteWithReport($id)['status'], ['deleted', 'partial'], true);
    }

    /**
     * Delete one item and say exactly what happened (1.2.67).
     *
     * The row goes first: if the database refuses, nothing on disk has been
     * touched and the item is still whole ('failed'). Before 1.2.67 the files
     * went first, so a failed row delete left a library entry pointing at
     * nothing. Then the original and its generated copies are removed; any
     * that could not be are reported ('partial', with their names relative to
     * the uploads folder) rather than called a success.
     *
     * @return array{status:'deleted'|'partial'|'missing'|'failed', leftover?:string[]}
     */
    public function deleteWithReport(int $id): array
    {
        $row = $this->repo->find($id);
        if (!$row) return ['status' => 'missing'];

        // 1.2.87: while the row and its files still exist (an app that keeps
        // backups or records of its own cleans up here).
        $this->hooks->doAction('media.deleting', $row);

        try {
            $removed = $this->repo->delete($id);
        } catch (\Throwable $e) {
            return ['status' => 'failed'];
        }
        if ($removed < 1) {
            // Someone else deleted it a moment ago: the same end state.
            return $this->repo->find($id) ? ['status' => 'failed'] : ['status' => 'missing'];
        }

        $root = rtrim($this->uploadPath, '/');
        $rel = ltrim((string) ($row['storage_path'] ?? ''), '/');
        $relDir = dirname($rel) === '.' ? '' : dirname($rel) . '/';
        $files = [$rel];
        foreach ($this->decodeSizes($row['sizes'] ?? null) as $v) {
            // Only a bare file name: a stored path cannot point anywhere else.
            if (!empty($v['file']) && basename((string) $v['file']) === (string) $v['file']) $files[] = $relDir . $v['file'];
        }
        $leftover = [];
        foreach (array_unique($files) as $f) {
            if ($f === '' || str_contains($f, '..')) continue;
            $abs = $root . '/' . $f;
            if (is_file($abs) && !@unlink($abs) && is_file($abs)) $leftover[] = $f;
        }

        $this->hooks->doAction('media.deleted', $row);
        return $leftover ? ['status' => 'partial', 'leftover' => $leftover] : ['status' => 'deleted'];
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
