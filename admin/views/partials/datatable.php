<?php
/**
 * Shared Data Table partial. See docs/DATA-TABLE.md.
 *
 * Renders the toolbar, a server-rendered first page (works with no JavaScript
 * and paints instantly), and an embedded JSON config + payload that
 * admin/assets/js/datatable.js enhances.
 *
 * Variables: $tableKey, $bulkEndpoint, $editBase, optional $heading/$subheading,
 * $newUrl/$newLabel, $deleteConfirm, $bulkEvents (1.2.67: bulk actions are
 * handled by the page through the bh:dt-bulk event, not posted as a form).
 */

use App\Core\Application;
use App\Core\Table\TableProvider;
use App\Core\Table\TableRegistry;
use App\Core\Table\TableService;
use App\Services\AuthService;
use App\Services\TaxonomyService;

$__app  = Application::getInstance();
$__reg  = $__app->make(TableRegistry::class);
$__def  = $__reg->get($tableKey ?? '');
$base   = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';

if ($__def === null) {
    echo '<div class="bh-card bh-card--pad">Unknown table.</div>';
    return;
}

$__user = $__app->make(AuthService::class)->sessionUser();
$__csrf = $__app->make(\App\Core\Session::class)->csrfToken();
$__svc  = $__app->make(TableService::class);
$__provider = $__app->make($__def->provider);
if (method_exists($__provider, 'forTable')) $__provider->forTable($__def->key);
$__filterKeys = $__provider instanceof TableProvider ? $__provider->filterKeys() : [];

// First page from the current request (GET) — a bookmarked, filtered URL and a
// no-JS browser both get the right data. Status is a single value; category and
// tag accept a comma list of term ids (multi-select).
$__filters = [];
// Every single-value filter a column declares (1.2.101, BH-109; only
// `status` before), read from the address so a reload or a shared link keeps
// it. The table service still checks each value against the column's set.
$__scalarFilters = ['status'];
foreach ($__reg->columnsFor($__def->key, $__user) as $__col) {
    $__f = $__col->filterSpec();
    if ($__f !== null && !in_array($__f['field'], ['category', 'tag'], true)) $__scalarFilters[] = $__f['field'];
}
foreach (array_unique($__scalarFilters) as $__sf) {
    if (in_array($__sf, $__filterKeys, true) && isset($_GET[$__sf]) && is_string($_GET[$__sf]) && $_GET[$__sf] !== '') {
        $__filters[$__sf] = (string) $_GET[$__sf];
    }
}
foreach (['category', 'tag'] as $__tf) {
    if (!in_array($__tf, $__filterKeys, true) || !isset($_GET[$__tf]) || $_GET[$__tf] === '') {
        continue;
    }
    $__raw = is_array($_GET[$__tf]) ? $_GET[$__tf] : explode(',', (string) $_GET[$__tf]);
    $__ids = [];
    foreach ($__raw as $__v) { $__id = (int) $__v; if ($__id > 0) { $__ids[] = (string) $__id; } }
    if ($__ids !== []) { $__filters[$__tf] = $__ids; }
}
$__payload = $__svc->build($__def, $__user, [
    'page'    => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    'search'  => isset($_GET['q']) ? (string) $_GET['q'] : '',
    'sort'    => isset($_GET['sort']) ? (string) $_GET['sort'] : null,
    'filters' => $__filters,
]);

// Term filters (category / tag): the initial most-used terms the client shows
// before any search, plus the taxonomy's total so the client can decide between
// an offline filter and a live AJAX search. Defaults: 30 categories, 50 tags.
$__termFilters = [];
$__termLimits = ['category' => 30, 'tag' => 50];
$__labels = ['category' => 'Categories', 'tag' => 'Tags'];
$__taxSvc = $__app->make(TaxonomyService::class);
foreach (['category', 'tag'] as $__tf) {
    if (!in_array($__tf, $__filterKeys, true)) {
        continue;
    }
    try {
        $__sel = $__filters[$__tf] ?? [];
        $__termFilters[$__tf] = [
            'label' => $__labels[$__tf],
            'limit' => $__termLimits[$__tf],
            'terms' => $__taxSvc->searchTerms($__tf, '', $__termLimits[$__tf], array_map('intval', $__sel)),
            'total' => $__taxSvc->countTermsBySlug($__tf),
        ];
    } catch (\Throwable) {}
}

