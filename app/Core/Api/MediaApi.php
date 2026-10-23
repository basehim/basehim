<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Config;
use App\Services\MediaService;

/**
 * MediaApi — the media library.
 *
 *     $api->media()->paginate(['type' => 'image']);
 *     $api->media()->update($id, ['alt_text' => 'A cat']);
 *     $api->media()->uploadFromPath('/tmp/x.png', 'chart.png');
 *
 * 1.2.87, for apps that work with files:
 *
 *     $api->media()->fromUpload($ctx->file('file'), ['title' => 'Logo']);  // an AJAX upload into the library
 *     $api->media()->path($id);              // absolute path of the file on this server, or null
 *     $api->media()->replaceFile($id, $tmp); // swap the file in place (same URL), rebuild sizes
 *     $api->media()->regenerate($id);        // rebuild generated sizes
 *     $api->media()->sizes('image/jpeg');    // size definitions, after the media.sizes filter
 *     $api->media()->uploadRules('app:my-app'); // allowed extensions and size limit
 *     $api->media()->lastError();            // why the last call returned null, for the person
 */
class MediaApi extends Resource
{
    private ?string $lastError = null;

    /**
     * Why the last fromUpload(), replaceFile() or regenerate() call returned
     * null: a sentence for the person (a rejected file), or a generic one
     * when something else went wrong (details are in the app's log). Null
     * after a call that succeeded.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Run $fn, keeping a person-facing reason when it fails. */
    private function tryFile(string $what, callable $fn): mixed
    {
        $this->lastError = null;
        if (!$this->permitted($what)) {
            $this->lastError = 'This app has not been allowed to change media.';
            return null;
        }
        try {
            return $fn();
        } catch (\App\Services\MediaRejected $e) {
            $this->lastError = $e->getMessage();
            return null;
        } catch (\Throwable $e) {
            $this->log("API {$what} failed: " . $e->getMessage(), [], 'warning');
            $this->lastError = 'The file could not be processed.';
            return null;
        }
    }

    /**
     * Add a file sent to one of this app's AJAX actions to the media library
     * (1.2.87). It goes through every check a library upload does, and the
     * media.upload.* filters with context 'app:{slug}' unless $context says
     * otherwise. The upload is moved into the library.
     *
     * $meta: title, alt_text (or alt), caption, name_hint.
     *
     * @return array|null the new media row; null with lastError() on refusal
     */
    public function fromUpload(\App\Core\Ajax\UploadedFile $file, array $meta = [], ?int $authorId = null, ?string $context = null): ?array
    {
        return $this->tryFile('fromUpload', function () use ($file, $meta, $authorId, $context) {
            if ($file->wasMoved()) throw new \App\Services\MediaRejected('This upload was already stored elsewhere.');
            // Whoever is signed in must be allowed to upload media, whatever
            // capability the app's action asked for.
            $user = $this->currentUser();
            if ($user !== null && !\App\Http\Middleware\CheckCapability::userCan($user, 'upload_media')) {
                throw new \App\Services\MediaRejected('You are not allowed to add files to the media library.');
            }
            $service = $this->service();
            $config = $this->make(Config::class);
            $meta = self::normalizeMeta($meta);
            $meta['context'] = $context ?? ('app:' . $this->slug);
            $allowed = $service->allowedTypes((array) $config->get('cms.media.allowed_types', []));
            $max = $service->maxUploadBytes((int) $config->get('cms.media.max_upload_size', 64 * 1024 * 1024));
            $author = $authorId ?? (isset($user['id']) ? (int) $user['id'] : null) ?? 1;
            $entry = ['name' => $file->name(), 'tmp_name' => $file->path(), 'error' => UPLOAD_ERR_OK, 'size' => $file->size()];
            $row = $file->isHttpUpload()
                ? $service->upload($entry, $author, $allowed, $max, $meta)
                : $service->importFile($file->path(), $file->name(), $author, $allowed, $max, $meta);
            $this->log("Added media #{$row['id']} from an upload ({$file->name()})");
            return $row;
        });
    }

    /** Absolute path of an item's file on this server, or null (see MediaService::localPath). */
    public function path(int $id): ?string
    {
        return $this->attempt(function () use ($id) {
            $row = $this->service()->find($id);
            return $row ? $this->service()->localPath($row) : null;
        }, null, 'path');
    }

    /**
     * Replace an item's file in place (MediaService::replaceFile): same name
     * and URL, size and dimensions updated, generated sizes rebuilt.
     * Options: regenerate (bool, default true), expect_sha1 (string).
     *
     * @return array|null the updated row; null with lastError() on refusal
     */
    public function replaceFile(int $id, string $sourcePath, array $opts = []): ?array
    {
        return $this->tryFile('replaceFile', function () use ($id, $sourcePath, $opts) {
            $row = $this->service()->replaceFile($id, $sourcePath, array_intersect_key($opts, ['regenerate' => 1, 'expect_sha1' => 1]));
            $this->log("Replaced the file of media #{$id}");
            return $row;
        });
    }

    /**
     * Rebuild an item's generated sizes.
     *
     * @return array|null ['status' => processed|skipped|failed, 'variants' => n, 'item' => row]
     */
    public function regenerate(int $id): ?array
    {
        return $this->tryFile('regenerate', fn() => $this->service()->regenerate($id));
    }

