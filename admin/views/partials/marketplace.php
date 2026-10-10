<?php
/**
 * The marketplace — one page with an Apps tab and a Themes tab.
 *
 * Both /admin/apps/marketplace and /admin/themes/marketplace render this
 * partial; $tab says which one is open. The tabs are ordinary links, so the
 * sidebar, the address bar and the Back button all agree on where you are.
 *
 * Layout, top to bottom: tabs, search and sort, category chips, a featured
 * spotlight (first page, no filters), then the grid. App cards put a
 * 120px icon on the left and the details on the right; theme cards show
 * the screenshot on top.
 * Clicking anything opens the details window (partials/item-modal).
 */
$tab  = ($tab ?? 'apps') === 'themes' ? 'themes' : 'apps';
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$csrf = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken();
$isApps = $tab === 'apps';
?>

<div class="mk-page">
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
        <a href="<?= $base ?>/admin/apps/marketplace" class="mk-tab<?= $isApps ? ' is-on' : '' ?>"<?= $isApps ? ' aria-current="page"' : '' ?>>
            <?= icon('puzzle-piece', 'w-4 h-4') ?> Apps
        </a>
        <a href="<?= $base ?>/admin/themes/marketplace" class="mk-tab<?= !$isApps ? ' is-on' : '' ?>"<?= !$isApps ? ' aria-current="page"' : '' ?>>
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

<style>
/* Marketplace. The prebuilt admin stylesheet has no utilities for most of
   this (aspect ratios, auto-fill grids, scroll chips), so it lives here. */
.mk-page { --mk-accent: #0f766e; --mk-accent-soft: #ccfbf1; max-width: 90rem; }
.mk-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.1rem; }

