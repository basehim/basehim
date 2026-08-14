<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * Serves static asset files bundled inside app folders.
 *
 *   URL:  /content/apps/{slug}/assets/{path}
 *   File: content/apps/{slug}/assets/{path}
 *
 * Both content directories are searched, so an app still living under the
 * app serves its assets from this URL — a page cached
 * with old asset URLs keeps rendering after the rename.
 *
 * Only the `assets/` subdirectory is exposed. PHP and other executable
 * extensions are explicitly blocked.
 */
class AppAssetController extends Controller
{
    public function serve(Request $request, string $slug, string $path): Response
    {
        // Sanitize.
        if (str_contains($slug, '..') || str_contains($slug, '/') || str_contains($slug, "\0")) {
            return Response::make('Forbidden', 403);
        }
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return Response::make('Forbidden', 403);
        }

        // Apps live in content/apps.
        $real = null;
        foreach (['apps'] as $container) {
            $assetsBase = BASEHIM_ROOT . '/content/' . $container . '/' . $slug . '/assets';
            $candidate = realpath($assetsBase . '/' . $path);
            $baseReal  = realpath($assetsBase);
            if (!$candidate || !$baseReal) continue;
            if (!str_starts_with($candidate, $baseReal . DIRECTORY_SEPARATOR)) continue;
            if (!is_file($candidate) || !is_readable($candidate)) continue;
            $real = $candidate;
            break;
        }
        if ($real === null) {
            return Response::make('Not Found', 404);
        }

        // Block executable scripts as defense-in-depth.
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $blocked = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
                    'pl', 'py', 'jsp', 'asp', 'sh', 'cgi', 'htaccess'];
        if (in_array($ext, $blocked, true)) {
            return Response::make('Forbidden', 403);
        }

        $mime = $this->mimeFor($real, $ext);
        $size = filesize($real);
        $mtime = filemtime($real);
        $etag = '"' . sha1($real . $size . $mtime) . '"';

        if ($request->header('if-none-match') === $etag) {
            $r = Response::make('', 304);
            $r->header('ETag', $etag);
            return $r;
        }

        $content = file_get_contents($real);
        $response = Response::make($content !== false ? $content : '', 200);
        $response->header('Content-Type', $mime);
        $response->header('Content-Length', (string)$size);
        $response->header('ETag', $etag);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $response->header('Cache-Control', 'public, max-age=2592000');
        return $response;
    }

    private function mimeFor(string $path, string $ext): string
    {
        static $map = [
            'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
            'map' => 'application/json',
        ];
        if (isset($map[$ext])) return $map[$ext];

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $path) : null;
            if ($finfo) finfo_close($finfo);
            if ($mime) return $mime;
        }
        return 'application/octet-stream';
    }
}
