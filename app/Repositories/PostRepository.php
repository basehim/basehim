<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Cache;
use App\Core\Database;

class PostRepository
{
    /** Public list totals are cached this long (1.2.76, CR-17); any save starts a new generation. */
    public const COUNT_TTL = 300;
    private const GEN_KEY = 'posts.list_generation';
    private const FT_FAILED_KEY = 'posts.fulltext_unavailable';

    /** Set once a FULLTEXT query has failed in this process (no index, an engine without it). */
    private static bool $fulltextFailed = false;

    /**
     * @param \Closure|null $searchMode returns the reading.search_mode setting:
     *        'auto' (FULLTEXT where possible) or 'like' (substring match, the
     *        behaviour before 1.2.76)
     */
    public function __construct(
        private Database $db,
        private ?Cache $cache = null,
        private ?\Closure $searchMode = null,
    ) {}

    /**
     * Decorate a row: normalize featured_url for the install location, and
     * decode the featured image's generated sizes (thumbnail, medium, large)
     * into featured_sizes: name => ['url', 'width', 'height'], URLs normalized
     * the same way. Themes read them through bh_image_url() / bh_post_image().
     */
    private function decorate(?array $row): ?array
    {
        if (!$row) return $row;
        // The author's profile photo, small: cards and post meta show it at 20–40 px.
        if (array_key_exists('author_avatar_src', $row)) {
            $src = (string) ($row['author_avatar_src'] ?? '');
            $row['author_avatar_url'] = $src !== ''
                ? \App\Services\AvatarService::pick($src, $row['author_avatar_sizes'] ?? null, 40)
                : null;
            unset($row['author_avatar_src'], $row['author_avatar_sizes']);
        }
        if (!empty($row['featured_url'])) {
            $row['featured_url'] = self::mediaUrl((string) $row['featured_url']);
        }
        if (array_key_exists('featured_sizes', $row)) {
            $sizes = [];
            $raw = is_string($row['featured_sizes']) ? json_decode($row['featured_sizes'], true) : $row['featured_sizes'];
            foreach (is_array($raw) ? $raw : [] as $name => $v) {
                if (!is_array($v) || empty($v['url'])) continue;
                $sizes[(string) $name] = [
                    'url'    => self::mediaUrl((string) $v['url']),
                    'width'  => (int) ($v['width'] ?? 0),
                    'height' => (int) ($v['height'] ?? 0),
                ];
            }
            $row['featured_sizes'] = $sizes;
        }
        return $row;
    }

    /** /storage/uploads/… (legacy) → /uploads/…, and prefix the install's base path. */
    public static function mediaUrl(string $url): string
    {
        if ($url === '' || $url[0] !== '/') return $url;
        if (str_starts_with($url, '/storage/uploads/')) {
            $url = '/uploads/' . substr($url, strlen('/storage/uploads/'));
        }
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        if ($base !== '' && !str_starts_with($url, $base . '/')) {
            $url = $base . $url;
        }
        return $url;
    }

    /**
     * Published posts for a list widget.
     *
     * $opts: limit (1–50), order (latest | popular | commented),
     *        category (a category slug, '' for all).
     */
    public function widgetList(array $opts): array
    {
        $limit = max(1, min(50, (int) ($opts['limit'] ?? 5)));
        $order = match ((string) ($opts['order'] ?? 'latest')) {
            'popular'   => 'p.view_count DESC, p.published_at DESC',
            'commented' => 'comment_total DESC, p.published_at DESC',
            default     => 'p.published_at DESC, p.id DESC',
        };
        $where  = "p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL";
        $params = [];
        $cat = trim((string) ($opts['category'] ?? ''));
        if ($cat !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM {post_term} pt
                                      JOIN {terms} t ON t.id = pt.term_id
                                      JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category'
                                     WHERE pt.post_id = p.id AND t.slug = :cat)";
            $params['cat'] = $cat;
        }
        $rows = $this->db->select(
            "SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes,
                    m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height,
                    (SELECT COUNT(*) FROM {comments} c WHERE c.post_id = p.id AND c.status = 'approved') AS comment_total
               FROM {posts} p
               LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
               LEFT JOIN {media} m ON m.id = p.featured_media_id
              WHERE {$where}
              ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
        return array_map(fn($r) => $this->decorate($r), $rows);
    }

