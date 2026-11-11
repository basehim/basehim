<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Http\PublicFiles;
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
 * Only the `assets/` subdirectory is exposed, and only the static file
 * types in PublicFiles (1.2.124): before, anything but a list of script
 * extensions was sent, so a backup such as `app.js.bak` was too.
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

        // Static assets only: the same list content/.htaccess allows.
        $mime = PublicFiles::typeOf($real);
        if ($mime === null) {
            return Response::make('Forbidden', 403);
        }

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
        // A versioned URL (?v=…, App::asset() adds one since 1.2.90) changes
        // with each release, so it can be kept for a month; an unversioned one
        // must be checked each time, or an update never reaches browsers.
        $versioned = ($request->query['v'] ?? '') !== '';
        $response->header('Cache-Control', $versioned ? 'public, max-age=2592000' : 'public, no-cache');
        return $response;
    }
}
