<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Table\TableDefinition;
use App\Core\Table\TablePreferences;
use App\Core\Table\TableRegistry;
use App\Core\Table\TableService;
use App\Http\Middleware\AdminAreaPolicy;
use App\Http\Middleware\CheckCapability;

/**
 * The Data Table gateway handlers (registered in routes/ajax.php).
 *
 * One read action serves every registered table; writes stay on each
 * feature's existing, CSRF-protected routes (e.g. /admin/posts/bulk), so the
 * ownership and publish rules that already live there are never bypassed.
 *
 * The AJAX action is registered with capability 'read'. The REAL gate is the
 * table's own capability and the admin area it stands in for, resolved here
 * through AdminAreaPolicy::requiredFor() so a site's admin.area_policy
 * overrides apply — the action is never more open than the screen.
 */
final class TableActions
{
    public function __construct(
        private TableRegistry $registry,
        private TableService $service,
        private TablePreferences $preferences,
    ) {
    }

    /** GET content.table — one page of a table's rows. */
    public function query(AjaxContext $ctx): array
    {
        $table = $this->authorizedTable($ctx);

        $params = [
            'page'     => $ctx->input('page'),
            'per_page' => $ctx->input('per_page'),
            'sort'     => $ctx->input('sort'),
            'search'   => $ctx->input('search'),
            'filters'  => $this->decodeObject((string) $ctx->input('filters', '')),
            'columns'  => $this->decodeList((string) $ctx->input('columns', '')),
        ];

        return $this->service->build($table, $ctx->user(), $params);
    }

    /**
     * GET content.table_terms — terms of a taxonomy for a multi-select filter.
     *
     * Returns the most-used terms first (with an optional search), plus the
     * taxonomy's total term count so the client can decide between searching
     * offline (everything is already loaded) and searching live over AJAX.
     */
    public function terms(AjaxContext $ctx): array
    {
        // Gate on the same capability as the posts/pages tables this feeds.
        $user = $ctx->user();
        if (!CheckCapability::userCan($user, 'edit_posts')
            && !CheckCapability::userCan($user, 'edit_pages')) {
            throw AjaxException::forbidden();
        }

        $taxonomy = (string) $ctx->input('taxonomy', '');
        if (!in_array($taxonomy, ['category', 'tag'], true)) {
            throw AjaxException::invalid('Unknown taxonomy.');
        }

        $q = trim((string) $ctx->input('q', ''));
        $limit = (int) $ctx->input('limit', 30);
        if ($limit < 1) {
            $limit = 30;
        }
        $limit = min($limit, 200);

        $ids = [];
        foreach (explode(',', (string) $ctx->input('selected', '')) as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $tax = \App\Core\Application::getInstance()->make(\App\Services\TaxonomyService::class);

        return [
            'taxonomy' => $taxonomy,
            'terms'    => $tax->searchTerms($taxonomy, $q, $limit, $ids),
            'total'    => $tax->countTermsBySlug($taxonomy),
        ];
    }

    /** POST table.prefs — save this user's view preferences for a table. */
    public function savePrefs(AjaxContext $ctx): array
    {
        $table = $this->authorizedTable($ctx);
        $userId = $ctx->userId();
        if ($userId === null) {
            throw AjaxException::forbidden();
        }
        $permitted = $this->registry->columnsFor($table->key, $ctx->user());

        $incoming = $this->decodeObject((string) $ctx->input('prefs', ''));
        $stored = $this->preferences->save($userId, $table, $permitted, $incoming);

        return ['prefs' => $stored];
    }

    /** POST table.prefs_reset — forget a user's saved preferences for a table. */
    public function resetPrefs(AjaxContext $ctx): array
    {
        $table = $this->authorizedTable($ctx);
        $userId = $ctx->userId();
        if ($userId === null) {
            throw AjaxException::forbidden();
        }
        $this->preferences->reset($userId, $table);
        $permitted = $this->registry->columnsFor($table->key, $ctx->user());
        return ['prefs' => $this->preferences->resolve($userId, $table, $permitted)];
    }

    /**
     * GET content.quick_edit — the Quick Edit form for one row (1.2.61).
     *
     * The table's capability and area are checked first (authorizedTable),
     * then the table's QuickEditor checks the record itself: its type, whose
     * it is, and which fields this user may change.
     */
    public function quickEditForm(AjaxContext $ctx): array
    {
        [$table, $editor] = $this->quickEditor($ctx);
        $user = $ctx->user();
        return ['form' => $editor->form((int) $ctx->input('id'), $user)];
    }

    /**
     * POST content.quick_edit_save — save the changed fields and return the
     * row re-rendered with the table's own columns, so the client replaces it
     * in place without reloading the table (1.2.61).
     */
    public function quickEditSave(AjaxContext $ctx): array
    {
        [$table, $editor] = $this->quickEditor($ctx);
        $user = $ctx->user();
        $values = json_decode((string) $ctx->input('values'), true, 6);
        if (!is_array($values) || array_is_list($values) && $values !== []) {
            throw AjaxException::invalid('The changes could not be read.', ['values' => ['Send the changed fields as a JSON object.']]);
        }
        $result = $editor->save((int) $ctx->input('id'), $values, $user, [
            'version' => (string) $ctx->input('version', ''),
            'confirm' => (bool) $ctx->input('confirm', false),
        ]);

        $columnIds = $this->decodeList((string) $ctx->input('columns', ''));
        if ($columnIds === []) {
            $permitted = $this->registry->columnsFor($table->key, $user);
            $columnIds = $this->preferences->resolve($ctx->userId(), $table, $permitted)['visible'];
        }
        $ctx->log('Quick edit', ['table' => $table->key, 'id' => (int) $ctx->input('id'), 'fields' => array_keys($values)]);

        return [
            'row'     => $this->service->renderRow($table, $user, $result['row'], $columnIds),
            'version' => $result['version'],
            'notices' => $result['notices'],
        ];
    }

    /** @return array{0:TableDefinition,1:\App\Core\Table\QuickEditor} */
    private function quickEditor(AjaxContext $ctx): array
    {
        $table = $this->authorizedTable($ctx);
        if ($table->quickEdit === null) {
            throw AjaxException::notFound('This table has no Quick Edit.');
        }
        /** @var \App\Core\Table\QuickEditor $editor */
        $editor = \App\Core\Application::getInstance()->make($table->quickEdit);
        return [$table, $editor];
    }

    /**
     * Resolve the requested table and enforce its capability and area. A
     * table nobody registered is a 404; a table this user may not see is a 403
     * — the same answers the screen itself would give.
     */
    private function authorizedTable(AjaxContext $ctx): TableDefinition
    {
        $key = (string) $ctx->input('table', '');
        $table = $this->registry->get($key);
        if ($table === null) {
            throw AjaxException::notFound('No such table.');
        }

        $user = $ctx->user();
        if (!CheckCapability::userCan($user, $table->capability)) {
            throw AjaxException::forbidden();
        }
        if ($table->area !== null) {
            $areaCap = AdminAreaPolicy::requiredFor($table->area);
            if ($areaCap !== null && !CheckCapability::userCan($user, $areaCap)) {
                throw AjaxException::forbidden();
            }
        }
        return $table;
    }

    /** Decode a JSON object input into an associative array, or []. */
    private function decodeObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 6, JSON_BIGINT_AS_STRING);
        return is_array($decoded) ? $decoded : [];
    }

    /** Decode a JSON array-of-strings input into a list, or []. */
    private function decodeList(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 4);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }
}
