/**
 * Marketplace (admin) — browse, install, then activate in place.
 *
 * Loaded only on /admin/marketplace (see partials/marketplace.php). All
 * configuration comes from #mk-root's data- attributes; icons come from
 * window.BasehimIcon (icons.js). No markup, styles or config are inlined in
 * the view.
 *
 * Flow: Install downloads and installs the package; the button then becomes
 * Activate. Activating a theme turns it on directly. Activating an app that
 * declares permissions opens a consent modal first — the operator allows them
 * (clearly told the app needs them to work) and the app is activated with the
 * granted set.
 */
(function () {
    'use strict';

    var root = document.getElementById('mk-root');
    if (!root) return;

    var BASE = root.getAttribute('data-base') || '';
    var CSRF = root.getAttribute('data-csrf') || '';
    var KIND = root.getAttribute('data-tab') === 'themes' ? 'themes' : 'apps';
    var IS_APPS = KIND === 'apps';
    var EP = BASE + '/admin/' + KIND + '/marketplace';
    var NOUN = IS_APPS ? 'app' : 'theme';

    var state = { q: '', category: '', tag: '', sort: 'featured', page: 1 };
    var items = {}, busy = {}, busyAct = {}, seq = 0;
    var $ = function (id) { return document.getElementById(id); };

    function ic(name, cls) { return window.BasehimIcon ? window.BasehimIcon(name, cls || '') : ''; }
    var I = {
        piece: ic('puzzle-piece', 'w-6 h-6'),
        photo: ic('photo', 'w-6 h-6'),
        dl:    ic('arrow-down-tray', 'w-4 h-4'),
        up:    ic('arrow-up', 'w-4 h-4'),
        power: ic('bolt', 'w-4 h-4'),
        check: ic('check', 'w-4 h-4'),
        spin:  ic('arrow-path', 'w-4 h-4 animate-spin'),
        users: ic('users', 'w-4 h-4'),
        disk:  ic('circle-stack', 'w-4 h-4'),
        star:  ic('star', 'w-3.5 h-3.5'),
        x:     ic('x-mark', 'w-3.5 h-3.5'),
        shield: ic('shield-check', 'w-4 h-4'),
        ok:    ic('check-circle', 'w-4 h-4 mr-1 inline-block align-text-bottom'),
        warn:  ic('exclamation-triangle', 'w-4 h-4 mr-1 inline-block align-text-bottom')
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
    function post(url, data) {
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k, x); });
            else if (v != null) fd.append(k, v);
        });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
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
    document.addEventListener('error', function (e) {
        var img = e.target;
        if (img && img.tagName === 'IMG' && img.hasAttribute('data-mk-art')) img.outerHTML = fallback();
    }, true);

    /* ---------- the install / activate / update button ---------- */
    function action(t, big) {
        var b = 'mk-btn' + (big ? ' mk-btn--big' : '');
        if (busy[t.slug]) return '<button type="button" disabled class="' + b + ' mk-btn--done">' + I.spin + 'Installing…</button>';
        if (busyAct[t.slug]) return '<button type="button" disabled class="' + b + ' mk-btn--done">' + I.spin + 'Activating…</button>';
        var st = status(t);
        if (st === 'update') return '<button type="button" data-slug="' + esc(t.slug) + '" class="' + b + ' mk-btn--up mk-install">' + I.up + (big ? 'Update to v' + esc(t.version) : 'Update') + '</button>';
        if (st === 'new') return '<button type="button" data-slug="' + esc(t.slug) + '" class="' + b + ' mk-btn--go mk-install">' + (big ? I.dl : '') + 'Install' + '</button>';
        // Installed and current. Active → done; otherwise the next step is Activate.
        if (t.active) {
            return '<button type="button" disabled class="' + b + ' mk-btn--done">' + I.check + (IS_APPS ? 'Active' : 'In use') + '</button>';
        }
        return '<button type="button" data-slug="' + esc(t.slug) + '" class="' + b + ' mk-btn--act mk-activate">' + (big ? I.power : '') + 'Activate</button>';
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
            ? '<span class="mk-art">' + art(t) + '</span>'
            + '<span class="mk-info">'
            +   (t.featured == 1 ? '<span class="mk-kicker">' + I.star + 'Featured</span>' : '')
            +   '<span class="mk-name">' + esc(t.name) + '</span>'
            +   (sub ? '<span class="mk-who">' + sub + '</span>' : '')
            +   '<span class="mk-meta">' + meta + '</span>'
            +   (t.description ? '<span class="mk-desc">' + esc(t.description) + '</span>' : '')
            + '</span>'
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
        else if (st === 'current') chips += '<span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-medium">' + (t.active ? (IS_APPS ? 'Installed and active' : 'Installed and in use') : 'Installed · not active') + '</span>';

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
        // Leaving any prior consent view: restore the detail sections the
        // consent step hides, so reopening the modal shows the facts again.
        var factsEl = $('bh-im-facts'); if (factsEl) factsEl.classList.remove('hidden');
        consentSlug = null;
        sync(slug);
    }
    function sync(slug) {
        if (!window.bhItemModal || bhItemModal.current() !== slug) return;
        bhItemModal.action(action(items[slug], true));
    }
    function refresh(slug) {
        var t = items[slug];
        document.querySelectorAll('[data-slug="' + slug + '"].mk-card').forEach(function (el) { el.outerHTML = card(t); });
        document.querySelectorAll('[data-slug="' + slug + '"].mk-hero .mk-act').forEach(function (el) { el.innerHTML = action(t); });
        sync(slug);
    }

    /* ---------- install ---------- */
    function install(slug) {
        var t = items[slug]; if (!t || busy[slug]) return;
        busy[slug] = true; refresh(slug);
        if (window.bhItemModal && bhItemModal.current() === slug) bhItemModal.state('');
        post(EP + '/install', { slug: slug })
            .then(function (d) {
                busy[slug] = false;
                if (d && d.ok) {
                    var wasUpdate = t.installed;
                    t.installed = true; t.installed_version = t.version;
                    refresh(slug);
                    var msg = '“' + esc(t.name) + '” ' + (wasUpdate ? 'updated to v' + esc(t.version) : 'installed') + '.';
                    if (!t.active) msg += ' Click <strong>Activate</strong> to turn it on.';
                    showStatus(I.ok + msg, 'ok');
                    if (window.bhItemModal && bhItemModal.current() === slug) bhItemModal.state(I.ok + msg, 'ok');
                } else {
                    refresh(slug);
                    var err = esc((d && d.error) || 'Install failed.');
                    showStatus(I.warn + err, 'error');
                    if (window.bhItemModal && bhItemModal.current() === slug) bhItemModal.state(err, 'error');
                }
            })
            .catch(function () {
                busy[slug] = false; refresh(slug);
                showStatus(I.warn + 'Network error during install.', 'error');
            });
    }

    /* ---------- activate (themes directly; apps via consent when needed) ---------- */
    function activate(slug) {
        var t = items[slug]; if (!t || busy[slug] || busyAct[slug]) return;
        if (!IS_APPS) { doActivate(slug); return; }
        // Apps: find out whether permission consent is required first.
        busyAct[slug] = true; refresh(slug);
        get(BASE + '/admin/apps/' + encodeURIComponent(slug) + '/consent.json').then(function (d) {
            busyAct[slug] = false;
            if (!d || !d.ok) { refresh(slug); showStatus(I.warn + esc((d && d.error) || 'Could not read permissions.'), 'error'); return; }
            if (d.needs_consent && d.permissions && d.permissions.length) {
                refresh(slug);
                renderConsent(slug, d);
            } else {
                doActivate(slug);   // no permissions, or already approved
            }
        }).catch(function () { busyAct[slug] = false; refresh(slug); showStatus(I.warn + 'Network error reading permissions.', 'error'); });
    }

    function doActivate(slug, extra) {
        var t = items[slug]; if (!t) return;
        busyAct[slug] = true; refresh(slug);
        var url = IS_APPS
            ? BASE + '/admin/apps/' + encodeURIComponent(slug) + (extra ? '/consent' : '/activate')
            : BASE + '/admin/themes/' + encodeURIComponent(slug) + '/activate';
        post(url, extra || {}).then(function (d) {
            busyAct[slug] = false;
            if (d && d.ok && d.active) {
                onActivated(slug, d);
            } else if (d && d.needs_consent) {
                // Edge: consent became required between the check and the call.
                refresh(slug);
                get(BASE + '/admin/apps/' + encodeURIComponent(slug) + '/consent.json').then(function (c) {
                    if (c && c.ok && c.permissions && c.permissions.length) renderConsent(slug, c);
                });
            } else {
                refresh(slug);
                showStatus(I.warn + esc((d && d.error) || 'Could not activate.'), 'error');
            }
        }).catch(function () { busyAct[slug] = false; refresh(slug); showStatus(I.warn + 'Network error during activation.', 'error'); });
    }

    function onActivated(slug, d) {
        var t = items[slug];
        // One active theme at a time: reflect the switch on the others.
        if (!IS_APPS) Object.keys(items).forEach(function (k) { if (k !== slug && items[k].active) { items[k].active = false; refresh(k); } });
        t.active = true; refresh(slug);
        var note = (d && d.withheld_count) ? ' (' + d.withheld_count + ' permission' + (d.withheld_count === 1 ? '' : 's') + ' withheld)' : '';
        showStatus(I.ok + '“' + esc(t.name) + '” is now ' + (IS_APPS ? 'active' : 'in use') + '.' + esc(note), 'ok');
        if (window.bhToast) bhToast('“' + t.name + '” ' + (IS_APPS ? 'activated' : 'applied'), 'success');
    }

    /* ---------- consent, rendered inside the details modal (#bh-im) ----------
       The old build opened a second .bh-modal overlay, which stacked under the
       open details dialog. Instead, the permission step now loads into the
       already-open details modal: the header and footer are the modal's own,
       and only its body is swapped for the permission list. */
    var consentSlug = null;

    function riskClass(r) { return 'mk-risk mk-risk--' + (['low', 'medium', 'high'].indexOf(r) >= 0 ? r : 'unknown'); }

    /** Detail body sections the consent view hides while it is shown. */
    function hideDetailSections(hide) {
        ['bh-im-chips', 'bh-im-stats', 'bh-im-desc', 'bh-im-facts', 'bh-im-extra'].forEach(function (id) {
            var el = $(id); if (!el) return;
            // bh-im-extra is where consent lives, so it stays visible.
            if (id === 'bh-im-extra') return;
            el.classList.toggle('hidden', !!hide);
        });
    }

    function renderConsent(slug, d) {
        if (!window.bhItemModal) return;
        // The permission step belongs in this app's details modal — open it if
        // the operator activated straight from a grid card.
        if (bhItemModal.current() !== slug) open(slug);
        consentSlug = slug;

        var t = items[slug] || { name: (d && d.name) || slug };
        var perms = (d && d.permissions) || [];
        var rows = perms.map(function (pp) {
            return '<li><label class="mk-perm-row">'
                 + '<input type="checkbox" name="perm" value="' + esc(pp.key) + '" checked>'
                 + '<span class="mk-perm-row__main">'
                 +   '<span class="mk-perm-row__top"><span class="mk-perm-row__label">' + esc(pp.label || pp.key) + '</span>'
                 +     '<span class="' + riskClass(pp.risk) + '">' + esc(pp.risk || 'info') + '</span></span>'
                 +   '<span class="mk-perm-row__desc">' + esc(pp.description || '') + '</span>'
                 + '</span></label></li>';
        }).join('');
        var unknown = (d && d.unknown) || [];
        var unknownHtml = unknown.length
            ? '<p class="mk-consent__unknown">' + I.warn + 'These requested items aren’t recognised and grant nothing: ' + unknown.map(esc).join(', ') + '.</p>'
            : '';

        hideDetailSections(true);
        $('bh-im-extra').innerHTML =
            '<div class="mk-consent">'
          +   '<p class="mk-consent__lead">“' + esc(t.name || slug) + '” needs the permissions below to work properly. Allow them to finish turning it on — withhold any you’re not comfortable with, or change them later under Apps.</p>'
          +   '<div class="mk-consent__note">' + I.shield + '<span>Only grant apps you trust. Each permission is one the app explicitly asked for.</span></div>'
          +   (rows ? '<ul class="mk-perms">' + rows + '</ul>' : '<p class="mk-consent__none">This app asks for no special permissions.</p>')
          +   unknownHtml
          + '</div>';

        bhItemModal.state('');
        bhItemModal.action(
            '<button type="button" class="mk-btn mk-btn--big mk-btn--line" data-consent-none>Activate without extras</button>'
          + '<button type="button" class="mk-btn mk-btn--big mk-btn--act" data-consent-allow>' + I.check + 'Allow &amp; activate</button>'
        );
        var allow = document.querySelector('#bh-im-action [data-consent-allow]');
        if (allow) allow.focus();
    }

    function submitConsent(granted) {
        var slug = consentSlug; if (!slug) return;
        var btns = document.querySelectorAll('#bh-im-action button');
        Array.prototype.forEach.call(btns, function (b) { b.setAttribute('disabled', 'disabled'); });
        bhItemModal.state(I.spin + ' Activating…', 'info');
        var fields = { activate: '1' };
        fields['permissions[]'] = granted;   // array → repeated fields
        post(BASE + '/admin/apps/' + encodeURIComponent(slug) + '/consent', fields).then(function (d) {
            if (d && d.ok && d.active) { consentSlug = null; bhItemModal.close(); onActivated(slug, d); }
            else {
                Array.prototype.forEach.call(btns, function (b) { b.removeAttribute('disabled'); });
                bhItemModal.state(I.warn + esc((d && d.error) || 'Could not activate.'), 'error');
            }
        }).catch(function () {
            Array.prototype.forEach.call(btns, function (b) { b.removeAttribute('disabled'); });
            bhItemModal.state(I.warn + 'Network error during activation.', 'error');
        });
    }

    /* ---------- clicks: install / activate / open details / tags ---------- */
    function onClick(e) {
        var inst = e.target.closest('.mk-install[data-slug]');
        if (inst) { install(inst.dataset.slug); return; }
        var act = e.target.closest('.mk-activate[data-slug]');
        if (act) { activate(act.dataset.slug); return; }
        var o = e.target.closest('.mk-open');
        if (o) { var host = o.closest('[data-slug]'); if (host) open(host.getAttribute('data-slug')); }
    }
    root.addEventListener('click', onClick);
    var modalHost = $('bh-im');
    if (modalHost) modalHost.addEventListener('click', function (e) {
        if (e.target.closest('.mk-install[data-slug]') || e.target.closest('.mk-activate[data-slug]')) { onClick(e); return; }
        if (e.target.closest('[data-consent-none]')) { submitConsent([]); return; }
        if (e.target.closest('[data-consent-allow]')) {
            var checked = Array.prototype.map.call(
                modalHost.querySelectorAll('input[name="perm"]:checked'),
                function (c) { return c.value; });
            submitConsent(checked);
            return;
        }
        var tag = e.target.closest('[data-mk-tag]');
        if (tag) { bhItemModal.close(); setTag(tag.getAttribute('data-mk-tag')); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });

    function renderPager(meta) {
        var pager = $('mk-pager');
        if (!meta.last_page || meta.last_page <= 1) { pager.classList.add('hidden'); pager.classList.remove('flex'); return; }
        pager.classList.remove('hidden'); pager.classList.add('flex');
        var b = 'mk-pg inline-flex items-center gap-1 px-3 py-1.5 border border-slate-200 rounded-full text-sm bg-white hover:bg-slate-50';
        var html = '';
        if (meta.page > 1) html += '<button type="button" data-pg="' + (meta.page - 1) + '" class="' + b + '">' + ic('arrow-left', 'w-4 h-4') + 'Previous</button>';
        html += '<span class="text-sm text-slate-500">Page ' + meta.page + ' of ' + meta.last_page + '</span>';
        if (meta.page < meta.last_page) html += '<button type="button" data-pg="' + (meta.page + 1) + '" class="' + b + '">Next' + ic('arrow-right', 'w-4 h-4') + '</button>';
        pager.innerHTML = html;
        pager.querySelectorAll('.mk-pg').forEach(function (x) {
            x.addEventListener('click', function () { state.page = +x.dataset.pg; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        });
    }

    load();
})();
