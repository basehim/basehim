<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Helpers;
use Basehim\WpMigrator\UrlMapper;

/**
 * MediaImporter
 *
 * Downloads media files from the WordPress site and saves them into the
 * Basehim storage/uploads tree, then writes media records that Basehim
 * can reference. The original WP attachment URL is recorded in
 * app_wpmig_idmap under 'media_url' so the content-rewriter step can
 * later replace inline references.
 *
 * Failures are logged and the record is skipped — a single bad image
 * should never abort the migration.
 */
class MediaImporter extends Importer
{
    /** Each download can take seconds; a small batch stays inside PHP's time limit. */
    protected int $batchSize = 10;

    public function entityType(): string { return 'media'; }
    public function total(): int { return $this->source->countAttachments(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchAttachments($offset, $limit);
        if (!$rows) return 0;

        $started = microtime(true);
        $done = 0;
        foreach ($rows as $row) {
            $done++;
            try {
                $this->importOne($row);
            } catch (\Throwable $e) {
                $this->log("attachment {$row['ID']} failed: " . $e->getMessage());
            }
            // Stop early rather than hit the PHP time limit; the next batch
            // starts where this one stopped.
            if (microtime(true) - $started > 20) break;
        }
        return $done;
    }

    private function importOne(array $row): void
    {
        $oldId = (int)$row['ID'];
        $meta  = $this->metaOf($row);
        $attached = ltrim((string) ($meta['_wp_attached_file'] ?? $row['attached_file'] ?? ''), '/');
        $url   = trim((string)($row['attachment_url'] ?? ''));
        if ($url === '' && $attached !== '') {
            $url = rtrim($this->source->siteUrl(), '/') . '/wp-content/uploads/' . $attached;
        }
        if ($url === '') $url = trim((string) ($row['guid'] ?? ''));
        if ($url === '') return;

        // Idempotency check. The paths are recorded again even so: a re-run
        // after upgrading from 1.2.0 fills in what that version left out.
        if ($existing = $this->idMap->get('media', $oldId)) {
            $this->recordPaths($existing, $url, $attached, $meta, $row);
            return;
        }

        // Download the file. Cap size at 25 MB.
        [$data, $mime] = $this->fetch($url, 25 * 1024 * 1024);
        if ($data === null) {
            $this->log("could not fetch {$url}");
            return;
        }

        // Build storage path: /YYYY/MM/{uuid}.{ext}
        $year = date('Y');
        $month = date('m');
        $relDir = "$year/$month";
        $uploadRoot = $this->uploadRoot();
        $absDir = $uploadRoot . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $this->log("could not create directory {$absDir}");
            return;
        }

        $ext = $this->extFromUrlOrMime($url, $mime) ?: 'bin';
        $uuid = Helpers::uuid();
        $safeName = $uuid . '.' . $ext;
        $absPath = $absDir . '/' . $safeName;
        $relPath = $relDir . '/' . $safeName;

        if (file_put_contents($absPath, $data) === false) {
            $this->log("could not write {$absPath}");
            return;
        }
        @chmod($absPath, 0644);

        // Image dimensions, if possible.
        $width = null; $height = null;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $info = @getimagesize($absPath);
            if ($info) { $width = $info[0]; $height = $info[1]; }
        }

