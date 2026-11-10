<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Repositories\TaxonomyRepository;
use App\Services\TaxonomyRegistry;

/**
 * The terms of one taxonomy (1.2.123): Categories, Tags and apps'
 * taxonomies. One table is registered per taxonomy, keyed terms.{slug}
 * (dashes as underscores); the taxonomy comes from that key, so one provider
 * serves them all.
 *
 * Before 1.2.123 the screen printed every term twice (a row and a hidden
 * edit form) with a parent list per row, so a site with thousands of tags
 * sent megabytes and the page stopped responding. The table pages it.
 */
final class TermTableProvider implements TableProvider
{
    private string $current = '';

    public function __construct(private TaxonomyRepository $repo) {}

    public function sortKeys(): array
    {
        return array_keys(TaxonomyRepository::TERM_SORTS);
    }

    public function filterKeys(): array
    {
        return ['usage'];
    }

    public function forTable(string $key): void
    {
        $this->current = self::taxonomyFromKey($key);
    }

    /** The table key for a taxonomy (table keys allow no dashes). */
    public static function keyFor(string $taxonomy): string
    {
        return 'terms.' . str_replace('-', '_', $taxonomy);
    }

    /** The taxonomy a table key lists, or ''. */
    public static function taxonomyFromKey(string $key): string
    {
        if (!str_starts_with($key, 'terms.')) return '';
        $seg = substr($key, 6);
        try {
            foreach (\App\Core\Application::getInstance()->make(TaxonomyRegistry::class)->all() as $slug => $_) {
                if (str_replace('-', '_', (string) $slug) === $seg) return (string) $slug;
            }
        } catch (\Throwable) {}
        return '';
    }

    public function query(TableQuery $q): array
    {
        $this->current = self::taxonomyFromKey($q->table);
        $tax = $this->current !== '' ? $this->repo->findTaxonomyBySlug($this->current) : null;
        if ($tax === null) {
            return ['data' => [], 'meta' => ['page' => 1, 'per_page' => $q->perPage, 'total' => 0, 'last_page' => 1]];
        }
        $result = $this->repo->paginateTerms((int) $tax['id'], [
            'search' => $q->search,
            'usage'  => $q->filter('usage'),
            'sort'   => $q->sort,
        ], $q->page, $q->perPage);
        foreach ($result['data'] as &$row) {
            $row['taxonomy'] = $this->current;
            $row['row_key'] = (string) ($row['slug'] ?? '');
        }
        unset($row);
        return $result;
    }
}
