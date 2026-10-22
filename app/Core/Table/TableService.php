<?php

declare(strict_types=1);

namespace App\Core\Table;

use App\Core\Application;
use App\Services\HtmlSanitizer;

/**
 * Turns a validated request for a table into a response the client renders.
 *
 * It is the one place that:
 *   1. reconciles the request with the table definition, the provider's sort
 *      and filter whitelists, and the user's saved preferences;
 *   2. asks the provider for one page of rows (the provider owns the SQL);
 *   3. resolves each visible column's cells using the two-phase column
 *      contract — prepare() once per column for the whole page, then a pure
 *      render() per cell — so a page costs O(columns), never O(rows×columns);
 *   4. escapes plain values and sanitizes app-owned HTML before it leaves PHP.
 *
 * Both the AJAX action and the no-JS server-rendered first page call build().
 */
final class TableService
{
    /** Hard ceiling on prepare() work, independent of a table's page sizes. */
    private const MAX_ROWS = 100;

    public function __construct(
        private TableRegistry $registry,
        private TablePreferences $preferences,
    ) {
    }

    /**
     * @param array<string,mixed> $params  page, per_page, sort, search, filters[], columns[]
     * @return array  rows/meta/columns/catalog/prefs/table — ready to JSON-encode
     */
    public function build(TableDefinition $table, ?array $user, array $params): array
    {
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        // Full permitted column set (core + app extensions, capability-gated).
        $permitted = $this->registry->columnsFor($table->key, $user);
        $byId = [];
        foreach ($permitted as $c) {
            $byId[$c->id] = $c;
        }

        // Effective preferences (reconciled with the live definition).
        $prefs = $this->preferences->resolve($userId, $table, $permitted);

        // The provider and its whitelists.
        /** @var TableProvider $provider */
        $provider = Application::getInstance()->make($table->provider);
        $sortKeys = $provider->sortKeys();
        $filterKeys = array_flip($provider->filterKeys());

        // --- Reconcile the request ------------------------------------------
        $perPage = $prefs['page_size'];
        if (isset($params['per_page'])) {
            $n = (int) $params['per_page'];
            if ($table->allowsPageSize($n)) {
                $perPage = $n;
            }
        }
        $perPage = min($perPage, self::MAX_ROWS);

        $page = max(1, (int) ($params['page'] ?? 1));

        $sort = $prefs['sort'];
        if (isset($params['sort']) && is_string($params['sort']) && in_array($params['sort'], $sortKeys, true)) {
            $sort = $params['sort'];
        }
        if ($sort !== null && !in_array($sort, $sortKeys, true)) {
            $sort = $table->defaultSort !== '' && in_array($table->defaultSort, $sortKeys, true)
                ? $table->defaultSort : null;
        }

        $search = isset($params['search']) && is_string($params['search']) ? trim($params['search']) : '';

        // Filters: only keys the provider declared, and values that match any
        // declared `in` list on the column that drives them.
        $filters = [];
        $requested = (isset($params['filters']) && is_array($params['filters'])) ? $params['filters'] : [];
        $allowedValues = $this->declaredFilterValues($permitted);
        foreach ($requested as $field => $value) {
            if (!is_string($field) || !isset($filterKeys[$field])) {
                continue;
            }
            if (is_array($value)) {
                // Multi-value filter (e.g. category/tag checkboxes). Keep the
                // non-empty scalar members; if the column declares an `in` set,
                // each member must belong to it.
                $clean = [];
                foreach ($value as $v) {
                    if (!is_scalar($v) || $v === '') {
                        continue;
                    }
                    $v = (string) $v;
                    if (isset($allowedValues[$field]) && !in_array($v, $allowedValues[$field], true)) {
                        continue;
                    }
                    if (!in_array($v, $clean, true)) {
                        $clean[] = $v;
                    }
                }
                if ($clean !== []) {
                    $filters[$field] = $clean;
                }
                continue;
            }
            if (!is_scalar($value) || $value === '') {
                continue;
            }
            $value = (string) $value;
            if (isset($allowedValues[$field]) && !in_array($value, $allowedValues[$field], true)) {
                continue;  // value not in the column's declared set — ignore
            }
            $filters[$field] = $value;
        }

        // Which columns to resolve this request: an explicit request overrides
        // prefs, intersected with permitted; non-hideable columns always in.
        $visibleIds = $prefs['visible'];
        if (isset($params['columns']) && is_array($params['columns'])) {
            $req = array_values(array_filter($params['columns'], fn($id) => is_string($id) && isset($byId[$id])));
            if ($req !== []) {
                $visibleIds = $req;
            }
        }
        // Order visible columns by the preference order, and force non-hideable in.
        $visible = [];
        foreach ($prefs['order'] as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $col = $byId[$id];
            if (in_array($id, $visibleIds, true) || !$col->canHide) {
                $visible[] = $col;
            }
        }
        if ($visible === []) {
            // Never resolve to an empty table; fall back to default-visible.
            $visible = array_values(array_filter($permitted, fn(Column $c) => $c->defaultVisible || !$c->canHide));
        }

        $query = new TableQuery(
            table: $table->key,
            page: $page,
            perPage: $perPage,
            sort: $sort,
            search: $search !== '' ? $search : null,
            filters: $filters,
            columns: array_map(fn(Column $c) => $c->id, $visible),
        );

        // --- Data ------------------------------------------------------------
        $result = $provider->query($query);
        $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [
            'page' => $page, 'per_page' => $perPage, 'total' => count($rows),
            'last_page' => 1,
        ];

        // --- Two-phase cell resolution --------------------------------------
        // Phase A: one prepare() per visible column, for the whole page.
        $prepared = [];
        foreach ($visible as $col) {
            if ($col->prepare !== null) {
                try {
                    $prepared[$col->id] = ($col->prepare)($rows);
                } catch (\Throwable $e) {
                    $prepared[$col->id] = null;  // a failing extension costs its column, not the page
                }
            }
        }

        $links = $this->links($table, $rows);

        // Phase B: pure render() per cell; no I/O here.
        $outRows = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($visible as $col) {
                $cells[$col->id] = $this->cell($col, $row, $prepared[$col->id] ?? null);
            }
            $outRows[] = [
                'id'     => isset($row['id']) ? (int) $row['id'] : null,
                'status' => isset($row['status']) ? (string) $row['status'] : null,
                'cells'  => $cells,
            ] + (isset($row['id'], $links[(int) $row['id']]) ? ['link' => $links[(int) $row['id']]] : [])
              + self::rowExtras($row);
        }

