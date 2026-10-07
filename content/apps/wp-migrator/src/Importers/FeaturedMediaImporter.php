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

    /** @var list<array{post_id:int,thumb_id:int}>|null */
    private ?array $matches = null;

    /** Posts that had a featured image — from the post references, not every post body. */
    private function matches(): array
    {
        if ($this->matches !== null) return $this->matches;
        $this->matches = [];
        foreach ($this->source->postRefs() as $p) {
            $thumb = (int) ($p['thumbnail_id'] ?? 0);
            if ($thumb > 0) $this->matches[] = ['post_id' => (int) $p['ID'], 'thumb_id' => $thumb];
        }
        return $this->matches;
    }

    public function total(): int
    {
        return count($this->matches());
    }

    public function runBatch(int $offset, int $limit): int
    {
        $slice = array_slice($this->matches(), $offset, $limit);
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
