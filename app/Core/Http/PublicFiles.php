<?php
declare(strict_types=1);

namespace App\Core\Http;

/**
 * The files under content/ that a browser may download directly (1.2.124).
 *
 * content/ holds code (theme templates, app source), manifests, notes and,
 * now and then, a backup someone left behind: `single.php.bak2`, an app's
 * zip, a README. Before 1.2.124 the web server sent any of those that wasn't
 * a .php file as it was, so a backup of a template showed its source.
 *
 * Only static assets are public now: stylesheets, scripts, images, fonts,
 * audio and video. The same list is used in three places, so they can't
 * drift apart:
 *
 *  - content/.htaccess (Apache and LiteSpeed), checked against this list by
 *    a test;
 *  - server.php (PHP's built-in server, for local development);
 *  - AppAssetController (an app's assets/ folder served through PHP).
 */
final class PublicFiles
{
    /** Extension => the Content-Type it is sent with. */
    public const TYPES = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'map'   => 'application/json',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'mp4'   => 'video/mp4',
        'm4v'   => 'video/mp4',
        'webm'  => 'video/webm',
        'mp3'   => 'audio/mpeg',
        'm4a'   => 'audio/mp4',
        'wav'   => 'audio/wav',
        'ogg'   => 'audio/ogg',
    ];

    /** @return string[] the extensions, lower case */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Whether a file name may be sent from content/. The last extension
     * decides, so `header.php.bak` and `style.css.orig` are refused, and so
     * is a name with no extension. Dotfiles are never public.
     */
    public static function isPublic(string $path): bool
    {
        $name = basename(str_replace('\\', '/', $path));
        if ($name === '' || $name[0] === '.' || !str_contains($name, '.')) return false;
        return isset(self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))]);
    }

    /** The Content-Type for a public file, or null when it isn't one. */
    public static function typeOf(string $path): ?string
    {
        return self::isPublic($path) ? self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] : null;
    }

    /** The FilesMatch pattern content/.htaccess must use for this list. */
    public static function htaccessPattern(): string
    {
        return '\.(?i:' . implode('|', self::extensions()) . ')$';
    }
}
