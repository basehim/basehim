<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Helpers;
use Basehim\WpMigrator\Downloader;
use Basehim\WpMigrator\MediaFilter;
use Basehim\WpMigrator\UrlMapper;

/**
 * MediaImporter
 *
 * Downloads media files from the WordPress site and saves them into the
 * Basehim storage/uploads tree, then writes media records that Basehim
 * can reference. Every address an attachment can appear at in content is
 * recorded in app_wpmig_idmap (entity 'media_path') so the rewrite step can
 * later replace inline references.
 *
 * 1.4.0:
 *   - Media filter (MediaFilter): import only chosen types (images, video,
 *     audio, documents, archives, other), skip resized copies, prefer the
 *     original upload over WordPress's "-scaled" copy, exclude by file-name
 *     pattern, and a configurable size cap.
 *   - Downloads go through Downloader: http/https only, no private
 *     addresses unless allowed, streamed to disk with the cap enforced
 *     while downloading (1.3.1 read file:// URLs and local paths, and held
 *     whole files in memory).
 *   - The stored extension comes from an allow-list and the stored MIME type
 *     from the file's content, not from the remote server's header. An
 *     "image" that is really an HTML error page is rejected.
 *   - Files keep their WordPress year/month folder.
 *   - One summary line per batch in the log.
 *
 * Failures are logged and the record is skipped — a single bad file
 * should never abort the migration.
 */
class MediaImporter extends Importer
{
    /** Each download can take seconds; a small batch stays inside PHP's time limit. */
    protected int $batchSize = 10;

    private ?MediaFilter $filter = null;
    private ?Downloader $downloader = null;

