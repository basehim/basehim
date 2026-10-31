<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helpers
 *
 * Static helpers used throughout the codebase. We don't want a global
 * helpers.php file (the spec railed against procedural soup); these
 * are namespaced static methods instead.
 */
final class Helpers
{
    /**
     * A URL slug: lower-case a–z, 0–9 and the separator.
     *
     * With $unicode (1.2.76, CR-18; used for posts, pages and terms), text in
     * a script that has no Latin transliteration — Urdu, Arabic, Chinese,
     * Cyrillic, Devanagari… — keeps its own letters ("اردو-میں-خبریں")
     * instead of becoming "n-a". Latin text, accents included, is unchanged:
     * "Café déjà vu" is still "cafe-deja-vu". Such slugs are percent-encoded
     * in links (postUrl()), as browsers expect.
     */
    public static function slug(string $text, string $separator = '-', bool $unicode = false): string
    {
        $text = trim($text);
        if ($unicode && preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $text)) {
            $u = self::unicodeSlug($text, $separator);
            if ($u !== '') return $u;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', $separator, $text);
        $text = trim($text, $separator);
        return $text ?: 'n-a';
    }

    /** Letters, marks and digits of any script, lower-cased, at most 190 bytes. */
    private static function unicodeSlug(string $text, string $separator): string
    {
        if (!preg_match('//u', $text)) return '';
        if (class_exists(\Normalizer::class)) $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', $separator, $text);
        $text = trim($text, $separator);
        if (strlen($text) > 190) {
            $text = rtrim(mb_strcut($text, 0, 190, 'UTF-8'), $separator);
        }
        return $text;
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function excerpt(string $content, int $words = 30, string $suffix = '…'): string
    {
        $clean = strip_tags($content);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $parts = explode(' ', trim($clean));
        if (count($parts) <= $words) {
            return trim($clean);
        }
        return rtrim(implode(' ', array_slice($parts, 0, $words))) . $suffix;
    }

    public static function asset(string $path): string
    {
        return self::url(ltrim($path, '/'));
    }

    /**
     * Convert an internal-app path to a base-aware URL.
     * - Empty / leading-slash path: prepend BASEHIM_BASE (works in subdir installs)
     * - Full URL (http://, https://, //): returned unchanged
     * - Fragment / mailto / tel / data: returned unchanged
     */
    public static function link(string $path): string
    {
        if ($path === '') return defined('BASEHIM_BASE') ? (BASEHIM_BASE ?: '/') : '/';
        if (preg_match('#^(https?:)?//#i', $path)) return $path;
        if (preg_match('/^(mailto:|tel:|data:|#)/i', $path)) return $path;
        if ($path[0] !== '/') $path = '/' . $path;
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        // Don't double-prefix
        if ($base !== '' && !str_starts_with($path, $base . '/') && $path !== $base) {
            $path = $base . $path;
        }
        return $path;
    }

    public static function url(string $path = ''): string
    {
        $base = Env::get('APP_URL', null);
        if (!$base) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = "{$scheme}://{$host}";
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public static function adminUrl(string $path = ''): string
    {
        return self::url('admin' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Now in UTC, the way every time is stored. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** A stored (UTC) time shown in the site timezone. See App\Core\Time. */
    public static function formatDate(?string $datetime, string $format = 'M j, Y'): string
    {
        if (!$datetime) return '';
        $out = Time::format($datetime, $format);
        return $out !== '' ? $out : $datetime;
    }

    /** "5 minutes ago", measured correctly whatever the site timezone. */
    public static function timeAgo(?string $datetime): string
    {
        if (!$datetime) return '';
        $out = Time::ago($datetime);
        return $out !== '' ? $out : $datetime;
    }

    public static function bytesFormat(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes(intdiv($length, 2)));
    }

    /**
     * Return the public URL for a post row (or any array with 'slug' and 'type').
     *
     * Permalink structures:
     *   pretty   (default) → /posts/{slug}      pages: /{slug}
     *   category            → /{cat-slug}/{slug} pages: /{slug}
     *   flat                → /{slug}            pages: /{slug}
     *
     * For 'category' structure, pass the primary category slug in
     * $post['primary_category_slug'], or supply it via the optional
     * $categorySlug argument. If neither is available the helper falls back
     * to a single DB query to find it, so callers in tight loops should
     * eagerly load the primary category to avoid N+1 queries.
     *
     * The structure is cached statically so themes can call this in a loop.
     */
    public static function postUrl(array $post, string $base = '', string $categorySlug = ''): string
    {
        // Encoded for the URL: a no-op for a–z/0–9 slugs, needed for Unicode ones.
        $slug      = rawurlencode((string) ($post['slug'] ?? ''));
        $type      = $post['type'] ?? 'post';
        $structure = static::permalinkStructure();

        // Pages live at the site root, /{slug}, under every permalink
        // structure. The one exception is a page whose slug a route has
        // claimed ("search", "feed", an app's prefix): /{slug} would reach the
        // route instead, so that page keeps /page/{slug}. New content never
        // gets such a slug; PostService skips reserved slugs.
        if ($type === 'page') {
            return $base . (in_array($slug, static::reservedSlugs(), true) ? '/page/' : '/') . $slug;
        }

        // App-registered types live under their URL prefix (1.2.94):
        // /events/summer-meetup. A type with no public URLs (public => false,
        // rewrite => false, or its app switched off) has no address, so ''
        // is returned and callers leave the link out. Templates keep /{slug}.
        if ($type !== 'post') {
            if ($type === 'template') return $base . '/' . $slug;
            try {
                $def = Application::getInstance()->make(\App\Services\PostTypeRegistry::class)->get((string) $type);
            } catch (\Throwable) {
                $def = null;
            }
            if ($def === null || empty($def['public']) || ($def['rewrite'] ?? '') === '') return '';
            return $base . '/' . $def['rewrite'] . '/' . $slug;
        }

        if ($structure === 'flat') {
            // Same exception for a legacy post under flat permalinks.
            return $base . (in_array($slug, static::reservedSlugs(), true) ? '/posts/' : '/') . $slug;
        }

        if ($structure === 'category') {
            $catSlug = $categorySlug
                ?: ($post['primary_category_slug'] ?? '')
                ?: static::lookupPrimaryCategory((int)($post['id'] ?? 0));
            if ($catSlug !== '') {
                return $base . '/' . rawurlencode((string) $catSlug) . '/' . $slug;
            }
            // No category attached. Since 1.2.7 every post is given the
            // default category when saved, so this only covers a post not yet
            // re-saved; /posts/{slug} keeps its URL valid meanwhile.
        }

        return $base . '/posts/' . $slug;
    }

    /**
     * Look up the first-attached category slug for a post ID.
     * Result is NOT cached here — callers should pass it in when possible.
     */
    public static function lookupPrimaryCategory(int $postId): string
    {
        if ($postId <= 0) return '';
        try {
            $app = \App\Core\Application::getInstance();
            if (!$app) return '';
            /** @var \App\Core\Database $db */
            $db  = $app->make(\App\Core\Database::class);
            $row = $db->selectOne(
                "SELECT t.slug
                 FROM {post_term} pt
                 JOIN {terms} t ON t.id = pt.term_id
                 JOIN {taxonomies} tax ON tax.id = t.taxonomy_id
                 WHERE pt.post_id = :pid AND tax.slug = 'category'
                 ORDER BY pt.term_order ASC
                 LIMIT 1",
                ['pid' => $postId]
            );
            return $row ? (string)$row['slug'] : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Return the public URL for a page row — always /{slug} regardless of
     * permalink structure (pages never get a /page/ prefix in Basehim).
     */
    public static function pageUrl(array $page, string $base = ''): string
    {
        return $base . '/' . ($page['slug'] ?? '');
    }

    /**
     * Read the permalink structure setting once and cache it statically.
     * Falls back to 'pretty' if the DB is not available.
     */
    /**
     * Slugs no post or page may use: the first segment of every registered
     * route, which would answer /{slug} before the content could. Read from
     * the live router, so an installed app's routes count too.
     *
     * @return string[]
     */
    public static function reservedSlugs(): array
    {
        $list = ['admin', 'api', 'posts', 'page', 'category', 'tag', 'author', 'search',
                 'comments', 'feed', 'uploads', 'content', 'mcp', 'oauth', 'install'];
        try {
            $router = Application::getInstance()->make(Router::class);
            if (method_exists($router, 'reservedSegments')) {
                $list = array_merge($list, $router->reservedSegments());
            }
        } catch (\Throwable) {
            // The fixed list above still covers core's own routes.
        }
        return array_values(array_unique($list));
    }

    private static ?string $permalinkStructure = null;

    public static function permalinkStructure(): string
    {
        if (static::$permalinkStructure !== null) {
            return static::$permalinkStructure;
        }

        try {
            // Resolve SettingService through the container if available.
            if (class_exists('\\App\\Core\\Application', false)) {
                $app = \App\Core\Application::getInstance();
                if ($app) {
                    /** @var \App\Services\SettingService $settings */
                    $settings = $app->make(\App\Services\SettingService::class);
                    static::$permalinkStructure = (string)$settings->get('permalinks', 'structure', 'pretty');
                    return static::$permalinkStructure;
                }
            }
        } catch (\Throwable) {}

        static::$permalinkStructure = 'pretty';
        return static::$permalinkStructure;
    }
}
