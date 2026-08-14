<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TaxonomyRepository;
use App\Core\HookRegistry;
use App\Core\Helpers;

class TaxonomyService
{
    public function __construct(
        private TaxonomyRepository $repo,
        private HookRegistry $hooks
    ) {}

    public function allTaxonomies(): array { return $this->repo->allTaxonomies(); }

    public function findTaxonomyBySlug(string $slug): ?array { return $this->repo->findTaxonomyBySlug($slug); }

    public function termsByTaxonomySlug(string $slug): array { return $this->repo->termsByTaxonomySlug($slug); }

    public function findTerm(int $id): ?array { return $this->repo->findTerm($id); }

    /**
     * Look up a term by its slug within a taxonomy.
     *   findTermBySlug('category', 'tech') -> term row or null
     */
    public function findTermBySlug(string $taxonomySlug, string $termSlug): ?array
    {
        $tax = $this->repo->findTaxonomyBySlug($taxonomySlug);
        if (!$tax) return null;
        return $this->repo->findTermBySlug((int)$tax['id'], $termSlug);
    }

    public function createTerm(string $taxonomySlug, array $data): ?int
    {
        $tax = $this->repo->findTaxonomyBySlug($taxonomySlug);
        if (!$tax) return null;

        $name = trim($data['name'] ?? '');
        if ($name === '') return null;

        $slug = !empty($data['slug']) ? Helpers::slug($data['slug']) : Helpers::slug($name);
        if ($slug === '') $slug = Helpers::slug($name);
        // ensure unique within taxonomy
        $slug = $this->uniqueTermSlug((int)$tax['id'], $slug);

        $id = $this->repo->createTerm([
            'taxonomy_id' => (int)$tax['id'],
            'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'term_order' => (int)($data['term_order'] ?? 0),
        ]);

        $this->hooks->doAction('term.created', $this->repo->findTerm($id));
        return $id;
    }

    public function updateTerm(int $id, array $data): bool
    {
        $existing = $this->repo->findTerm($id);
        if (!$existing) return false;

        $taxonomyId = (int)$existing['taxonomy_id'];
        $payload = [];

        if (isset($data['name'])) $payload['name'] = trim((string)$data['name']);

        // Slug: use an explicit slug if given, otherwise re-derive from the name.
        // Either way keep it unique within the taxonomy so we never trip the
        // (taxonomy_id, slug) unique key when saving.
        if (isset($data['slug']) || isset($data['name'])) {
            $desired = !empty($data['slug'])
                ? Helpers::slug((string)$data['slug'])
                : Helpers::slug((string)($data['name'] ?? $existing['name']));
            if ($desired === '') $desired = (string)$existing['slug'];
            $payload['slug'] = $this->uniqueTermSlug($taxonomyId, $desired, $id);
        }

        if (array_key_exists('description', $data)) $payload['description'] = $data['description'];

        if (array_key_exists('parent_id', $data)) {
            $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
            // A term can never be its own ancestor — that would orphan a whole subtree.
            if ($parentId !== null && $this->wouldCreateCycle($id, $parentId)) {
                $parentId = $existing['parent_id'] !== null ? (int)$existing['parent_id'] : null;
            }
            $payload['parent_id'] = $parentId;
        }

        if (isset($data['term_order'])) $payload['term_order'] = (int)$data['term_order'];

        if (!empty($payload)) {
            $this->repo->updateTerm($id, $payload);
        }
        $this->hooks->doAction('term.updated', $this->repo->findTerm($id));
        return true;
    }

    public function deleteTerm(int $id): bool
    {
        $existing = $this->repo->findTerm($id);
        if (!$existing) return false;
        $this->repo->deleteTerm($id);
        $this->hooks->doAction('term.deleted', $existing);
        return true;
    }

    /**
     * Make a slug unique within a taxonomy, appending -2, -3, … on collision.
     * $ignoreId lets the term being edited keep its own slug.
     */
    private function uniqueTermSlug(int $taxonomyId, string $slug, int $ignoreId = 0): string
    {
        $base = $slug !== '' ? $slug : 'term';
        $slug = $base;
        $i = 2;
        while (($found = $this->repo->findTermBySlug($taxonomyId, $slug)) && (int)$found['id'] !== $ignoreId) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * True when setting $parentId as the parent of $termId would create a loop
     * (i.e. $parentId is $termId itself or one of its descendants).
     */
    private function wouldCreateCycle(int $termId, int $parentId): bool
    {
        if ($termId === $parentId) return true;
        $cursor = $this->repo->findTerm($parentId);
        $guard = 0;
        while ($cursor && $cursor['parent_id'] !== null && $guard++ < 1000) {
            if ((int)$cursor['parent_id'] === $termId) return true;
            $cursor = $this->repo->findTerm((int)$cursor['parent_id']);
        }
        return false;
    }
}
