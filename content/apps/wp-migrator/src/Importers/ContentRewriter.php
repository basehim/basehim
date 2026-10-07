<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use Basehim\WpMigrator\ContentFixer;
use Basehim\WpMigrator\UrlMapper;

/**
 * ContentRewriter
 *
 * The migration's last step: every imported post's body is passed through
 * ContentFixer, so images, galleries, captions and internal links point at
 * the new site. See ContentFixer and UrlMapper for what is rewritten and why.
 */
class ContentRewriter extends Importer
{
    protected int $batchSize = 20;

    private ?ContentFixer $fixer = null;
    private ?UrlMapper $mapper = null;

    public function entityType(): string { return 'rewrite_content'; }

    public function total(): int { return $this->idMap->count('post'); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->db->select(
            'SELECT old_id, new_id FROM app_wpmig_idmap
             WHERE entity_type = :t ORDER BY id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            ['t' => 'post']
        );
        if (!$rows) return 0;

        $fixer = $this->fixer();
        $started = microtime(true);
        $done = 0;

        foreach ($rows as $r) {
            $newId = (int) $r['new_id'];
            $post = $this->db->selectOne('SELECT id, content FROM posts WHERE id = :id', ['id' => $newId]);
            $done++;
            if (!$post || (string) $post['content'] === '') continue;

            $content = (string) $post['content'];
            $fixed = $fixer->fix($content, (int) $r['old_id']);
            if ($fixed !== $content) {
                try {
                    $this->db->update('posts', ['content' => $fixed], ['id' => $newId]);
                    $this->state->bumpCount($this->jobId, 'rewrite_content');
                } catch (\Throwable $e) {
                    $this->log("rewrite failed for post {$newId}: " . $e->getMessage());
                }
            }
            // Fetching resized copies can be slow: stop early and let the
            // next batch carry on, rather than hit the PHP time limit.
            if (microtime(true) - $started > 20) break;
        }

        if ($offset + $done >= $this->total()) {
            $s = $fixer->stats;
            $n = static fn(int $c, string $one, string $many) => $c . ' ' . ($c === 1 ? $one : $many);
            $this->log('rewrote ' . $n($s['urls'], 'image URL', 'image URLs') . ', ' . $n($s['galleries'], 'gallery', 'galleries') . ', '
                . $n($s['captions'], 'caption', 'captions') . ', ' . $n($s['links'], 'link', 'links')
                . ($this->mapper->fetched ? "; fetched {$this->mapper->fetched} resized copies" : ''));
        }
        if ($fixer->stats['unmapped'] && $fixer->unmappedSamples) {
            $this->log('left ' . $fixer->stats['unmapped'] . ' upload URLs unchanged (no imported file), e.g. ' . $fixer->unmappedSamples[0]);
            $fixer->stats['unmapped'] = 0;
            $fixer->unmappedSamples = [];
        }
        return $done;
    }

    private function fixer(): ContentFixer
    {
        if ($this->fixer) return $this->fixer;
        $siteUrl = rtrim($this->source->siteUrl(), '/');
        $hosts = UrlMapper::hostsFrom($siteUrl);
        // Attachments are often recorded on another host (http vs https, an
        // old domain, a CDN): every host seen on an imported file counts.
        foreach ($this->db->select('SELECT old_id FROM app_wpmig_idmap WHERE entity_type = :t', ['t' => 'media_host']) as $r) {
            $hosts[] = strtolower((string) $r['old_id']);
        }
        $this->mapper = new UrlMapper($this->db, $this->idMap, array_values(array_unique($hosts)), $siteUrl, true, false);
        return $this->fixer = new ContentFixer($this->db, $this->idMap, $this->mapper);
    }
}