$__cfg = [
    'table'        => $__def->key,
    'csrf'         => $__csrf,
    'base'         => $base,
    'bulkEndpoint' => $bulkEndpoint ?? '',
    'editBase'     => $editBase ?? '',
    'deleteConfirm'=> $deleteConfirm ?? '',
    // The host screen runs bulk actions itself, from the bh:dt-bulk event (1.2.67).
    'bulkEvents'   => !empty($bulkEvents),
    'filters'      => $__termFilters,
    'initial'      => $__payload,
];

// A menu for each catalog column whose filter has a fixed set of values
// (1.2.101, BH-109): status first, as before, then the others in column order.
$__menus = [];
foreach ($__payload['catalog'] as $__c) {
    $__f = $__c['filter'] ?? null;
    if (empty($__f['in']) || in_array($__f['field'] ?? '', ['category', 'tag'], true) || isset($__menus[$__f['field']])) continue;
    $__menus[$__f['field']] = $__c;
}
if (isset($__menus['status'])) $__menus = ['status' => $__menus['status']] + $__menus;
$__statusCol = $__menus['status'] ?? null;   // kept for anything that read it

$__bulkMeta = [
    'publish' => ['Publish', 'publish'], 'draft' => ['Unpublish', 'neutral'], 'delete' => ['Delete', 'danger'],
    'approve' => ['Approve', 'publish'], 'pending' => ['Unapprove', 'neutral'], 'spam' => ['Spam', 'danger'],
];
?>
<?php if (!empty($heading)): ?>
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($heading) ?></h2>
        <?php if (!empty($subheading)): ?><p class="text-sm text-slate-500"><?= htmlspecialchars($subheading) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($newUrl)): ?>
    <a href="<?= htmlspecialchars($newUrl) ?>" class="bh-btn bh-btn--primary"><?= icon('plus', 'w-4 h-4') ?> <?= htmlspecialchars($newLabel ?? 'New') ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Selection only where there is something to do with it (1.2.102): a table
// with no bulk action (Users: one person at a time) shows no checkboxes.
$__declared = (array) ($__payload['actions'] ?? []);
$__selectable = $__declared
    ? (bool) array_filter($__declared, static fn($a) => !empty($a['bulk']) && empty($a['href']) && empty($a['event']))
    : !empty($__payload['bulk_actions']);
