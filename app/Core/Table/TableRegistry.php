<?php

declare(strict_types=1);

namespace App\Core\Table;

use App\Core\HookRegistry;
use App\Core\Logger;
use App\Http\Middleware\CheckCapability;

/**
 * The central registry of admin data tables.
 *
 * Core registers its tables once at boot (routes/tables.php). Each table has a
 * stable key (content.posts, media.library, …), a capability, a data provider
 * and a set of core columns. Apps never replace a table; they append columns
 * through the `datatable.columns.{key}` filter, which this registry applies
 * when it assembles the column list for a request.
 *
 * What the registry guarantees:
 *  - A key is registered once; a second registration of the same key is
 *    refused, never silently wins (mirrors AjaxRegistry).
 *  - Every column definition is validated — a malformed one is refused at
 *    registration (core) or dropped with a log line (an app's, so one bad app
 *    column cannot break the screen).
 *  - Column ids are unique within a table; a duplicate from an extension is
 *    dropped and logged.
 *  - A column naming a capability the viewer lacks is removed from the result
 *    entirely (not hidden client-side).
 */
final class TableRegistry
{
    /** @var array<string,TableDefinition> */
    private array $tables = [];

    public function __construct(
        private HookRegistry $hooks,
        private Logger $logger,
    ) {
    }

