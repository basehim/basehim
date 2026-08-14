<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class MediaRepository
{
    public function __construct(private Database $db) {}

    /** Decorate a media row by making `url` install-base-aware. */
    private function decorate(?array $row): ?array
    {
        if (!$row) return $row;
        if (!empty($row['url']) && $row['url'][0] === '/') {
            // Normalize legacy URLs: convert "/storage/uploads/..." stored before
            // the PHP-served route was added to "/uploads/..." so old records
            // automatically use the new route.
            if (str_starts_with($row['url'], '/storage/uploads/')) {
                $row['url'] = '/uploads/' . substr($row['url'], strlen('/storage/uploads/'));
            }
            // Prepend BASEHIM_BASE for subdirectory installs.
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            if ($base !== '' && !str_starts_with($row['url'], $base . '/')) {
                $row['url'] = $base . $row['url'];
            }
        }
        return $row;
    }

    public function find(int $id): ?array
    {
        return $this->decorate($this->db->selectOne('SELECT * FROM {media} WHERE id = :id', ['id' => $id]));
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert('media', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('media', $data, ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('media', ['id' => $id]);
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $t = $filters['type'];
            if ($t === 'svg') {
                $where[] = "mime_type = 'image/svg+xml'";
            } elseif ($t === 'image') {
                // Images but not SVG (SVG has its own pill).
                $where[] = "mime_type LIKE 'image/%' AND mime_type <> 'image/svg+xml'";
            } elseif ($t === 'video') {
                $where[] = "mime_type LIKE 'video/%'";
            } elseif ($t === 'audio') {
                $where[] = "mime_type LIKE 'audio/%'";
            } elseif ($t === 'document') {
                $where[] = "mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'audio/%'";
            } else {
                // Fallback: treat as a raw mime prefix (back-compat).
                $where[] = 'mime_type LIKE :type';
                $params['type'] = $t . '%';
            }
        }
        if (!empty($filters['author_id'])) {
            $where[] = 'author_id = :author';
            $params['author'] = $filters['author_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :search1 OR original_name LIKE :search2 OR alt_text LIKE :search3)';
            $like = '%' . $filters['search'] . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM {media} WHERE {$whereSql}", $params);
        $total = (int)($countRow['c'] ?? 0);

        // Whitelisted sort — never interpolate raw user input into SQL.
        // Note: SQL string literals use single quotes so this works whether or
        // not the server runs in ANSI_QUOTES mode.
        $sortMap = [
            'newest' => 'created_at DESC',
            'oldest' => 'created_at ASC',
            'name'   => "COALESCE(NULLIF(title, ''), original_name) ASC",
            'name_desc' => "COALESCE(NULLIF(title, ''), original_name) DESC",
            'largest' => 'file_size DESC',
            'smallest' => 'file_size ASC',
        ];
        $orderBy = $sortMap[$filters['sort'] ?? 'newest'] ?? $sortMap['newest'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT * FROM {media} WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $rows = array_map(fn($r) => $this->decorate($r), $rows);

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage))],
        ];
    }

    public function totalCount(): int
    {
        $r = $this->db->selectOne('SELECT COUNT(*) AS c FROM {media}');
        return (int)($r['c'] ?? 0);
    }

    /**
     * Counts per broad media category, for the filter pills. SVG is split out
     * of images since it's often handled differently in a CMS.
     */
    public function typeCounts(?string $search = null): array
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== null && $search !== '') {
            $where[] = '(title LIKE :s1 OR original_name LIKE :s2 OR alt_text LIKE :s3)';
            $like = '%' . $search . '%';
            $params['s1'] = $like; $params['s2'] = $like; $params['s3'] = $like;
        }
        $whereSql = implode(' AND ', $where);
        // Aggregate in SQL rather than pulling every row into PHP — scales to
        // large libraries.
        $rows = $this->db->select("SELECT mime_type, COUNT(*) AS c FROM {media} WHERE {$whereSql} GROUP BY mime_type", $params);
        $counts = ['all' => 0, 'image' => 0, 'svg' => 0, 'video' => 0, 'audio' => 0, 'document' => 0];
        foreach ($rows as $r) {
            $mime = (string)($r['mime_type'] ?? '');
            $n = (int)($r['c'] ?? 0);
            $counts['all'] += $n;
            if ($mime === 'image/svg+xml') $counts['svg'] += $n;
            elseif (str_starts_with($mime, 'image/')) $counts['image'] += $n;
            elseif (str_starts_with($mime, 'video/')) $counts['video'] += $n;
            elseif (str_starts_with($mime, 'audio/')) $counts['audio'] += $n;
            else $counts['document'] += $n;
        }
        return $counts;
    }

    public function totalSize(): int
    {
        $r = $this->db->selectOne('SELECT COALESCE(SUM(file_size), 0) AS s FROM {media}');
        return (int)($r['s'] ?? 0);
    }
}