?>
<div class="bh-dt bh-card overflow-visible" data-select="<?= $__selectable ? 'many' : 'none' ?>" data-density="<?= htmlspecialchars($__payload['prefs']['density']) ?>" data-view="<?= htmlspecialchars($__payload['prefs']['view'] ?? 'table') ?>">
    <script type="application/json" data-dt-config><?= json_encode($__cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <!-- Toolbar -->
    <div class="bh-card--pad bh-dt-top">
        <div class="bh-dt-toolbar">
            <!-- Phones/tablets: a compact Select all that sits in the single
                 toolbar row (1.2.108); hidden on desktop and when selection is
                 off. The table header / cards keep their own in sync. -->
            <label class="bh-dt-selall" data-dt-selall hidden title="Select all">
                <input type="checkbox" data-dt-selall-input aria-label="Select all">
            </label>
            <!-- Touch screens (1.2.116): no ticks at rest; this enters select
                 mode (so does a long press on an item). Hidden elsewhere by CSS. -->
            <button type="button" class="bh-btn bh-btn--secondary bh-btn--icon bh-dt-selectmode" data-dt-selectmode hidden aria-pressed="false" aria-label="Select items" title="Select"><?= icon('check-circle', 'w-4 h-4') ?></button>
            <label class="bh-dt-search">
                <?= icon('magnifying-glass') ?>
                <input type="search" class="bh-input" data-dt-search placeholder="<?= htmlspecialchars($__def->searchPlaceholder) ?>" value="<?= htmlspecialchars($__payload['search']) ?>">
            </label>
            <?php foreach (['category', 'tag'] as $__tf): ?>
            <?php if (isset($__termFilters[$__tf])): ?>
            <div data-dt-termfilter="<?= $__tf ?>" data-dt-label="<?= htmlspecialchars($__termFilters[$__tf]['label']) ?>"></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($__menus as $__field => $__mc): $__mf = $__mc['filter']; ?>
            <select class="bh-select bh-select--auto" data-dt-filter="<?= htmlspecialchars((string) $__field) ?>" aria-label="<?= htmlspecialchars($__mc['label']) ?>">
                <option value=""><?= htmlspecialchars($__mf['all'] ?? ($__field === 'status' ? 'All status' : 'All: ' . $__mc['label'])) ?></option>
                <?php foreach ($__mf['in'] as $__opt): ?>
                <option value="<?= htmlspecialchars($__opt) ?>" <?= (($__filters[$__field] ?? '') === $__opt) ? 'selected' : '' ?>><?= htmlspecialchars($__mf['labels'][$__opt] ?? ucfirst($__opt)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endforeach; ?>
            <div class="bh-dt-tools">
                <div class="bh-seg bh-seg--icon bh-dt-views" role="group" aria-label="View">
                    <button type="button" class="bh-dt-viewbtn" data-dt-view="table" aria-pressed="false" aria-label="Table view" title="Table view"><?= icon('table-cells', 'w-4 h-4') ?></button>
                    <button type="button" class="bh-dt-viewbtn" data-dt-view="list" aria-pressed="false" aria-label="List view" title="List view"><?= icon('list-bullet', 'w-4 h-4') ?></button>
                    <button type="button" class="bh-dt-viewbtn" data-dt-view="grid" aria-pressed="false" aria-label="Grid view" title="Grid view"><?= icon('squares-2x2', 'w-4 h-4') ?></button>
                </div>
                <!-- Phones/tablets: one button opens the filter & sort drawer
                     (built by datatable.js); hidden on desktop by CSS. -->
                <button type="button" class="bh-btn bh-btn--secondary bh-dt-filterm" data-dt-drawer-open aria-haspopup="dialog" aria-expanded="false" aria-label="Filter and sort"><?= icon('sliders', 'w-4 h-4') ?> <span class="bh-dt-filterm__text">Filter &amp; sort</span><span class="bh-dt-filterm__badge" data-dt-drawer-badge hidden>0</span></button>
                <button type="button" class="bh-btn bh-btn--secondary" data-dt-options aria-haspopup="true" aria-expanded="false" aria-label="Screen Options" title="Screen Options"><?= icon('cog-6-tooth', 'w-4 h-4') ?> <span class="bh-dt-options__label">Screen Options</span></button>
            </div>
            <div class="bh-dt-panel" data-dt-panel></div>
        </div>

        <!-- Selection / bulk bar: floats over the content (1.2.104); the script
             moves it to <body> and builds the buttons to match the selection. -->
        <div class="bh-dt-bulk" data-dt-bulk role="region" aria-label="Bulk actions">
            <span class="bh-dt-bulk__count" data-dt-bulk-count aria-live="polite"></span>
            <span class="bh-dt-bulk__sep" aria-hidden="true"></span>
            <div class="bh-dt-bulk__actions" data-dt-bulk-actions></div>
            <button type="button" class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-dt-bulkclear" data-dt-bulk-clear aria-label="Clear selection" title="Clear selection"><?= icon('x-mark', 'w-4 h-4') ?></button>
        </div>
    </div>

    <!-- Table (desktop) + cards (mobile) -->
    <div class="bh-dt-wrap">
        <div class="bh-dt-loading"><span class="bh-dt-spinner"></span></div>
        <table class="bh-dt-table">
            <thead data-dt-head>
                <tr>
                    <th class="bh-dt-check"><input type="checkbox" data-dt-all></th>
                    <?php foreach ($__payload['columns'] as $__c): ?>
                    <th class="<?= $__c['id'] === 'thumb' ? 'bh-dt-col-thumb ' : '' ?><?= $__c['align'] === 'right' ? 'bh-dt-al-right' : ($__c['align'] === 'center' ? 'bh-dt-al-center' : '') ?>"><?= $__c['id'] === 'thumb' ? '' : htmlspecialchars($__c['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="bh-dt-rowactions"></th>
                </tr>
            </thead>
            <tbody data-dt-body>
                <?php if (empty($__payload['rows'])): ?>
                <?php
                    // The same empty state datatable.js draws (1.2.66).
                    $__noun = strtolower($__def->label) ?: 'items';
                    $__filtered = $__payload['search'] !== '' || $__filters !== [];
                    $__own = $__def->empty;   // the table's own empty state (1.2.122)
                    $__path = $__filtered ? 'M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z'
                        : (($__own['icon'] ?? '') === 'trash' ? 'M6 7.5h12M9.75 7.5V6a1.5 1.5 0 011.5-1.5h1.5A1.5 1.5 0 0114.25 6v1.5m-7 0 .64 11a1.5 1.5 0 001.5 1.4h5.22a1.5 1.5 0 001.5-1.4l.64-11' : 'M4 8l2-4h12l2 4v8a2 2 0 01-2 2H6a2 2 0 01-2-2V8zM4 12h4l2 3h4l2-3h4');
                    $__emptyTitle = $__filtered ? 'No matches' : ($__own['title'] ?? 'Nothing here yet');
                    $__emptyText = $__filtered ? 'No ' . $__noun . ' match the current search or filters.' : ($__own['text'] ?? 'There are no ' . $__noun . ' to show yet.');
                ?>
                <tr><td colspan="99"><div class="bh-dt-state bh-dt-state--empty" role="status"><span class="bh-dt-state__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $__path ?>"/></svg></span><p class="bh-dt-state__title"><?= htmlspecialchars($__emptyTitle) ?></p><p class="bh-dt-state__text"><?= htmlspecialchars($__emptyText) ?></p></div></td></tr>
                <?php else: foreach ($__payload['rows'] as $__row): ?>
                <tr data-dt-row="<?= (int) $__row['id'] ?>">
                    <td class="bh-dt-check"><input type="checkbox" class="bh-dt-rowcheck"></td>
                    <?php foreach ($__payload['columns'] as $__c): ?>
                    <td class="<?= $__c['id'] === 'views' ? 'bh-dt-col-views ' : '' ?><?= $__c['align'] === 'right' ? 'bh-dt-al-right' : ($__c['align'] === 'center' ? 'bh-dt-al-center' : '') ?>"><?= $__row['cells'][$__c['id']] ?? '' ?></td>
                    <?php endforeach; ?>
                    <td class="bh-dt-rowactions">
                        <?php if (!empty($editBase)): ?><a href="<?= htmlspecialchars(str_replace('{id}', (string) $__row['id'], $editBase)) ?>" title="Edit"><?= icon('pencil-square', 'w-4 h-4') ?></a><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <div class="bh-dt-cards" data-dt-cards></div>
    </div>

    <!-- Footer / pagination -->
    <?php $__m = $__payload['meta']; ?>
    <div class="bh-dt-foot" data-dt-foot>
        <span class="bh-dt-count" data-dt-count>Showing <?= $__m['total'] ? (($__m['page'] - 1) * $__m['per_page'] + 1) : 0 ?>–<?= min($__m['page'] * $__m['per_page'], $__m['total']) ?> of <?= (int) $__m['total'] ?> <?= htmlspecialchars(strtolower($__def->label) ?: 'results') ?></span>
        <div class="bh-dt-foot__right">
            <div class="bh-dt-pages" data-dt-pages></div>
            <label class="bh-dt-perpage">Rows per page
                <select class="bh-select bh-select--auto bh-select--sm" data-dt-perpage>
                    <?php foreach ($__def->pageSizes as $__ps): ?>
                    <option value="<?= (int) $__ps ?>" <?= ($__ps === (int) $__payload['prefs']['page_size']) ? 'selected' : '' ?>><?= (int) $__ps ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>
</div>

<?php
// Cache-bust on each asset's modification time, so a changed file is picked up
// immediately without a release bump or a hard refresh.
$__cssVer = @filemtime(BASEHIM_ROOT . '/admin/assets/css/datatable.css') ?: BASEHIM_VERSION;
$__jsVer  = @filemtime(BASEHIM_ROOT . '/admin/assets/js/datatable.js') ?: BASEHIM_VERSION;
?>
<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/datatable.css?v=<?= urlencode((string) $__cssVer) ?>">
<?php if ($__def->quickEdit !== null): $__pfVer = @filemtime(BASEHIM_ROOT . '/admin/assets/js/post-fields.js') ?: BASEHIM_VERSION; ?>
<?php // Quick Edit draws Categories, Tags and Publish with the block editor's components (1.2.62). ?>
<script src="<?= $base ?>/admin/assets/js/post-fields.js?v=<?= urlencode((string) $__pfVer) ?>"></script>
<?php endif; ?>
<script src="<?= $base ?>/admin/assets/js/datatable.js?v=<?= urlencode((string) $__jsVer) ?>"></script>
