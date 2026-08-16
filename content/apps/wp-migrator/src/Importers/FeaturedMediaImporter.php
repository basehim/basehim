<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

/**
 * FeaturedMediaImporter
 *
 * Walks every imported post's source `_thumbnail_id` meta and links the
 * matching imported media as the post's featured_media_id. Runs after
 * both posts and media have been imported.
 */
class FeaturedMediaImporter extends Importer
{
    public function entityType(): string { return 'featured_media'; }

    public function total(): int
    {
        // Total is the number of posts that have a _thumbnail_id meta.
        $all = $this->source->fetchPosts(0, PHP_INT_MAX);
        $n = 0;
        foreach ($all as $p) {
            foreach (($p['postmeta'] ?? []) as $m) {
                if ($m['meta_key'] === '_thumbnail_id') { $n++; break; }
            }
        }
        return $n;
    }

    public function runBatch(int $offset, int $limit): int
    {
        $all = $this->source->fetchPosts(0, PHP_INT_MAX);
        $matches = [];
        foreach ($all as $p) {
            foreach (($p['postmeta'] ?? []) as $m) {
                if ($m['meta_key'] === '_thumbnail_id' && $m['meta_value'] !== '') {
                    $matches[] = ['post_id' => (int)$p['ID'], 'thumb_id' => (int)$m['meta_value']];
                    break;
                }
            }
        }
        $slice = array_slice($matches, $offset, $limit);
        if (!$slice) return 0;

        foreach ($slice as $m) {
            $newPostId  = $this->idMap->get('post', $m['post_id']);
            $newMediaId = $this->idMap->get('media', $m['thumb_id']);
            if (!$newPostId || !$newMediaId) continue;

            try {
                $this->db->update('posts',
                    ['featured_media_id' => $newMediaId],
                    ['id' => $newPostId]
                );
                $this->state->bumpCount($this->jobId, 'featured_media');
            } catch (\Throwable $e) {
                $this->log("featured set failed for post {$newPostId}: " . $e->getMessage());
            }
        }
        return count($slice);
    }
}
