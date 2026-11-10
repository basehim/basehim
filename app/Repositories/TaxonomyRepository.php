<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class TaxonomyRepository
{
    public function __construct(private Database $db) {}

    public function allTaxonomies(): array
    {
        return $this->db->select('SELECT * FROM {taxonomies} ORDER BY label');
    }

    public function findTaxonomyBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM {taxonomies} WHERE slug = :slug', ['slug' => $slug]);
    }

    public function termsByTaxonomyId(int $taxonomyId): array
    {
        return $this->db->select(
            'SELECT * FROM {terms} WHERE taxonomy_id = :tid ORDER BY parent_id, term_order, name',
            ['tid' => $taxonomyId]
        );
    }

    public function termsByTaxonomySlug(string $slug): array
    {
        $tax = $this->findTaxonomyBySlug($slug);
        if (!$tax) return [];
        return $this->termsByTaxonomyId((int)$tax['id']);
    }

    public function findTerm(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {terms} WHERE id = :id', ['id' => $id]);
    }

    /** How many terms a taxonomy has, by its slug. */
    public function countTermsBySlug(string $slug): int
    {
        $tax = $this->findTaxonomyBySlug($slug);
        if (!$tax) return 0;
        return $this->termCounts((int) $tax['id']);
    }

    /**
     * Terms of a taxonomy for the filter picker: the most-used first, with an
     * optional name/slug search. $limit caps the rows; $ids force-includes
     * specific terms (so a currently-selected term always appears in the list).
     *
     * @param int[] $ids
     * @return array<int,array{id:int,name:string,slug:string,count:int}>
     */
    public function searchTerms(string $slug, string $q = '', int $limit = 30, array $ids = []): array
    {
        $tax = $this->findTaxonomyBySlug($slug);
        if (!$tax) return [];
        $limit = max(1, min(200, $limit));

        $where = ['taxonomy_id = :tid'];
        $params = ['tid' => (int) $tax['id']];

        $idList = [];
        foreach ($ids as $v) {
            $id = (int) $v;
            if ($id > 0 && !in_array($id, $idList, true)) $idList[] = $id;
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes((string) $q, '\\%_') . '%';
            if ($idList !== []) {
                // Always keep the selected terms in the list, even if they
                // don't match the search text.
                $in = implode(',', $idList);
                $where[] = "((name LIKE :q OR slug LIKE :q2) OR id IN ($in))";
            } else {
                $where[] = '(name LIKE :q OR slug LIKE :q2)';
            }
            $params['q'] = $like;
            $params['q2'] = $like;
        }

        $rows = $this->db->select(
            'SELECT id, name, slug, count FROM {terms} WHERE ' . implode(' AND ', $where)
                . ' ORDER BY count DESC, name ASC LIMIT ' . $limit,
            $params
        );
        return array_map(static fn($r) => [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'slug'  => (string) $r['slug'],
            'count' => (int) $r['count'],
        ], $rows);
    }

    public function findTermBySlug(int $taxonomyId, string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM {terms} WHERE taxonomy_id = :tid AND slug = :slug',
            ['tid' => $taxonomyId, 'slug' => $slug]
        );
    }

    public function createTerm(array $data): int
    {
        return (int)$this->db->insert('terms', $data);
    }

    public function updateTerm(int $id, array $data): int
    {
        return $this->db->update('terms', $data, ['id' => $id]);
    }

    public function deleteTerm(int $id): int
    {
        $this->db->execute('DELETE FROM {post_term} WHERE term_id = :id', ['id' => $id]);
        return $this->db->delete('terms', ['id' => $id]);
    }

    /** Whitelisted orders for the terms table (1.2.123). */
    public const TERM_SORTS = [
        'name_az'    => 't.name ASC, t.id ASC',
        'name_za'    => 't.name DESC, t.id DESC',
        'count_desc' => 't.count DESC, t.name ASC',
        'count_asc'  => 't.count ASC, t.name ASC',
        'newest'     => 't.id DESC',
        'oldest'     => 't.id ASC',
    ];

    /**
     * One page of a taxonomy's terms for the admin table (1.2.123), with each
     * term's parent name. Filters: search (name or slug), usage (used | unused).
     * The sort is a key of TERM_SORTS; anything else is name A–Z.
     *
     * @return array{data:array<int,array>,meta:array{page:int,per_page:int,total:int,last_page:int}}
     */
    public function paginateTerms(int $taxonomyId, array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $where = ['t.taxonomy_id = :tid'];
        $params = ['tid' => $taxonomyId];
        $q = trim((string) ($filters['search'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(t.name LIKE :q OR t.slug LIKE :q2)';
            $params['q'] = $like;
            $params['q2'] = $like;
        }
        $usage = (string) ($filters['usage'] ?? '');
        if ($usage === 'unused') $where[] = 't.count = 0';
        elseif ($usage === 'used') $where[] = 't.count > 0';
        $whereSql = implode(' AND ', $where);
        $order = self::TERM_SORTS[(string) ($filters['sort'] ?? '')] ?? self::TERM_SORTS['name_az'];

        $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM {terms} t WHERE {$whereSql}", $params)['c'] ?? 0);
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($page, $last);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT t.*, p.name AS parent_name FROM {terms} t LEFT JOIN {terms} p ON p.id = t.parent_id
              WHERE {$whereSql} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $last]];
    }

    /**
     * Terms of a taxonomy whose name (lower-cased) or slug is in these lists
     * (1.2.123: duplicates when adding several at once). Bound in chunks.
     *
     * @param string[] $lowerNames
     * @param string[] $slugs
     */
    public function termsMatching(int $taxonomyId, array $lowerNames, array $slugs): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_merge($lowerNames, $slugs))), 200) as $chunk) {
            $params = ['tid' => $taxonomyId];
            $ph = [];
            foreach ($chunk as $i => $v) { $ph[] = ':v' . $i; $params['v' . $i] = (string) $v; }
            $in = implode(',', $ph);
            $ph2 = [];
            foreach ($chunk as $i => $v) { $ph2[] = ':w' . $i; $params['w' . $i] = (string) $v; }
            $in2 = implode(',', $ph2);
            foreach ($this->db->select("SELECT id, name, slug FROM {terms} WHERE taxonomy_id = :tid AND (LOWER(name) IN ({$in}) OR slug IN ({$in2}))", $params) as $r) {
                $out[(int) $r['id']] = $r;
            }
        }
        return array_values($out);
    }

    /** One term with its parent's name, in the terms table's row shape (1.2.123). */
    public function findTermRow(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT t.*, p.name AS parent_name FROM {terms} t LEFT JOIN {terms} p ON p.id = t.parent_id WHERE t.id = :id',
            ['id' => $id]
        ) ?: null;
    }

    public function termCounts(int $taxonomyId): int
    {
        $r = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM {terms} WHERE taxonomy_id = :tid',
            ['tid' => $taxonomyId]
        );
        return (int)($r['c'] ?? 0);
    }
}
