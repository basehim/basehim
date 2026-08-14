<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\TaxonomyService;

/**
 * TermsApi — taxonomies and their terms (categories, tags, custom).
 *
 *     $api->terms()->create('category', ['name' => 'News']);
 *     $api->terms()->inTaxonomy('tag');
 */
class TermsApi extends Resource
{
    private function service(): TaxonomyService
    {
        return $this->make(TaxonomyService::class);
    }

    /** Every registered taxonomy. */
    public function taxonomies(): array
    {
        return (array) $this->attempt(fn() => $this->service()->allTaxonomies(), [], 'taxonomies');
    }

    public function taxonomy(string $slug): ?array
    {
        return $this->attempt(fn() => $this->service()->findTaxonomyBySlug($slug), null, 'taxonomy');
    }

    /** All terms in a taxonomy. */
    public function inTaxonomy(string $taxonomySlug): array
    {
        return (array) $this->attempt(
            fn() => $this->service()->termsByTaxonomySlug($taxonomySlug), [], 'inTaxonomy'
        );
    }

    public function find(int $id): ?array
    {
        return $this->attempt(fn() => $this->service()->findTerm($id), null, 'find');
    }

    public function findBySlug(string $taxonomySlug, string $termSlug): ?array
    {
        return $this->attempt(
            fn() => $this->service()->findTermBySlug($taxonomySlug, $termSlug), null, 'findBySlug'
        );
    }

    /** Create a term. Returns the new id, or 0. */
    public function create(string $taxonomySlug, array $data): int
    {
        $id = (int) $this->attempt(fn() => $this->service()->createTerm($taxonomySlug, $data), 0, 'create');
        if ($id > 0) $this->log("Created term #{$id} in {$taxonomySlug}");
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->updateTerm($id, $data), false, 'update');
        if ($ok) $this->log("Updated term #{$id}");
        return $ok;
    }

    public function delete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->deleteTerm($id), false, 'delete');
        if ($ok) $this->log("Deleted term #{$id}");
        return $ok;
    }

    /**
     * Find a term by name, creating it if absent.
     *
     * The tagging pattern almost every importer needs, and the one everybody
     * otherwise reimplements with a race in it.
     */
    public function firstOrCreate(string $taxonomySlug, string $name): ?array
    {
        $slug = $this->slugify($name);
        $existing = $this->findBySlug($taxonomySlug, $slug);
        if ($existing) return $existing;

        $id = $this->create($taxonomySlug, ['name' => $name, 'slug' => $slug]);
        if ($id > 0) return $this->find($id);

        // A concurrent request may have created it between our check and our
        // insert; a second lookup resolves that without surfacing an error.
        return $this->findBySlug($taxonomySlug, $slug);
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'term';
    }
}
