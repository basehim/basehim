<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Helpers;

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
    public function entityType(): string { return 'media'; }
    public function total(): int { return $this->source->countAttachments(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchAttachments($offset, $limit);
        if (!$rows) return 0;

        foreach ($rows as $row) {
            try {
                $this->importOne($row);
            } catch (\Throwable $e) {
                $this->log("attachment {$row['ID']} failed: " . $e->getMessage());
            }
        }
        return count($rows);
    }

    private function importOne(array $row): void
    {
        $oldId = (int)$row['ID'];
        $url   = trim((string)($row['attachment_url'] ?? $row['guid'] ?? ''));
        if ($url === '') return;

        // Idempotency check.
        if ($this->idMap->get('media', $oldId)) {
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
            'alt_text'      => $row['post_title'] ?? null,
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
        $this->idMap->put('media_url', $url, $newId);

        $this->state->bumpCount($this->jobId, 'media');
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
