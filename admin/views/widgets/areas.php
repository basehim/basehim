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
<style>
/* Layout for this screen only; colours are the admin's slate and accent. */
.bhw-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:20px}
.bhw-grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));align-items:start}
.bhw-area .bh-card__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.bhw-key{font:500 11px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:#94a3b8;margin-left:6px}
.bhw-count{font-size:12px;color:#64748b;white-space:nowrap}
.bhw-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px;min-height:48px;border-radius:10px}
.bhw-list.is-over{outline:2px dashed #93c5fd;outline-offset:4px}
.bhw-empty{border:1px dashed #cbd5e1;border-radius:10px;padding:14px;text-align:center;font-size:13px;color:#64748b}
.bhw-item{border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.bhw-item.is-dragging{opacity:.45}
.bhw-item.is-off .bhw-row__name{color:#94a3b8}
.bhw-row{display:flex;align-items:center;gap:8px;padding:8px 10px;min-width:0}
.bhw-handle{cursor:grab;color:#94a3b8}
.bhw-handle:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.bhw-row__icon{color:#64748b;flex:0 0 auto;display:inline-flex}
.bhw-row__text{flex:1 1 auto;min-width:0}
.bhw-row__name{font-size:14px;font-weight:600;color:#0f172a;overflow-wrap:anywhere}
.bhw-row__label{font-size:12px;color:#64748b;overflow-wrap:anywhere}
.bhw-row__badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:2px}
.bhw-row__actions{display:flex;align-items:center;gap:4px;flex:0 0 auto}
.bhw-panel{border-top:1px solid #f1f5f9;padding:14px 12px 12px;display:grid;gap:14px}
.bhw-field{display:grid;gap:4px}
.bhw-field[hidden]{display:none}
.bhw-field .bh-switch{justify-self:start}
.bhw-inline{display:flex;align-items:center;gap:10px}
.bhw-checks{display:grid;gap:4px;max-height:180px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px}
.bhw-checks label{display:flex;gap:8px;align-items:center;font-size:13px;color:#334155}
.bhw-error{font-size:12px;color:#dc2626}
.bhw-locked{font-size:12px;color:#64748b}
.bhw-options{border-top:1px dashed #e2e8f0;padding-top:12px;display:grid;gap:12px}
.bhw-buttons{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bhw-preview{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;background:#fff}
.bhw-preview iframe{display:block;width:100%;border:0;min-height:120px;background:#fff}
.bhw-preview__note{font-size:12px;color:#64748b;padding:8px 10px;border-top:1px solid #f1f5f9}
.bhw-picker{width:min(640px,calc(100vw - 32px));max-height:min(80vh,720px);padding:0;border:0;border-radius:14px;box-shadow:0 20px 50px rgba(15,23,42,.25)}
.bhw-picker::backdrop{background:rgba(15,23,42,.45)}
.bhw-picker__head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #f1f5f9;position:sticky;top:0;background:#fff;z-index:1}
.bhw-picker__body{padding:12px 16px 16px;display:grid;gap:16px}
.bhw-picker__group h3{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin:0 0 6px}
.bhw-picker__items{display:grid;gap:6px;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr))}
.bhw-pick{display:flex;gap:10px;align-items:flex-start;text-align:left;border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#fff;cursor:pointer;width:100%}
.bhw-pick:hover:not([aria-disabled=true]),.bhw-pick:focus-visible{border-color:#2563eb;background:#eff6ff;outline:none}
.bhw-pick[aria-disabled=true]{cursor:not-allowed;opacity:.6}
.bhw-pick__title{font-size:14px;font-weight:600;color:#0f172a}
.bhw-pick__text{font-size:12px;color:#64748b}
.bhw-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (max-width:640px){
  .bhw-row{flex-wrap:wrap}
  .bhw-row__actions{width:100%;justify-content:flex-end}
  .bhw-row .bh-btn--icon{width:44px;height:44px}
  .bhw-panel .bh-input,.bhw-panel .bh-select,.bhw-panel .bh-textarea{font-size:16px}
}
</style>

<div class="bhw-head">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Widget areas</h2>
        <p class="text-sm text-slate-500">Choose which widgets appear in each area of your theme, and in what order. Drag a widget by its handle, or focus the handle and use the arrow keys.</p>
    </div>
</div>

<div class="flex items-center gap-1 mb-5 text-sm">
    <a href="<?= $base ?>/admin/widgets" class="px-3 py-1.5 rounded-lg text-slate-500 hover:bg-slate-100">Registered widgets</a>
    <a href="<?= $base ?>/admin/widgets/areas" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-medium" aria-current="page">Widget areas</a>
</div>

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
