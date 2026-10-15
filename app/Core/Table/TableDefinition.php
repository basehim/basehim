<?php

declare(strict_types=1);

namespace App\Core\Table;

/**
 * A registered table: its identity, who may see it, where its data comes from,
 * and its core columns. Apps add further columns at query time through the
 * `datatable.columns.{key}` filter; this object holds only what core declared.
 *
 * `capability` and `area` mirror the admin screen the table serves, so the
 * AJAX action that reads it is never more open than that screen — the area is
 * resolved through AdminAreaPolicy::requiredFor(), which honours a site's
 * admin.area_policy overrides.
 */
final class TableDefinition
{
    /**
     * @param Column[]  $columns      Core columns, in default order
     * @param int[]     $pageSizes    Allowed rows-per-page values
     * @param string[]  $rowActions   Row action ids the UI may offer (edit, trash, …)
     * @param string[]  $bulkActions  Bulk action ids the UI may offer (publish, delete, …)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $capability,
        public readonly ?string $area,
        public readonly string $provider,
        public readonly string $defaultSort,
        public readonly array $pageSizes,
        public readonly array $columns,
        public readonly array $rowActions = [],
        public readonly array $bulkActions = [],
        public readonly string $searchPlaceholder = 'Search…',
    ) {
    }

    public function defaultPageSize(): int
    {
        return $this->pageSizes[0] ?? 20;
    }

    public function maxPageSize(): int
    {
        return $this->pageSizes === [] ? 100 : max($this->pageSizes);
    }

    public function allowsPageSize(int $n): bool
    {
        return in_array($n, $this->pageSizes, true);
    }
}