.mk-tabs { display: inline-flex; gap: .25rem; padding: .25rem; background: #e2e8f0; border-radius: .8rem; margin-bottom: 1rem; }
.mk-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .5rem 1.1rem; border-radius: .6rem; font-size: .875rem; font-weight: 600; color: #475569; }
.mk-tab:hover { color: #0f172a; }
.mk-tab.is-on { background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(15, 23, 42, .12); }
.mk-tab.is-on svg { color: var(--mk-accent); }

.mk-bar { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .75rem; }
.mk-search { position: relative; flex: 1 1 16rem; min-width: 0; }
.mk-search__icon { position: absolute; inset: 0 auto 0 0; display: flex; align-items: center; padding-left: .85rem; color: #94a3b8; pointer-events: none; }
.mk-search input { width: 100%; padding: .65rem .9rem .65rem 2.4rem; border: 1px solid #cbd5e1; border-radius: .7rem; font-size: .9rem; background: #fff; outline: none; }
.mk-search input:focus, .mk-select:focus { border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20, 184, 166, .18); }
.mk-select { padding: .65rem 2rem .65rem .9rem; border: 1px solid #cbd5e1; border-radius: .7rem; font-size: .875rem; background-color: #fff; outline: none; }

.mk-chips { display: flex; gap: .4rem; overflow-x: auto; padding: .15rem 0 .6rem; margin-bottom: .5rem; scrollbar-width: thin; }
.mk-chip { flex: none; display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .85rem; border: 1px solid #e2e8f0; border-radius: 999px; background: #fff; font-size: .8rem; font-weight: 500; color: #475569; white-space: nowrap; }
.mk-chip:hover { border-color: #94a3b8; color: #0f172a; }
.mk-chip.is-on { background: #0f172a; border-color: #0f172a; color: #fff; }
.mk-chip__n { font-size: .7rem; opacity: .6; }
.mk-filter { display: flex; align-items: center; gap: .5rem; font-size: .8rem; color: #475569; margin-bottom: .9rem; }
.mk-filter button { display: inline-flex; align-items: center; gap: .3rem; padding: .25rem .4rem .25rem .7rem; border-radius: 999px; background: var(--mk-accent-soft); color: #115e59; font-weight: 600; }

/* Featured spotlight */
.mk-spot { display: grid; gap: 1rem; grid-template-columns: 1fr; margin: .5rem 0 2rem; }
@media (min-width: 1100px) { .mk-spot--2 { grid-template-columns: 1fr 1fr; } }
.mk-hero { position: relative; display: flex; gap: 1.5rem; align-items: center; padding: 1.5rem; border-radius: 1.25rem; color: #fff; overflow: hidden;
           background: radial-gradient(120% 140% at 0% 0%, #14b8a6 0%, #0f766e 38%, #134e4a 75%, #0b3b37 100%); }
.mk-hero::after { content: ""; position: absolute; right: -4rem; top: -4rem; width: 16rem; height: 16rem; border-radius: 50%; background: rgba(255, 255, 255, .06); pointer-events: none; }
.mk-hero--theme { background: radial-gradient(120% 140% at 0% 0%, #334155 0%, #1e293b 45%, #0f172a 100%); }
.mk-hero__art { flex: none; width: 9rem; height: 9rem; border-radius: 1.75rem; overflow: hidden; background: rgba(255, 255, 255, .12); box-shadow: 0 18px 40px -16px rgba(0, 0, 0, .55); }
.mk-hero--theme .mk-hero__art { width: 13rem; height: 8.5rem; border-radius: .9rem; }
.mk-hero__art img, .mk-hero__art > div { width: 100%; height: 100%; object-fit: cover; object-position: top; }
.mk-hero__body { position: relative; z-index: 1; min-width: 0; flex: 1; }
.mk-hero__kicker { display: inline-flex; align-items: center; gap: .3rem; font-size: .75rem; font-weight: 600; color: #fde68a; }
.mk-hero__name { font-size: 1.5rem; font-weight: 700; line-height: 1.2; margin: .2rem 0 .15rem; letter-spacing: -.01em; }
.mk-hero__who { font-size: .85rem; color: rgba(255, 255, 255, .75); }
.mk-hero__desc { font-size: .875rem; color: rgba(255, 255, 255, .88); margin: .6rem 0 .9rem; max-width: 40rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.mk-hero__row { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.mk-hero__meta { font-size: .8rem; color: rgba(255, 255, 255, .75); display: inline-flex; gap: .9rem; flex-wrap: wrap; }
.mk-hero .mk-btn--ghost { background: rgba(255, 255, 255, .14); color: #fff; }
.mk-hero .mk-btn--ghost:hover { background: rgba(255, 255, 255, .24); }
.mk-hero .mk-btn--go { background: #fff; color: #0f766e; }
.mk-hero .mk-btn--go:hover { background: #f0fdfa; }
@media (max-width: 640px) {
    .mk-hero { flex-direction: column; align-items: flex-start; padding: 1.25rem; }
    .mk-hero__art { width: 6.5rem; height: 6.5rem; border-radius: 1.4rem; }
    .mk-hero--theme .mk-hero__art { width: 100%; height: 9rem; }
}

/* Grid */
.mk-head { display: flex; align-items: baseline; gap: .6rem; margin-bottom: .8rem; }
.mk-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 21rem), 1fr)); }
.mk-grid--themes { grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); }
@media (max-width: 640px) {
    .mk-grid { grid-template-columns: 1fr; gap: .75rem; }
    .mk-spot--2 .mk-hero + .mk-hero { display: none; }
}
.mk-card { position: relative; display: flex; flex-direction: column; background: #fff; border: 1px solid #e2e8f0; border-radius: 1.1rem; padding: .75rem; transition: box-shadow .18s, border-color .18s, transform .18s; }
.mk-card:hover { border-color: #cbd5e1; box-shadow: 0 14px 30px -18px rgba(15, 23, 42, .35); transform: translateY(-2px); }
.mk-card .mk-open { display: block; width: 100%; text-align: left; border-radius: .8rem; }
/* App card: the 120px icon on the left; details, then the button, on the right. */
.mk-card--app { padding: 1rem; }
.mk-card--app .mk-open { display: flex; align-items: flex-start; gap: 1rem; }
.mk-card--app .mk-info { flex: 1; min-width: 0; padding: 0; }
.mk-card--app .mk-art { flex: none; width: 120px; height: 120px; max-width: none; margin: 0; border-radius: 26px; }
.mk-card--app .mk-foot { padding: .75rem 0 0 calc(120px + 1rem); }
.mk-desc { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: .8rem; line-height: 1.45; color: #475569; margin-top: .45rem; }
.mk-kicker { display: inline-flex; align-items: center; gap: .2rem; font-size: .68rem; font-weight: 700; color: #b45309; margin-bottom: .15rem; }
.mk-kicker svg { width: .8rem; height: .8rem; }
.mk-art { position: relative; display: block; width: 100%; max-width: 256px; margin: 0 auto; aspect-ratio: 1 / 1; border-radius: 22%; overflow: hidden; background: #f1f5f9; box-shadow: 0 1px 2px rgba(15, 23, 42, .06), inset 0 0 0 1px rgba(15, 23, 42, .05); }
.mk-art img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .25s; }
.mk-card:hover .mk-art img { transform: scale(1.03); }
.mk-grid--themes .mk-art { max-width: none; aspect-ratio: 16 / 10; border-radius: .75rem; }
.mk-grid--themes .mk-art img { object-position: top; }
.mk-fallback { width: 100%; height: 100%; display: grid; place-items: center; background: linear-gradient(135deg, #ccfbf1, #a7f3d0); color: #0d9488; }
.mk-fallback svg { width: 38%; height: 38%; }
.mk-grid--themes .mk-fallback, .mk-hero--theme .mk-fallback { background: linear-gradient(135deg, #e2e8f0, #cbd5e1); color: #64748b; }
.mk-grid--themes .mk-fallback svg { width: 22%; height: 22%; }
.mk-flag { position: absolute; top: .55rem; left: .55rem; z-index: 1; display: inline-flex; align-items: center; gap: .2rem; padding: .15rem .5rem; border-radius: 999px; font-size: .68rem; font-weight: 700; background: rgba(255, 255, 255, .92); color: #b45309; box-shadow: 0 1px 3px rgba(15, 23, 42, .15); }
.mk-info { display: block; padding: .8rem .2rem 0; }
.mk-name { display: block; font-size: .95rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mk-who { display: block; font-size: .78rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: .1rem; }
.mk-meta { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; font-size: .75rem; color: #64748b; margin-top: .45rem; }
.mk-meta > span { display: inline-flex; align-items: center; gap: .25rem; white-space: nowrap; }
.mk-meta svg { width: .85rem; height: .85rem; color: #94a3b8; }
.mk-foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .8rem .2rem .1rem; margin-top: auto; }
.mk-ver { font-size: .72rem; color: #94a3b8; white-space: nowrap; }
.mk-state { font-size: .72rem; font-weight: 600; white-space: nowrap; }

/* Buttons */
.mk-btn { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; padding: .45rem 1rem; border-radius: 999px; font-size: .8rem; font-weight: 700; white-space: nowrap; transition: background-color .15s; }
.mk-btn svg { width: 1rem; height: 1rem; }
.mk-btn--go { background: #f0fdfa; color: #0f766e; box-shadow: inset 0 0 0 1px #99f6e4; }
.mk-btn--go:hover { background: #0f766e; color: #fff; box-shadow: none; }
.mk-btn--up { background: #f59e0b; color: #fff; }
.mk-btn--up:hover { background: #d97706; }
.mk-btn--done { background: #f1f5f9; color: #64748b; cursor: default; }
.mk-btn--big { padding: .6rem 1.4rem; font-size: .875rem; }
.mk-btn--big.mk-btn--go { background: #0f766e; color: #fff; box-shadow: none; }
.mk-btn--big.mk-btn--go:hover { background: #115e59; }
.mk-btn:focus-visible, .mk-tab:focus-visible, .mk-chip:focus-visible, .mk-card .mk-open:focus-visible { outline: 2px solid #5eead4; outline-offset: 2px; }

/* Loading placeholders */
.mk-ghost { background: #fff; border: 1px solid #e2e8f0; border-radius: 1.1rem; padding: .75rem; }
.mk-ghost__art { aspect-ratio: 1 / 1; max-width: 256px; margin: 0 auto; border-radius: 22%; background: #f1f5f9; }
.mk-ghost--app { display: flex; gap: 1rem; padding: 1rem; }
.mk-ghost--app .mk-ghost__art { flex: none; width: 120px; height: 120px; margin: 0; border-radius: 26px; }
.mk-ghost--app .mk-ghost__text { flex: 1; }
.mk-ghost--app .mk-ghost__line:first-child { margin-top: .2rem; }
.mk-grid--themes .mk-ghost__art { aspect-ratio: 16 / 10; max-width: none; border-radius: .75rem; }
.mk-ghost__line { height: .7rem; border-radius: 999px; background: #f1f5f9; margin-top: .8rem; }
.mk-ghost__line + .mk-ghost__line { width: 60%; margin-top: .5rem; }
.mk-empty { grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 4rem 1rem; }

/* Details window additions */
.mk-modal-art { width: 7.5rem; height: 7.5rem; border-radius: 1.7rem; overflow: hidden; background: #f1f5f9; box-shadow: 0 12px 28px -14px rgba(15, 23, 42, .45); }
.mk-modal-art img, .mk-modal-art > div { width: 100%; height: 100%; object-fit: cover; }
.mk-tagbtn { display: inline-block; font-size: .72rem; padding: .15rem .55rem; margin: 0 .25rem .25rem 0; border-radius: 999px; background: #f1f5f9; color: #475569; }
.mk-tagbtn:hover { background: var(--mk-accent-soft, #ccfbf1); color: #115e59; }
.mk-perm { display: inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; padding: .1rem .4rem; margin: 0 .25rem .25rem 0; border-radius: .3rem; background: #f1f5f9; color: #334155; }
.mk-link { color: #0f766e; }
.mk-link:hover { text-decoration: underline; }
@media (prefers-reduced-motion: reduce) { .mk-card, .mk-art img { transition: none; } .mk-card:hover { transform: none; } .mk-card:hover .mk-art img { transform: none; } }
</style>

<script>
(function () {
    var KIND = <?= json_encode($tab) ?>;
    var IS_APPS = KIND === 'apps';
    var BASE = <?= json_encode($base) ?>, CSRF = <?= json_encode($csrf) ?>;
    var EP = BASE + '/admin/' + KIND + '/marketplace';
    var NOUN = IS_APPS ? 'app' : 'theme';
    var state = { q: '', category: '', tag: '', sort: 'featured', page: 1 };
    var items = {}, busy = {}, seq = 0;
    var $ = function (id) { return document.getElementById(id); };

    var I = {
        piece:   <?= json_encode(icon('puzzle-piece', 'w-6 h-6')) ?>,
        photo:   <?= json_encode(icon('photo', 'w-6 h-6')) ?>,
        dl:      <?= json_encode(icon('arrow-down-tray', 'w-4 h-4')) ?>,
        up:      <?= json_encode(icon('arrow-up', 'w-4 h-4')) ?>,
        check:   <?= json_encode(icon('check', 'w-4 h-4')) ?>,
        spin:    <?= json_encode(icon('arrow-path', 'w-4 h-4 animate-spin')) ?>,
        users:   <?= json_encode(icon('users', 'w-4 h-4')) ?>,
        disk:    <?= json_encode(icon('hard-drive', 'w-4 h-4')) ?>,
        star:    <?= json_encode(icon('star', 'w-3.5 h-3.5')) ?>,
        x:       <?= json_encode(icon('x-mark', 'w-3.5 h-3.5')) ?>,
        ok:      <?= json_encode(icon('check-circle', 'w-4 h-4 mr-1 inline-block align-text-bottom')) ?>,
        warn:    <?= json_encode(icon('exclamation-triangle', 'w-4 h-4 mr-1 inline-block align-text-bottom')) ?>
    };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function bytes(n) { n = +n || 0; return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B'; }
    function count(n) {
        n = +n || 0;
        if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
        return String(n);
    }
    function installs(n) { n = +n || 0; return count(n) + (n === 1 ? ' install' : ' installs'); }
    function day(s) {
        if (!s) return '';
        return window.BasehimTime ? esc(BasehimTime.date(s)) : esc(String(s).slice(0, 10));
    }
    function get(url) { return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }); }
    function cmpVer(a, b) {
        var x = String(a || '').split(/[.+-]/), y = String(b || '').split(/[.+-]/);
        for (var i = 0; i < Math.max(x.length, y.length); i++) {
            var p = parseInt(x[i] || '0', 10) || 0, q = parseInt(y[i] || '0', 10) || 0;
            if (p !== q) return p < q ? -1 : 1;
        }
        return 0;
    }
    function status(t) {
        if (!t.installed) return 'new';
        return cmpVer(t.version, t.installed_version) > 0 ? 'update' : 'current';
    }

    /* ---------- who made it ---------- */
    function parties(t) {
        var m = t.meta || {};
        var dev = m.developer || t.developer || (t.author ? { name: t.author, url: null } : null);
        if (dev && typeof dev === 'string') dev = { name: dev, url: null };
        var co = m.company || t.company || null;
        if (co && typeof co === 'string') co = { name: co, url: null };
        if (co && dev && co.name === dev.name) co = null;
        return { dev: dev, co: co };
    }
    function who(t, links) {
        var p = parties(t), bits = [];
        var fmt = function (x) {
            return links && x.url ? '<a href="' + esc(x.url) + '" target="_blank" rel="noopener nofollow" class="mk-link">' + esc(x.name) + '</a>' : esc(x.name);
        };
        if (p.dev) bits.push(fmt(p.dev));
        if (p.co) bits.push(fmt(p.co));
        return bits.join(', ');
    }

    /* ---------- artwork: an app's icon, a theme's screenshot ---------- */
    function fallback() { return '<div class="mk-fallback">' + (IS_APPS ? I.piece : I.photo) + '</div>'; }
    function artSrc(t) { return IS_APPS ? t.icon_url : t.screenshot_url; }
    function art(t, alt) {
        var src = artSrc(t);
        return src ? '<img src="' + esc(src) + '" alt="' + esc(alt || '') + '" data-mk-art loading="lazy">' : fallback();
    }
    // Artwork that fails to load becomes the plain tile instead of a broken image.
    document.addEventListener('error', function (e) {
        var img = e.target;
        if (img && img.tagName === 'IMG' && img.hasAttribute('data-mk-art')) img.outerHTML = fallback();
    }, true);

    /* ---------- the install / update button ---------- */
    function action(t, big) {
        var b = 'mk-btn mk-install' + (big ? ' mk-btn--big' : '');
        if (busy[t.slug]) return '<button type="button" disabled class="' + b + ' mk-btn--done">' + I.spin + 'Installing…</button>';
        var st = status(t);
        if (st === 'current') {
            var label = t.active ? (IS_APPS ? 'Active' : 'In use') : 'Installed';
            return '<button type="button" disabled class="' + b + ' mk-btn--done">' + I.check + label + '</button>';
        }
        if (st === 'update') return '<button type="button" data-slug="' + esc(t.slug) + '" class="' + b + ' mk-btn--up">' + I.up + (big ? 'Update to v' + esc(t.version) : 'Update') + '</button>';
        return '<button type="button" data-slug="' + esc(t.slug) + '" class="' + b + ' mk-btn--go">' + (big ? I.dl : '') + 'Install' + '</button>';
    }

    /* ---------- grid card ---------- */
    function card(t) {
        var p = parties(t);
        var sub = [p.dev ? esc(p.dev.name) : '', t.category ? esc(t.category) : ''].filter(Boolean).join(' · ');
        var meta = '<span title="Installs">' + I.users + installs(t.installs) + '</span>';
        if (+t.size_bytes > 0) meta += '<span title="Download size">' + I.disk + bytes(t.size_bytes) + '</span>';
        var st = status(t), note = '';
        if (st === 'update') note = '<span class="mk-state text-amber-600">v' + esc(t.installed_version) + ' installed</span>';
        else if (st === 'current' && !t.active) note = '<span class="mk-state text-slate-500">Not active</span>';
        var open = '<button type="button" class="mk-open" aria-haspopup="dialog" aria-label="' + esc(t.name) + ' details">';
        var body = IS_APPS
            // App: the 120px icon on the left, details on the right.
            ? '<span class="mk-art">' + art(t) + '</span>'
            + '<span class="mk-info">'
            +   (t.featured == 1 ? '<span class="mk-kicker">' + I.star + 'Featured</span>' : '')
            +   '<span class="mk-name">' + esc(t.name) + '</span>'
            +   (sub ? '<span class="mk-who">' + sub + '</span>' : '')
            +   '<span class="mk-meta">' + meta + '</span>'
            +   (t.description ? '<span class="mk-desc">' + esc(t.description) + '</span>' : '')
            + '</span>'
            // Theme: the screenshot on top, details under it.
            : '<span class="mk-art">' + (t.featured == 1 ? '<span class="mk-flag">' + I.star + 'Featured</span>' : '') + art(t) + '</span>'
            + '<span class="mk-info">'
            +   '<span class="mk-name">' + esc(t.name) + '</span>'
            +   (sub ? '<span class="mk-who">' + sub + '</span>' : '')
            +   '<span class="mk-meta">' + meta + '</span>'
            + '</span>';
        return '<article class="mk-card' + (IS_APPS ? ' mk-card--app' : '') + '" data-slug="' + esc(t.slug) + '">'
             + open + body + '</button>'
             + '<div class="mk-foot"><span class="mk-act">' + action(t) + '</span>' + (note || '<span class="mk-ver">v' + esc(t.version) + '</span>') + '</div>'
             + '</article>';
    }

    /* ---------- featured spotlight ---------- */
    function hero(t) {
        var meta = '<span>' + installs(t.installs) + '</span><span>v' + esc(t.version) + '</span>' + (t.category ? '<span>' + esc(t.category) + '</span>' : '');
        var w = who(t, false);
        return '<article class="mk-hero' + (IS_APPS ? '' : ' mk-hero--theme') + '" data-slug="' + esc(t.slug) + '">'
             + '<div class="mk-hero__art">' + art(t, t.name) + '</div>'
             + '<div class="mk-hero__body">'
             +   '<span class="mk-hero__kicker">' + I.star + 'Featured ' + NOUN + '</span>'
             +   '<div class="mk-hero__name">' + esc(t.name) + '</div>'
             +   (w ? '<div class="mk-hero__who">by ' + w + '</div>' : '')
             +   (t.description ? '<p class="mk-hero__desc">' + esc(t.description) + '</p>' : '<div class="h-3"></div>')
             +   '<div class="mk-hero__row"><span class="mk-act">' + action(t) + '</span>'
             +     '<button type="button" class="mk-btn mk-btn--ghost mk-open">Details</button>'
             +     '<span class="mk-hero__meta">' + meta + '</span></div>'
             + '</div></article>';
    }
    function renderSpot(list) {
        var spot = $('mk-spot');
        var plain = state.page === 1 && !state.q && !state.category && !state.tag && state.sort === 'featured';
        var picks = plain ? list.filter(function (t) { return t.featured == 1; }).slice(0, 2) : [];
        if (!picks.length) { spot.className = 'hidden'; spot.innerHTML = ''; return; }
        spot.className = 'mk-spot' + (picks.length > 1 ? ' mk-spot--2' : '');
        spot.innerHTML = picks.map(hero).join('');
    }

    /* ---------- grid ---------- */
    function ghosts() {
        var g = '';
        for (var i = 0; i < 6; i++) g += IS_APPS
            ? '<div class="mk-ghost mk-ghost--app animate-pulse"><div class="mk-ghost__art"></div><div class="mk-ghost__text"><div class="mk-ghost__line"></div><div class="mk-ghost__line"></div><div class="mk-ghost__line"></div></div></div>'
            : '<div class="mk-ghost animate-pulse"><div class="mk-ghost__art"></div><div class="mk-ghost__line"></div><div class="mk-ghost__line"></div></div>';
        return g;
    }
    function render(d) {
        var list = d[IS_APPS ? 'apps' : 'themes'] || d.plugins || [];
        items = {};
        list.forEach(function (t) { items[t.slug] = t; });
        renderSpot(list);
        var meta = d.meta || {}, total = meta.total != null ? +meta.total : list.length;
        $('mk-count').textContent = total ? total + ' ' + NOUN + (total === 1 ? '' : 's') : '';
        $('mk-heading').textContent = state.q ? 'Results for “' + state.q + '”'
            : state.category ? state.category
            : state.tag ? 'Tagged “' + state.tag + '”'
            : (IS_APPS ? 'All apps' : 'All themes');
        $('mk-grid').innerHTML = list.length ? list.map(card).join('')
            : '<div class="mk-empty">' + (IS_APPS ? I.piece : I.photo).replace('w-6 h-6', 'w-8 h-8 mb-2 mx-auto block opacity-50') + 'No ' + NOUN + 's match. Try another search or category.</div>';
        renderPager(meta);
    }

    function showStatus(html, kind) {
        var s = $('mk-status');
        s.className = 'mb-4 rounded-xl border p-4 text-sm ' +
            (kind === 'error' ? 'bg-red-50 border-red-200 text-red-700' :
             kind === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' :
             'bg-amber-50 border-amber-200 text-amber-800');
        s.innerHTML = html;
    }

    function load() {
        var my = ++seq;
        var qs = 'q=' + encodeURIComponent(state.q) + '&category=' + encodeURIComponent(state.category)
               + '&tag=' + encodeURIComponent(state.tag) + '&sort=' + encodeURIComponent(state.sort) + '&page=' + state.page;
        $('mk-grid').innerHTML = ghosts();
        $('mk-status').className = 'hidden';
        renderFilter();
        get(EP + '/browse.json?' + qs).then(function (d) {
            if (my !== seq) return;
            if (!d || !d.ok) {
                if (d && d.error === 'not_connected') {
                    showStatus('The Basehim marketplace isn\'t reachable from this site yet. It connects automatically — open <a href="' + BASE + '/admin/updates" class="underline font-medium">Updates</a> to retry, then come back.', 'error');
                } else {
                    showStatus(I.warn + esc((d && d.error) || 'Could not load the marketplace.'), 'error');
                }
                $('mk-grid').innerHTML = ''; $('mk-spot').className = 'hidden'; $('mk-count').textContent = '';
                $('mk-pager').classList.add('hidden');
                return;
            }
            render(d);
        }).catch(function () {
            if (my !== seq) return;
            showStatus(I.warn + 'Network error loading the marketplace. Check the connection and reload the page.', 'error');
            $('mk-grid').innerHTML = '';
        });
    }

    /* ---------- search, sort, categories, tag filter ---------- */
    var debounce;
    $('mk-q').addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { state.q = $('mk-q').value.trim(); state.page = 1; load(); }, 300);
    });
    $('mk-sort').addEventListener('change', function () { state.sort = $('mk-sort').value; state.page = 1; load(); });

    function setCategory(name) {
        state.category = name; state.page = 1;
        $('mk-cats').querySelectorAll('.mk-chip').forEach(function (c) { c.classList.toggle('is-on', c.dataset.cat === name); });
        load();
    }
    $('mk-cats').addEventListener('click', function (e) {
        var c = e.target.closest('.mk-chip'); if (c) setCategory(c.dataset.cat);
    });
    function setTag(tag) { state.tag = tag; state.page = 1; load(); }
    function renderFilter() {
        var f = $('mk-filter');
        if (!state.tag) { f.className = 'hidden'; f.innerHTML = ''; return; }
        f.className = 'mk-filter';
        f.innerHTML = 'Tag <button type="button" data-clear-tag aria-label="Remove tag filter">' + esc(state.tag) + I.x + '</button>';
    }
    $('mk-filter').addEventListener('click', function (e) { if (e.target.closest('[data-clear-tag]')) setTag(''); });

    get(EP + '/facets.json').then(function (d) {
        if (!d || !d.ok) return;
        var cats = $('mk-cats');
        (d.categories || []).forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'mk-chip'; b.dataset.cat = c.name;
            b.innerHTML = esc(c.name) + ' <span class="mk-chip__n">' + (+c.count || 0) + '</span>';
            cats.appendChild(b);
        });
    }).catch(function () {});

    /* ---------- details window ---------- */
    function perms(t) {
        var p = t.permissions;
        if (typeof p === 'string') { try { p = JSON.parse(p); } catch (e) { p = null; } }
        return Array.isArray(p) ? p : null;
    }
    function open(slug) {
        var t = items[slug]; if (!t) return;
        var p = parties(t), st = status(t);
        var badge = t.featured == 1 ? '<span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-semibold whitespace-nowrap">Featured</span>' : '';
        var chips = '';
        if (st === 'update') chips += '<span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-medium">v' + esc(t.installed_version) + ' installed · update available</span>';
        else if (st === 'current') chips += '<span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-medium">' + (t.active ? (IS_APPS ? 'Installed and active' : 'Installed and in use') : 'Installed') + '</span>';

        var stats = [
            ['Installs', count(t.installs)],
            ['Version', esc(t.version)],
            ['Size', +t.size_bytes > 0 ? bytes(t.size_bytes) : '—'],
            ['Category', t.category ? esc(t.category) : '—']
        ];
        var facts = [];
        if (IS_APPS) {
            var pm = perms(t);
            facts.push(['Permissions', pm === null ? '' : (pm.length ? pm.map(function (x) { return '<span class="mk-perm">' + esc(x) + '</span>'; }).join('') : 'None requested')]);
        }
        facts.push(['Tags', (t.tag_list || []).map(function (x) { return '<button type="button" class="mk-tagbtn" data-mk-tag="' + esc(x) + '" title="Show ' + NOUN + 's tagged ' + esc(x) + '">' + esc(x) + '</button>'; }).join('')]);
        facts.push(['Added', day(t.created_at)]);
        if (p.dev) facts.push([IS_APPS ? 'Developer' : 'Author', p.dev.url ? '<a href="' + esc(p.dev.url) + '" target="_blank" rel="noopener nofollow" class="mk-link">' + esc(p.dev.name) + '</a>' : esc(p.dev.name)]);
        if (p.co) facts.push(['Company', p.co.url ? '<a href="' + esc(p.co.url) + '" target="_blank" rel="noopener nofollow" class="mk-link">' + esc(p.co.name) + '</a>' : esc(p.co.name)]);

        bhItemModal.open({
            key: slug,
            name: t.name,
            iconHtml: IS_APPS ? '<div class="mk-modal-art">' + art(t) + '</div>' : '',
            mediaHtml: !IS_APPS && t.screenshot_url ? '<img src="' + esc(t.screenshot_url) + '" alt="' + esc(t.name) + ' screenshot">' : '',
            badgeHtml: badge,
            whoHtml: who(t, true) ? 'by ' + who(t, true) : '',
            chipsHtml: chips,
            stats: stats,
            description: t.description || '',
            facts: facts
        });
        sync(slug);
    }
    function sync(slug) {
        if (bhItemModal.current() !== slug) return;
        var t = items[slug];
        bhItemModal.action(action(t, true));
        if (status(t) === 'current' && !t.active && !busy[slug]) {
            bhItemModal.state('Installed. <a href="' + BASE + '/admin/' + KIND + '" class="underline font-medium">Activate it on the ' + (IS_APPS ? 'Apps' : 'Themes') + ' page</a>.', 'ok');
        }
    }
    function refresh(slug) {
        var t = items[slug];
        document.querySelectorAll('[data-slug="' + slug + '"].mk-card').forEach(function (el) { el.outerHTML = card(t); });
        document.querySelectorAll('[data-slug="' + slug + '"].mk-hero .mk-act').forEach(function (el) { el.innerHTML = action(t); });
        sync(slug);
    }

    // Anywhere on the page: a button with data-slug installs, an .mk-open opens the details.
    document.querySelector('.mk-page').addEventListener('click', function (e) {
        var btn = e.target.closest('.mk-install[data-slug]');
        if (btn) { install(btn.dataset.slug); return; }
        var o = e.target.closest('.mk-open');
        if (o) { var host = o.closest('[data-slug]'); if (host) open(host.getAttribute('data-slug')); }
    });
    $('bh-im').addEventListener('click', function (e) {
        var btn = e.target.closest('.mk-install[data-slug]');
        if (btn) { install(btn.dataset.slug); return; }
        var tag = e.target.closest('[data-mk-tag]');
        if (tag) { bhItemModal.close(); setTag(tag.getAttribute('data-mk-tag')); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });

    function install(slug) {
        var t = items[slug]; if (!t || busy[slug]) return;
        busy[slug] = true; refresh(slug);
        if (bhItemModal.current() === slug) bhItemModal.state('');
        var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('slug', slug);
        fetch(EP + '/install', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy[slug] = false;
                if (d.ok) {
                    var wasUpdate = t.installed;
                    t.installed = true; t.installed_version = t.version;
                    refresh(slug);
                    var msg = '“' + esc(t.name) + '” ' + (wasUpdate ? 'updated to v' + esc(t.version) : 'installed') + '.';
                    if (!t.active) msg += ' <a href="' + BASE + '/admin/' + KIND + '" class="underline font-medium">Go to ' + (IS_APPS ? 'Apps' : 'Themes') + '</a> to activate it.';
                    showStatus(I.ok + msg, 'ok');
                    if (bhItemModal.current() === slug) bhItemModal.state(I.ok + msg, 'ok');
                } else {
                    refresh(slug);
                    var err = esc(d.error || 'Install failed.');
                    showStatus(I.warn + err, 'error');
                    if (bhItemModal.current() === slug) bhItemModal.state(err, 'error');
                }
            })
            .catch(function () {
                busy[slug] = false; refresh(slug);
                showStatus(I.warn + 'Network error during install.', 'error');
                if (bhItemModal.current() === slug) bhItemModal.state('Network error during install.', 'error');
            });
    }

    function renderPager(meta) {
        var pager = $('mk-pager');
        if (!meta.last_page || meta.last_page <= 1) { pager.classList.add('hidden'); pager.classList.remove('flex'); return; }
        pager.classList.remove('hidden'); pager.classList.add('flex');
        var b = 'mk-pg inline-flex items-center gap-1 px-3 py-1.5 border border-slate-200 rounded-full text-sm bg-white hover:bg-slate-50';
        var html = '';
        if (meta.page > 1) html += '<button type="button" data-pg="' + (meta.page - 1) + '" class="' + b + '"><?= icon('arrow-left', 'w-4 h-4') ?>Previous</button>';
        html += '<span class="text-sm text-slate-500">Page ' + meta.page + ' of ' + meta.last_page + '</span>';
        if (meta.page < meta.last_page) html += '<button type="button" data-pg="' + (meta.page + 1) + '" class="' + b + '">Next<?= icon('arrow-right', 'w-4 h-4') ?></button>';
        pager.innerHTML = html;
        pager.querySelectorAll('.mk-pg').forEach(function (x) {
            x.addEventListener('click', function () { state.page = +x.dataset.pg; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        });
    }

    load();
})();
</script>
