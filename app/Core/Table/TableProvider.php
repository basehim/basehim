<?php

declare(strict_types=1);

namespace App\Core\Table;

/**
 * A table's data provider.
 *
 * Each core feature keeps its own retrieval logic: a provider is a thin
 * adapter that maps a validated, generic {@see TableQuery} onto the feature's
 * existing `paginate()` method. The Data Table service never holds SQL of its
 * own — it asks the provider, which asks the repository the admin and the REST
 * API already use.
 *
 * Two small whitelists make the generic request safe:
 *
 *  - sortKeys()   — the sort tokens this provider accepts. A request to sort
 *                   by anything else is rejected before a query runs, so no
 *                   user string is ever interpolated into an ORDER BY.
 *  - filterKeys() — the filter fields this provider accepts, for the same
 *                   reason.
 *
 * query() returns the house pagination shape unchanged:
 *   ['data' => array<int,array>, 'meta' => ['page','per_page','total','last_page']]
 */
interface TableProvider
{
    /** Sort tokens this provider understands, e.g. ['newest','oldest','title_az']. */
    public function sortKeys(): array;

    /** Filter field names this provider understands, e.g. ['status','author_id']. */
    public function filterKeys(): array;

    /**
     * Run one page of the query.
     *
     * The query is already validated: `sort` is one of sortKeys() (or null),
     * every active filter is one of filterKeys(), and `perPage` is clamped.
     * Return the existing `{data, meta}` shape; the service turns `data` into
     * column cells.
     */
    public function query(TableQuery $query): array;
}