    /** Size definitions {name => [w, h, crop]}, after the media.sizes filter. */
    public function sizes(string $mime = ''): array
    {
        return $this->attempt(fn() => $this->service()->sizeDefinitions(null, $mime), [], 'sizes');
    }

    /**
     * What an upload in $context may be: ['types' => [...], 'max_bytes' => n],
     * after the media.upload.allowed_types and media.upload.max_bytes filters.
     */
    public function uploadRules(?string $context = null): array
    {
        return $this->attempt(fn() => $this->service()->uploadRules($context ?? ('app:' . $this->slug)), ['types' => [], 'max_bytes' => 0], 'uploadRules');
    }

    private function currentUser(): ?array
    {
        try {
            $u = $this->app->has('auth.user') ? $this->app->make('auth.user') : null;
            return is_array($u) && !empty($u['id']) ? $u : null;
        } catch (\Throwable) {
            return null;
        }
    }
    private function service(): MediaService
    {
        return $this->make(MediaService::class);
    }

    public function find(int $id): ?array
    {
        return $this->attempt(fn() => $this->service()->find($id), null, 'find');
    }

    /** @param array $filters search, type, sort */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        return $this->attempt(
            fn() => $this->service()->paginate($filters, max(1, $page), max(1, min(100, $perPage))),
            ['data' => [], 'meta' => []],
            'paginate'
        );
    }

    public function all(array $filters = [], int $limit = 500): array
    {
        $out = [];
        $page = 1;
        while (count($out) < $limit) {
            $chunk = $this->paginate($filters, $page, 100);
            $rows = $chunk['data'] ?? [];
            if (!$rows) break;
            foreach ($rows as $row) {
                $out[] = $row;
                if (count($out) >= $limit) break 2;
            }
            if (count($rows) < 100) break;
            $page++;
        }
        return $out;
    }

    /**
     * Update a media item's details.
     *
     * Text: `title`, `alt_text` (or `alt`), `caption`, `description`.
     * File facts, for an app that rewrote the file in place: `width`,
     * `height`, `file_size` — whole numbers, zero or more. These used to be
     * dropped silently, so an image edited in place kept its old dimensions.
     */
    public function update(int $id, array $data): bool
    {
        $data  = self::normalizeMeta($data);
        $clean = array_intersect_key($data, array_flip(['title', 'alt_text', 'caption', 'description']));
        foreach (['width', 'height', 'file_size'] as $k) {
            if (array_key_exists($k, $data) && is_numeric($data[$k]) && (int) $data[$k] >= 0) {
                $clean[$k] = (int) $data[$k];
            }
        }
        if (!$clean) return false;

        $ok = ((int) $this->attempt(fn() => $this->service()->update($id, $clean), 0, 'update')) > 0;
        if ($ok) $this->log("Updated media #{$id}");
        return $ok;
    }

    /** Delete a media item and its files. */
    public function delete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->delete($id), false, 'delete');
        if ($ok) $this->log("Deleted media #{$id}");
        return $ok;
    }

    /**
     * `alt` is accepted as the short form of `alt_text`. Every app that saves
     * to the library passed `alt`, which core never read, so everything those
     * apps saved had no alt text. `alt_text` wins if both are given.
     */
    private static function normalizeMeta(array $meta): array
    {
        if (array_key_exists('alt', $meta)) {
            if (!isset($meta['alt_text']) || $meta['alt_text'] === '') {
                $meta['alt_text'] = (string) $meta['alt'];
            }
            unset($meta['alt']);
        }
        return $meta;
    }

    /**
     * Add a file already on disk to the media library.
     *
     * The core uploader expects a $_FILES-shaped array, which an app generating
     * a file itself (a rendered chart, a fetched remote image) does not have.
     * This builds that shape from a plain path.
     *
     * $meta: `title`, `alt_text` (or `alt`), `caption`.
     *
     * @return array|null The created media row, or null on failure.
     */
    public function uploadFromPath(string $path, ?string $filename = null, ?int $authorId = null, array $meta = []): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->log("uploadFromPath: unreadable path {$path}", [], 'warning');
            return null;
        }

        $service = $this->service();
        $config = $this->make(Config::class);
        $name = $filename ?: basename($path);
        $meta = self::normalizeMeta($meta);

        // importFile(), not upload(): upload() only accepts a file PHP received
        // in this HTTP request, which a file an app generated never is.
        $result = $this->attempt(
            fn() => $service->importFile(
                $path,
                $name,
                $authorId ?? 1,
                $service->allowedTypes((array) $config->get('cms.uploads.allowed_types', [])),
                $service->maxUploadBytes((int) $config->get('cms.uploads.max_size', 8388608)),
                $meta
            ),
            null,
            'uploadFromPath'
        );

        if (is_array($result) && !empty($result['id'])) {
            $this->log("Uploaded media #{$result['id']} ({$name})");
            return $result;
        }
        return null;
    }

    /** Library totals: ['count' => int, 'bytes' => int]. */
    public function stats(): array
    {
        return [
            'count' => (int) $this->attempt(fn() => $this->service()->totalCount(), 0, 'stats'),
            'bytes' => (int) $this->attempt(fn() => $this->service()->totalSize(), 0, 'stats'),
        ];
    }

    private function mimeOf(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') return $mime;
            }
        }
        return 'application/octet-stream';
    }
}
