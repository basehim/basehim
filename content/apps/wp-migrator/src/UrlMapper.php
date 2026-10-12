<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Database;

/**
 * UrlMapper
 *
 * Turns a WordPress upload URL into the URL of the Basehim media it became.
 *
 * WordPress content almost never points at the file that was uploaded. It
 * points at the copies WordPress made of it — `photo-1024x683.jpg`,
 * `photo-300x200.jpg`, `photo-150x150.jpg`, `photo-scaled.jpg` — and a
 * gallery points at several of them at once (src, srcset, data-full-url,
 * data-link). Up to 1.2.0 only the exact attachment URL was recognised, so
 * those copies were left pointing at the old site, and a later step cut them
 * down to `/wp-content/uploads/...` — a path that does not exist on Basehim.
 * That was the "corrupted gallery" bug.
 *
 * Resolution, in order:
 *   1. The exact file, recorded when the attachment was imported
 *      (every size listed in _wp_attachment_metadata is recorded too).
 *   2. A resized copy (-WIDTHxHEIGHT): the same size is fetched from the old
 *      site once and stored next to the imported file, so a cropped gallery
 *      thumbnail stays cropped. If it cannot be fetched, the full image.
 *   3. -scaled, -rotated and -e{timestamp} (edited) copies: the full image.
 *   4. With no import map (repairing an older migration): a file-name match,
 *      used only when exactly one media item has that name.
 *
 * Anything else is returned as null and the caller leaves the URL alone —
 * an old absolute URL that still works is better than a path that never will.
 */
final class UrlMapper
{
    /** @var array<string,?array> uploads-relative path (lower-case) => media row, or null for a miss */
    private array $memo = [];

    /** @var list<string> lower-case host names the old site answered on */
    private array $hosts;

    /** @var array<string,array|false> */
    private array $byNameCache = [];

    public int $fetched = 0;

