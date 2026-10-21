<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Page-view counting without a lock on the post's row (1.2.76, CR-23).
 *
 * Settings → Reading → Page views (reading.view_counting):
 *   buffered (default)  a view adds 1 to a small counter row, one per post,
 *                       minute and SHARDS shard; the core task "post-views"
 *                       adds the finished minutes to posts.view_count;
 *   direct              the old way, an UPDATE of the post's row per view;
 *   off                 views are not counted.
 *
 * Buffered counts appear in view_count about a minute later. A view never
 * fails a page: if the counter table cannot be written, the view is counted
 * directly instead.
 */
final class PostViewCounter
{
    public const SHARDS = 16;
    /** At most this many posts' counts are folded per run. */
    private const FOLD_POSTS = 5000;

    private const TABLE_DDL = 'CREATE TABLE IF NOT EXISTS {post_views} (
        `post_id` BIGINT UNSIGNED NOT NULL,
        `bucket`  INT UNSIGNED NOT NULL,
        `shard`   TINYINT UNSIGNED NOT NULL,
        `hits`    INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`post_id`, `bucket`, `shard`),
        KEY `idx_bucket` (`bucket`)
    ) ENGINE=InnoDB DEFAULT CHARSET=ascii';

    public function __construct(private Database $db, private SettingService $settings) {}

    public function mode(): string
    {
        $m = (string) $this->settings->get('reading', 'view_counting', 'buffered');
        return in_array($m, ['buffered', 'direct', 'off'], true) ? $m : 'buffered';
    }

    public function record(int $postId, ?int $now = null): void
    {
        if ($postId <= 0) return;
        $mode = $this->mode();
        if ($mode === 'off') return;
        if ($mode === 'buffered') {
            $insert = fn() => $this->db->execute(
                'INSERT INTO {post_views} (post_id, bucket, shard, hits) VALUES (:p, :b, :s, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                ['p' => $postId, 'b' => intdiv($now ?? time(), 60), 's' => random_int(0, self::SHARDS - 1)]
            );
            try {
                $insert();
                return;
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1146 || $e->getCode() === '42S02') {
                    try { $this->db->execute(self::TABLE_DDL); $insert(); return; } catch (\Throwable) {}
                }
                // Fall through: count it directly.
            }
        }
        $this->db->execute(
            'UPDATE {posts} SET view_count = view_count + 1, updated_at = updated_at WHERE id = :id',
            ['id' => $postId]
        );
    }

    /**
     * Add every finished minute's views to posts.view_count. The current and
     * previous minute are left alone, since views may still be arriving for
     * them. Returns the number of views added.
     */
    public function fold(?int $now = null): int
    {
        $cut = intdiv($now ?? time(), 60) - 1;
        try {
            $rows = $this->db->select(
                'SELECT post_id, SUM(hits) AS n FROM {post_views} WHERE bucket < :cut
                  GROUP BY post_id ORDER BY post_id LIMIT ' . self::FOLD_POSTS,
                ['cut' => $cut]
            );
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1146 || $e->getCode() === '42S02') return 0;   // nothing buffered yet
            throw $e;
        }
        if (!$rows) return 0;
        $ids = array_map(fn($r) => (int) $r['post_id'], $rows);
        $max = max($ids);
        $added = 0;
        $this->db->transaction(function () use ($rows, $cut, $max, &$added): void {
            $added = 0;
            foreach ($rows as $r) {
                $this->db->execute(
                    'UPDATE {posts} SET view_count = view_count + :n, updated_at = updated_at WHERE id = :id',
                    ['n' => (int) $r['n'], 'id' => (int) $r['post_id']]
                );
                $added += (int) $r['n'];
            }
            // Exactly the rows summed above: finished minutes of these posts
            // (post_id ≤ the last one read, in the same order as the LIMIT).
            $this->db->execute('DELETE FROM {post_views} WHERE bucket < :cut AND post_id <= :max', ['cut' => $cut, 'max' => $max]);
        });
        return $added;
    }

    /** Views counted but not yet in view_count, for one post. */
    public function pending(int $postId): int
    {
        try {
            return (int) ($this->db->selectOne('SELECT SUM(hits) AS n FROM {post_views} WHERE post_id = :p', ['p' => $postId])['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