    public function find(int $id): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE p.id = :id AND p.deleted_at IS NULL LIMIT 1';
        return $this->decorate($this->db->selectOne($sql, ['id' => $id]));
    }

    public function findByUuid(string $uuid): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id
                WHERE p.uuid = :uuid AND p.deleted_at IS NULL LIMIT 1';
        return $this->decorate($this->db->selectOne($sql, ['uuid' => $uuid]));
    }

    public function findBySlug(string $slug, ?string $type = null): ?array
    {
        $sql = 'SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
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

    /**
     * Whether any row of the given types already holds $slug.
     *
     * Trashed rows count: a slug stays reserved while its content is in the
     * Trash, so restoring it can never collide. A permanently deleted row is
     * gone from the table, so its slug is free again.
     *
     * @param string[] $types
     */
    public function slugTaken(string $slug, array $types, ?int $excludeId = null): bool
    {
        if ($types === []) return false;
        $params = ['slug' => $slug];
        $in = [];
        foreach (array_values($types) as $i => $t) { $in[] = ':t' . $i; $params['t' . $i] = $t; }
        $sql = 'SELECT id FROM {posts} WHERE slug = :slug AND type IN (' . implode(',', $in) . ')';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        return $this->db->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Serialise slug allocation across requests, so two saves at the same
     * moment cannot both claim the same free slug — a post and a page share
     * one namespace, which no database index covers. Since 1.2.76 a save
     * that cannot get the lock is refused (PostService::slugLock()).
     */
    public function lockSlugs(): ?bool
    {
        // true = taken; false = another save held it for 10 s; null = this
        // database has no named locks (the caller carries on, as before).
        try {
            $r = $this->db->selectOne('SELECT GET_LOCK(:n, 10) AS l', ['n' => $this->slugLockName()]);
            return (int) ($r['l'] ?? 0) === 1;
        } catch (\Throwable) { return null; }
    }

    /**
     * $base and every "$base-…" slug held by rows of these types (trashed
     * ones included), as a set: slug => true. One indexed range read.
     *
     * @param string[] $types
     * @return array<string,true>
     */
    public function slugsLike(string $base, array $types, ?int $excludeId = null): array
    {
        if ($types === []) return [];
        $params = ['b' => $base, 'b2' => $base, 'pat' => self::escapeLike($base) . '-%'];
        $in = [];
        foreach (array_values($types) as $i => $t) { $in[] = ':t' . $i; $params['t' . $i] = $t; }
        // is_base: equal under the column's collation (case, and for some
        // scripts more), which is what the unique index compares.
        $sql = 'SELECT slug, (slug = :b2) AS is_base FROM {posts} WHERE type IN (' . implode(',', $in) . ') AND (slug = :b OR slug LIKE :pat)';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        $out = [];
        foreach ($this->db->select($sql, $params) as $r) {
            $out[(string) $r['slug']] = true;
            if ((int) $r['is_base'] === 1) $out[$base] = true;
        }
        return $out;
    }

    public function unlockSlugs(): void
    {
        try { $this->db->selectOne('SELECT RELEASE_LOCK(:n) AS l', ['n' => $this->slugLockName()]); } catch (\Throwable) {}
    }

    private function slugLockName(): string
    {
        // Per install: several sites may share one MySQL server.
        return 'basehim_slugs_' . substr(md5(defined('BASEHIM_ROOT') ? BASEHIM_ROOT : __DIR__), 0, 16);
    }

    /** Whether the post has at least one term in the category taxonomy. */
    public function hasCategory(int $postId): bool
    {
        return $this->db->selectOne(
            "SELECT 1 AS x FROM {post_term} pt
               JOIN {terms} t ON t.id = pt.term_id
               JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category'
              WHERE pt.post_id = :pid LIMIT 1",
            ['pid' => $postId]
        ) !== null;
    }

    /** @return int[] every term id attached to the post, any taxonomy */
    public function termIds(int $postId): array
    {
        return array_map('intval', array_column(
            $this->db->select('SELECT term_id FROM {post_term} WHERE post_id = :pid', ['pid' => $postId]),
            'term_id'
        ));
    }

    /** A category term by id or slug, or null. */
    public function categoryTerm(int $id = 0, string $slug = ''): ?array
    {
        $where = $id > 0 ? 't.id = :v' : 't.slug = :v';
        return $this->db->selectOne(
            "SELECT t.id, t.slug, t.name FROM {terms} t
               JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category'
              WHERE {$where} LIMIT 1",
            ['v' => $id > 0 ? $id : $slug]
        );
    }

    /** Create a category; returns its id, or 0 if there is no category taxonomy. */
    public function createCategory(string $name, string $slug): int
    {
        $tax = $this->db->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'category' LIMIT 1");
        if (!$tax) return 0;
        return (int) $this->db->insert('terms', [
            'taxonomy_id' => (int) $tax['id'],
            'name'        => $name,
            'slug'        => $slug,
            'count'       => 0,
        ]);
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

    /**
     * Normalise a term filter value (a single id, or a list of ids as an
     * array or comma string) to a list of distinct positive integers.
     *
     * @return int[]
     */
    private static function termIdList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $out = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
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
        // Filter by category and/or tag term ids. Each filter accepts one id or
        // a list of ids: within a filter the match is OR (any of the chosen
        // terms), and two filters together are AND (must match a chosen
        // category AND a chosen tag). Only positive integers are ever placed in
        // the SQL, so nothing user-supplied is interpolated.
        foreach (['category' => $filters['category'] ?? null, 'tag' => $filters['tag'] ?? null] as $ids) {
            $list = self::termIdList($ids);
            if ($list !== []) {
                $in = implode(',', $list);
                $where[] = "p.id IN (SELECT pt.post_id FROM {post_term} pt WHERE pt.term_id IN ($in))";
            }
        }
        $published = $status === 'published' && empty($filters['trashed']);
        $sortKey = (string) ($filters['sort'] ?? 'newest');

        // Published content always has published_at (PostService sets it;
        // migration 018 filled any gaps), so its lists sort on
        // (published_at, id), which idx_list serves without a filesort.
        // Drafts and mixed lists keep the creation-date fallback (CR-17).
        $date = $published ? 'p.published_at' : 'COALESCE(p.published_at, p.created_at)';
        // Whitelisted sort orders (never interpolate user input directly).
        $sortMap = [
            'newest'     => "{$date} DESC, p.id DESC",
            'oldest'     => "{$date} ASC, p.id ASC",
            'title_az'   => 'p.title ASC, p.id ASC',
            'title_za'   => 'p.title DESC, p.id DESC',
            'views_desc' => 'p.view_count DESC, p.id DESC',
            'views_asc'  => 'p.view_count ASC, p.id ASC',
        ];
        if (!isset($sortMap[$sortKey])) $sortKey = 'newest';
        $orderBy = $sortMap[$sortKey];

        // Keyset pagination (?after= on the API): only for the newest-first
        // order of published content, the one with a matching index.
        $cursor = $published && $sortKey === 'newest' ? self::decodeCursor($filters['after'] ?? null) : null;

        $run = function (bool $fulltext) use ($where, $params, $search, $orderBy, $page, $perPage, $published, $cursor, $sortKey): array {
            if ($search !== null && $search !== '') {
                $where[] = $this->searchClause((string) $search, 'p', $params, $fulltext);
            }
            $whereSql = implode(' AND ', $where);
            $select = "SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes, u.username AS author_username,
                       m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
                FROM {posts} p
                LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
                LEFT JOIN {media} m ON m.id = p.featured_media_id";

            if ($cursor !== null) {
                $params['cur_at'] = $cursor[0];
                $params['cur_at2'] = $cursor[0];
                $params['cur_id'] = $cursor[1];
                $items = $this->db->select(
                    "{$select}
                     WHERE {$whereSql} AND (p.published_at < :cur_at OR (p.published_at = :cur_at2 AND p.id < :cur_id))
                     ORDER BY {$orderBy}
                     LIMIT " . ($perPage + 1),
                    $params
                );
                $more = count($items) > $perPage;
                $items = array_slice($items, 0, $perPage);
                return [
                    'data' => array_map(fn($r) => $this->decorate($r), $items),
                    'meta' => [
                        'per_page' => $perPage,
                        'next_cursor' => $more && $items ? self::encodeCursor(end($items)) : null,
                    ],
                ];
            }

            $total = $this->count("SELECT COUNT(*) AS c FROM {posts} p WHERE {$whereSql}", $params, $published);
            $offset = max(0, ($page - 1) * $perPage);
            $items = $this->db->select("{$select}
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT {$perPage} OFFSET {$offset}", $params);
            $items = array_map(fn($r) => $this->decorate($r), $items);

            $meta = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage)),
            ];
            if ($published && $sortKey === 'newest') {
                $last = $items ? end($items) : null;
                $meta['next_cursor'] = $last && $page * $perPage < $total ? self::encodeCursor($last) : null;
            }
            return ['data' => $items, 'meta' => $meta];
        };

        return $this->withSearchFallback($search, $run);
    }

    // ------------------------------------------------------------------
    // Search (1.2.76, CR-16)
    // ------------------------------------------------------------------

    /**
     * The WHERE condition for a search, with its parameters added to $params.
     *
     * With FULLTEXT: every word of three or more characters must appear
     * (as a word or a word prefix) in the title, content or excerpt, through
     * idx_ft_content. A query with no such word (one or two letters, or a
     * script written without spaces) matches the title only, as a substring.
     * Without FULLTEXT ('like' mode, or after a failure): the old substring
     * match on all three columns. LIKE wildcards are escaped either way.
     */
    public function searchClause(string $q, string $alias, array &$params, bool $fulltext): string
    {
        $q = trim($q);
        $like = '%' . self::escapeLike($q) . '%';
        $words = $fulltext ? self::fulltextWords($q) : [];
        if ($words !== []) {
            $params['ft_q'] = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
            return "MATCH({$alias}.title, {$alias}.content, {$alias}.excerpt) AGAINST(:ft_q IN BOOLEAN MODE)";
        }
        if ($fulltext) {
            $params['s_title'] = $like;
            return "{$alias}.title LIKE :s_title";
        }
        $params['s_1'] = $like;
        $params['s_2'] = $like;
        $params['s_3'] = $like;
        return "({$alias}.title LIKE :s_1 OR {$alias}.content LIKE :s_2 OR {$alias}.excerpt LIKE :s_3)";
    }

    /** Escape %, _ and the escape character itself for a LIKE pattern. */
    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /**
     * The words of a query that FULLTEXT can use: letters and digits only (the
     * boolean-mode operators are dropped), three characters or more, at most
     * eight. Shorter words are below InnoDB's minimum token size.
     *
     * @return string[]
     */
    public static function fulltextWords(string $q): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($parts, fn($w) => mb_strlen($w) >= 3)));
        return array_slice($words, 0, 8);
    }

    private function useFulltext(): bool
    {
        if (self::$fulltextFailed) return false;
        try {
            if ($this->cache?->get(self::FT_FAILED_KEY)) {
                self::$fulltextFailed = true;
                return false;
            }
        } catch (\Throwable) {}
        $mode = 'auto';
        try { $mode = $this->searchMode ? (string) ($this->searchMode)() : 'auto'; } catch (\Throwable) {}
        return $mode !== 'like';
    }

    /**
     * Run a query builder with FULLTEXT when that is on, and again with LIKE
     * if the database refuses it (no FULLTEXT index on this install, or an
     * engine without FULLTEXT). The failure is remembered for this request.
     */
    private function withSearchFallback(?string $search, \Closure $run): array
    {
        $searching = $search !== null && trim((string) $search) !== '';
        if (!$searching || !$this->useFulltext()) return $run(false);
        try {
            return $run(true);
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) throw $e;
            try {
                $result = $run(false);
            } catch (\PDOException) {
                throw $e;   // not a FULLTEXT problem: the plain search fails too
            }
            // FULLTEXT is what failed. Remembered for an hour, so every
            // search does not fail first.
            self::$fulltextFailed = true;
            try { $this->cache?->set(self::FT_FAILED_KEY, 1, 3600); } catch (\Throwable) {}
            error_log('Basehim: FULLTEXT search unavailable, using LIKE: ' . $e->getMessage());
            return $result;
        }
    }

    // ------------------------------------------------------------------
    // Counts and cursors (1.2.76, CR-17)
    // ------------------------------------------------------------------

    /**
     * COUNT(*) for a list. Lists of published content are counted at most
     * once per COUNT_TTL for the same query, and again after any save.
     */
    private function count(string $sql, array $params, bool $cacheable): int
    {
        $query = fn(): int => (int) ($this->db->selectOne($sql, $params)['c'] ?? 0);
        if (!$cacheable || $this->cache === null) return $query();
        try {
            $key = 'posts.count.' . $this->generation() . '.' . md5($sql . "\n" . json_encode($params));
            $hit = $this->cache->get($key);
            if (is_int($hit)) return $hit;
            $n = $query();
            $this->cache->set($key, $n, self::COUNT_TTL);
            return $n;
        } catch (\PDOException $e) {
            throw $e;
        } catch (\Throwable) {
            return $query();
        }
    }

    /** Changes whenever content is saved; for caches built from published content (sitemaps). */
    public function listGeneration(): string
    {
        try { return $this->generation(); } catch (\Throwable) { return '0'; }
    }

    private function generation(): string
    {
        $g = $this->cache?->get(self::GEN_KEY);
        if (is_string($g) && $g !== '') return $g;
        $g = bin2hex(random_bytes(4));
        $this->cache?->set(self::GEN_KEY, $g, 86400);
        return $g;
    }

    /** Forget cached list totals: called by every write that can change a list. */
    public function listsChanged(): void
    {
        try { $this->cache?->set(self::GEN_KEY, bin2hex(random_bytes(4)), 86400); } catch (\Throwable) {}
    }

    /** An opaque cursor for keyset pagination: the last row's published_at and id. */
    public static function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode((string) ($row['published_at'] ?? '') . '|' . (int) ($row['id'] ?? 0)), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:int}|null */
    public static function decodeCursor(mixed $cursor): ?array
    {
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 64) return null;
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($raw) || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,19})$/', $raw, $m)) return null;
        return [$m[1], (int) $m[2]];
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
        $id = (int)$this->db->insert('posts', $data);
        $this->listsChanged();
        return $id;
    }

    public function update(int $id, array $data): int
    {
        $n = $this->db->update('posts', $data, ['id' => $id]);
        $this->listsChanged();
        return $n;
    }

    // ------------------------------------------------------------------
    // Scheduled posts (1.2.60)
    // ------------------------------------------------------------------

    /**
     * The ids of scheduled posts whose time has come, oldest first. Trashed
     * posts are never published.
     *
     * @return int[]
     */
    public function dueScheduledIds(string $nowUtc, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        return array_map('intval', array_column($this->db->select(
            "SELECT id FROM {posts}
              WHERE status = 'scheduled' AND deleted_at IS NULL
                AND published_at IS NOT NULL AND published_at <= :now
           ORDER BY published_at ASC, id ASC
              LIMIT {$limit}",
            ['now' => $nowUtc]
        ), 'id'));
    }

    /** The earliest publication time among scheduled posts (UTC), or null. */
    public function nextScheduledAt(): ?string
    {
        $row = $this->db->selectOne(
            "SELECT MIN(published_at) AS next_at FROM {posts}
              WHERE status = 'scheduled' AND deleted_at IS NULL AND published_at IS NOT NULL"
        );
        $v = $row['next_at'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Take one due scheduled post live, if nobody else has.
     *
     * A single conditional UPDATE: the row only changes while it is still
     * `scheduled` and due, so of two requests racing for the same post exactly
     * one sees an affected row. That one fires the hooks; the other moves on.
     */
    public function claimScheduled(int $id, string $nowUtc): bool
    {
        $won = $this->db->execute(
            "UPDATE {posts} SET status = 'published'
              WHERE id = :id AND status = 'scheduled' AND deleted_at IS NULL
                AND published_at IS NOT NULL AND published_at <= :now",
            ['id' => $id, 'now' => $nowUtc]
        ) === 1;
        if ($won) $this->listsChanged();
        return $won;
    }

    public function softDelete(int $id): int
    {
        $n = $this->db->update('posts', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        $this->listsChanged();
        return $n;
    }

    public function restore(int $id): int
    {
        $n = $this->db->update('posts', ['deleted_at' => null], ['id' => $id]);
        $this->listsChanged();
        return $n;
    }

    /** Find a row by id regardless of soft-delete state (for restore/purge). */
    public function findTrashedOrAny(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {posts} WHERE id = :id LIMIT 1', ['id' => $id]) ?: null;
    }

    public function forceDelete(int $id): int
    {
        $this->releaseTermCounts('p.id = :id', ['id' => $id]);
        $n = $this->db->delete('posts', ['id' => $id]);
        $this->listsChanged();
        return $n;
    }

    /**
     * Take the posts about to be deleted out of their terms' counts.
     *
     * Their post_term rows go with them through the foreign key, but
     * attachTerms() maintains `count` by hand, and nothing lowered it on a
     * permanent delete — so every emptied Trash left category and tag counts
     * too high, for good.
     */
    private function releaseTermCounts(string $where, array $params): void
    {
        try {
            $this->db->execute(
                "UPDATE {terms} t
                   JOIN (SELECT pt.term_id, COUNT(*) AS n
                           FROM {post_term} pt JOIN {posts} p ON p.id = pt.post_id
                          WHERE {$where}
                          GROUP BY pt.term_id) d ON d.term_id = t.id
                    SET t.count = GREATEST(0, t.count - d.n)",
                $params
            );
        } catch (\Throwable) {
            // A stale count must never stop a deletion.
        }
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
        $this->releaseTermCounts('p.type = :type AND p.deleted_at IS NOT NULL', ['type' => $type]);
        $n = $this->db->execute(
            'DELETE FROM {posts} WHERE type = :type AND deleted_at IS NOT NULL',
            ['type' => $type]
        );
        $this->listsChanged();
        return $n;
    }

    public function incrementViewCount(int $id): void
    {
        // `updated_at = updated_at`: the column is ON UPDATE CURRENT_TIMESTAMP,
        // so without it every page view stamped the post as modified "now",
        // which leaked into article:modified_time, dateModified and the
        // sitemap's lastmod (1.2.49).
        $this->db->execute('UPDATE {posts} SET view_count = view_count + 1, updated_at = updated_at WHERE id = :id', ['id' => $id]);
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
            "SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes,
                    m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
             FROM {posts} p
             LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
             LEFT JOIN {media} m ON m.id = p.featured_media_id
             WHERE p.type = :type AND p.deleted_at IS NULL
             ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
             LIMIT {$limit}",
            ['type' => $type]
        );
        return array_map(fn($r) => $this->decorate($r), $items);
    }

    /**
     * Attach exactly $termIds to the post (others are detached).
     *
     * 1.2.73 (BH-026): idempotent and safe to run twice at once. A term
     * already attached by a parallel save is not inserted again (it was a
     * duplicate-key error, a 500 after the post itself had been saved), and
     * the counts of the terms touched are recomputed from post_term instead
     * of being nudged by one, which drifted whenever two saves overlapped.
     * Callers validate the ids first (PostService::validTermIds()).
     */
    public function attachTerms(int $postId, array $termIds): void
    {
        $existingIds = array_map('intval', array_column($this->db->select(
            'SELECT term_id FROM {post_term} WHERE post_id = :pid',
            ['pid' => $postId]
        ), 'term_id'));
        $newIds = array_values(array_unique(array_filter(array_map('intval', $termIds), fn($i) => $i > 0)));

        $toRemove = array_values(array_diff($existingIds, $newIds));
        $toAdd    = array_values(array_diff($newIds, $existingIds));

        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $this->db->execute(
                "DELETE FROM {post_term} WHERE post_id = ? AND term_id IN ({$placeholders})",
                array_merge([$postId], $toRemove)
            );
        }

        if ($toAdd) {
            $order = (int) ($this->db->selectOne(
                'SELECT COALESCE(MAX(term_order), -1) + 1 AS next FROM {post_term} WHERE post_id = :pid',
                ['pid' => $postId]
            )['next'] ?? 0);
            foreach ($toAdd as $tid) {
                $this->db->execute(
                    'INSERT INTO {post_term} (post_id, term_id, term_order) VALUES (:p, :t, :o)
                     ON DUPLICATE KEY UPDATE term_order = term_order',
                    ['p' => $postId, 't' => $tid, 'o' => $order++]
                );
            }
        }

        $this->recountTerms(array_merge($toRemove, $toAdd));
        if ($toRemove || $toAdd) $this->listsChanged();
    }

    /** Recompute terms.count from post_term for these term ids. */
    public function recountTerms(array $termIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $termIds), fn($i) => $i > 0)));
        if (!$ids) return;
        $in = implode(',', $ids);   // positive integers only
        $this->db->execute(
            "UPDATE {terms} t SET t.count = (SELECT COUNT(*) FROM {post_term} pt WHERE pt.term_id = t.id) WHERE t.id IN ({$in})"
        );
    }

    /**
     * The ids among $termIds that are not existing terms.
     *
     * @return int[]
     */
    public function unknownTermIds(array $termIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $termIds)));
        $bad = array_values(array_filter($ids, fn($i) => $i <= 0));
        $ids = array_values(array_filter($ids, fn($i) => $i > 0));
        if (!$ids) return $bad;
        $in = implode(',', $ids);
        $found = array_map('intval', array_column($this->db->select("SELECT id FROM {terms} WHERE id IN ({$in})"), 'id'));
        return array_values(array_merge($bad, array_diff($ids, $found)));
    }

    /** Run $fn in one database transaction (joins an open one). */
    public function transaction(\Closure $fn): mixed
    {
        return $this->db->transaction(fn() => $fn());
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
        $total = $this->count(
            "SELECT COUNT(*) AS c FROM {post_term} pt
             JOIN {posts} p ON p.id = pt.post_id
             WHERE pt.term_id = :tid AND p.status = 'published' AND p.deleted_at IS NULL",
            ['tid' => $termId],
            true
        );

        $items = $this->db->select(
            "SELECT p.*, u.display_name AS author_name, av.url AS author_avatar_src, av.sizes AS author_avatar_sizes,
                    m.url AS featured_url, m.alt_text AS featured_alt, m.sizes AS featured_sizes, m.width AS featured_width, m.height AS featured_height
             FROM {post_term} pt
             JOIN {posts} p ON p.id = pt.post_id
             LEFT JOIN {users} u ON u.id = p.author_id
               LEFT JOIN {media} av ON av.id = u.avatar_media_id
             LEFT JOIN {media} m ON m.id = p.featured_media_id
             WHERE pt.term_id = :tid AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.published_at DESC, p.id DESC
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
        if (trim($term) === '') return [];
        $limit = max(1, min(200, $limit));
        return $this->withSearchFallback($term, function (bool $fulltext) use ($term, $limit): array {
            $params = [];
            $cond = $this->searchClause($term, 'p', $params, $fulltext);
            return $this->db->select(
                "SELECT p.id, p.uuid, p.type, p.title, p.slug, p.excerpt, p.published_at
                 FROM {posts} p
                 WHERE p.status = 'published' AND p.deleted_at IS NULL AND {$cond}
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT {$limit}",
                $params
            );
        });
    }
}
