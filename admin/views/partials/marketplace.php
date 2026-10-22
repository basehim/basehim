<?php
/**
 * The marketplace: one page with an Apps tab and a Themes tab.
 *
 * MarketplaceController renders it at /admin/marketplace, and $tab (from
 * ?tab=) says which tab is open. The tabs are ordinary links, so the sidebar,
 * the address bar and the Back button all agree on where you are.
 *
 * 1.2.84: built from the core components in admin/assets/css/src/tailwind.css
 * (.bh-mk-*, .bh-btn, .bh-badge, .bh-input, .bh-dskel, .bh-empty), with the
 * same header panel and cards as the dashboard. There is no stylesheet of
 * its own any more, and no colours outside the theme's accent and slate.
 * admin/assets/js/marketplace.js talks to the AJAX gateway (apps.market_* and
 * themes.market_*) through Basehim.ajax. Icons in markup the script builds
 * come from window.BasehimIcon.
 */
$tab       = ($tab ?? 'apps') === 'themes' ? 'themes' : 'apps';
$canThemes = (bool) ($canThemes ?? true);
$base      = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$ver       = defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1';
$isApps    = $tab === 'apps';
$noun      = $isApps ? 'apps' : 'themes';
?>
<div class="bh-mk" id="mk-root" data-tab="<?= htmlspecialchars($tab) ?>" data-base="<?= htmlspecialchars($base) ?>">

    <header class="bh-mk-head">
        <div class="bh-mk-head__text">
            <span class="bh-mk-head__icon" aria-hidden="true"><?= icon('building-storefront', 'w-5 h-5') ?></span>
            <div class="min-w-0">
                <h2 class="bh-mk-head__title">Marketplace</h2>
                <p class="bh-mk-head__sub">Apps and themes for your site. Every download is checksum-verified before it installs.</p>
            </div>
        </div>
        <a href="<?= $base ?>/admin/<?= $isApps ? 'apps' : 'themes' ?>" class="bh-btn bh-btn--secondary bh-mk-head__back">
            <?= icon($isApps ? 'puzzle-piece' : 'swatch', 'w-4 h-4') ?> <span>Installed <?= $noun ?></span>
        </a>
    </header>

    <div class="bh-mk-bar">
        <?php if ($canThemes): ?>
        <nav class="bh-mk-tabs" aria-label="Marketplace">
            <a href="<?= $base ?>/admin/marketplace" class="bh-mk-tab<?= $isApps ? ' is-on' : '' ?>"<?= $isApps ? ' aria-current="page"' : '' ?>>
                <?= icon('puzzle-piece', 'w-4 h-4') ?> Apps
            </a>
            <a href="<?= $base ?>/admin/marketplace?tab=themes" class="bh-mk-tab<?= !$isApps ? ' is-on' : '' ?>"<?= !$isApps ? ' aria-current="page"' : '' ?>>
                <?= icon('swatch', 'w-4 h-4') ?> Themes
            </a>
        </nav>
        <?php endif; ?>
        <label class="bh-mk-search">
            <span class="bh-mk-search__icon"><?= icon('magnifying-glass', 'w-4 h-4') ?></span>
            <input id="mk-q" type="search" class="bh-input" autocomplete="off"
                   placeholder="<?= $isApps ? 'Search apps' : 'Search themes' ?>" aria-label="Search <?= $noun ?>">
        </label>
        <select id="mk-sort" class="bh-select bh-mk-sort" aria-label="Sort">
            <option value="featured">Featured</option>
            <option value="popular">Most installed</option>
            <option value="newest">Newest</option>
            <option value="name">Name A–Z</option>
        </select>
    </div>

    <div id="mk-filters" class="bh-mk-filters" hidden>
        <div id="mk-cats" class="bh-mk-chips" role="toolbar" aria-label="Categories">
            <button type="button" class="bh-mk-chip is-on" data-cat="" aria-pressed="true">All</button>
        </div>
        <div id="mk-filter" class="bh-mk-tagfilter" hidden></div>
    </div>

    <section id="mk-spot" class="bh-mk-spot" aria-label="Featured" hidden></section>

    <div class="bh-mk-section">
        <h3 id="mk-heading" class="bh-mk-section__title"><?= $isApps ? 'All apps' : 'All themes' ?></h3>
        <span id="mk-count" class="bh-badge bh-badge--gray" hidden></span>
    </div>
    <div id="mk-grid" class="bh-mk-grid<?= $isApps ? '' : ' bh-mk-grid--themes' ?>" aria-live="polite" aria-busy="true"></div>
    <nav id="mk-pager" class="bh-mk-pager" aria-label="Pages" hidden></nav>
</div>

<?php $this->include('partials.item-modal'); ?>

<script src="<?= $base ?>/admin/assets/js/marketplace.js?v=<?= urlencode((string) $ver) ?>" defer></script>
