<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Dashboard (1.2.68) — see DashboardController and docs/DASHBOARD-WIDGETS.md.
 *
 * This template prints the frame (greeting, Customize, the empty grid, the
 * Add Widgets panel) and the data dashboard.js builds the cards from. Card
 * contents are drawn in the browser with textContent, never from strings
 * pasted into markup; the only HTML here that comes from a widget is a
 * pre-1.2.68 widget's own render output, in its own hidden holder.
 */
$__v = urlencode((string) (@filemtime(BASEHIM_ROOT . '/admin/assets/js/dashboard.js') ?: BASEHIM_VERSION));
$__cv = urlencode((string) (@filemtime(BASEHIM_ROOT . '/admin/assets/css/dashboard.css') ?: BASEHIM_VERSION));
$__name = (string) ($currentUser['display_name'] ?? '') ?: (string) ($currentUser['username'] ?? 'there');

// Every icon the cards can use, rendered once by the PHP icon set (the JS set
// is a subset), so a widget's icon looks the same here as in the sidebar.
$__icons = [];
foreach ($catalog as $__w) {
    $__icons[$__w['icon']] = icon($__w['icon'], 'w-4 h-4');
    foreach ($__w['actions'] as $__a) {
        if ($__a['icon'] !== '') $__icons[$__a['icon']] = icon($__a['icon'], 'w-4 h-4');
    }
}
foreach (['arrow-path', 'chevron-down', 'ellipsis-vertical', 'cog-6-tooth', 'eye-slash', 'x-mark', 'plus',
          'bars-2', 'arrow-up', 'arrow-down', 'check', 'exclamation-triangle', 'information-circle', 'no-symbol',
          'x-circle', 'check-circle', 'question-mark-circle', 'arrow-uturn-left', 'magnifying-glass', 'arrow-top-right-on-square',
          'squares-plus', 'arrow-right'] as $__i) {
    $__icons[$__i] = icon($__i, 'w-4 h-4');
}

$__boot = [
    'layout'         => $layout,
    'catalog'        => $catalog,
    'sizes'          => \App\Core\WidgetRegistry::SIZES,
    'hasSiteDefault' => $hasSiteDefault,
    'canSetDefault'  => $canSetDefault,
    'icons'          => $__icons,
    'legacy'         => array_keys($legacyHtml),
];
?>
<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/dashboard.css?v=<?= $__cv ?>">

<div class="bh-dash" data-bh-dash>
    <header class="bh-dash-head">
        <div class="bh-dash-head__text">
            <h1 class="bh-dash-head__title">Welcome back, <?= htmlspecialchars($__name) ?></h1>
            <p class="bh-dash-head__sub"><?= htmlspecialchars((string) $siteName) ?></p>
        </div>
        <div class="bh-dash-head__actions" data-bh-dash-view-actions>
            <?php if ($canWrite): ?>
                <a href="<?= $base ?>/admin/posts/create" class="bh-btn bh-btn--secondary bh-btn--sm"><?= icon('pencil-square', 'w-4 h-4') ?> New post</a>
            <?php endif; ?>
            <?php if ($canUpload): ?>
                <a href="<?= $base ?>/admin/media" class="bh-btn bh-btn--secondary bh-btn--sm"><?= icon('arrow-up-tray', 'w-4 h-4') ?> Upload</a>
            <?php endif; ?>
            <button type="button" class="bh-btn bh-btn--primary bh-btn--sm" data-bh-dash-customize>
                <?= icon('adjustments-horizontal', 'w-4 h-4') ?> Customize
            </button>
        </div>
    </header>

    <div class="bh-dash-editbar" data-bh-dash-editbar hidden role="region" aria-label="Customize dashboard">
        <div class="bh-dash-editbar__text">
            <strong>Customizing your dashboard</strong>
            <span>Drag cards by their handle, or use the arrows. Changes apply when you save.</span>
        </div>
        <div class="bh-dash-editbar__actions">
            <button type="button" class="bh-btn bh-btn--secondary bh-btn--sm" data-bh-dash-add><?= icon('squares-plus', 'w-4 h-4') ?> Add widgets</button>
            <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-bh-dash-reset><?= icon('arrow-uturn-left', 'w-4 h-4') ?> Reset to default</button>
            <?php if ($canSetDefault): ?>
                <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-bh-dash-sitedefault>Use as site default</button>
            <?php endif; ?>
            <span class="bh-dash-editbar__sep" aria-hidden="true"></span>
            <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-bh-dash-cancel>Cancel</button>
            <button type="button" class="bh-btn bh-btn--primary bh-btn--sm" data-bh-dash-save>Save changes</button>
        </div>
    </div>

    <div class="bh-dash-grid" data-bh-dash-grid aria-label="Dashboard widgets"></div>

    <div class="bh-empty bh-dash-blank" data-bh-dash-blank hidden>
        <span class="bh-empty__icon"><?= icon('squares-2x2') ?></span>
        <p class="bh-empty__title">Your dashboard is empty</p>
        <p class="bh-empty__text">Add the widgets you want to see first when you sign in.</p>
        <div class="bh-empty__actions"><button type="button" class="bh-btn bh-btn--primary" data-bh-dash-add-blank>Add widgets</button></div>
    </div>

    <p class="bh-dash-sr" aria-live="polite" data-bh-dash-live></p>
    <noscript><p class="bh-dash-noscript">The dashboard needs JavaScript to load its widgets.</p></noscript>
