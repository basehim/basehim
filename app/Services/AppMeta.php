<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AppMeta — the presentable side of an app's manifest.
 *
 * Reads the fields that describe an app to a person — icon, description,
 * developer, company and their links — and turns them into one normalised
 * shape the Apps screen, the Updates screen and the marketplace all render
 * the same way.
 *
 * ── app.json ────────────────────────────────────────────────────────────────
 *
 *     {
 *       "name": "Analytics",
 *       "slug": "analytics",
 *       "version": "1.6.0",
 *       "icon": "assets/icon.png",
 *       "description": "First-party visitor statistics without cookies.",
 *       "developer": { "name": "Jane Doe", "url": "https://jane.dev" },
 *       "company":   { "name": "Circuits DIY", "url": "https://circuitsdiy.com" }
 *     }
 *
 * Every one of these is optional, and every older spelling still works, so an
 * app written before 1.2.36 displays exactly as it did:
 *
 *   - "author": "Name"                      → the developer's name
 *   - "developer": "Name"                   → the same, as a plain string
 *   - "developer_url", "company_url"        → flat alternatives to the objects
 *   - "icon": "heroicon:chart-bar"          → a built-in glyph, as before
 *   - "icon": "fa-rocket"                   → a Font Awesome class, as before
 *
 * ── The icon image ──────────────────────────────────────────────────────────
 *
 * A PNG or GIF inside the app's assets/ folder, square, 256×256 or 512×512,
 * at most 1 MB. Only assets/ is served to the browser, which is why the file
 * has to live there. An image that breaks a rule is still shown when it can
 * be — refusing to draw a slightly-too-big icon helps nobody — and the rule
 * it broke is reported on the Apps screen so the developer can fix it. A
 * missing, unreadable or oversized file is not shown, and the app falls back
 * to its initial.
 *
 * Nothing here can stop an app loading: a manifest with nonsense in these
 * fields is an app with a plain card, never an app that fails.
 */
final class AppMeta
{
    /** The largest icon file accepted, in bytes. */
    public const ICON_MAX_BYTES = 1048576;

    /** The square sizes an icon image may be, in pixels. */
    public const ICON_SIZES = [256, 512];

    /** Image formats the icon may use. */
    public const ICON_TYPES = ['png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF];

    /**
     * Normalise one app's manifest for display.
     *
     * @param array  $manifest The decoded app.json.
     * @param string $slug     The app's folder name.
     * @param string $dir      The app's folder on disk ('' when not installed,
     *                         e.g. a marketplace listing; file checks are skipped).
     * @return array{
     *   slug:string, name:string, version:string, description:string,
     *   developer:?array{name:string,url:?string},
     *   company:?array{name:string,url:?string},
     *   icon:array{type:string,value:string},
     *   initial:string, problems:list<string>
     * }
     */
    public static function from(array $manifest, string $slug, string $dir = ''): array
    {
        $problems = [];

        $name = self::text($manifest['name'] ?? '', 80) ?: $slug;
        $description = self::text($manifest['description'] ?? '', 600);

        $developer = self::party($manifest, 'developer', $problems);
        if ($developer === null) {
            // Every app before 1.2.36 named its author this way.
            $author = self::text($manifest['author'] ?? '', 100);
            if ($author !== '') $developer = ['name' => $author, 'url' => null];
        }
        $company = self::party($manifest, 'company', $problems);

        return [
            'slug'        => $slug,
            'name'        => $name,
            'version'     => self::text($manifest['version'] ?? '', 32),
            'description' => $description,
            'developer'   => $developer,
            'company'     => $company,
            'icon'        => self::icon($manifest, $slug, $dir, $problems),
            'initial'     => mb_strtoupper(mb_substr($name, 0, 1)) ?: '?',
            'problems'    => $problems,
        ];
    }

    /**
     * Meta for a marketplace or update entry from CloudHim, which describes
     * an app that may not be installed. Uses whichever fields the hub sends;
     * a hub that predates the new fields still yields a usable card.
     */
    public static function fromHub(array $item): array
    {
        $ignored = [];
        // The hub may pass the manifest's own shapes through: an object, or a
        // string with a flat *_url. Either way, "author" is the fallback.
        $developer = self::party($item, 'developer', $ignored);
        if ($developer === null) {
            $author = self::text($item['author'] ?? '', 100);
            if ($author !== '') $developer = ['name' => $author, 'url' => null];
        }
        $company = self::party($item, 'company', $ignored);

        $name = self::text($item['name'] ?? '', 80) ?: (string) ($item['slug'] ?? '');
        $iconUrl = self::url($item['icon_url'] ?? null);
        return [
            'slug'        => (string) ($item['slug'] ?? ''),
            'name'        => $name,
            'version'     => self::text($item['version'] ?? '', 32),
            'description' => self::text($item['description'] ?? '', 600),
            'developer'   => $developer,
            'company'     => $company,
            'icon'        => $iconUrl !== null ? ['type' => 'image', 'value' => $iconUrl] : ['type' => 'none', 'value' => ''],
            'initial'     => mb_strtoupper(mb_substr($name, 0, 1)) ?: '?',
            'problems'    => [],
        ];
    }

    /**
     * The icon as something a view can draw.
     *
     * @return array{type:string,value:string} type is 'image' (value = URL),
     *         'glyph' (value = core icon name), 'fa' (value = class) or 'none'.
     */
    private static function icon(array $manifest, string $slug, string $dir, array &$problems): array
    {
        $icon = trim((string) (is_string($manifest['icon'] ?? null) ? $manifest['icon'] : ''));
        if ($icon === '') return ['type' => 'none', 'value' => ''];

        if (str_starts_with($icon, 'heroicon:')) {
            $glyph = substr($icon, 9);
            return preg_match('/^[a-z0-9-]{1,60}$/', $glyph) ? ['type' => 'glyph', 'value' => $glyph] : ['type' => 'none', 'value' => ''];
        }
        if (preg_match('/^fa[srb]? |^fa-[a-z0-9-]+$/', $icon)) {
            return ['type' => 'fa', 'value' => $icon];
        }

        $ext = strtolower(pathinfo($icon, PATHINFO_EXTENSION));
        if ($ext === '') {
            // A bare word was always read as a core glyph name.
            return preg_match('/^[a-z0-9-]{1,60}$/', $icon) ? ['type' => 'glyph', 'value' => $icon] : ['type' => 'none', 'value' => ''];
        }

        // An image file. It must be inside assets/, the only folder served.
        $rel = ltrim(str_replace('\\', '/', $icon), '/');
        if (str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
            $problems[] = 'Icon: "' . self::text($icon, 80) . '" is not a valid path inside the app.';
            return ['type' => 'none', 'value' => ''];
        }
        if (!str_starts_with($rel, 'assets/')) {
            /*
             * Before 1.2.36 a bare file name meant a file inside assets/
             * ("icon.svg" → assets/icon.svg), so that is still how it is read.
             * A file that is really at the app's root cannot be served, and
             * the developer is told where to move it.
             */
            if ($dir !== '' && !is_file(rtrim($dir, '/') . '/assets/' . $rel) && is_file(rtrim($dir, '/') . '/' . $rel)) {
                $problems[] = 'Icon: move ' . $rel . ' into the app\'s assets/ folder and set "icon": "assets/' . $rel . '" — only assets/ is served to the browser.';
                return ['type' => 'none', 'value' => ''];
            }
            $rel = 'assets/' . $rel;
        }
        $legacy = in_array($ext, ['svg', 'jpg', 'jpeg', 'webp'], true);
        if (!isset(self::ICON_TYPES[$ext]) && !$legacy) {
            $problems[] = 'Icon: use a PNG or GIF image.';
            return ['type' => 'none', 'value' => ''];
        }

        $url = (defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '')
             . '/content/apps/' . rawurlencode($slug) . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));

