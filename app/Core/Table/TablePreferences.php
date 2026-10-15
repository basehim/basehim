<?php

declare(strict_types=1);

namespace App\Core\Table;

use App\Services\UserService;

/**
 * Per-user, per-table view preferences: which columns are visible, their
 * order, the page size, row density and (optionally) the sort.
 *
 * Preferences live in the existing `users.meta` JSON column under the key
 * `datatable`, scoped by the stable table key. No new storage subsystem is
 * introduced. Every read is validated against the CURRENT table definition and
 * the columns the user is actually allowed to see, so a preference saved
 * before a column was removed, renamed, or lost by a permission change can
 * never widen access or crash the table — unknown ids are dropped, visibility
 * is intersected with permitted columns, and the page size is clamped to the
 * table's allowed sizes.
 *
 * Responsive hiding (narrow screens showing cards) is a RENDER concern handled
 * in the client and is deliberately NOT stored here: changing your phone
 * layout must never overwrite your desktop column choices.
 */
final class TablePreferences
{
    private const META_KEY = 'datatable';
    private const DENSITIES = ['comfortable', 'compact'];
    private const VIEWS = ['table', 'list', 'grid'];

    public function __construct(private UserService $users)
    {
    }

    /**
     * The effective preferences for a user and table, already reconciled with
     * the live definition. Safe to hand straight to the renderer.
     *
     * @param Column[] $permitted  Columns this user may see (from TableRegistry::columnsFor)
     * @return array{visible:string[],order:string[],page_size:int,density:string,sort:?string}
     */
    public function resolve(?int $userId, TableDefinition $table, array $permitted): array
    {
        $permittedIds = array_map(fn(Column $c) => $c->id, $permitted);
        $defaults = $this->defaults($table, $permitted);

        if ($userId === null) {
            return $defaults;
        }

        $all = $this->users->meta($userId, self::META_KEY, []);
        $saved = (is_array($all) && isset($all[$table->key]) && is_array($all[$table->key]))
            ? $all[$table->key] : [];

        return $this->reconcile($saved, $table, $permitted, $permittedIds, $defaults);
    }

    /**
     * Validate and store a client-supplied preference set for one table.
     * Returns the stored (reconciled) preferences, so the UI shows exactly
     * what the server kept.
     *
     * @param array<string,mixed> $incoming
     * @param Column[] $permitted
     */
    public function save(int $userId, TableDefinition $table, array $permitted, array $incoming): array
    {
        $permittedIds = array_map(fn(Column $c) => $c->id, $permitted);
        $defaults = $this->defaults($table, $permitted);
        $clean = $this->reconcile($incoming, $table, $permitted, $permittedIds, $defaults);

        $all = $this->users->meta($userId, self::META_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }
        // Keep at most a sane number of remembered tables per user.
        if (!isset($all[$table->key]) && count($all) >= 50) {
            array_shift($all);
        }
        $all[$table->key] = $clean;
        $this->users->setMeta($userId, self::META_KEY, $all);

        return $clean;
    }

    public function reset(int $userId, TableDefinition $table): void
    {
        $all = $this->users->meta($userId, self::META_KEY, []);
        if (is_array($all) && isset($all[$table->key])) {
            unset($all[$table->key]);
            $this->users->setMeta($userId, self::META_KEY, $all);
        }
    }

    /**
     * @param Column[] $permitted
     * @return array{visible:string[],order:string[],page_size:int,density:string,sort:?string}
     */
    private function defaults(TableDefinition $table, array $permitted): array
    {
        $order = array_map(fn(Column $c) => $c->id, $permitted);
        $visible = array_values(array_map(
            fn(Column $c) => $c->id,
            array_filter($permitted, fn(Column $c) => $c->defaultVisible),
        ));
        return [
            'visible'   => $visible,
            'order'     => $order,
            'page_size' => $table->defaultPageSize(),
            'density'   => 'comfortable',
            'view'      => 'table',
            'sort'      => $table->defaultSort !== '' ? $table->defaultSort : null,
        ];
    }

    /**
     * Fold saved values onto the defaults, dropping anything no longer valid.
     *
     * @param array<string,mixed> $saved
     * @param Column[]            $permitted
     * @param string[]            $permittedIds
     * @param array{visible:string[],order:string[],page_size:int,density:string,sort:?string} $defaults
     */
    private function reconcile(array $saved, TableDefinition $table, array $permitted, array $permittedIds, array $defaults): array
    {
        $permittedSet = array_flip($permittedIds);
        $byId = [];
        foreach ($permitted as $c) {
            $byId[$c->id] = $c;
        }

        // Order: saved ids that still exist, then any new permitted ids appended.
        $order = [];
        foreach ((array) ($saved['order'] ?? []) as $id) {
            if (is_string($id) && isset($permittedSet[$id]) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach ($permittedIds as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        // Visibility: saved visible ids that still exist; a non-hideable column
        // is always visible regardless of what was saved.
        $savedVisible = [];
        $hasSavedVisible = isset($saved['visible']) && is_array($saved['visible']);
        foreach ((array) ($saved['visible'] ?? []) as $id) {
            if (is_string($id) && isset($permittedSet[$id])) {
                $savedVisible[$id] = true;
            }
        }
        $visible = [];
        foreach ($order as $id) {
            $col = $byId[$id];
            $on = $hasSavedVisible ? isset($savedVisible[$id]) : $col->defaultVisible;
            if (!$col->canHide) {
                $on = true;
            }
            if ($on) {
                $visible[] = $id;
            }
        }

        // Page size: must be one the table allows.
        $pageSize = (int) ($saved['page_size'] ?? $defaults['page_size']);
        if (!$table->allowsPageSize($pageSize)) {
            $pageSize = $defaults['page_size'];
        }

        // Density.
        $density = in_array($saved['density'] ?? '', self::DENSITIES, true)
            ? (string) $saved['density'] : $defaults['density'];

        // View mode (table / list / grid).
        $view = in_array($saved['view'] ?? '', self::VIEWS, true)
            ? (string) $saved['view'] : $defaults['view'];

        // Sort: must be a token some permitted, sortable column declares.
        $sort = null;
        $candidate = $saved['sort'] ?? $defaults['sort'];
        if (is_string($candidate) && $candidate !== '') {
            foreach ($permitted as $c) {
                if (in_array($candidate, $c->sortable, true)) {
                    $sort = $candidate;
                    break;
                }
            }
            if ($sort === null && $candidate === $defaults['sort']) {
                $sort = $defaults['sort'];  // the table default is always allowed
            }
        }

        return [
            'visible'   => $visible,
            'order'     => $order,
            'page_size' => $pageSize,
            'density'   => $density,
            'view'      => $view,
            'sort'      => $sort,
        ];
    }
}
