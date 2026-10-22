/**
 * Marketplace (admin): browse, install, then activate in place.
 *
 * Loaded only on /admin/marketplace (partials/marketplace.php). It talks to
 * the AJAX gateway through Basehim.ajax (1.2.84):
 *
 *   apps.market_browse / themes.market_browse      GET   the grid
 *   apps.market_facets / themes.market_facets      GET   category chips
 *   apps.market_install / themes.market_install    POST  download + install
 *   apps.market_consent                            GET   what an app asks for
 *   apps.market_activate / themes.market_activate  POST  turn it on
 *
 * Errors arrive as Basehim.ajax.AjaxError (a code and a message safe to
 * show), so an expired session or a missing capability is reported as what
 * it is rather than as a "network error". The markup uses the core
 * components only (.bh-mk-*, .bh-btn, .bh-badge, .bh-empty); busy buttons
 * use bhBusy(), the one shared spinner.
 *
 * The permission step is the shared window in app-consent.js (1.2.85).
 *
 * Flow: Install downloads and installs the package; the button then becomes
 * Activate. A theme is applied directly. An app that declares permissions
 * shows the consent step in its details window first, and is activated with
 * the permissions the operator leaves switched on.
 */
(function () {
    'use strict';

    var root = document.getElementById('mk-root');
    if (!root || !window.Basehim || !Basehim.ajax) return;

    var BASE = root.getAttribute('data-base') || '';
    var IS_APPS = root.getAttribute('data-tab') !== 'themes';
    var NS = IS_APPS ? 'apps' : 'themes';
    var NOUN = IS_APPS ? 'app' : 'theme';

    var state = { q: '', category: '', tag: '', sort: 'featured', page: 1 };
    var items = {}, busy = {}, seq = 0, consentSlug = null;
    var $ = function (id) { return document.getElementById(id); };

    function ic(name, cls) { return window.BasehimIcon ? BasehimIcon(name, cls || 'w-4 h-4') : ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function bytes(n) { n = +n || 0; return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B'; }
    function count(n) {
        n = +n || 0;
        if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
        return String(n);
    }
    function installs(n) { n = +n || 0; return count(n) + (n === 1 ? ' install' : ' installs'); }
    function day(s) { return !s ? '' : window.BasehimTime ? esc(BasehimTime.date(s)) : esc(String(s).slice(0, 10)); }
    function toast(msg, type) { if (window.bhToast) bhToast(msg, type); }

    function call(name, data, method) {
        return Basehim.ajax.request(NS + '.market_' + name, data || {}, { method: method || 'POST', timeout: name === 'install' ? 150000 : 30000 });
    }

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
    function party(name, url) { return name ? { name: String(name), url: url || null } : null; }
    function parties(t) {
        var m = t.meta || {};
        var dev = m.developer || t.developer || null;
        if (dev && typeof dev === 'string') dev = party(dev, t.developer_url);
        if (!dev && t.author) dev = party(t.author);
        var co = m.company || t.company || null;
        if (co && typeof co === 'string') co = party(co, t.company_url);
        if (co && dev && co.name === dev.name) co = null;
        return { dev: dev, co: co };
    }
    function link(x) {
        return x.url ? '<a href="' + esc(x.url) + '" target="_blank" rel="noopener nofollow">' + esc(x.name) + '</a>' : esc(x.name);
    }
    function who(t, links) {
        var p = parties(t), bits = [];
        if (p.dev) bits.push(links ? link(p.dev) : esc(p.dev.name));
        if (p.co) bits.push(links ? link(p.co) : esc(p.co.name));
        return bits.join(', ');
    }

    /* ---------- artwork: an app's icon, a theme's screenshot ---------- */
    function fallback() { return '<span class="bh-mk-fallback">' + ic(IS_APPS ? 'puzzle-piece' : 'photo', '') + '</span>'; }
    function art(t, alt) {
        var src = IS_APPS ? t.icon_url : t.screenshot_url;
        return src ? '<img src="' + esc(src) + '" alt="' + esc(alt || '') + '" data-mk-art loading="lazy">' : fallback();
    }
    document.addEventListener('error', function (e) {
        var img = e.target;
        if (img && img.tagName === 'IMG' && img.hasAttribute('data-mk-art')) img.outerHTML = fallback();
    }, true);

    /* ---------- the install / update / activate control ---------- */
    function action(t, big) {
        var sz = big ? '' : ' bh-btn--sm';
        var b = busy[t.slug];
        if (b) return '<button type="button" class="bh-btn bh-btn--secondary' + sz + '" data-mk-busy="' + esc(b) + '" disabled>' + esc(b) + '</button>';
        var st = status(t);
        if (st === 'update') {
            return '<button type="button" class="bh-btn bh-btn--primary' + sz + ' mk-install" data-slug="' + esc(t.slug) + '">'
                 + ic('arrow-up') + (big ? 'Update to v' + esc(t.version) : 'Update') + '</button>';
        }
        if (st === 'new') {
            return '<button type="button" class="bh-btn ' + (big ? 'bh-btn--primary' : 'bh-btn--secondary') + sz + ' mk-install" data-slug="' + esc(t.slug) + '">'
                 + ic('arrow-down-tray') + 'Install</button>';
        }
        if (t.active) {
            return '<span class="bh-badge bh-badge--green">' + ic('check-circle', '') + (IS_APPS ? 'Active' : 'In use') + '</span>';
        }
        return '<button type="button" class="bh-btn bh-btn--primary' + sz + ' mk-activate" data-slug="' + esc(t.slug) + '">'
             + ic('bolt') + (IS_APPS ? 'Activate' : 'Use theme') + '</button>';
    }
    /** What sits opposite the button: the version, or what is installed. */
    function note(t) {
        var st = status(t);
        if (st === 'update') return '<span class="bh-badge bh-badge--amber">v' + esc(t.installed_version) + ' installed</span>';
        if (st === 'current' && !t.active) return '<span class="bh-badge bh-badge--gray">Installed</span>';
        return '<span class="bh-mk-card__note">v' + esc(t.version) + '</span>';
    }
    /** Busy buttons get the shared spinner once they are in the page. */
    function paintBusy(scope) {
        if (!window.bhBusy) return;
        (scope || document).querySelectorAll('[data-mk-busy]').forEach(function (b) {
            if (!b._bhLoader) bhBusy(b, true, b.getAttribute('data-mk-busy'));
        });
    }

    function metaBits(t) {
        var m = '<span title="Installs">' + ic('users', '') + installs(t.installs) + '</span>';
        if (+t.size_bytes > 0) m += '<span title="Download size">' + ic('circle-stack', '') + bytes(t.size_bytes) + '</span>';
        return m;
    }

    /* ---------- grid card ---------- */
    function card(t) {
        var p = parties(t);
        var sub = [p.dev ? esc(p.dev.name) : '', t.category ? esc(t.category) : ''].filter(Boolean).join(', ');
        var flag = t.featured == 1 ? '<span class="bh-badge bh-badge--amber' + (IS_APPS ? '' : ' bh-mk-flag') + '">' + ic('star', '') + 'Featured</span>' : '';
        var info = '<span class="bh-mk-info">'
                 + (IS_APPS && flag ? '<span class="bh-mk-info__flag">' + flag + '</span>' : '')
                 + '<span class="bh-mk-name">' + esc(t.name) + '</span>'
                 + (sub ? '<span class="bh-mk-who">' + sub + '</span>' : '')
                 + '<span class="bh-mk-meta">' + metaBits(t) + '</span>'
                 + '</span>';
        // Apps: the icon beside the name, the description across the card.
        // Themes: the screenshot on top.
        return '<article class="bh-mk-card" data-slug="' + esc(t.slug) + '">'
             + '<button type="button" class="bh-mk-card__open mk-open" aria-haspopup="dialog" aria-label="' + esc(t.name) + ' details">'
             +   (IS_APPS
                    ? '<span class="bh-mk-card__top"><span class="bh-mk-art">' + art(t) + '</span>' + info + '</span>'
                      + (t.description ? '<span class="bh-mk-desc">' + esc(t.description) + '</span>' : '')
                    : '<span class="bh-mk-art">' + flag + art(t) + '</span>' + info)
             + '</button>'
             + '<div class="bh-mk-card__foot"><span class="mk-act">' + action(t) + '</span>' + note(t) + '</div>'
             + '</article>';
    }

    /* ---------- featured: the first page, before any filter ---------- */
    function feature(t) {
        var w = who(t, false);
        var meta = '<span>' + ic('users', '') + installs(t.installs) + '</span><span>v' + esc(t.version) + '</span>'
                 + (t.category ? '<span>' + esc(t.category) + '</span>' : '');
        return '<article class="bh-mk-feature' + (IS_APPS ? '' : ' bh-mk-feature--theme') + '" data-slug="' + esc(t.slug) + '">'
             + '<div class="bh-mk-feature__art">'
             +   '<div class="bh-mk-feature__glow" aria-hidden="true">' + art(t) + '</div>'
             +   '<div class="bh-mk-feature__img">' + art(t, t.name) + '</div>'
             + '</div>'
             + '<div class="bh-mk-feature__body">'
             +   '<span class="bh-badge bh-badge--amber">' + ic('star', '') + 'Featured ' + NOUN + '</span>'
             +   '<h3 class="bh-mk-feature__name">' + esc(t.name) + '</h3>'
             +   (w ? '<p class="bh-mk-feature__who">by ' + w + '</p>' : '')
             +   (t.description ? '<p class="bh-mk-feature__desc">' + esc(t.description) + '</p>' : '')
             +   '<div class="bh-mk-feature__row"><span class="mk-act">' + action(t, true) + '</span>'
             +     '<button type="button" class="bh-btn bh-btn--secondary mk-open">Details</button>'
             +     '<span class="bh-mk-feature__meta">' + meta + '</span></div>'
             + '</div></article>';
    }
    function renderSpot(list) {
        var spot = $('mk-spot');
        var plain = state.page === 1 && !state.q && !state.category && !state.tag && state.sort === 'featured';
        var picks = plain ? list.filter(function (t) { return t.featured == 1; }).slice(0, 2) : [];
        spot.hidden = !picks.length;
        spot.className = 'bh-mk-spot' + (picks.length > 1 ? ' bh-mk-spot--2' : '');
        spot.innerHTML = picks.map(feature).join('');
    }

    /* ---------- loading, empty and failed states ---------- */
    function ghosts() {
        var g = '';
        for (var i = 0; i < 6; i++) {
            g += '<div class="bh-mk-card bh-mk-ghost" aria-hidden="true"><div class="bh-mk-card__open">'
               + (IS_APPS ? '<span class="bh-mk-card__top">' : '')
               + '<span class="bh-mk-art"></span>'
               + '<span class="bh-mk-info">'
               +   '<span class="bh-dskel__bone w-3/5"></span>'
               +   '<span class="bh-dskel__bone h-2.5 w-2/5"></span>'
               +   '<span class="bh-dskel__bone h-2.5 w-1/2"></span>'
               + '</span>'
               + (IS_APPS ? '</span><span class="bh-mk-desc"><span class="bh-dskel__bone h-2.5 w-11/12"></span><span class="bh-dskel__bone h-2.5 w-3/4"></span></span>' : '')
               + '</div>'
               + '<div class="bh-mk-card__foot"><span class="bh-dskel__bone h-8 w-24 rounded-lg"></span></div></div>';
        }
        return g;
    }
    function stateCard(o) {
        return '<div class="bh-mk-state' + (o.error ? ' is-error' : '') + '"><div class="bh-empty">'
             + '<span class="bh-empty__icon">' + ic(o.icon, '') + '</span>'
             + '<p class="bh-empty__title">' + esc(o.title) + '</p>'
             + '<p class="bh-empty__text">' + o.text + '</p>'
             + (o.actions ? '<div class="bh-empty__actions">' + o.actions + '</div>' : '')
             + '</div></div>';
    }
    function showFailure(err) {
        var offline = err && err.code === 'NOT_CONNECTED';
        $('mk-grid').innerHTML = stateCard({
            error: !offline,
            icon: offline ? 'signal-slash' : 'exclamation-triangle',
            title: offline ? 'Not connected to the marketplace yet' : 'The marketplace couldn\'t be loaded',
            text: offline
                ? 'Your site connects to the Basehim marketplace on its own. Check for updates to connect now, then try again.'
                : esc((err && err.message) || 'Try again in a moment.'),
            actions: '<button type="button" class="bh-btn bh-btn--primary" data-mk-retry>' + ic('arrow-path') + 'Try again</button>'
                   + (offline ? '<a class="bh-btn bh-btn--secondary" href="' + esc(BASE) + '/admin/updates">Open Updates</a>' : '')
        });
        $('mk-spot').hidden = true;
        $('mk-count').hidden = true;
        $('mk-pager').hidden = true;
    }

    /* ---------- the grid ---------- */
    function render(d) {
        var list = d.items || [];
        items = {};
        list.forEach(function (t) { items[t.slug] = t; });
        renderSpot(list);
        var meta = d.meta || {}, total = meta.total != null ? +meta.total : list.length;
        $('mk-count').hidden = !total;
        $('mk-count').textContent = total + ' ' + NOUN + (total === 1 ? '' : 's');
        $('mk-heading').textContent = state.q ? 'Results for “' + state.q + '”'
            : state.category ? state.category
            : state.tag ? 'Tagged “' + state.tag + '”'
            : (IS_APPS ? 'All apps' : 'All themes');
        var filtered = state.q || state.category || state.tag;
        $('mk-grid').innerHTML = list.length ? list.map(card).join('') : stateCard(filtered ? {
            icon: 'magnifying-glass',
            title: 'No ' + NOUN + 's match',
            text: 'Try another word or category, or clear the filters to see every ' + NOUN + '.',
            actions: '<button type="button" class="bh-btn bh-btn--secondary" data-mk-clear>Clear filters</button>'
        } : {
            icon: IS_APPS ? 'puzzle-piece' : 'swatch',
            title: 'No ' + NOUN + 's yet',
            text: 'The marketplace has no ' + NOUN + 's to offer right now. New ones appear here as soon as they are published.'
        });
        paintBusy(root);
        renderPager(meta);
    }

    function load() {
        var my = ++seq;
        $('mk-grid').setAttribute('aria-busy', 'true');
        $('mk-grid').innerHTML = ghosts();
        renderFilter();
        call('browse', {
            q: state.q, category: state.category, tag: state.tag, sort: state.sort, page: state.page
        }, 'GET').then(function (d) {
            if (my !== seq) return;
            render(d || {});
        }).catch(function (err) {
            if (my !== seq) return;
            showFailure(err);
        }).then(function () {
            if (my === seq) $('mk-grid').setAttribute('aria-busy', 'false');
        });
    }

    function renderPager(meta) {
        var pager = $('mk-pager');
        if (!meta.last_page || meta.last_page <= 1) { pager.hidden = true; return; }
        pager.hidden = false;
        var html = '';
        if (meta.page > 1) html += '<button type="button" class="bh-btn bh-btn--secondary bh-btn--sm" data-pg="' + (meta.page - 1) + '">' + ic('arrow-left') + 'Previous</button>';
        html += '<span>Page ' + meta.page + ' of ' + meta.last_page + '</span>';
        if (meta.page < meta.last_page) html += '<button type="button" class="bh-btn bh-btn--secondary bh-btn--sm" data-pg="' + (meta.page + 1) + '">Next' + ic('arrow-right') + '</button>';
        pager.innerHTML = html;
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
        $('mk-cats').querySelectorAll('.bh-mk-chip').forEach(function (c) {
            var on = c.dataset.cat === name;
            c.classList.toggle('is-on', on);
            c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        load();
    }
    function setTag(tag) { state.tag = tag; state.page = 1; load(); }
    function renderFilter() {
        var f = $('mk-filter');
        f.hidden = !state.tag;
        if (state.tag) $('mk-filters').hidden = false;
        f.innerHTML = state.tag
            ? 'Tag <button type="button" class="bh-mk-chip is-on" data-clear-tag aria-label="Remove the tag filter ' + esc(state.tag) + '">' + esc(state.tag) + ic('x-mark', 'w-3.5 h-3.5') + '</button>'
            : '';
    }

    call('facets', {}, 'GET').then(function (d) {
        var cats = $('mk-cats');
        ((d && d.categories) || []).forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'bh-mk-chip'; b.dataset.cat = c.name;
            b.setAttribute('aria-pressed', 'false');
            b.innerHTML = esc(c.name) + '<span class="bh-mk-chip__n">' + (+c.count || 0) + '</span>';
            cats.appendChild(b);
        });
        // Only "All" would be a row with nothing to choose.
        if ((d && d.categories || []).length) $('mk-filters').hidden = false;
    }).catch(function () { /* the grid reports the problem */ });

    /* ---------- details window ---------- */
    function perms(t) {
        var p = t.permissions;
        if (typeof p === 'string') { try { p = JSON.parse(p); } catch (e) { p = null; } }
        return Array.isArray(p) ? p : (p == null ? [] : null);
    }
    function open(slug) {
        var t = items[slug]; if (!t) return;
        var p = parties(t), st = status(t);
        var chips = '';
        if (t.featured == 1) chips += '<span class="bh-badge bh-badge--amber">' + ic('star', '') + 'Featured</span>';
        if (st === 'update') chips += '<span class="bh-badge bh-badge--amber">v' + esc(t.installed_version) + ' installed, update available</span>';
        else if (st === 'current') chips += t.active
            ? '<span class="bh-badge bh-badge--green">' + ic('check-circle', '') + (IS_APPS ? 'Installed and active' : 'Installed and in use') + '</span>'
            : '<span class="bh-badge bh-badge--gray">Installed, not active</span>';

        var facts = [];
        if (IS_APPS) {
            var pm = perms(t);
            facts.push(['Permissions', pm === null ? '' : (pm.length ? '<span class="bh-im-tags">' + pm.map(function (x) { return '<span class="bh-im-perm">' + esc(x) + '</span>'; }).join('') + '</span>' : 'None requested')]);
        }
        var tags = t.tag_list || (typeof t.tags === 'string' ? t.tags.split(',') : []);
        if (tags.length) facts.push(['Tags', '<span class="bh-im-tags">' + tags.map(function (x) {
            x = String(x).trim();
            return '<button type="button" class="bh-im-tag" data-mk-tag="' + esc(x) + '" title="Show ' + NOUN + 's tagged ' + esc(x) + '">' + esc(x) + '</button>';
        }).join('') + '</span>']);
        facts.push(['Added', day(t.created_at)]);
        if (p.dev) facts.push([IS_APPS ? 'Developer' : 'Author', link(p.dev)]);
        if (p.co) facts.push(['Company', link(p.co)]);

        bhItemModal.open({
            key: slug,
            name: t.name,
            iconHtml: IS_APPS ? '<span class="bh-mk-art">' + art(t) + '</span>' : '',
            mediaHtml: !IS_APPS && t.screenshot_url ? '<img src="' + esc(t.screenshot_url) + '" alt="' + esc(t.name) + ' screenshot">' : '',
            whoHtml: who(t, true) ? 'by ' + who(t, true) : '',
            chipsHtml: chips,
            stats: [
                ['Installs', count(t.installs)],
                ['Version', esc(t.version)],
                ['Size', +t.size_bytes > 0 ? bytes(t.size_bytes) : '—'],
                ['Category', t.category ? esc(t.category) : '—']
            ],
            description: t.description || '',
            facts: facts,
            onClose: function () { consentSlug = null; }
        });
        // A consent step shown earlier hid these; a fresh details view shows them.
        var factsEl = $('bh-im-facts'); if (factsEl) factsEl.classList.remove('hidden');
        consentSlug = null;
        sync(slug);
    }
    function sync(slug) {
        if (!window.bhItemModal || bhItemModal.current() !== slug || consentSlug === slug) return;
        paintBusy(bhItemModal.action(action(items[slug], true)));
    }
    function refresh(slug) {
        var t = items[slug]; if (!t) return;
        document.querySelectorAll('.bh-mk-card[data-slug="' + slug + '"]').forEach(function (el) { el.outerHTML = card(t); });
        document.querySelectorAll('.bh-mk-feature[data-slug="' + slug + '"] .mk-act').forEach(function (el) { el.innerHTML = action(t, true); });
        paintBusy(root);
        sync(slug);
    }
    function setBusy(slug, label) {
        if (label) busy[slug] = label; else delete busy[slug];
        refresh(slug);
    }
    function modalState(slug, html, kind) {
        if (window.bhItemModal && bhItemModal.current() === slug) bhItemModal.state(html, kind);
    }

    /* ---------- install ---------- */
    function install(slug) {
        var t = items[slug]; if (!t || busy[slug]) return;
        var wasUpdate = !!t.installed;
        setBusy(slug, wasUpdate ? 'Updating…' : 'Installing…');
        modalState(slug, '');
        call('install', { slug: slug }).then(function () {
            t.installed = true; t.installed_version = t.version;
            setBusy(slug, null);
            var msg = '“' + t.name + '” ' + (wasUpdate ? 'updated to v' + t.version : 'installed') + '.';
            toast(msg + (t.active ? '' : ' Activate it to turn it on.'), 'success');
            modalState(slug, ic('check-circle', '') + esc(msg), 'ok');
        }).catch(function (err) {
            setBusy(slug, null);
            toast(err.message, 'error');
            modalState(slug, ic('exclamation-triangle', '') + esc(err.message), 'error');
        });
    }

    /* ---------- activate (themes directly; apps through consent if asked) ---------- */
    function activate(slug) {
        var t = items[slug]; if (!t || busy[slug]) return;
        if (!IS_APPS) { doActivate(slug, null).catch(function () {}); return; }
        setBusy(slug, 'Checking…');
        call('consent', { slug: slug }, 'GET').then(function (d) {
            setBusy(slug, null);
            if (d.needs_consent && d.permissions && d.permissions.length) renderConsent(slug, d);
            else doActivate(slug, null).catch(function () { /* reported in a toast */ });
        }).catch(function (err) { setBusy(slug, null); toast(err.message, 'error'); });
    }

    function doActivate(slug, granted) {
        var t = items[slug]; if (!t) return;
        var data = { slug: slug };
        if (granted) { data.consent = true; data.permissions = granted; }
        if (!granted) setBusy(slug, IS_APPS ? 'Activating…' : 'Applying…');
        return call('activate', data).then(function (d) {
            setBusy(slug, null);
            if (d && d.needs_consent) {
                // Approval became necessary between the check and the call.
                return call('consent', { slug: slug }, 'GET').then(function (c) { renderConsent(slug, c); });
            }
            if (!IS_APPS) Object.keys(items).forEach(function (k) { if (k !== slug && items[k].active) { items[k].active = false; refresh(k); } });
            t.active = true;
            consentSlug = null;
            refresh(slug);
            var withheld = d && d.withheld_count ? ' ' + d.withheld_count + ' permission' + (d.withheld_count === 1 ? '' : 's') + ' withheld.' : '';
            toast('“' + t.name + '” is ' + (IS_APPS ? 'active.' : 'now your theme.') + withheld, 'success');
        }).catch(function (err) {
            setBusy(slug, null);
            if (consentSlug !== slug) toast(err.message, 'error');   // the permission window shows its own
            throw err;
        });
    }

    /* ---------- consent: the shared permission window (app-consent.js) ---------- */
    function renderConsent(slug, d) {
        if (!window.BasehimAppConsent || !window.bhItemModal) return;
        if (bhItemModal.current() !== slug) open(slug);
        consentSlug = slug;
        BasehimAppConsent.show({
            slug: slug, data: d, mode: 'activate',
            submit: function (granted) { return doActivate(slug, granted); }
        });
    }

    /* ---------- clicks ---------- */
    function onClick(e) {
        var el;
        if ((el = e.target.closest('.mk-install[data-slug]'))) { install(el.dataset.slug); return true; }
        if ((el = e.target.closest('.mk-activate[data-slug]'))) { activate(el.dataset.slug); return true; }
        return false;
    }
    root.addEventListener('click', function (e) {
        if (onClick(e)) return;
        var el;
        if ((el = e.target.closest('.mk-open'))) { var host = el.closest('[data-slug]'); if (host) open(host.getAttribute('data-slug')); return; }
        if ((el = e.target.closest('.bh-mk-chip[data-cat]'))) { setCategory(el.dataset.cat); return; }
        if (e.target.closest('[data-clear-tag]')) { setTag(''); return; }
        if (e.target.closest('[data-mk-retry]')) { load(); return; }
        if (e.target.closest('[data-mk-clear]')) {
            state.q = ''; state.tag = ''; $('mk-q').value = ''; setCategory(''); return;
        }
        if ((el = e.target.closest('[data-pg]'))) { state.page = +el.dataset.pg; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });
    var modalHost = $('bh-im');
    if (modalHost) modalHost.addEventListener('click', function (e) {
        if (onClick(e)) return;
        var el;
        if ((el = e.target.closest('[data-mk-tag]'))) { bhItemModal.close(); setTag(el.getAttribute('data-mk-tag')); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });

    load();
})();
