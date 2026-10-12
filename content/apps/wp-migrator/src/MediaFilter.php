<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

/**
 * MediaFilter
 *
 * Decides which WordPress attachments the media step imports, and which
 * file extensions may be written to storage/uploads at all.
 *
 * Options (job options['media']):
 *   types           list of image|video|audio|document|archive|other
 *   originals_only  skip resized copies (photo-300x200.jpg, photo@2x.jpg),
 *                   download the original instead of WordPress's -scaled
 *                   copy, and do not fetch resized copies during the
 *                   rewrite step (content links go to the full image)
 *   exclude         comma/newline-separated file-name globs (*-150x150.*)
 *   allow_svg       SVG can carry script; off unless the source is trusted
 *   max_mb          per-file size cap
 *   allow_private   allow downloads from private/loopback addresses
 */
final class MediaFilter
{
    public const TYPES = ['image', 'video', 'audio', 'document', 'archive', 'other'];

    private const EXT = [
        'image'    => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'ico', 'heic', 'heif'],
        'video'    => ['mp4', 'm4v', 'mov', 'webm', 'ogv', 'avi', 'wmv', 'mkv', '3gp', '3g2', 'mpg', 'mpeg', 'flv'],
        'audio'    => ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac', 'aac', 'wma', 'opus', 'mid', 'midi'],
        'document' => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'csv', 'xls', 'xlsx', 'ods', 'ppt', 'pptx',
                       'odp', 'pps', 'ppsx', 'key', 'pages', 'numbers', 'epub', 'md'],
        'archive'  => ['zip', 'gz', 'tgz', '7z', 'rar', 'tar', 'bz2'],
    ];

    /** Never written to the uploads folder, whatever the filter says. */
    private const DENY = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
                          'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'jar',
                          'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'xht', 'xml', 'xsl', 'svgz', 'hta', 'jsp', 'asp', 'aspx',
                          'htaccess', 'htpasswd', 'ini', 'swf', 'vbs', 'ps1', 'scr'];

    /** MIME => extension, for files whose URL has none. */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/heic' => 'heic',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/ogg' => 'ogv',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'audio/flac' => 'flac', 'application/pdf' => 'pdf', 'application/zip' => 'zip', 'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    /** @param list<string> $types @param list<string> $exclude */
    public function __construct(
        public readonly array $types = self::TYPES,
        public readonly bool $originalsOnly = false,
        public readonly array $exclude = [],
        public readonly bool $allowSvg = false,
        public readonly int $maxBytes = Downloader::DEFAULT_MAX_BYTES,
        public readonly bool $allowPrivate = false,
    ) {}

    public static function fromOptions(array $o): self
    {
        $types = array_values(array_intersect(self::TYPES, array_map('strval', (array) ($o['types'] ?? self::TYPES))));
        $mb = (int) ($o['max_mb'] ?? 25);
        return new self(
            types: $types,
            originalsOnly: (bool) ($o['originals_only'] ?? false),
            exclude: self::parsePatterns((string) ($o['exclude'] ?? '')),
            allowSvg: (bool) ($o['allow_svg'] ?? false),
            maxBytes: max(1, min(512, $mb ?: 25)) * 1024 * 1024,
            allowPrivate: (bool) ($o['allow_private'] ?? false),
        );
    }

    /** @return list<string> */
    public static function parsePatterns(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && strlen($p) <= 200) $out[] = strtolower($p);
        }
        return array_slice(array_values(array_unique($out)), 0, 50);
    }

    /** A file name looks like a copy WordPress (or a retina plugin) made of an upload. */
    public static function isResizedCopy(string $fileName): bool
    {
        return (bool) preg_match('/-\d{2,5}x\d{2,5}(?:@\dx)?\.[a-z0-9]{2,5}$/i', $fileName)
            || (bool) preg_match('/@\dx\.[a-z0-9]{2,5}$/i', $fileName);
    }

    /** Why this attachment is not imported, or null to import it. */
    public function rejects(string $fileName, string $mime = ''): ?string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $cat = $this->category($ext, $mime);
        if (!in_array($cat, $this->types, true)) return "type '{$cat}' not selected";
        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            if (!$this->allowSvg) return 'SVG not allowed (enable "Allow SVG" for trusted sources)';
        }
        if ($this->originalsOnly && self::isResizedCopy($fileName)) return 'resized copy (originals only)';
        $lower = strtolower($fileName);
        foreach ($this->exclude as $glob) {
            if (fnmatch($glob, $lower, FNM_NOESCAPE)) return "matches exclude pattern '{$glob}'";
        }
        return null;
    }

    /** image|video|audio|document|archive|other */
    public function category(string $ext, string $mime = ''): string
    {
        $ext = strtolower($ext);
        if ($ext === 'svg') return 'image';
        foreach (self::EXT as $cat => $list) {
            if (in_array($ext, $list, true)) return $cat;
        }
        $mime = strtolower($mime);
        if ($mime !== '') {
            foreach (['image', 'video', 'audio'] as $p) if (str_starts_with($mime, $p . '/')) return $p;
            if ($mime === 'application/pdf' || str_starts_with($mime, 'text/')
                || str_contains($mime, 'officedocument') || str_contains($mime, 'msword')
                || str_contains($mime, 'opendocument')) return 'document';
            if (in_array($mime, ['application/zip', 'application/gzip', 'application/x-7z-compressed',
                                 'application/x-rar-compressed', 'application/x-tar'], true)) return 'archive';
        }
        return 'other';
    }

    /**
     * The extension to store a file under, or null when it may not be
     * stored. Taken from the URL when allowed, else from the content type.
     */
    public function safeExtension(string $url, string $mime): ?string
    {
        $ext = strtolower(pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        $mime = strtolower($mime);

        // Content that a browser would run is never stored, whatever its name.
        if (in_array($mime, ['text/html', 'application/xhtml+xml', 'application/x-php', 'text/x-php',
                             'application/javascript', 'text/javascript', 'application/x-httpd-php'], true)) {
            return null;
        }
        if ($mime === 'image/svg+xml' || $ext === 'svg') return $this->allowSvg ? 'svg' : null;

        if ($ext !== '' && !in_array($ext, self::DENY, true)) {
            $known = false;
            foreach (self::EXT as $list) if (in_array($ext, $list, true)) { $known = true; break; }
            if ($known) return $ext;
            // Unknown extension, "other" type: only when it is plainly inert.
            if (in_array('other', $this->types, true) && preg_match('/^[a-z0-9]{1,8}$/', $ext)) return $ext;
        }
        return self::MIME_EXT[$mime] ?? null;
    }
}