    public function entityType(): string { return 'media'; }
    public function total(): int { return $this->source->countAttachments(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchAttachments($offset, $limit);
        if (!$rows) return 0;

        $started = microtime(true);
        $done = 0;
        $tally = ['imported' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $done++;
            try {
                $tally[$this->importOne($row)]++;
            } catch (\Throwable $e) {
                $tally['failed']++;
                $this->warn("attachment {$row['ID']} failed: " . $e->getMessage());
            }
            // Stop early rather than hit the PHP time limit; the next batch
            // starts where this one stopped.
            if (microtime(true) - $started > 20) break;
        }

        if ($tally['skipped']) $this->state->bumpCount($this->jobId, 'media_skipped', $tally['skipped']);
        if ($tally['failed'])  $this->state->bumpCount($this->jobId, 'media_failed', $tally['failed']);
        $this->log(sprintf('records %d–%d: %d imported, %d already imported, %d skipped by filter, %d failed',
            $offset + 1, $offset + $done, $tally['imported'], $tally['existing'], $tally['skipped'], $tally['failed']));
        return $done;
    }

    private function filter(): MediaFilter
    {
        return $this->filter ??= MediaFilter::fromOptions((array) $this->opt('media', []));
    }

    private function downloader(): Downloader
    {
        return $this->downloader ??= new Downloader($this->filter()->allowPrivate);
    }

    /** @return 'imported'|'existing'|'skipped'|'failed' */
    private function importOne(array $row): string
    {
        $oldId = (int)$row['ID'];
        $meta  = $this->metaOf($row);
        $attached = ltrim((string) ($meta['_wp_attached_file'] ?? $row['attached_file'] ?? ''), '/');
        $url   = trim((string)($row['attachment_url'] ?? ''));
        if ($url === '' && $attached !== '') {
            $url = rtrim($this->source->siteUrl(), '/') . '/wp-content/uploads/' . $attached;
        }
        if ($url === '') $url = trim((string) ($row['guid'] ?? ''));
        if ($url === '') {
            $this->warn("attachment {$oldId} has no file URL");
            return 'failed';
        }

        // Idempotency check. The paths are recorded again even so: a re-run
        // after upgrading from 1.2.0 fills in what that version left out.
        if ($existing = $this->idMap->get('media', $oldId)) {
            $this->recordPaths($existing, $url, $attached, $meta, $row);
            return 'existing';
        }

        // The filter: type, resized copies, name patterns.
        $fileName = basename((string) (parse_url($url, PHP_URL_PATH) ?: $attached));
        $reason = $this->filter()->rejects($fileName, (string) ($row['post_mime_type'] ?? ''));
        if ($reason !== null) {
            $this->log("skipped {$fileName}: {$reason}");
            return 'skipped';
        }

        // "-scaled" is a copy WordPress made of a big upload; the original
        // name is in the metadata. Prefer it when asked to.
        $info = $this->unserializeMeta((string) ($meta['_wp_attachment_metadata'] ?? $row['attachment_metadata'] ?? ''));
        $downloadUrl = $url;
        if ($this->filter()->originalsOnly && !empty($info['original_image']) && is_string($info['original_image'])) {
            $orig = basename($info['original_image']);
            $downloadUrl = preg_replace('#[^/]+$#', rawurlencode($orig), $url) ?: $url;
        }

        $cacheDir = $this->cacheDir();
        $dl = $this->downloader()->toTempFile($downloadUrl, $cacheDir, $this->filter()->maxBytes);
        if ($dl === null && $downloadUrl !== $url) {
            $this->log("original {$downloadUrl} unavailable ({$this->downloader()->lastError}); using {$url}");
            $downloadUrl = $url;
            $dl = $this->downloader()->toTempFile($url, $cacheDir, $this->filter()->maxBytes);
        }
        if ($dl === null) {
            $this->warn("could not fetch {$downloadUrl}: {$this->downloader()->lastError}");
            return 'failed';
        }

        try {
            // What the file really is, not what the server said it was.
            $mime = $this->sniffMime($dl['path']) ?? $dl['mime'];
            $ext = $this->filter()->safeExtension($downloadUrl, $mime);
            if ($ext === null) {
                $this->warn("refused {$fileName}: file type not allowed ({$mime})");
                return 'skipped';
            }
            // A host that answers a missing image with a 200 HTML page.
            if ($this->filter()->category($ext, $mime) === 'image'
                && (str_starts_with($mime, 'text/') || str_contains($mime, 'html') || str_contains($mime, 'json'))) {
                $this->warn("refused {$fileName}: expected an image, got {$mime} (an error page?)");
                return 'failed';
            }
            // Re-check the type filter against what was actually downloaded.
            $reason = $this->filter()->rejects($fileName, $mime);
            if ($reason !== null) {
                $this->log("skipped {$fileName}: {$reason}");
                return 'skipped';
            }

            // storage/uploads/YYYY/MM/{uuid}.{ext}; WordPress's own folder when known.
            $relDir = preg_match('#^(\d{4})/(\d{2})/#', $attached, $ym) ? "{$ym[1]}/{$ym[2]}" : date('Y') . '/' . date('m');
            $absDir = $this->uploadRoot() . '/' . $relDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                $this->warn("could not create directory {$absDir}");
                return 'failed';
            }

            $uuid = Helpers::uuid();
            $safeName = $uuid . '.' . $ext;
            $absPath = $absDir . '/' . $safeName;
            $relPath = $relDir . '/' . $safeName;

            if (!@rename($dl['path'], $absPath) && !(@copy($dl['path'], $absPath))) {
                $this->warn("could not write {$absPath}");
                return 'failed';
            }
            @chmod($absPath, 0644);

            $width = null; $height = null;
            if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
                $img = @getimagesize($absPath);
                if ($img) { $width = $img[0]; $height = $img[1]; }
            }

            $authorId = (int) ($this->opt('default_author_id', 1));
            $title = trim((string) ($row['post_title'] ?? ''))
                ?: (pathinfo($fileName, PATHINFO_FILENAME) ?: 'imported');

            $newId = (int) $this->db->insert('media', [
                'uuid'          => $uuid,
                'author_id'     => $authorId,
                'title'         => mb_substr($title, 0, 255),
                // WordPress keeps alt text in its own field; the title is not alt text.
                'alt_text'      => ($meta['_wp_attachment_image_alt'] ?? '') !== '' ? (string) $meta['_wp_attachment_image_alt'] : null,
                'caption'       => $row['post_excerpt'] ?? null,
                'description'   => $row['post_content'] ?? null,
                'mime_type'     => $mime,
                'file_name'     => $safeName,
                'original_name' => mb_substr(basename((string) (parse_url($downloadUrl, PHP_URL_PATH) ?: $safeName)), 0, 255),
                'file_size'     => $dl['size'],
                'width'         => $width,
                'height'        => $height,
                'storage_disk'  => 'local',
                'storage_path'  => $relPath,
                'url'           => '/uploads/' . $relPath,
            ]);

            $this->idMap->put('media', $oldId, $newId);
            $this->recordPaths($newId, $url, $attached, $meta, $row);
            $this->state->bumpCount($this->jobId, 'media');
            return 'imported';
        } finally {
            if (is_file($dl['path'])) @unlink($dl['path']);
        }
    }

    /**
     * Record every address this attachment can be found at in post content:
     * the uploaded file, the original (for -scaled images) and every resized
     * copy WordPress listed in _wp_attachment_metadata. Keys are hashed
     * uploads-relative paths — see UrlMapper::key().
     */
    private function recordPaths(int $newId, string $url, string $attached, array $meta, array $row): void
    {
        $rel = $attached !== '' ? $attached : (string) (UrlMapper::split((string) $this->relFromUrl($url))[0] ?? '');
        $fromUrl = $this->relFromUrl($url);
        $paths = array_filter([$rel, $fromUrl]);

        $info = $this->unserializeMeta((string) ($meta['_wp_attachment_metadata'] ?? $row['attachment_metadata'] ?? ''));
        $dir = $rel !== '' && str_contains($rel, '/') ? dirname($rel) . '/' : '';
        if (!empty($info['original_image']) && is_string($info['original_image'])) $paths[] = $dir . basename($info['original_image']);
        foreach ((array) ($info['sizes'] ?? []) as $size) {
            if (is_array($size) && !empty($size['file']) && is_string($size['file'])) $paths[] = $dir . basename($size['file']);
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

    private function sniffMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) return null;
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if (!$f) return null;
        $m = finfo_file($f, $path);
        finfo_close($f);
        return is_string($m) && $m !== '' ? strtolower($m) : null;
    }

    private function uploadRoot(): string
    {
        return UrlMapper::uploadRoot();
    }

    private function cacheDir(): string
    {
        $dir = (defined('BASEHIM_ROOT') ? BASEHIM_ROOT : dirname(__DIR__, 5)) . '/storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
    }
}
