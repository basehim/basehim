<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class PostRepository
{
    public function __construct(private Database $db) {}

    /** Decorate a row: normalize featured_url for the install location. */
    private function decorate(?array $row): ?array
    {
        if (!$row) return $row;
        if (!empty($row['featured_url']) && $row['featured_url'][0] === '/') {
            // Legacy: /storage/uploads/... → /uploads/...
            if (str_starts_with($row['featured_url'], '/storage/uploads/')) {
                $row['featured_url'] = '/uploads/' . substr($row['featured_url'], strlen('/storage/uploads/'));
            }
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            if ($base !== '' && !str_starts_with($row['featured_url'], $base . '/')) {
                $row['featured_url'] = $base . $row['featured_url'];
            }
        }
        return $row;
    }

    public function find(int $id): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE p.id = :id AND p.deleted_at IS NULL LIMIT 1';
        return $this->decorate($this->db->selectOne($sql, ['id' => $id]));
    }

    public function findByUuid(string $uuid): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE p.uuid = :uuid AND p.deleted_at IS NULL LIMIT 1';
        return $this->decorate($this->db->selectOne($sql, ['uuid' => $uuid]));
    }

    public function findBySlug(string $slug, ?string $type = null): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE p.slug = :slug AND p.deleted_at IS NULL';
        $params = ['slug' => $slug];
        if ($type !== null) {
            $sql .= ' AND p.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' LIMIT 1';
        return $this->decorate($this->db->selectOne($sql, $params));
    }

    public function slugExists(string $slug, string $type, ?int $excludeId = null): bool
    {
        // The unique index uq_type_slug covers ALL rows including soft-deleted
        // ones, so we must treat deleted rows as occupying the slug too —
        // otherwise resolveSlug() hands back a slug that the DB UPDATE will
        // still reject with a duplicate-key error.
        $sql = 'SELECT id FROM {posts} WHERE slug = :slug AND type = :type';
        $params = ['slug' => $slug, 'type' => $type];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        return $this->db->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $type = $filters['type'] ?? 'post';
        $status = $filters['status'] ?? null;
        $authorId = $filters['author_id'] ?? null;
        $search = $filters['search'] ?? null;

        $where = ['p.type = :type'];
        $params = ['type' => $type];

        // Trash view shows only soft-deleted rows; normal views exclude them.
        if (!empty($filters['trashed'])) {
            $where[] = 'p.deleted_at IS NOT NULL';
        } else {
            $where[] = 'p.deleted_at IS NULL';
        }

        if ($status !== null) { $where[] = 'p.status = :status'; $params['status'] = $status; }
        if ($authorId !== null) { $where[] = 'p.author_id = :author_id'; $params['author_id'] = $authorId; }
        if ($search !== null && $search !== '') {
            $where[] = '(p.title LIKE :search1 OR p.content LIKE :search2 OR p.excerpt LIKE :search3)';
            $like = '%' . $search . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
        }

        $whereSql = implode(' AND ', $where);

        // Whitelisted sort orders (never interpolate user input directly).
        $sortMap = [
            'newest'   => 'COALESCE(p.published_at, p.created_at) DESC',
            'oldest'   => 'COALESCE(p.published_at, p.created_at) ASC',
            'title_az' => 'p.title ASC',
            'title_za' => 'p.title DESC',
        ];
        $orderBy = $sortMap[$filters['sort'] ?? 'newest'] ?? $sortMap['newest'];

        $countRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM {posts} p WHERE {$whereSql}", $params);
        $total = (int)($countRow['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT p.*, u.display_name AS author_name, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$perPage} OFFSET {$offset}";

        $items = $this->db->select($sql, $params);
        $items = array_map(fn($r) => $this->decorate($r), $items);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage)),
            ],
        ];
    }

    public function publishedFeed(int $page = 1, int $perPage = 10, ?string $type = 'post'): array
    {
        return $this->paginate([
            'type' => $type,
            'status' => 'published',
        ], $page, $perPage);
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert('posts', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('posts', $data, ['id' => $id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('posts', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function restore(int $id): int
    {
        return $this->db->update('posts', ['deleted_at' => null], ['id' => $id]);
    }

    /** Find a row by id regardless of soft-delete state (for restore/purge). */
    public function findTrashedOrAny(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {posts} WHERE id = :id LIMIT 1', ['id' => $id]) ?: null;
    }

    public function forceDelete(int $id): int
    {
        return $this->db->delete('posts', ['id' => $id]);
    }

    /** Count of items currently in the trash for a type. */
    public function trashedCount(string $type = 'post'): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM {posts} WHERE type = :type AND deleted_at IS NOT NULL',
            ['type' => $type]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** All trashed rows of a type (for pre-purge hook fan-out). */
    public function trashedRows(string $type = 'post'): array
    {
        return $this->db->select(
            'SELECT * FROM {posts} WHERE type = :type AND deleted_at IS NOT NULL',
            ['type' => $type]
        );
    }

    /** Permanently delete every trashed item of a type. Returns rows removed. */
    public function emptyTrash(string $type = 'post'): int
    {
        return $this->db->execute(
            'DELETE FROM {posts} WHERE type = :type AND deleted_at IS NOT NULL',
            ['type' => $type]
        );
    }

    public function incrementViewCount(int $id): void
    {
        $this->db->execute('UPDATE {posts} SET view_count = view_count + 1 WHERE id = :id', ['id' => $id]);
    }

    public function counts(): array
    {
        $rows = $this->db->select(
            "SELECT type, status, COUNT(*) AS c
             FROM {posts} WHERE deleted_at IS NULL
             GROUP BY type, status"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['type']][$r['status']] = (int)$r['c'];
            $out[$r['type']]['total'] = ($out[$r['type']]['total'] ?? 0) + (int)$r['c'];
        }
        return $out;
    }

    public function recent(int $limit = 5, string $type = 'post'): array
    {
        $limit = (int)$limit;
        $items = $this->db->select(
            "SELECT p.*, u.display_name AS author_name,
                    m.url AS featured_url, m.alt_text AS featured_alt
             FROM {posts} p
             LEFT JOIN {users} u ON u.id = p.author_id
             LEFT JOIN {media} m ON m.id = p.featured_media_id
             WHERE p.type = :type AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC
             LIMIT {$limit}",
            ['type' => $type]
        );
        return array_map(fn($r) => $this->decorate($r), $items);
    }

    public function attachTerms(int $postId, array $termIds): void
    {
        // Find which term IDs are currently attached so we can accurately
        // adjust the per-term post counts (decrement removed, increment added).
        $existing = $this->db->select(
            'SELECT term_id FROM {post_term} WHERE post_id = :pid',
            ['pid' => $postId]
        );
        $existingIds = array_column($existing, 'term_id');
        $newIds      = array_map('intval', $termIds);

        $toRemove = array_diff($existingIds, $newIds);
        $toAdd    = array_diff($newIds, $existingIds);

        // Remove detached terms.
        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $this->db->execute(
                "DELETE FROM {post_term} WHERE post_id = ? AND term_id IN ({$placeholders})",
                array_merge([$postId], array_values($toRemove))
            );
            foreach ($toRemove as $tid) {
                $this->db->execute(
                    'UPDATE {terms} SET count = GREATEST(0, count - 1) WHERE id = :id',
                    ['id' => $tid]
                );
            }
        }

        // Add newly attached terms.
        $order = count($newIds) > 0 ? (int)$this->db->selectOne(
            'SELECT COALESCE(MAX(term_order), -1) + 1 AS next FROM {post_term} WHERE post_id = :pid',
            ['pid' => $postId]
        )['next'] : 0;

        foreach ($toAdd as $tid) {
            $this->db->insert('post_term', [
                'post_id'    => $postId,
                'term_id'    => $tid,
                'term_order' => $order++,
            ]);
            $this->db->execute(
                'UPDATE {terms} SET count = count + 1 WHERE id = :id',
                ['id' => $tid]
            );
        }
    }

    public function getTerms(int $postId): array
    {
        return $this->db->select(
            'SELECT t.*, tax.slug AS taxonomy_slug, tax.label AS taxonomy_label
             FROM {post_term} pt
             JOIN {terms} t ON t.id = pt.term_id
             JOIN {taxonomies} tax ON tax.id = t.taxonomy_id
             WHERE pt.post_id = :pid
             ORDER BY pt.term_order',
            ['pid' => $postId]
        );
    }

    public function byTermId(int $termId, int $page = 1, int $perPage = 10): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM {post_term} pt
             JOIN {posts} p ON p.id = pt.post_id
             WHERE pt.term_id = :tid AND p.status = 'published' AND p.deleted_at IS NULL",
            ['tid' => $termId]
        );
        $total = (int)($countRow['c'] ?? 0);

        $items = $this->db->select(
            "SELECT p.*, u.display_name AS author_name,
                    m.url AS featured_url, m.alt_text AS featured_alt
             FROM {post_term} pt
             JOIN {posts} p ON p.id = pt.post_id
             LEFT JOIN {users} u ON u.id = p.author_id
             LEFT JOIN {media} m ON m.id = p.featured_media_id
             WHERE pt.term_id = :tid AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            ['tid' => $termId]
        );
        $items = array_map(fn($r) => $this->decorate($r), $items);

        return [
            'data' => $items,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage))],
        ];
    }

    public function search(string $term, int $limit = 20): array
    {
        $like = '%' . $term . '%';
        return $this->db->select(
            "SELECT id, uuid, type, title, slug, excerpt, published_at
             FROM {posts}
             WHERE status = 'published' AND deleted_at IS NULL
               AND (title LIKE :q1 OR content LIKE :q2 OR excerpt LIKE :q3)
             ORDER BY published_at DESC
             LIMIT " . (int)$limit,
            ['q1' => $like, 'q2' => $like, 'q3' => $like]
        );
    }
}