        return [
            'table'   => $table->key,
            'label'   => $table->label,
            'rows'    => $outRows,
            'meta'    => $meta,
            'columns' => array_map(fn(Column $c) => $c->toClientArray(), $visible),
            'catalog' => array_map(fn(Column $c) => $c->toClientArray(), $permitted),
            'prefs'   => $prefs,
            'sort'    => $sort,
            'search'  => $search,
            'filters' => $filters,
            'page_sizes'   => $table->pageSizes,
            'bulk_actions' => $table->bulkActions,
            'row_actions'  => $table->rowActions,
            'actions'      => $table->actions,
            'quick_edit'   => $table->quickEdit !== null,
        ];
    }

    /**
     * One row, rendered exactly as build() renders it, for updating a row in
     * place after Quick Edit (1.2.61). The same columns (core and app,
     * capability-gated), the same two-phase prepare()/render(), the same
     * escaping — just for a single record.
     *
     * @param array    $raw       the row in the provider's shape (e.g. PostService::find())
     * @param string[] $columnIds the columns the client is showing; others are skipped
     * @return array{id:?int,status:?string,cells:array<string,string>}
     */
    public function renderRow(TableDefinition $table, ?array $user, array $raw, array $columnIds): array
    {
        $permitted = $this->registry->columnsFor($table->key, $user);
        $want = array_flip(array_filter($columnIds, 'is_string'));
        $visible = array_values(array_filter($permitted, fn(Column $c) => isset($want[$c->id]) || !$c->canHide));

        $cells = [];
        foreach ($visible as $col) {
            $prepared = null;
            if ($col->prepare !== null) {
                try { $prepared = ($col->prepare)([$raw]); } catch (\Throwable) { $prepared = null; }
            }
            $cells[$col->id] = $this->cell($col, $raw, $prepared);
        }
        $links = $this->links($table, [$raw]);
        return [
            'id'     => isset($raw['id']) ? (int) $raw['id'] : null,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
            'cells'  => $cells,
        ] + (isset($raw['id'], $links[(int) $raw['id']]) ? ['link' => $links[(int) $raw['id']]] : []);
    }

    /**
     * Each row's public address for View / Preview (1.2.62), from the table's
     * row_links callable, once for the whole page. Only http(s) or
     * root-relative URLs pass; a failing callable costs the links, not the page.
     *
     * @return array<int,array{url:string,live:bool}>
     */
    private function links(TableDefinition $table, array $rows): array
    {
        if ($table->rowLinks === null || $rows === []) return [];
        try {
            $raw = ($table->rowLinks)($rows);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $id => $l) {
            $url = is_array($l) ? (string) ($l['url'] ?? '') : '';
            if ($url === '' || !preg_match('#^(https?://|/)#i', $url) || str_starts_with($url, '//')) continue;
            $out[(int) $id] = ['url' => $url, 'live' => !empty($l['live'])];
        }
        return $out;
    }

    /** Resolve and make-safe one cell's HTML. */
    private function cell(Column $col, array $row, mixed $prepared): string
    {
        if ($col->value !== null) {
            $raw = $row[$col->value] ?? '';
            return htmlspecialchars(is_scalar($raw) ? (string) $raw : '', ENT_QUOTES);
        }
        if ($col->render !== null) {
            try {
                $html = (string) ($col->render)($row, $prepared);
            } catch (\Throwable $e) {
                return '';  // a throwing renderer yields an empty cell, never a 500
            }
            // Core columns are written by core and trusted. An app column's
            // output is untrusted HTML: strip scripts, forms, iframes, etc.
            if ($col->owner !== Column::OWNER_CORE) {
                $html = HtmlSanitizer::clean($html)['html'];
            }
            return $html;
        }
        return '';
    }

    /**
     * Map of filter field => allowed value list, gathered from columns that
     * declare a filter with an `in` set.
     *
     * @param Column[] $permitted
     * @return array<string,string[]>
     */
    private function declaredFilterValues(array $permitted): array
    {
        $out = [];
        foreach ($permitted as $c) {
            if ($c->filter !== null && isset($c->filter['field'], $c->filter['in'])) {
                $out[$c->filter['field']] = $c->filter['in'];
            }
        }
        return $out;
    }

    /**
     * What a row may carry for declared actions (1.2.85): `row_key`, a string
     * id for {key} in an action's href (a slug, say), and `row_flags`, the
     * names an action's `flag` checks. Both come from the provider.
     */
    private static function rowExtras(array $row): array
    {
        $out = [];
        if (isset($row['row_key']) && is_scalar($row['row_key'])) $out['key'] = (string) $row['row_key'];
        if (isset($row['row_flags']) && is_array($row['row_flags'])) {
            $out['flags'] = array_values(array_filter(array_map('strval', $row['row_flags']), fn($f) => preg_match('/^[a-z][a-z0-9_]{0,39}$/', $f)));
        }
        return $out;
    }
}
