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

    public function termCounts(int $taxonomyId): int
    {
        $r = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM {terms} WHERE taxonomy_id = :tid',
            ['tid' => $taxonomyId]
        );
        return (int)($r['c'] ?? 0);
    }
}