    /**
     * @param list<string> $hosts  host names of the old site (www and bare forms are added)
     * @param string $siteUrl      the old site's address, for fetching resized copies
     * @param bool $fetchVariants  download resized copies that are referenced but not imported
     * @param bool $byNameFallback match by file name when the import map has no entry
     */
    public function __construct(
        private Database $db,
        private IdMap $idMap,
        array $hosts,
        private string $siteUrl = '',
        private bool $fetchVariants = true,
        private bool $byNameFallback = false,
        private ?\Closure $log = null,
        private ?Downloader $downloader = null,
    ) {
        $all = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim((string) $h));
            if ($h === '') continue;
            $bare = preg_replace('/^www\./', '', $h);
            $all[$bare] = true;
            $all['www.' . $bare] = true;
        }
        $this->hosts = array_keys($all);
    }

    /** Hosts from any mix of URLs (the site address, attachment URLs, guids). */
    public static function hostsFrom(string ...$urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $h = parse_url(trim($u), PHP_URL_HOST);
            if (is_string($h) && $h !== '') $out[] = strtolower($h);
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function hosts(): array { return $this->hosts; }

    /**
     * The uploads-relative path (`2020/12/photo-300x200.jpg`) of a WordPress
     * upload URL, or null when the URL is not one. Accepts absolute URLs on the
     * old site's hosts, protocol-relative ones, and bare `/wp-content/uploads/`.
     */
    public function uploadsPath(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^(?:https?:)?//([^/]+)(/.*)$#i', $url, $m)) {
            $host = strtolower(preg_replace('/:\d+$/', '', $m[1]));
            if (!in_array($host, $this->hosts, true)) return null;
            $path = $m[2];
        } elseif (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            return null;
        }
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        $i = stripos($path, '/wp-content/uploads/');
        if ($i === false) return null;
        $rel = rawurldecode(substr($path, $i + strlen('/wp-content/uploads/')));
        return $rel !== '' ? $rel : null;
    }

    /** The new URL for an old upload URL, or null to leave it alone. */
    public function map(string $url): ?string
    {
        $rel = $this->uploadsPath($url);
        if ($rel === null) return null;
        return $this->mapPath($rel, $url);
    }

    public function mapPath(string $rel, string $originalUrl = ''): ?string
    {
        // 1. The exact file.
        $media = $this->mediaFor($rel);
        if ($media) return (string) $media['url'];

        [$base, $size] = self::split($rel);

        // 2–3. A copy of a file that was imported.
        if ($base !== $rel) {
            $media = $this->mediaFor($base);
            if ($media) {
                if ($size !== null) {
                    $copy = $this->variantUrl($media, $size, $rel, $originalUrl);
                    if ($copy !== null) return $copy;
                }
                return (string) $media['url'];
            }
        }
        return null;
    }

    /**
     * `2020/12/photo-300x200.jpg` => [`2020/12/photo.jpg`, `300x200`].
     * -scaled / -rotated / -e{timestamp} are stripped with no size.
     *
     * @return array{0:string,1:?string}
     */
    public static function split(string $rel): array
    {
        $size = null;
        $base = preg_replace_callback('/-(\d{2,5}x\d{2,5})(?=\.[a-z0-9]{2,5}$)/i', function ($m) use (&$size) {
            $size = strtolower($m[1]);
            return '';
        }, $rel) ?? $rel;
        $base = preg_replace('/-(scaled|rotated|e\d{10,})(?=\.[a-z0-9]{2,5}$)/i', '', $base) ?? $base;
        return [$base, $size];
    }

    /** The media row an uploads-relative path was imported as, or null. */
    private function mediaFor(string $rel): ?array
    {
        $key = strtolower($rel);
        if (array_key_exists($key, $this->memo)) return $this->memo[$key];

        // Keys are hashed: app_wpmig_idmap.old_id is VARCHAR(64), and a path
        // stored as-is is silently cut there on a non-strict server.
        $id = $this->idMap->get('media_path', self::key($rel));
        $row = $id ? $this->db->selectOne('SELECT id, url, storage_path, mime_type FROM {media} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]) : null;

        if (!$row && $this->byNameFallback) {
            $row = $this->byName(basename($key));
        }
        return $this->memo[$key] = $row ?: null;
    }

    /** The import-map key for an uploads-relative path. */
    public static function key(string $rel): string
    {
        return md5(strtolower(ltrim($rel, '/')));
    }

    /**
     * A media item whose original file name is exactly this, when there is
     * only one. One indexed-by-name query per distinct name; 1.3.1 loaded
     * the whole media table into memory instead — and the public
     * /wp-content/uploads/ redirect did that on every request.
     */
    private function byName(string $file): ?array
    {
        if ($file === '') return null;
        if (isset($this->byNameCache[$file])) return $this->byNameCache[$file] ?: null;
        try {
            $hits = $this->db->select(
                'SELECT id, url, storage_path, mime_type FROM {media}
                  WHERE original_name = :n AND deleted_at IS NULL LIMIT 2',
                ['n' => $file]
            );
        } catch (\Throwable) {
            $hits = [];
        }
        $this->byNameCache[$file] = count($hits) === 1 ? $hits[0] : false;
        return $this->byNameCache[$file] ?: null;
    }

    /**
     * The same resized copy WordPress made, stored beside the imported file as
     * `{uuid}-{W}x{H}.{ext}`. Fetched from the old site the first time it is
     * needed; null when it cannot be (the site is gone, the copy never existed).
     */
    private function variantUrl(array $media, string $size, string $rel, string $originalUrl): ?string
    {
        $storage = (string) ($media['storage_path'] ?? '');
        if ($storage === '' || !str_starts_with((string) ($media['mime_type'] ?? ''), 'image/')) return null;
        $dot = strrpos($storage, '.');
        if ($dot === false) return null;
        $variant = substr($storage, 0, $dot) . '-' . $size . substr($storage, $dot);
        $abs = self::uploadRoot() . '/' . $variant;
        $url = '/uploads/' . $variant;

        if (is_file($abs)) return $url;
        if (!$this->fetchVariants) return null;

        $from = $originalUrl;
        if ($from === '' || str_starts_with($from, '/') && !str_starts_with($from, '//')) {
            if ($this->siteUrl === '') return null;
            $from = rtrim($this->siteUrl, '/') . '/wp-content/uploads/' . $rel;
        } elseif (str_starts_with($from, '//')) {
            $from = 'https:' . $from;
        }

        if (str_contains($variant, '..')) return null;
        $dl = $this->downloader ?? new Downloader(false, 20, 8);
        $cache = (defined('BASEHIM_ROOT') ? BASEHIM_ROOT : '.') . '/storage/cache';
        $data = $dl->toString($from, is_dir($cache) ? $cache : sys_get_temp_dir(), 25 * 1024 * 1024);
        if ($data === null || @getimagesizefromstring($data) === false) return null;
        if (@file_put_contents($abs, $data) === false) return null;
        @chmod($abs, 0644);
        $this->fetched++;
        return $url;
    }

    public static function uploadRoot(): string
    {
        return defined('BASEHIM_ROOT') ? BASEHIM_ROOT . '/storage/uploads' : 'storage/uploads';
    }
}