    /**
     * Register a core table. Throws on a malformed definition — a typo is a
     * bug to fix now, not a check that silently never runs.
     *
     * @param array<string,mixed> $def
     */
    public function register(string $key, array $def): TableDefinition
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,3}$/', $key)) {
            throw new \InvalidArgumentException("Invalid table key '{$key}': expected namespace.name, lower case.");
        }
        if (isset($this->tables[$key])) {
            throw new \RuntimeException("Table '{$key}' is already registered.");
        }

        $capability = $def['capability'] ?? null;
        if (!is_string($capability) || $capability === '') {
            throw new \InvalidArgumentException("Table '{$key}' needs a non-empty 'capability'.");
        }

        $provider = $def['provider'] ?? null;
        if (!is_string($provider) || !is_a($provider, TableProvider::class, true)) {
            throw new \InvalidArgumentException("Table '{$key}' provider must be a class implementing TableProvider.");
        }

        $pageSizes = array_values(array_filter(array_map('intval', (array) ($def['page_sizes'] ?? [20])), fn($n) => $n > 0 && $n <= 1000));
        if ($pageSizes === []) {
            throw new \InvalidArgumentException("Table '{$key}' needs at least one valid page size.");
        }

        $columns = [];
        $seen = [];
        foreach ((array) ($def['columns'] ?? []) as $raw) {
            $col = $this->buildColumn($key, $raw, Column::OWNER_CORE, $seen);
            if ($col === null) {
                // Core columns must be valid — surface the problem loudly.
                throw new \InvalidArgumentException("Table '{$key}' has an invalid column definition.");
            }
            $columns[] = $col;
            $seen[$col->id] = true;
        }
        if ($columns === []) {
            throw new \InvalidArgumentException("Table '{$key}' needs at least one column.");
        }

        $defaultSort = (string) ($def['default_sort'] ?? '');

        $table = new TableDefinition(
            key: $key,
            label: (string) ($def['label'] ?? $key),
            capability: $capability,
            area: isset($def['area']) ? (string) $def['area'] : null,
            provider: $provider,
            defaultSort: $defaultSort,
            pageSizes: $pageSizes,
            columns: $columns,
            rowActions: array_values(array_map('strval', (array) ($def['row_actions'] ?? []))),
            bulkActions: array_values(array_map('strval', (array) ($def['bulk_actions'] ?? []))),
            searchPlaceholder: (string) ($def['search_placeholder'] ?? 'Search…'),
        );

        return $this->tables[$key] = $table;
    }

    public function get(string $key): ?TableDefinition
    {
        return $this->tables[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->tables[$key]);
    }

    /** @return array<string,TableDefinition> */
    public function all(): array
    {
        return $this->tables;
    }

    /**
     * The columns for one request: core columns plus any an app appended
     * through `datatable.columns.{key}`, de-duplicated, then filtered to the
     * ones this user may see.
     *
     * @param array|null $user The signed-in user row (null = nobody)
     * @return Column[]
     */
    public function columnsFor(string $key, ?array $user): array
    {
        $table = $this->get($key);
        if ($table === null) {
            return [];
        }

        $columns = $table->columns;
        $seen = [];
        foreach ($columns as $c) {
            $seen[$c->id] = true;
        }

        // App/theme extensions. The filter receives the public column arrays so
        // a bad extension cannot read core callables; it returns the list with
        // its own column arrays appended. Each addition is validated and
        // de-duplicated here, exactly like a core column but non-fatal.
        $extended = $this->hooks->applyFilters(
            'datatable.columns.' . $key,
            array_map(fn(Column $c) => $this->exposeForFilter($c), $columns),
            $key,
        );

        if (is_array($extended)) {
            foreach ($extended as $raw) {
                if (!is_array($raw) || !isset($raw['id']) || isset($seen[$raw['id']])) {
                    // Core columns echo back unchanged (already added); only
                    // genuinely new ids are considered.
                    continue;
                }
                $owner = isset($raw['owner']) && is_string($raw['owner']) && $raw['owner'] !== ''
                    ? $raw['owner'] : 'app';
                $col = $this->buildColumn($key, $raw, $owner, $seen);
                if ($col === null) {
                    $this->logger->warning('Data table: dropped an invalid extension column', [
                        'table' => $key, 'id' => (string) ($raw['id'] ?? '?'),
                    ]);
                    continue;
                }
                $columns[] = $col;
                $seen[$col->id] = true;
            }
        }

        // Capability gate: remove, do not merely hide.
        return array_values(array_filter($columns, function (Column $c) use ($user): bool {
            return $c->capability === null || CheckCapability::userCan($user, $c->capability);
        }));
    }

    /**
     * Validate and normalise one column definition into a Column, or null when
     * it is malformed. `$seen` holds ids already taken in this table.
     *
     * @param array<string,mixed> $raw
     * @param array<string,bool>  $seen
     */
    private function buildColumn(string $table, mixed $raw, string $owner, array $seen): ?Column
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/i', $id) || strlen($id) > 80) {
            return null;
        }
        if (isset($seen[$id])) {
            return null;  // duplicate
        }

        $label = $raw['label'] ?? null;
        if (!is_string($label) || $label === '' || strlen($label) > 120) {
            return null;
        }

        $value  = isset($raw['value']) && is_string($raw['value']) ? $raw['value'] : null;
        $render = (isset($raw['render']) && is_callable($raw['render'])) ? $raw['render'] : null;
        $prepare = (isset($raw['prepare']) && is_callable($raw['prepare'])) ? $raw['prepare'] : null;

        // Exactly one value source. A string handler is refused (no "Class::m"
        // turned into a call from config), same stance as AjaxRegistry.
        if ($value === null && $render === null) {
            return null;
        }
        if (isset($raw['render']) && !is_callable($raw['render'])) {
            return null;  // a non-callable render (e.g. a string) is a mistake, not a fallback
        }
        if (isset($raw['prepare']) && !is_callable($raw['prepare'])) {
            return null;
        }

        $sortable = [];
        if (isset($raw['sortable'])) {
            if ($raw['sortable'] === false) {
                $sortable = [];
            } elseif (is_array($raw['sortable'])) {
                $sortable = array_values(array_filter(array_map('strval', $raw['sortable']), fn($s) => $s !== ''));
            } else {
                return null;  // must be a list of tokens or false
            }
        }

        $filter = null;
        if (isset($raw['filter']) && is_array($raw['filter']) && isset($raw['filter']['field']) && is_string($raw['filter']['field'])) {
            $filter = ['field' => $raw['filter']['field']];
            if (isset($raw['filter']['in']) && is_array($raw['filter']['in'])) {
                $filter['in'] = array_values(array_map('strval', $raw['filter']['in']));
            }
        }

        $capability = isset($raw['capability']) && is_string($raw['capability']) && $raw['capability'] !== ''
            ? $raw['capability'] : null;

        $responsiveRaw = $raw['responsive'] ?? 'always';
        $responsive = in_array($responsiveRaw, ['always', 'desktop-only', 'tablet-up'], true) ? (string) $responsiveRaw : 'always';
        $alignRaw = $raw['align'] ?? 'left';
        $align = in_array($alignRaw, ['left', 'center', 'right'], true) ? (string) $alignRaw : 'left';

        return new Column(
            id: $id,
            label: $label,
            value: $value,
            render: $render,
            prepare: $prepare,
            sortable: $sortable,
            filter: $filter,
            defaultVisible: (bool) ($raw['default_visible'] ?? true),
            canHide: (bool) ($raw['can_hide'] ?? true),
            capability: $capability,
            responsive: $responsive,
            align: $align,
            owner: $owner,
        );
    }

    /** The column arrays handed to the extension filter (no callables). */
    private function exposeForFilter(Column $c): array
    {
        return $c->toClientArray();
    }
}