</div>

<!-- Add widgets -->
<div class="bh-dash-drawer" data-bh-dash-drawer hidden>
    <div class="bh-dash-drawer__scrim" data-bh-dash-drawer-close></div>
    <aside class="bh-dash-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="bh-dash-drawer-title">
        <div class="bh-dash-drawer__head">
            <h2 id="bh-dash-drawer-title" class="bh-dash-drawer__title">Add widgets</h2>
            <button type="button" class="bh-dash-icon-btn" data-bh-dash-drawer-close aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
        </div>
        <div class="bh-dash-drawer__search">
            <label class="bh-dash-sr" for="bh-dash-search">Search widgets</label>
            <input id="bh-dash-search" type="search" class="bh-input bh-input--sm" placeholder="Search widgets" autocomplete="off" data-bh-dash-search>
        </div>
        <div class="bh-dash-drawer__body" data-bh-dash-catalog></div>
    </aside>
</div>

<!-- Widget settings -->
<dialog class="bh-dash-dialog" data-bh-dash-settings aria-labelledby="bh-dash-settings-title">
    <form method="dialog" class="bh-dash-dialog__form" data-bh-dash-settings-form novalidate>
        <div class="bh-dash-dialog__head">
            <h2 id="bh-dash-settings-title" class="bh-dash-dialog__title" data-bh-dash-settings-title>Widget settings</h2>
            <button type="button" class="bh-dash-icon-btn" data-bh-dash-settings-cancel aria-label="Close"><?= icon('x-mark', 'w-5 h-5') ?></button>
        </div>
        <div class="bh-dash-dialog__body" data-bh-dash-settings-fields></div>
        <div class="bh-dash-dialog__foot">
            <button type="button" class="bh-btn bh-btn--ghost" data-bh-dash-settings-cancel>Cancel</button>
            <button type="submit" class="bh-btn bh-btn--primary" data-bh-dash-settings-save>Save changes</button>
        </div>
    </form>
</dialog>

<?php // Pre-1.2.68 widgets' own HTML, moved into their cards by dashboard.js. ?>
<?php foreach ($legacyHtml as $__id => $__html): ?>
<div hidden data-bh-dash-legacy="<?= htmlspecialchars((string) $__id, ENT_QUOTES) ?>"><?= $__html ?></div>
<?php endforeach; ?>

<script>window.BH_DASHBOARD = <?= json_encode($__boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= $base ?>/admin/assets/js/dashboard.js?v=<?= $__v ?>"></script>

<?php
// Background update check — runs after the dashboard has painted so it never
// delays the page. The endpoint is throttled server-side, so firing it on every
// dashboard load is cheap and keeps the badge fresh without a manual click.
$__canUpdates = \App\Http\Middleware\CheckCapability::userCan($currentUser ?? null, 'manage_settings');
$__csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
?>
<?php if ($__canUpdates): ?>
<script>
(function () {
    // Wait for idle so we never compete with rendering.
    var start = function () {
        var body = new FormData();
        body.append('_csrf', <?= json_encode($__csrf) ?>);
        fetch(<?= json_encode($base . '/admin/updates/sync.json') ?>, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d || !d.ok) return;
            var badge = document.querySelector('[data-bh-badge="updates"]');
            if (!badge) return;
            var n = parseInt(d.count, 10) || 0;
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = n === 0;
        })
        .catch(function () { /* offline or service down — badge just stays as-is */ });
    };
    if ('requestIdleCallback' in window) {
        requestIdleCallback(start, { timeout: 3000 });
    } else {
        setTimeout(start, 800);
    }
})();
</script>
<?php endif; ?>

<?php $this->endSection(); ?>
