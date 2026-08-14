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
 */
class MediaApi extends Resource
{
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

    /** Update metadata (title, alt_text, caption, description). */
    public function update(int $id, array $data): bool
    {
        $allowed = ['title', 'alt_text', 'caption', 'description'];
        $clean = array_intersect_key($data, array_flip($allowed));
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
     * Add a file already on disk to the media library.
     *
     * The core uploader expects a $_FILES-shaped array, which an app generating
     * a file itself (a rendered chart, a fetched remote image) does not have.
     * This builds that shape from a plain path.
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

        $file = [
            'name'     => $filename ?: basename($path),
            'type'     => $this->mimeOf($path),
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($path),
        ];

        $result = $this->attempt(
            fn() => $service->upload(
                $file,
                $authorId ?? 1,
                $service->allowedTypes((array) $config->get('cms.uploads.allowed_types', [])),
                $service->maxUploadBytes((int) $config->get('cms.uploads.max_size', 8388608)),
                $meta
            ),
            null,
            'uploadFromPath'
        );

        if (is_array($result) && !empty($result['id'])) {
            $this->log("Uploaded media #{$result['id']} ({$file['name']})");
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
