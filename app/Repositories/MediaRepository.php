<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class MediaRepository
{
    public function __construct(private Database $db) {}

    /** BH Search (1.2.92), or null where it is not available. */
    private function searchIndex(): ?\App\Core\Search\SearchManager
    {
        try {
            $app = \App\Core\Application::getInstance();
            return $app->has(\App\Core\Search\SearchManager::class) ? $app->make(\App\Core\Search\SearchManager::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

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
        $id = (int)$this->db->insert('media', $data);
        $this->searchIndex()?->touch('media', $id);
        return $id;
    }

    public function update(int $id, array $data): int
    {
        $n = $this->db->update('media', $data, ['id' => $id]);
        $this->searchIndex()?->touch('media', $id);
        return $n;
    }

    public function delete(int $id): int
    {
        $n = $this->db->delete('media', ['id' => $id]);
        $this->searchIndex()?->remove('media', $id);
        return $n;
    }

    /** The type pills / picker groups, each a fixed SQL condition (never user text). */
    public const TYPE_GROUPS = [
        'image'    => "(mime_type LIKE 'image/%' AND mime_type <> 'image/svg+xml')",
        'svg'      => "mime_type = 'image/svg+xml'",
        'video'    => "mime_type LIKE 'video/%'",
        'audio'    => "mime_type LIKE 'audio/%'",
        'document' => "(mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'audio/%')",
    ];

    /** Sort tokens and their ORDER BY. id breaks ties, so pages never repeat or skip a row. */
    public const SORTS = [
        'newest'    => 'created_at DESC, id DESC',
        'oldest'    => 'created_at ASC, id ASC',
        'name'      => "COALESCE(NULLIF(title, ''), original_name) ASC, id ASC",
        'name_desc' => "COALESCE(NULLIF(title, ''), original_name) DESC, id DESC",
        'largest'   => 'file_size DESC, id DESC',
        'smallest'  => 'file_size ASC, id ASC',
        // The same orders under the names the Data Table's sort arrows read
        // (…_az / …_asc ascending), for the media.library table.
        'title_az'  => "COALESCE(NULLIF(title, ''), original_name) ASC, id ASC",
        'title_za'  => "COALESCE(NULLIF(title, ''), original_name) DESC, id DESC",
        'size_desc' => 'file_size DESC, id DESC',
        'size_asc'  => 'file_size ASC, id ASC',
    ];

    /**
     * WHERE clause for the list filters.
     *
     *   type    one of TYPE_GROUPS (a raw MIME prefix still works, as before,
     *           for REST callers; it is bound, never interpolated)
     *   types   a list of TYPE_GROUPS keys, OR-ed (the picker: image + svg)
     *   search  matched literally: % and _ are escaped
     *   from/to UTC 'Y-m-d H:i:s' bounds on created_at (to is exclusive)
     *   author_id
     *
     * @return array{0:string,1:array}
     */
    private function where(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($filters['types']) && is_array($filters['types'])) {
            $or = [];
            foreach ($filters['types'] as $t) {
                if (isset(self::TYPE_GROUPS[$t])) $or[$t] = self::TYPE_GROUPS[$t];
            }
            // A list with nothing known in it matches nothing rather than everything.
            $where[] = $or ? '(' . implode(' OR ', $or) . ')' : '1=0';
        } elseif (!empty($filters['type'])) {
            $t = (string) $filters['type'];
            if (isset(self::TYPE_GROUPS[$t])) {
                $where[] = self::TYPE_GROUPS[$t];
            } else {
                // Back-compat: a raw mime prefix.
                $where[] = 'mime_type LIKE :type';
                $params['type'] = addcslashes($t, '\\%_') . '%';
            }
        }
        if (!empty($filters['author_id'])) {
            $where[] = 'author_id = :author';
            $params['author'] = (int) $filters['author_id'];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            // BH Search (1.2.92): every word, in any order, in the title, file
            // names, alt text, caption or description; through the search
            // index once it is built, the table's own columns until then.
            $index = $this->searchIndex();
            $where[] = $index !== null
                ? $index->constrain('media', (string) $filters['search'], '', $params)
                : \App\Core\Search\LikeMatcher::condition(\App\Core\Search\SearchQuery::parse((string) $filters['search']),
                    ['title', 'original_name', 'file_name', 'alt_text', 'caption', 'description'], $params);
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at < :to';
            $params['to'] = (string) $filters['to'];
        }
        return [implode(' AND ', $where), $params];
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        [$whereSql, $params] = $this->where($filters);

        $countRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM {media} WHERE {$whereSql}", $params);
        $total = (int)($countRow['c'] ?? 0);

        // Allowlisted sort: never interpolate raw user input into SQL. String
        // literals use single quotes so ANSI_QUOTES servers are fine too.
        $orderBy = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT * FROM {media} WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $rows = array_map(fn($r) => $this->decorate($r), $rows);
        $lastPage = max(1, (int)ceil($total / $perPage));

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => $lastPage, 'has_more' => $page < $lastPage],
        ];
    }

    /**
     * Months that have uploads, newest first, for the date filter:
     * [['value' => '2026-10', 'count' => 12], …]. Grouped on created_at moved
     * by $offsetSeconds (the site's UTC offset), so a month is the site's.
     */
    public function months(int $offsetSeconds = 0, int $limit = 120): array
    {
        $offsetSeconds = max(-50400, min(50400, $offsetSeconds));
        $limit = max(1, min(600, $limit));
        $rows = $this->db->select(
            "SELECT DATE_FORMAT(DATE_ADD(created_at, INTERVAL {$offsetSeconds} SECOND), '%Y-%m') AS ym, COUNT(*) AS c
               FROM {media} WHERE created_at IS NOT NULL
              GROUP BY ym ORDER BY ym DESC LIMIT {$limit}"
        );
        $out = [];
        foreach ($rows as $r) {
            if (preg_match('/^\d{4}-\d{2}$/', (string) ($r['ym'] ?? ''))) {
                $out[] = ['value' => (string) $r['ym'], 'count' => (int) $r['c']];
            }
        }
        return $out;
    }

    /** Where-clause for raster images; with $onlyMissing, those with no generated sizes yet. */
    private function imageWhere(bool $onlyMissing): string
    {
        $w = "mime_type LIKE 'image/%' AND mime_type <> 'image/svg+xml'";
        if ($onlyMissing) $w .= " AND (sizes IS NULL OR sizes = '' OR sizes = '[]' OR sizes = '{}')";
        return $w;
    }

    /** The next images after $afterId, oldest first: one batch of thumbnail regeneration. */
    public function imageBatch(int $afterId, int $limit, bool $onlyMissing): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->select(
            'SELECT * FROM {media} WHERE id > :after AND ' . $this->imageWhere($onlyMissing) . " ORDER BY id ASC LIMIT {$limit}",
            ['after' => $afterId]
        );
    }

    /** How many images (after $afterId) a regeneration run still has to visit. */
    public function imageCount(bool $onlyMissing, int $afterId = 0): int
    {
        $r = $this->db->selectOne('SELECT COUNT(*) AS c FROM {media} WHERE id > :after AND ' . $this->imageWhere($onlyMissing), ['after' => $afterId]);
        return (int) ($r['c'] ?? 0);
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
    public function typeCounts(?string $search = null, array $filters = []): array
    {
        // The same search and date as the list, without the type itself, so
        // each pill says how many items it would show.
        unset($filters['type'], $filters['types']);
        if ($search !== null && $search !== '') $filters['search'] = $search;
        [$whereSql, $params] = $this->where($filters);
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

    /**
     * Storage paths beginning with $prefix, for choosing a free file name.
     * LIKE wildcards in the prefix are escaped, so it matches literally.
     *
     * @return string[]
     */
    public function storagePathsLike(string $prefix): array
    {
        $like = addcslashes($prefix, '\\%_') . '%';
        $rows = $this->db->select(
            'SELECT storage_path FROM {media} WHERE storage_path LIKE :p',
            ['p' => $like]
        );
        return array_map(fn($r) => (string) $r['storage_path'], $rows);
    }
}