        $authorId = (int) ($this->opt('default_author_id', 1));
        $title = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_FILENAME) ?: 'imported';
        $size = strlen($data);

        $newId = (int) $this->db->insert('media', [
            'uuid'          => $uuid,
            'author_id'     => $authorId,
            'title'         => $title,
            // WordPress keeps alt text in its own field; the title is not alt text.
            'alt_text'      => ($meta['_wp_attachment_image_alt'] ?? '') !== '' ? (string) $meta['_wp_attachment_image_alt'] : null,
            'caption'       => $row['post_excerpt'] ?? null,
            'description'   => $row['post_content'] ?? null,
            'mime_type'     => $mime,
            'file_name'     => $safeName,
            'original_name' => basename(parse_url($url, PHP_URL_PATH) ?: $safeName),
            'file_size'     => $size,
            'width'         => $width,
            'height'        => $height,
            'storage_disk'  => 'local',
            'storage_path'  => $relPath,
            'url'           => '/uploads/' . $relPath,
        ]);

        $this->idMap->put('media', $oldId, $newId);
        $this->recordPaths($newId, $url, $attached, $meta, $row);

        $this->state->bumpCount($this->jobId, 'media');
    }

    /**
     * Record every address this attachment can be found at in post content:
     * the uploaded file, the original (for -scaled images) and every resized
     * copy WordPress listed in _wp_attachment_metadata. Keys are hashed
     * uploads-relative paths — see UrlMapper::key().
     *
     * Up to 1.2.0 the full URL itself was the key, in a VARCHAR(64) column:
     * on a non-strict server it was cut at 64 characters, the rewrite step
     * then replaced that prefix inside longer URLs and left the rest behind.
     */
    private function recordPaths(int $newId, string $url, string $attached, array $meta, array $row): void
    {
        $rel = $attached !== '' ? $attached : (string) (UrlMapper::split((string) $this->relFromUrl($url))[0] ?? '');
        $fromUrl = $this->relFromUrl($url);
        $paths = array_filter([$rel, $fromUrl]);

        $info = $this->unserializeMeta((string) ($meta['_wp_attachment_metadata'] ?? $row['attachment_metadata'] ?? ''));
        $dir = $rel !== '' && str_contains($rel, '/') ? dirname($rel) . '/' : '';
        if (!empty($info['original_image'])) $paths[] = $dir . $info['original_image'];
        foreach ((array) ($info['sizes'] ?? []) as $size) {
            if (is_array($size) && !empty($size['file'])) $paths[] = $dir . $size['file'];
        }
        foreach (array_unique($paths) as $p) {
            $this->idMap->put('media_path', UrlMapper::key((string) $p), $newId);
        }

        // Every host the files were served from, for the rewrite step.
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') $this->idMap->put('media_host', strtolower(substr($host, 0, 64)), 0);

        // For [gallery] without ids: the images attached to a post, in order.
        $parent = (int) ($row['post_parent'] ?? 0);
        if ($parent > 0) {
            $this->idMap->put('media_parent', $parent . ':' . sprintf('%05d', (int) ($row['menu_order'] ?? 0)) . ':' . (int) $row['ID'], $newId);
        }
    }

    /** `https://site/wp-content/uploads/2020/12/a.jpg` => `2020/12/a.jpg` */
    private function relFromUrl(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $i = stripos($path, '/wp-content/uploads/');
        return $i === false ? null : rawurldecode(substr($path, $i + 20));
    }

    /** @return array<string,string> meta_key => meta_value */
    private function metaOf(array $row): array
    {
        $out = [];
        foreach ((array) ($row['postmeta'] ?? []) as $m) {
            if (isset($m['meta_key'])) $out[(string) $m['meta_key']] = (string) ($m['meta_value'] ?? '');
        }
        return $out;
    }

    /** WordPress stores attachment metadata serialized. No objects are allowed back. */
    private function unserializeMeta(string $raw): array
    {
        if ($raw === '' || !preg_match('/^a:\d+:\{/', $raw)) return [];
        $v = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($v) ? $v : [];
    }

    /**
     * Fetch a URL into memory. Returns [bytes, mime] or [null, ''] on failure.
     */
    private function fetch(string $url, int $maxBytes): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'Basehim-WpMigrator/1.0',
                CURLOPT_BUFFERSIZE     => 8192,
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                curl_close($ch);
                return [null, ''];
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($status >= 400 || $body === '' || strlen($body) > $maxBytes) return [null, ''];
            $mime = $mime ? strtok($mime, ';') : 'application/octet-stream';
            return [$body, $mime];
        }

        // Fallback: file_get_contents (requires allow_url_fopen).
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || strlen($body) > $maxBytes) return [null, ''];

        // Try to detect mime.
        $mime = 'application/octet-stream';
        if (function_exists('finfo_buffer')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = finfo_buffer($f, $body) ?: $mime;
                finfo_close($f);
            }
        }
        return [$body, $mime];
    }

    private function extFromUrlOrMime(string $url, string $mime): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if ($ext) return preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function uploadRoot(): string
    {
        return defined('BASEHIM_ROOT') ? BASEHIM_ROOT . '/storage/uploads' : 'storage/uploads';
    }
}