        if ($dir === '') return ['type' => 'image', 'value' => $url];

        $file = rtrim($dir, '/') . '/' . $rel;
        if (!is_file($file)) {
            $problems[] = 'Icon: ' . $rel . ' was not found in the app.';
            return ['type' => 'none', 'value' => ''];
        }
        $size = (int) @filesize($file);
        if ($size > self::ICON_MAX_BYTES) {
            $problems[] = 'Icon: ' . $rel . ' is ' . round($size / 1048576, 1) . ' MB; the limit is 1 MB.';
            return ['type' => 'none', 'value' => ''];
        }
        if ($legacy) {
            // Earlier apps could ship SVG, JPEG or WebP icons; they keep
            // showing, with a nudge towards the format the marketplace expects.
            $problems[] = 'Icon: ' . strtoupper($ext) . ' still works, but use a 256×256 or 512×512 PNG or GIF.';
            return ['type' => 'image', 'value' => $url];
        }

        $info = @getimagesize($file);
        if (!$info || ($info[2] ?? null) !== self::ICON_TYPES[$ext]) {
            $problems[] = 'Icon: ' . $rel . ' is not a readable ' . strtoupper($ext) . ' image.';
            return ['type' => 'none', 'value' => ''];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w !== $h || !in_array($w, self::ICON_SIZES, true)) {
            $problems[] = 'Icon: ' . $rel . ' is ' . $w . '×' . $h . '; use 256×256 or 512×512.';
        }
        return ['type' => 'image', 'value' => $url];
    }

    /**
     * A developer or company: {"name","url"} object, a plain string, or a
     * string plus a flat "{key}_url". Null when nothing usable is given.
     *
     * @return array{name:string,url:?string}|null
     */
    private static function party(array $manifest, string $key, array &$problems): ?array
    {
        $raw = $manifest[$key] ?? null;
        $name = ''; $url = null;
        if (is_array($raw)) {
            $name = self::text($raw['name'] ?? '', 100);
            $url  = $raw['url'] ?? null;
        } elseif (is_string($raw)) {
            $name = self::text($raw, 100);
        }
        if ($url === null && isset($manifest[$key . '_url'])) $url = $manifest[$key . '_url'];
        if ($name === '') return null;

        $clean = self::url($url);
        if ($url !== null && $url !== '' && $clean === null) {
            $problems[] = ucfirst($key) . ' link: use a full http:// or https:// address.';
        }
        return ['name' => $name, 'url' => $clean];
    }

    /** One line of plain text, trimmed and cut to length. */
    private static function text(mixed $v, int $max): string
    {
        if (!is_string($v) && !is_numeric($v)) return '';
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', (string) $v));
        return mb_substr($v, 0, $max);
    }

    /** An absolute http(s) address, or null. Anything else could be a javascript: link. */
    private static function url(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        if ($v === '' || strlen($v) > 300) return null;
        if (!preg_match('#^https?://[^\s"<>]+$#i', $v)) return null;
        $host = parse_url($v, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $v : null;
    }
}
