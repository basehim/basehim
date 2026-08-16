<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

/**
 * CommentImporter
 *
 * Imports WordPress comments. Each comment is bound to a previously
 * imported post by way of the IdMap; comments orphaned to deleted/unmapped
 * posts are skipped. Parent-comment relationships are reconstructed after
 * all comments exist.
 *
 * Comment status mapping: '1' -> approved, '0' -> pending, 'spam' -> spam,
 * 'trash' -> trash.
 */
class CommentImporter extends Importer
{
    public function entityType(): string { return 'comments'; }
    public function total(): int { return $this->source->countComments(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchComments($offset, $limit);
        if (!$rows) return 0;

        foreach ($rows as $row) {
            try {
                $this->importOne($row);
            } catch (\Throwable $e) {
                $this->log('comment failed: ' . $e->getMessage());
            }
        }

        // After last batch, fix parent_id references.
        if ($offset + count($rows) >= $this->total()) {
            $this->rebuildParents();
        }

        return count($rows);
    }

    private function importOne(array $row): void
    {
        $oldId = (int)$row['comment_ID'];
        $oldPostId = (int)$row['comment_post_ID'];

        $newPostId = $this->idMap->get('post', $oldPostId);
        if (!$newPostId) return; // orphaned

        // Idempotency.
        if ($this->idMap->get('comment', $oldId)) return;

        $approved = (string)($row['comment_approved'] ?? '1');
        $status = match ($approved) {
            '1', 'approve', 'approved' => 'approved',
            'spam' => 'spam',
            'trash' => 'trash',
            default => 'pending',
        };

        $authorOldId = (int)($row['user_id'] ?? 0);
        $authorNewId = $authorOldId ? $this->idMap->get('user', $authorOldId) : null;

        try {
            $newId = (int) $this->db->insert('comments', [
                'post_id'      => $newPostId,
                'parent_id'    => null,   // fixed in second pass
                'author_id'    => $authorNewId,
                'author_name'  => (string)($row['comment_author'] ?? '') ?: 'Anonymous',
                'author_email' => (string)($row['comment_author_email'] ?? '') ?: null,
                'author_url'   => (string)($row['comment_author_url'] ?? '') ?: null,
                'author_ip'    => mb_substr((string)($row['comment_author_IP'] ?? ''), 0, 45),
                'content'      => (string)($row['comment_content'] ?? ''),
                'status'       => $status,
                'user_agent'   => mb_substr((string)($row['comment_agent'] ?? ''), 0, 500) ?: null,
                'created_at'   => $this->mapDate((string)($row['comment_date'] ?? '')) ?? date('Y-m-d H:i:s'),
            ]);
            $this->idMap->put('comment', $oldId, $newId);
            $this->state->bumpCount($this->jobId, 'comments');
        } catch (\Throwable $e) {
            $this->log("comment {$oldId} insert failed: " . $e->getMessage());
        }
    }

    private function rebuildParents(): void
    {
        // For every comment that has a non-zero comment_parent, look up the
        // new parent ID and set it.
        $sourceRows = $this->source->fetchComments(0, PHP_INT_MAX);
        foreach ($sourceRows as $row) {
            $parentOld = (int)($row['comment_parent'] ?? 0);
            if (!$parentOld) continue;
            $newId = $this->idMap->get('comment', (int)$row['comment_ID']);
            $newParent = $this->idMap->get('comment', $parentOld);
            if ($newId && $newParent) {
                try { $this->db->update('comments', ['parent_id' => $newParent], ['id' => $newId]); }
                catch (\Throwable) {}
            }
        }
    }

    private function mapDate(?string $wp): ?string
    {
        if (!$wp || $wp === '0000-00-00 00:00:00') return null;
        $ts = strtotime($wp);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
