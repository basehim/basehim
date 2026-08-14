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
