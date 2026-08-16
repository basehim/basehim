<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

/**
 * ContentRewriter
 *
 * Walks each imported post's content and rewrites:
 *
 *   - <img src="https://old-site.com/wp-content/uploads/..."> -> Basehim /uploads/...
 *   - Internal links to other imported posts -> new Basehim URLs
 *
 * Runs as the last step of the migration after all media and posts exist.
 * If we miss something here, the redirect dispatcher still catches it at
 * runtime, but rewriting in place is preferred for clean content.
 */
class ContentRewriter extends Importer
{
    public function entityType(): string { return 'rewrite_content'; }

    public function total(): int { return $this->idMap->count('post'); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->db->select(
            'SELECT new_id FROM app_wpmig_idmap
             WHERE entity_type = :t ORDER BY id LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            ['t' => 'post']
        );
        if (!$rows) return 0;

        $siteUrl = rtrim($this->source->siteUrl(), '/');
        // Bulk-load media url -> new id mapping.
        $this->idMap->loadAll('media_url');
        $mediaUrlRows = $this->db->select(
            'SELECT old_id, new_id FROM app_wpmig_idmap WHERE entity_type = :t',
            ['t' => 'media_url']
        );
        // Build URL -> /uploads/... lookup by joining media table.
        $newMediaUrls = [];
        if ($mediaUrlRows) {
            $ids = array_unique(array_column($mediaUrlRows, 'new_id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $media = $this->db->select(
                "SELECT id, url FROM media WHERE id IN ({$placeholders})",
                $ids
            );
            $idToUrl = [];
            foreach ($media as $m) { $idToUrl[(int)$m['id']] = $m['url']; }
            foreach ($mediaUrlRows as $r) {
                $newId = (int)$r['new_id'];
                if (isset($idToUrl[$newId])) {
                    $newMediaUrls[(string)$r['old_id']] = $idToUrl[$newId];
                }
            }
        }

        foreach ($rows as $r) {
            $newId = (int)$r['new_id'];
            $post = $this->db->selectOne('SELECT id, content FROM posts WHERE id = :id', ['id' => $newId]);
            if (!$post || $post['content'] === '' || $post['content'] === null) continue;

            $content = (string)$post['content'];
            $original = $content;

            // 1. Replace WP media URLs with new ones.
            foreach ($newMediaUrls as $oldUrl => $newUrl) {
                if ($oldUrl !== '' && str_contains($content, $oldUrl)) {
                    $content = str_replace($oldUrl, $newUrl, $content);
                }
            }

            // 2. Replace bare siteUrl prefix in any remaining absolute links
            // with a relative path (best-effort — only safe if the new site
            // mirrors the old structure, which redirects then handle).
            if ($siteUrl !== '') {
                $content = preg_replace_callback(
                    '#(href|src)=(["\'])' . preg_quote($siteUrl, '#') . '([^"\']*)(["\'])#i',
                    function ($m) {
                        $path = $m[3] !== '' ? $m[3] : '/';
                        return $m[1] . '=' . $m[2] . $path . $m[4];
                    },
                    $content
                ) ?? $content;
            }

            if ($content !== $original) {
                try {
                    $this->db->update('posts', ['content' => $content], ['id' => $newId]);
                    $this->state->bumpCount($this->jobId, 'rewrite_content');
                } catch (\Throwable $e) {
                    $this->log("rewrite failed for post {$newId}: " . $e->getMessage());
                }
            }
        }

        return count($rows);
    }
}
