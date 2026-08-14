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
    public static function slug(string $text, string $separator = '-'): string
    {
        $text = trim($text);
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

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function formatDate(?string $datetime, string $format = 'M j, Y'): string
    {
        if (!$datetime) return '';
        try {
            return (new \DateTime($datetime))->format($format);
        } catch (\Throwable) {
            return $datetime;
        }
    }

    public static function timeAgo(?string $datetime): string
    {
        if (!$datetime) return '';
        try {
            $then = new \DateTime($datetime);
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $then->getTimestamp();
            if ($diff < 60) return "just now";
            if ($diff < 3600) return floor($diff / 60) . 'm ago';
            if ($diff < 86400) return floor($diff / 3600) . 'h ago';
            if ($diff < 604800) return floor($diff / 86400) . 'd ago';
            return $then->format('M j, Y');
        } catch (\Throwable) {
            return $datetime;
        }
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
        $slug      = $post['slug'] ?? '';
        $type      = $post['type'] ?? 'post';
        $structure = static::permalinkStructure();

        // Pages and non-post types are always /{slug}.
        if ($type !== 'post' || $structure === 'flat') {
            return $base . '/' . $slug;
        }

        if ($structure === 'category') {
            $catSlug = $categorySlug
                ?: ($post['primary_category_slug'] ?? '')
                ?: static::lookupPrimaryCategory((int)($post['id'] ?? 0));
            if ($catSlug !== '') {
                return $base . '/' . $catSlug . '/' . $slug;
            }
            // No category attached — fall back to /posts/{slug} so the URL
            // stays valid rather than producing an ambiguous /{slug}.
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
