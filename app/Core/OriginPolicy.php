<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Which browser origins this site trusts with its users' cookies.
 *
 * Trusted: the site's own origin (from APP_URL and from the request's own
 * host) plus anything listed in CORS_ALLOWED_ORIGINS in .env, comma-separated
 * and exact (scheme://host[:port]), for a headless front end on another domain.
 *
 * Used by the CORS middleware, which sends Access-Control-Allow-Credentials
 * only to trusted origins, and by Authenticate, which ignores the session and
 * remember-me cookies on API requests from any other origin.
 *
 * Added in 1.2.43. Before that every origin was echoed back with credentials
 * allowed, so any page could read the API as whoever was signed in.
 */
final class OriginPolicy
{
    public static function isTrusted(?string $origin): bool
    {
        $origin = self::normalise((string) $origin);
        if ($origin === '') return false;
        return in_array($origin, self::trustedOrigins(), true);
    }

    /** @return string[] */
    public static function trustedOrigins(): array
    {
        $out = [];

        $appUrl = (string) (Env::get('APP_URL', '') ?? '');
        if ($appUrl !== '') $out[] = self::normalise($appUrl);

        // The request's own origin. A site reached on several hostnames (www and
        // bare, or a staging alias) is always same-origin with itself.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$|^\[[0-9a-fA-F:]+\](:\d+)?$/', $host)) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $out[] = self::normalise(($https ? 'https' : 'http') . '://' . $host);
        }

        $extra = (string) (Env::get('CORS_ALLOWED_ORIGINS', '') ?? '');
        foreach (explode(',', $extra) as $o) {
            $o = self::normalise(trim($o));
            if ($o !== '') $out[] = $o;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** scheme://host[:port], lower-cased, default ports dropped. '' if unusable. */
    public static function normalise(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === 'null') return '';
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return '';
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return '';
        $host = strtolower($p['host']);
        $port = isset($p['port']) ? (int) $p['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) $port = null;
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
