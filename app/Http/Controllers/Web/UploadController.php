<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * Serves files from storage/uploads via PHP. This is a fallback for hosts
 * where direct file access to storage/uploads is blocked by .htaccess
 * inheritance or restrictive default Apache config.
 *
 * URL: /uploads/{path}  →  storage/uploads/{path}
 * Returns the file with the right Content-Type, or 404 if missing.
 */
class UploadController extends Controller
{
    public function serve(Request $request, string $path): Response
    {
        // Sanitize path - prevent traversal
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return Response::make('Forbidden', 403);
        }

        $fullPath = BASEHIM_ROOT . '/storage/uploads/' . $path;
        $realPath = realpath($fullPath);
        $uploadsBase = realpath(BASEHIM_ROOT . '/storage/uploads');

        if (!$realPath || !$uploadsBase || !str_starts_with($realPath, $uploadsBase)) {
            return Response::make('Not Found', 404);
        }
        if (!is_file($realPath) || !is_readable($realPath)) {
            return Response::make('Not Found', 404);
        }

        // Block executable scripts as defense-in-depth
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $blocked = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
                    'pl', 'py', 'jsp', 'asp', 'sh', 'cgi', 'htaccess'];
        if (in_array($ext, $blocked, true)) {
            return Response::make('Forbidden', 403);
        }

        $mime = $this->mimeFor($realPath, $ext);
        $size = filesize($realPath);
        $mtime = filemtime($realPath);
        $etag = '"' . sha1($realPath . $size . $mtime) . '"';

        // 304 Not Modified
        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch === $etag) {
            $r = Response::make('', 304);
            $r->header('ETag', $etag);
            return $r;
        }

        // Stream the file
        $content = file_get_contents($realPath);
        $response = Response::make($content !== false ? $content : '', 200);
        $response->header('Content-Type', $mime);
        $response->header('Content-Length', (string)$size);
        $response->header('ETag', $etag);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $response->header('Cache-Control', 'public, max-age=2592000'); // 30 days

        return $response;
    }

    private function mimeFor(string $path, string $ext): string
    {
        // Static map for common types — finfo isn't reliable for all formats
        static $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
            'txt' => 'text/plain', 'csv' => 'text/csv',
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
