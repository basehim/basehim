<?php

declare(strict_types=1);

namespace App\Core\Table;

/**
 * A validated request for one page of a table.
 *
 * Built by {@see TableActions} from the AJAX input after every value has been
 * checked against the table definition and the provider's whitelists. By the
 * time a provider receives one, `sort` is a known token (or null), every
 * filter key is one the provider declared, and `perPage` sits inside the
 * table's allowed page sizes. A provider can therefore use the values
 * directly; it never has to re-validate request shape (it still checks
 * ownership and record access, which no request can be trusted to assert).
 */
final class TableQuery
{
    /**
     * @param array<string,scalar> $filters  Active filters, keys ∈ provider filterKeys()
     * @param string[]             $columns  Requested visible column ids (already intersected)
     */
    public function __construct(
        public readonly string $table,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $sort,
        public readonly ?string $search,
        public readonly array $filters,
        public readonly array $columns,
    ) {
    }

    /** One active filter value, or $default when it was not supplied. */
    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    public function hasFilter(string $key): bool
    {
        return array_key_exists($key, $this->filters);
    }
}
