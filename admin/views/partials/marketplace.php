<?php
/**
 * The marketplace — one page with an Apps tab and a Themes tab.
 *
 * Rendered by MarketplaceController at /admin/marketplace; $tab (from ?tab=)
 * says which one is open. The tabs are ordinary links, so the sidebar, the
 * address bar and the Back button all agree on where you are.
 *
 * Presentation lives in external files, not inline here:
 *   admin/assets/css/marketplace.css   the .mk-* styles + consent modal
 *   admin/assets/js/marketplace.js     browse / install / activate / consent
 * This partial only renders the shell and hands the script its configuration
 * through data- attributes on #mk-root. Icons in JS-built markup come from
 * window.BasehimIcon (icons.js, loaded in the admin <head>).
 *
 * Layout, top to bottom: tabs, search and sort, category chips, a featured
 * spotlight (first page, no filters), then the grid. App cards put a 120px
 * icon on the left and the details on the right; theme cards show the
 * screenshot on top. Clicking a card opens the details window
 * (partials/item-modal); installing then activating happens in place.
 */
$tab  = ($tab ?? 'apps') === 'themes' ? 'themes' : 'apps';
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
$ver  = defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1';
$isApps = $tab === 'apps';
?>

<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/marketplace.css?v=<?= urlencode((string) $ver) ?>">

<div class="mk-page" id="mk-root"
     data-base="<?= htmlspecialchars($base) ?>"
     data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-tab="<?= htmlspecialchars($tab) ?>">
    <div class="mk-top">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-slate-900">Marketplace</h2>
            <p class="text-sm text-slate-500">Apps and themes for your site. Every download is checksum-verified before it installs.</p>
        </div>
        <a href="<?= $base ?>/admin/<?= $tab ?>" class="inline-flex items-center gap-2 px-3 py-2 border border-slate-200 rounded-lg text-sm text-slate-600 hover:bg-slate-50 bg-white">
            <?= icon('arrow-left', 'w-4 h-4') ?> <?= $isApps ? 'Installed apps' : 'Installed themes' ?>
        </a>
    </div>

    <nav class="mk-tabs" aria-label="Marketplace">
        <a href="<?= $base ?>/admin/marketplace" class="mk-tab<?= $isApps ? ' is-on' : '' ?>"<?= $isApps ? ' aria-current="page"' : '' ?>>
            <?= icon('puzzle-piece', 'w-4 h-4') ?> Apps
        </a>
        <a href="<?= $base ?>/admin/marketplace?tab=themes" class="mk-tab<?= !$isApps ? ' is-on' : '' ?>"<?= !$isApps ? ' aria-current="page"' : '' ?>>
            <?= icon('swatch', 'w-4 h-4') ?> Themes
        </a>
    </nav>

    <div class="mk-bar">
        <label class="mk-search">
            <span class="mk-search__icon"><?= icon('magnifying-glass', 'w-4 h-4') ?></span>
            <input id="mk-q" type="search" placeholder="<?= $isApps ? 'Search apps by name, developer or tag' : 'Search themes by name, author or tag' ?>" aria-label="Search">
        </label>
        <select id="mk-sort" class="mk-select" aria-label="Sort">
            <option value="featured">Featured</option>
            <option value="popular">Most installed</option>
            <option value="newest">Newest</option>
            <option value="name">Name A–Z</option>
        </select>
    </div>

    <div id="mk-cats" class="mk-chips" role="toolbar" aria-label="Categories">
        <button type="button" class="mk-chip is-on" data-cat="">All</button>
    </div>
    <div id="mk-filter" class="hidden"></div>

    <div id="mk-status" class="hidden mb-4"></div>

    <section id="mk-spot" class="hidden" aria-label="Featured"></section>

    <div class="mk-head">
        <h3 id="mk-heading" class="text-base font-semibold text-slate-900"><?= $isApps ? 'All apps' : 'All themes' ?></h3>
        <span id="mk-count" class="text-sm text-slate-500"></span>
    </div>
    <div id="mk-grid" class="mk-grid<?= $isApps ? '' : ' mk-grid--themes' ?>" aria-live="polite"></div>
    <nav id="mk-pager" class="hidden items-center justify-center gap-3 mt-8"></nav>
</div>

<?php $this->include('partials.item-modal'); ?>

<script src="<?= $base ?>/admin/assets/js/marketplace.js?v=<?= urlencode((string) $ver) ?>" defer></script>
