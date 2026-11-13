<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Appearance → Widgets → Widget areas (1.2.93).
 *
 * The page is a shell: admin/assets/js/widget-areas.js reads the state from
 * the widgets.* AJAX actions and draws the areas, their widgets and each
 * widget's settings form (from the widget's schema). Every change is saved
 * at once and answered with the new state.
 *
 * Without JavaScript the noscript note points to the plain list; the
 * pre-1.2.93 form routes under /admin/widgets/areas/... still work.
 */
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
// Icons the script draws, rendered here from the PHP set so none is missing.
$iconNames = ['bars-2', 'chevron-up', 'chevron-down', 'pencil-square', 'document-duplicate', 'trash', 'plus', 'eye',
    'exclamation-triangle', 'magnifying-glass', 'x-mark', 'arrow-uturn-left', 'puzzle-piece', 'view-columns', 'arrows-up-down'];
foreach ($available as $w) $iconNames[] = (string) ($w['icon'] ?? 'puzzle-piece');
foreach ($assignments as $list) foreach ((array) $list as $inst) {
    $d = $registry->get((string) ($inst['widget'] ?? ''));
    if ($d) $iconNames[] = (string) $d['icon'];
}
$icons = [];
foreach (array_unique($iconNames) as $n) $icons[$n] = icon($n, 'w-4 h-4');
$jsVer = @filemtime(BASEHIM_ROOT . '/admin/assets/js/widget-areas.js') ?: BASEHIM_VERSION;
?>

<div class="bhw-head">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Widget areas</h2>
        <p class="text-sm text-slate-500">Choose which widgets appear in each area of your theme, and in what order. Drag a widget by its handle, or focus the handle and use the arrow keys.</p>
    </div>
</div>

<?php $this->include('widgets._nav', ['base' => $base, 'active' => 'areas']); ?>

<noscript>
    <div class="bh-note bh-note--warn mb-5">This screen needs JavaScript. Turn it on to place and edit widgets.</div>
</noscript>

<div id="bhw-app" data-base="<?= htmlspecialchars($base, ENT_QUOTES) ?>" aria-live="polite">
    <div class="bh-card bh-card--pad text-sm text-slate-500" id="bhw-loading">Loading widget areas…</div>
</div>
<p id="bhw-status" class="bhw-sr" role="status" aria-live="assertive"></p>

<dialog id="bhw-picker" class="bhw-picker" aria-labelledby="bhw-picker-title">
    <div class="bhw-picker__head">
        <h2 id="bhw-picker-title" class="text-base font-semibold text-slate-900" style="flex:1">Add a widget</h2>
        <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm bh-btn--icon" data-close aria-label="Close"><?= icon('x-mark', 'w-4 h-4') ?></button>
    </div>
    <div class="bhw-picker__body">
        <div>
            <label class="bhw-sr" for="bhw-picker-search">Find a widget</label>
            <input id="bhw-picker-search" type="search" class="bh-input" placeholder="Find a widget" autocomplete="off">
        </div>
        <div id="bhw-picker-list"></div>
    </div>
</dialog>

<script>window.BH_WIDGET_ICONS = <?= json_encode($icons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= $base ?>/admin/assets/js/widget-areas.js?v=<?= urlencode((string) $jsVer) ?>" defer></script>
<?php $this->endSection(); ?>
