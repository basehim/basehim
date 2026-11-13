/*
 * Basehim frontend admin bar (1.2.96). See docs/ADMIN-BAR.md.
 *
 * The server draws the bar and decides what is in it; this script makes it
 * work: dropdowns (click or hover, keyboard, outside click and Escape),
 * panels and badges loaded when needed, quick search, notifications, Quick
 * edit, the display choices, and the floating button (draggable).
 *
 * Every request goes through Basehim.ajax with the page's signed context;
 * the server checks each one again. Nothing here decides what a user may
 * see or do.
 */
(function (window, document) {
    'use strict';

    var cfg = window.BASEHIM_ADMIN_BAR;
    var root = document.getElementById('bh-admin-bar');
    if (!cfg || !root || root.__bhab) return;
    root.__bhab = true;

    var bar = root.querySelector('.bhab-bar');
    var fab = root.querySelector('.bhab-fab');
    var tab = root.querySelector('.bhab-tab');
    var live = root.querySelector('.bhab-live');
    var html = document.documentElement;
    var mqMobile = window.matchMedia ? window.matchMedia('(max-width: 782px)') : { matches: false, addEventListener: function () {} };
    var finePointer = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : { matches: true };
    var state = { mode: cfg.mode, position: cfg.position, collapsed: !!cfg.collapsed, hidden: (cfg.hidden || []).slice(), openTop: null };
    var loadedPanels = {};
    var notices = null;

    // --- helpers -----------------------------------------------------------
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var holder = document.createElement('span');
        holder.innerHTML = (cfg.icons && cfg.icons[name]) || '';
        return holder.firstChild || document.createTextNode('');
    }
    function ajax() { return window.Basehim && window.Basehim.ajax; }
    function get(action, data) {
        var a = ajax();
        if (!a) return Promise.reject(new Error('The admin bar could not reach the site. Reload the page.'));
        data = data || {};
        data.ctx = cfg.ctx;
        return a.request(action, data, { method: 'GET' });
    }
    function post(action, data) {
        var a = ajax();
        if (!a) return Promise.reject(new Error('The admin bar could not reach the site. Reload the page.'));
        return a.request(action, data || {});
    }
    function href(u) {
        if (!u) return '#';
        if (u.charAt(0) === '/' && cfg.base && u.indexOf(cfg.base + '/') !== 0) return cfg.base + u;
        return u;
    }
    function announce(msg) { if (live) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); } }
    // Dialogs and toasts live outside the bar, so they take its look
    // (light/dark/device and accent, 1.3.0) from the bar's own attributes.
    function look(node) {
        ['data-theme', 'data-appearance', 'data-accent'].forEach(function (k) {
            if (root.hasAttribute(k)) node.setAttribute(k, root.getAttribute(k)); else node.removeAttribute(k);
        });
    }
    var toastTimer = null;
    function toast(msg, isError) {
        var t = document.querySelector('.bhab-toast') || el('div', 'bhab-toast');
        t.className = 'bhab-toast' + (isError ? ' bhab-toast--error' : '');
        look(t);
        t.setAttribute('role', isError ? 'alert' : 'status');
        t.textContent = msg;
        if (!t.parentNode) document.body.appendChild(t);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 4200);
    }
    function idle(fn) { (window.requestIdleCallback || function (f) { return setTimeout(f, 300); })(fn, { timeout: 2500 }); }
    function store(kind, key, value) {
        try {
            var s = kind === 'session' ? window.sessionStorage : window.localStorage;
            if (value === undefined) return s.getItem(key);
            if (value === null) s.removeItem(key); else s.setItem(key, value);
        } catch (e) { return null; }
        return null;
    }
    function effectiveMode() {
        if (mqMobile.matches && cfg.mobile === 'floating') return 'floating';
        return state.mode;
    }
    function savePrefs(data) {
        return post('admin.bar_prefs', data).catch(function (err) {
            toast((err && err.message) || 'Your choice could not be saved.', true);
            throw err;
        });
    }

    // --- layout: push the page down, offset fixed headers --------------------
    var shifted = [];
    function barHeight() { return mqMobile.matches ? 46 : (parseInt(getComputedStyle(root).getPropertyValue('--bhab-h'), 10) || 40); }
    function layout() {
        var mode = effectiveMode();
        root.classList.toggle('bhab--horizontal', mode === 'horizontal');
        root.classList.toggle('bhab--floating', mode === 'floating');
        root.classList.toggle('is-collapsed', mode === 'horizontal' && state.collapsed);
        if (tab) tab.hidden = !(mode === 'horizontal' && state.collapsed);
        var early = document.getElementById('bhab-early');
        if (early && early.parentNode) early.parentNode.removeChild(early);
        var hiddenOnMobile = mqMobile.matches && cfg.mobile === 'hidden';
        var push = mode === 'horizontal' && !state.collapsed && !cfg.autoHide && !hiddenOnMobile && !root.hidden;
        html.classList.toggle('bhab-push', push);
        html.style.setProperty('--bhab-push', barHeight() + 'px');
        html.classList.add('has-bh-admin-bar');
        shiftFixed(push ? barHeight() : 0);
    }
    /**
     * A theme's fixed or sticky header at the very top would sit under the
     * bar: move it down by the bar's height (and back when the bar goes).
     * Only elements near the top of the document's structure are looked at.
     */
    function shiftFixed(by) {
        shifted.forEach(function (n) { n.style.top = n.__bhabTop || ''; });
        shifted = [];
        if (!by) return;
        var seen = 0;
        (function walk(parent, depth) {
            for (var i = 0; i < parent.children.length && seen < 400; i++) {
                var n = parent.children[i];
                seen++;
                if (n === root || n.classList.contains('bhab-dialog') || n.tagName === 'SCRIPT' || n.tagName === 'STYLE') continue;
                var cs = getComputedStyle(n);
                if ((cs.position === 'fixed' || cs.position === 'sticky') && parseFloat(cs.top) === 0 && cs.display !== 'none') {
                    n.__bhabTop = n.style.top;
                    n.style.top = by + 'px';
                    shifted.push(n);
                    continue;
                }
                if (depth < 3) walk(n, depth + 1);
            }
        })(document.body, 0);
    }

    // --- dropdowns ------------------------------------------------------------
    function topOf(btn) { return btn.closest('.bhab-menu'); }
    function dropOf(btn) { return btn.getAttribute('aria-controls') ? document.getElementById(btn.getAttribute('aria-controls')) : null; }
    function tops() { return Array.prototype.slice.call(bar.querySelectorAll('.bhab-menu:not(.is-user-hidden) > .bhab-top')); }

    function openMenu(btn, focusFirst) {
        var drop = dropOf(btn);
        if (!drop) return;
        if (state.openTop && state.openTop !== btn) closeMenu(state.openTop);
        btn.setAttribute('aria-expanded', 'true');
        drop.hidden = false;
        state.openTop = btn;
        fit(drop);
        loadPanels(drop);
        if (btn.getAttribute('data-bhab-action') === 'notifications') renderNotices(drop);
        if (btn.getAttribute('data-bhab-action') === 'display') renderSections(drop);
        if (focusFirst) { var f = focusables(drop)[0]; if (f) f.focus(); }
    }
    function closeMenu(btn, refocus) {
        if (!btn) return;
        var drop = dropOf(btn);
        btn.setAttribute('aria-expanded', 'false');
        if (drop) drop.hidden = true;
        if (state.openTop === btn) state.openTop = null;
        if (refocus) btn.focus();
    }
    function closeAll() { if (state.openTop) closeMenu(state.openTop); }
    /** Keep a dropdown inside the window on the horizontal bar. */
    function fit(drop) {
        if (effectiveMode() !== 'horizontal' || mqMobile.matches) return;
        drop.style.left = ''; drop.style.right = '';
        var r = drop.getBoundingClientRect();
        if (r.right > window.innerWidth - 8) { drop.style.left = 'auto'; drop.style.right = '0'; }
        r = drop.getBoundingClientRect();
        if (r.left < 8) { drop.style.left = '0'; drop.style.right = 'auto'; }
    }
    function focusables(scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])'))
            .filter(function (n) { return n.offsetParent !== null; });
    }

    bar.addEventListener('click', function (e) {
        var top = e.target.closest('.bhab-top');
        if (top && bar.contains(top)) {
            var action = top.getAttribute('data-bhab-action');
            if (action === 'search') { e.preventDefault(); closeAll(); openSearch(); return; }
            if (top.getAttribute('aria-controls')) {
                e.preventDefault();
                if (top.getAttribute('aria-expanded') === 'true') closeMenu(top); else openMenu(top, e.detail === 0);
            }
            return;
        }
        var item = e.target.closest('.bhab-item');
        if (!item) return;
        if (item.getAttribute('aria-controls')) {
            e.preventDefault();
            var sub = document.getElementById(item.getAttribute('aria-controls'));
            var open = item.getAttribute('aria-expanded') !== 'true';
            item.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (sub) { sub.hidden = !open; if (open) loadPanels(sub); }
            return;
        }
        var act = item.getAttribute('data-bhab-action');
        if (act) { e.preventDefault(); runAction(act, item); }
    });

    // Hover opens on a mouse when the site chose it; touch always uses taps.
    if (cfg.openOn === 'hover') {
        var hoverTimer = null;
        bar.addEventListener('mouseover', function (e) {
            if (!finePointer.matches || effectiveMode() !== 'horizontal') return;
            var li = e.target.closest('.bhab-menu');
            if (!li) return;
            var btn = li.querySelector(':scope > .bhab-top[aria-controls]');
            clearTimeout(hoverTimer);
            if (btn && btn !== state.openTop) hoverTimer = setTimeout(function () { openMenu(btn, false); }, 90);
        });
        bar.addEventListener('mouseleave', function () {
            if (!finePointer.matches || effectiveMode() !== 'horizontal') return;
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(closeAll, 280);
        });
        bar.addEventListener('mouseenter', function () { clearTimeout(hoverTimer); });
    }

    document.addEventListener('click', function (e) {
        if (root.contains(e.target)) return;
        if (e.target.closest && e.target.closest('.bhab-dialog')) return;
        closeAll();
        if (effectiveMode() === 'floating' && root.classList.contains('is-open')) setFloatingOpen(false);
    });

    bar.addEventListener('keydown', function (e) {
        var key = e.key;
        var top = e.target.closest('.bhab-top');
        if (key === 'Escape') {
            if (state.openTop) { e.preventDefault(); closeMenu(state.openTop, true); }
            else if (effectiveMode() === 'floating') { setFloatingOpen(false); fab.focus(); }
            return;
        }
        if (top && effectiveMode() === 'horizontal') {
            var list = tops(), i = list.indexOf(top);
            if (key === 'ArrowRight' || key === 'ArrowLeft') {
                e.preventDefault();
                var next = list[(i + (key === 'ArrowRight' ? 1 : -1) + list.length) % list.length];
                var wasOpen = !!state.openTop;
                closeAll();
                next.focus();
                if (wasOpen && next.getAttribute('aria-controls')) openMenu(next, false);
            } else if (key === 'ArrowDown' && top.getAttribute('aria-controls')) {
                e.preventDefault();
                openMenu(top, true);
            }
            return;
        }
        var drop = e.target.closest('.bhab-drop');
        if (drop && (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Home' || key === 'End')) {
            e.preventDefault();
            var f = focusables(drop), j = f.indexOf(document.activeElement);
            var k = key === 'Home' ? 0 : key === 'End' ? f.length - 1 : (j + (key === 'ArrowDown' ? 1 : -1) + f.length) % f.length;
            if (f[k]) f[k].focus();
        }
        if (drop && key === 'Tab' && effectiveMode() === 'horizontal') {
            // Tabbing out of a dropdown closes it.
            setTimeout(function () { if (state.openTop && !topOf(state.openTop).contains(document.activeElement)) closeAll(); }, 0);
        }
    });

    // --- panels ------------------------------------------------------------------
    /** Load the panels now visible in a dropdown (not those in closed sub-lists). */
    function loadPanels(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('.bhab-panel'), function (p) {
            for (var n = p.parentNode; n && n !== scope; n = n.parentNode) if (n.hidden) return;
            var id = p.getAttribute('data-bhab-panel');
            if (!loadedPanels[id]) loadPanel(p);
        });
    }
    function loadPanel(p) {
        var id = p.getAttribute('data-bhab-panel');
        var body = p.querySelector('.bhab-panel__body');
        loadedPanels[id] = true;
        p.setAttribute('aria-busy', 'true');
        if (!body.firstChild) body.appendChild(el('p', 'bhab-muted', 'Loading…'));
        get('admin.bar_panel', { id: id }).then(function (r) {
            p.setAttribute('aria-busy', 'false');
            body.textContent = '';
            if (!r.ok) { body.appendChild(el('p', 'bhab-error', r.error || 'This panel could not be loaded.')); return; }
            drawPanel(body, r.data || {});
        }).catch(function (err) {
            p.setAttribute('aria-busy', 'false');
            loadedPanels[id] = false;
            body.textContent = '';
            body.appendChild(el('p', 'bhab-error', (err && err.message) || 'This panel could not be loaded.'));
        });
    }
    root.addEventListener('click', function (e) {
        var r = e.target.closest('[data-bhab-refresh]');
        if (!r) return;
        e.preventDefault();
        var p = r.closest('.bhab-panel');
        if (p && p.getAttribute('aria-busy') !== 'true') loadPanel(p);
    });
    function tone(node, t) { if (t) { node.classList.add('bhab-tone', 'bhab-tone--' + t); } return node; }
    function drawPanel(body, d) {
        var any = false;
        if (d.score) {
            any = true;
            var s = el('div', 'bhab-score');
            var num = el('div', 'bhab-score__num', fmt(d.score.value));
            num.appendChild(el('span', 'bhab-score__max', ' / ' + fmt(d.score.max)));
            var meter = el('div', 'bhab-meter' + (d.score.tone ? ' bhab-meter--' + d.score.tone : ''));
            meter.setAttribute('role', 'meter');
            meter.setAttribute('aria-valuemin', '0'); meter.setAttribute('aria-valuemax', String(d.score.max)); meter.setAttribute('aria-valuenow', String(d.score.value));
            if (d.score.label) meter.setAttribute('aria-label', d.score.label);
            var fill = el('span'); fill.style.width = Math.max(0, Math.min(100, d.score.value / d.score.max * 100)) + '%';
            meter.appendChild(fill);
            s.appendChild(num); s.appendChild(meter);
            body.appendChild(s);
        }
        if (d.text) { any = true; body.appendChild(el('p', 'bhab-text', d.text)); }
        if (d.rows) {
            any = true;
            var dl = el('dl', 'bhab-rows');
            d.rows.forEach(function (r) {
                dl.appendChild(el('dt', '', r.label));
                var dd = el('dd');
                var v = r.url ? el('a', '', r.value) : el('span', '', r.value);
                if (r.url) v.href = href(r.url);
                dd.appendChild(tone(v, r.tone));
                dl.appendChild(dd);
            });
            body.appendChild(dl);
        }
        if (d.list) {
            any = true;
            var list = el('div', 'bhab-plist');
            d.list.forEach(function (r) {
                var row = r.url ? el('a') : el('span', 'bhab-plist__row');
                if (r.url) row.href = href(r.url);
                row.appendChild(tone(el('span', 'bhab-plist__title', r.title), r.tone));
                if (r.meta) row.appendChild(el('span', 'bhab-plist__meta', r.meta));
                list.appendChild(row);
            });
            body.appendChild(list);
        }
        if (d.actions) {
            var acts = el('div', 'bhab-actions');
            d.actions.forEach(function (a) { var x = el('a', '', a.label); x.href = href(a.url); acts.appendChild(x); });
            body.appendChild(acts);
            any = true;
        }
        if (!any) body.appendChild(el('p', 'bhab-muted', d.empty || 'Nothing to show here yet.'));
    }
    function fmt(n) { return Number(n).toLocaleString(undefined, { maximumFractionDigits: 1 }); }

    // --- badges (lazy indicators) ---------------------------------------------------
    function loadIndicators() {
        if (!cfg.lazy || !cfg.lazy.length) return;
        get('admin.bar_indicators', { ids: cfg.lazy.join(',') }).then(function (r) {
            var values = r.values || {};
            Array.prototype.forEach.call(root.querySelectorAll('[data-bhab-ind]'), function (b) {
                var v = values[b.getAttribute('data-bhab-ind')];
                if (!v || b.getAttribute('data-done')) return;
                drawBadge(b, v);
            });
        }).catch(function () { /* badges are a convenience: the menus work without them */ });
    }
    function drawBadge(b, v) {
        b.setAttribute('data-done', '1');
        b.className = 'bhab-badge bhab-badge--' + (v.tone || 'neutral');
        b.textContent = '';
        var shown = el('span', '', String(v.value));
        shown.setAttribute('aria-hidden', 'true');
        b.appendChild(shown);
        b.appendChild(el('span', 'bhab-sr', String(v.value) + (v.label ? ' ' + v.label : '')));
        b.hidden = false;
    }

    // --- notifications ----------------------------------------------------------------
    var NOTICE_TTL = 120000;
    function noticeKey() { return 'bhab-notices-' + cfg.user; }
    function loadNotices(force) {
        if (!cfg.notifications) return Promise.resolve([]);
        if (!force) {
            try {
                var cached = JSON.parse(store('session', noticeKey()) || 'null');
                if (cached && Date.now() - cached.at < NOTICE_TTL) { notices = cached.items; setBell(); return Promise.resolve(notices); }
            } catch (e) { /* fetch again */ }
        }
        return get('admin.bar_notifications').then(function (r) {
            notices = r.items || [];
            store('session', noticeKey(), JSON.stringify({ at: Date.now(), items: notices }));
            setBell();
            return notices;
        });
    }
    function setBell() {
        var n = (notices || []).filter(function (x) { return x.tone === 'danger' || x.tone === 'warning' || x.tone === 'info'; }).length;
        var top = bar.querySelector('.bhab-menu--notifications > .bhab-top');
        if (top) {
            var b = top.querySelector('.bhab-badge');
            if (!b) { b = el('span', 'bhab-badge'); top.appendChild(b); }
            var urgent = (notices || []).some(function (x) { return x.tone === 'danger'; });
            b.className = 'bhab-badge bhab-badge--' + (urgent ? 'danger' : 'info');
            b.textContent = n > 9 ? '9+' : String(n);
            b.hidden = n === 0;
            top.setAttribute('aria-label', n ? 'Notifications, ' + n + ' new' : 'Notifications');
        }
        var fb = fab && fab.querySelector('.bhab-fab__badge');
        if (fb) { fb.textContent = n > 9 ? '9+' : String(n); fb.hidden = n === 0; }
    }
    function renderNotices(drop) {
        var box = drop.querySelector('[data-bhab-notices]');
        if (!box) return;
        var draw = function (items) {
            box.textContent = '';
            if (!items.length) {
                var empty = el('div', 'bhab-empty');
                empty.appendChild(icon('check-circle'));
                empty.appendChild(el('span', '', 'Nothing needs your attention.'));
                box.appendChild(empty);
                return;
            }
            items.forEach(function (n) {
                var row = n.url ? el('a', 'bhab-notice bhab-notice--' + n.tone) : el('div', 'bhab-notice bhab-notice--' + n.tone);
                if (n.url) row.href = href(n.url);
                row.appendChild(icon({ danger: 'exclamation-circle', warning: 'exclamation-triangle', success: 'check-circle' }[n.tone] || 'information-circle'));
                var t = el('div');
                t.appendChild(el('div', 'bhab-notice__title', n.title));
                if (n.text) t.appendChild(el('div', 'bhab-notice__text', n.text));
                row.appendChild(t);
                box.appendChild(row);
            });
        };
        if (notices) draw(notices);
        loadNotices(true).then(draw).catch(function (err) {
            if (notices) return;
            box.textContent = '';
            box.appendChild(el('p', 'bhab-error', (err && err.message) || 'Notifications could not be loaded.'));
        });
    }

    // --- quick search -------------------------------------------------------------------
    var search = null;
    function dialog(cls, labelledBy) {
        var d = el('div', 'bhab-dialog');
        look(d);
        d.setAttribute('role', 'dialog');
        d.setAttribute('aria-modal', 'true');
        if (labelledBy) d.setAttribute('aria-labelledby', labelledBy);
        var box = el('div', 'bhab-dialog__box ' + (cls || ''));
        d.appendChild(box);
        d.addEventListener('mousedown', function (e) { d.__down = e.target === d; });
        d.addEventListener('click', function (e) { if (e.target === d && d.__down && d.__close) d.__close(); });
        d.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && d.__close) { e.preventDefault(); e.stopPropagation(); d.__close(); }
            if (e.key === 'Tab') {
                var f = focusables(box);
                if (!f.length) return;
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
            }
        });
        return { root: d, box: box };
    }
    function openSearch() {
        if (!cfg.search) return;
        if (search) { search.input.focus(); return; }
        var opener = document.activeElement;
        var dlg = dialog('', 'bhab-search-label');
        var label = el('span', 'bhab-sr', 'Search the site\'s content'); label.id = 'bhab-search-label';
        var field = el('div', 'bhab-search__field');
        var input = el('input', 'bhab-search__input');
        input.type = 'search'; input.placeholder = 'Search posts, pages, media, users…'; input.autocomplete = 'off';
        input.setAttribute('aria-labelledby', 'bhab-search-label');
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-expanded', 'true'); input.setAttribute('aria-controls', 'bhab-search-results');
        var x = el('button', 'bhab-dialog__x'); x.type = 'button'; x.setAttribute('aria-label', 'Close search'); x.appendChild(icon('x-mark'));
        field.appendChild(label); field.appendChild(icon('magnifying-glass')); field.appendChild(input); field.appendChild(x);
        var results = el('div', 'bhab-search__results'); results.id = 'bhab-search-results'; results.setAttribute('role', 'listbox');
        results.appendChild(el('p', 'bhab-muted', 'Type at least two letters.'));
        var hint = el('div', 'bhab-search__hint');
        hint.innerHTML = '<span><kbd>↑</kbd> <kbd>↓</kbd> to choose</span><span><kbd>Enter</kbd> to open</span><span><kbd>Esc</kbd> to close</span>';
        dlg.box.appendChild(field); dlg.box.appendChild(results); dlg.box.appendChild(hint);
        document.body.appendChild(dlg.root);
        var seq = 0, timer = null, items = [], sel = -1;
        function close() {
            if (dlg.root.parentNode) dlg.root.parentNode.removeChild(dlg.root);
            search = null;
            if (opener && opener.focus) opener.focus();
        }
        function select(i) {
            items.forEach(function (n, k) { n.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            sel = i;
            if (items[i]) { items[i].scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', items[i].id); }
        }
        function run() {
            var q = input.value.trim();
            var my = ++seq;
            if (q.length < 2) { results.textContent = ''; results.appendChild(el('p', 'bhab-muted', 'Type at least two letters.')); items = []; return; }
            get('admin.bar_search', { q: q }).then(function (r) {
                if (my !== seq) return;
                results.textContent = ''; items = []; sel = -1;
                if (!r.groups || !r.groups.length) { results.appendChild(el('p', 'bhab-muted', 'Nothing found for “' + q + '”.')); return; }
                r.groups.forEach(function (g) {
                    results.appendChild(el('div', 'bhab-search__group', g.label));
                    g.items.forEach(function (it) {
                        var a = el('a', 'bhab-search__item');
                        a.href = href(it.url); a.id = 'bhab-sr-' + items.length;
                        a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false'); a.tabIndex = -1;
                        a.appendChild(icon(it.icon || 'document-text'));
                        a.appendChild(el('span', 'bhab-search__title', it.title));
                        if (it.meta) a.appendChild(el('span', 'bhab-search__meta', it.meta));
                        results.appendChild(a); items.push(a);
                    });
                });
                select(0);
                announce(items.length + ' results');
            }).catch(function (err) {
                if (my !== seq) return;
                results.textContent = '';
                results.appendChild(el('p', 'bhab-muted bhab-error', (err && err.message) || 'Search failed. Try again.'));
            });
        }
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) select((sel + 1) % items.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) select((sel - 1 + items.length) % items.length); }
            else if (e.key === 'Enter' && items[sel]) { e.preventDefault(); window.location.href = items[sel].href; }
        });
        x.addEventListener('click', close);
        dlg.root.__close = close;
        search = { input: input };
        input.focus();
    }

    // --- Quick edit ------------------------------------------------------------------------
    /*
     * The same Quick edit the admin lists use (content.quick_edit and
     * content.quick_edit_save, with their checks and edit-conflict
     * protection). Title, address, status, author, parent, order and
     * comments are edited here; categories, tags and scheduling open the
     * editor, where their own controls live.
     */
    var SIMPLE = { text: 1, slug: 1, select: 1, toggle: 1, number: 1 };
    function quickEdit(item) {
        var table = item.getAttribute('data-table'), id = parseInt(item.getAttribute('data-id'), 10);
        var editHref = (bar.querySelector('[data-bhab-id="context.edit"] a') || {}).href || '';
        closeAll();
        var opener = document.activeElement;
        var dlg = dialog('', 'bhab-qe-title');
        var head = el('div', 'bhab-dialog__head');
        var titles = el('div', 'bhab-dialog__titles');
        var h = el('h2', 'bhab-dialog__title', 'Quick edit'); h.id = 'bhab-qe-title';
        var sub = el('div', 'bhab-dialog__sub', '');
        titles.appendChild(h); titles.appendChild(sub);
        var x = el('button', 'bhab-dialog__x'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.appendChild(icon('x-mark'));
        head.appendChild(titles); head.appendChild(x);
        var body = el('div', 'bhab-dialog__body'); body.appendChild(el('p', 'bhab-muted', 'Loading…'));
        var foot = el('div', 'bhab-dialog__foot');
        var cancel = el('button', 'bhab-btn', 'Cancel'); cancel.type = 'button';
        var save = el('button', 'bhab-btn bhab-btn--primary', 'Save'); save.type = 'submit'; save.disabled = true;
        foot.appendChild(cancel); foot.appendChild(save);
        var form = el('form', 'bhab-dialog__form'); form.noValidate = true;
        form.appendChild(head); form.appendChild(body); form.appendChild(foot);
        dlg.box.appendChild(form);
        document.body.appendChild(dlg.root);
        x.focus();

        var st = { fields: [], inputs: {}, initial: {}, version: '', busy: false };
        function dirty() { return Object.keys(changes()).length > 0; }
        function close(force) {
            if (!force && dirty() && !window.confirm('Discard your changes?')) return;
            if (dlg.root.parentNode) dlg.root.parentNode.removeChild(dlg.root);
            if (opener && opener.focus) opener.focus();
        }
        dlg.root.__close = function () { close(false); };
        x.addEventListener('click', function () { close(false); });
        cancel.addEventListener('click', function () { close(false); });

        function valueOf(name) {
            var i = st.inputs[name];
            if (!i) return undefined;
            if (i.type === 'checkbox') return !!i.checked;
            return i.value;
        }
        function changes() {
            var out = {};
            Object.keys(st.inputs).forEach(function (n) { var v = valueOf(n); if (v !== st.initial[n]) out[n] = v; });
            return out;
        }
        function sync() { save.disabled = st.busy || !dirty(); }

        window.Basehim.ajax.get('content.quick_edit', { table: table, id: id }).then(function (d) {
            var f = d.form;
            st.version = f.version;
            sub.textContent = f.title || '';
            body.textContent = '';
            var grid = el('div', 'bhab-form');
            var skipped = [];
            (f.fields || []).forEach(function (fd) {
                if (!SIMPLE[fd.type]) { skipped.push(fd.type === 'publish' ? 'Publish date' : fd.label); return; }
                var wrap = el('div', 'bhab-field' + (fd.span && fd.span <= 3 ? ' bhab-field--half' : ''));
                var fid = 'bhab-qe-' + fd.name, input;
                if (fd.type === 'toggle') {
                    var lab = el('label', 'bhab-toggle');
                    input = el('input'); input.type = 'checkbox'; input.checked = !!fd.value; input.id = fid;
                    lab.appendChild(input); lab.appendChild(el('span', '', fd.label));
                    wrap.appendChild(lab);
                    st.initial[fd.name] = !!fd.value;
                } else {
                    var l = el('label', '', fd.label); l.htmlFor = fid; wrap.appendChild(l);
                    if (fd.type === 'select') {
                        input = el('select', 'bhab-select');
                        (fd.options || []).forEach(function (o) { var op = el('option', '', o.label); op.value = String(o.value); input.appendChild(op); });
                        input.value = String(fd.value == null ? '' : fd.value);
                    } else {
                        input = el('input', 'bhab-input');
                        input.type = fd.type === 'number' ? 'number' : 'text';
                        if (fd.max && fd.type !== 'number') input.maxLength = fd.max;
                        if (fd.placeholder) input.placeholder = fd.placeholder;
                        if (fd.type === 'slug') { input.spellcheck = false; input.setAttribute('autocapitalize', 'off'); }
                        input.value = fd.value == null ? '' : String(fd.value);
                    }
                    input.id = fid;
                    wrap.appendChild(input);
                    st.initial[fd.name] = input.value;
                }
                var err = el('p', 'bhab-field__error'); err.hidden = true; err.id = fid + '-err';
                input.setAttribute('aria-describedby', err.id);
                wrap.appendChild(err);
                input.name = fd.name;
                input.addEventListener('input', sync); input.addEventListener('change', sync);
                st.inputs[fd.name] = input;
                st.errors = st.errors || {}; st.errors[fd.name] = err;
                grid.appendChild(wrap);
            });
            if (skipped.length) {
                var note = el('p', 'bhab-note');
                note.appendChild(document.createTextNode(skipped.join(', ') + ': change them in the editor. '));
                if (editHref) { var a = el('a', '', 'Open the editor'); a.href = editHref; note.appendChild(a); }
                grid.appendChild(note);
            }
            body.appendChild(grid);
            var first = grid.querySelector('input, select');
            if (first && finePointer.matches) first.focus();
            sync();
        }).catch(function (err) {
            body.textContent = '';
            body.appendChild(el('p', 'bhab-note bhab-note--error', (err && err.message) || 'Quick edit could not be opened.'));
        });

        function showErrors(fields) {
            Object.keys(st.errors || {}).forEach(function (n) { st.errors[n].hidden = true; });
            Object.keys(fields || {}).forEach(function (n) {
                var e = st.errors && st.errors[n];
                if (e) { e.textContent = [].concat(fields[n]).join(' '); e.hidden = false; }
            });
        }
        function send(confirmed) {
            var values = changes();
            st.busy = true; sync(); save.textContent = 'Saving…';
            window.Basehim.ajax.request('content.quick_edit_save', {
                table: table, id: id, version: st.version, values: JSON.stringify(values), confirm: !!confirmed
            }).then(function (r) {
                (r.notices || []).forEach(function (n) { toast(n); });
                announce('Saved');
                return get('admin.bar_url').then(function (u) {
                    close(true);
                    if (u.url && u.url !== window.location.href.split('#')[0]) window.location.href = u.url;
                    else if (!u.url && editHref) window.location.href = editHref;
                    else window.location.reload();
                }, function () { close(true); window.location.reload(); });
            }).catch(function (err) {
                st.busy = false; save.textContent = 'Save'; sync();
                if (err && err.code === 'CONFIRM_REQUIRED') {
                    if (window.confirm(err.message)) send(true);
                    return;
                }
                showErrors(err && err.fields);
                toast((err && err.message) || 'Not saved. Try again.', true);
            });
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); if (!save.disabled) send(false); });
    }

    // --- actions -----------------------------------------------------------------------------
    function runAction(act, item) {
        if (act === 'quick-edit') return quickEdit(item);
        if (act === 'logout') return logout(item);
        if (act === 'mode') return setMode(item.getAttribute('data-mode'));
        if (act === 'position') return setPosition(item.getAttribute('data-position'), true);
        if (act === 'collapse') return setCollapsed(true, true);
        if (act === 'hide') { closeAll(); root.hidden = true; layout(); announce('Admin bar hidden until the next page.'); return; }
    }
    function logout(item) {
        var a = ajax();
        var go = function () { window.location.href = item.href || href('/admin/logout'); };
        if (!a) return go();
        a.request('core.csrf', {}, { method: 'GET' }).then(function (r) {
            var token = r && (r.token || r.csrf);
            if (!token) return go();
            var f = el('form'); f.method = 'POST'; f.action = href('/admin/logout'); f.hidden = true;
            var i = el('input'); i.type = 'hidden'; i.name = '_csrf'; i.value = token;
            f.appendChild(i); document.body.appendChild(f); f.submit();
        }).catch(go);
    }
    function setMode(mode) {
        if (mode === 'off') {
            if (!window.confirm('Turn the admin bar off on the site? You can turn it back on in your profile.')) return;
            savePrefs({ mode: 'off' }).then(function () {
                closeAll(); root.hidden = true; layout();
                toast('The admin bar is off. Turn it back on in your profile.');
            });
            return;
        }
        savePrefs({ mode: mode }).then(function () {
            state.mode = mode;
            closeAll();
            window.location.reload();
        });
    }
    function setPosition(pos, save) {
        root.classList.remove('bhab--pos-' + state.position);
        state.position = pos;
        root.classList.add('bhab--pos-' + pos);
        Array.prototype.forEach.call(root.querySelectorAll('[data-bhab-action="position"]'), function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-position') === pos ? 'true' : 'false');
        });
        if (save && cfg.userOptions.indexOf('position') !== -1) savePrefs({ position: pos }).catch(function () {});
    }
    function setCollapsed(on, save) {
        state.collapsed = on;
        closeAll();
        layout();
        if (on && tab) tab.focus();
        if (save && cfg.remember) savePrefs({ collapsed: !!on }).catch(function () {});
    }
    if (tab) tab.addEventListener('click', function () { setCollapsed(false, true); var t = tops()[0]; if (t) t.focus(); });

    /** The Display menu's "Show" checkboxes: hide or show optional sections. */
    function renderSections(drop) {
        var list = drop.querySelector('[data-bhab-sections]');
        if (!list || list.__done) return;
        list.__done = true;
        Object.keys(cfg.optional || {}).forEach(function (id) {
            var li = el('li', 'bhab-li');
            var lab = el('label', 'bhab-check');
            var cb = el('input'); cb.type = 'checkbox'; cb.checked = state.hidden.indexOf(id) === -1;
            lab.appendChild(cb); lab.appendChild(el('span', '', cfg.optional[id]));
            li.appendChild(lab); list.appendChild(li);
            cb.addEventListener('change', function () {
                if (cb.checked) state.hidden = state.hidden.filter(function (h) { return h !== id; });
                else if (state.hidden.indexOf(id) === -1) state.hidden.push(id);
                var m = bar.querySelector('.bhab-menu[data-bhab-id="' + id + '"]');
                if (m) m.classList.toggle('is-user-hidden', !cb.checked);
                savePrefs({ hidden: JSON.stringify(state.hidden) }).catch(function () {});
            });
        });
    }

    // --- floating button --------------------------------------------------------------------
    function setFloatingOpen(open) {
        root.classList.toggle('is-open', open);
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { var f = tops()[0]; if (f) f.focus(); idle(function () { if (!notices) loadNotices(false).catch(function () {}); }); }
        else closeAll();
    }
    if (fab) {
        var drag = null;
        fab.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            drag = { x: e.clientX, y: e.clientY, moved: false };
            try { fab.setPointerCapture(e.pointerId); } catch (err) { /* old browsers */ }
        });
        fab.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
            if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 8) return;
            drag.moved = true;
            fab.classList.add('is-dragging');
            var s = fab.offsetWidth;
            fab.style.left = Math.max(4, Math.min(window.innerWidth - s - 4, e.clientX - s / 2)) + 'px';
            fab.style.top = Math.max(4, Math.min(window.innerHeight - s - 4, e.clientY - s / 2)) + 'px';
            fab.style.right = 'auto'; fab.style.bottom = 'auto';
        });
        fab.addEventListener('pointerup', function (e) {
            if (!drag) return;
            var moved = drag.moved;
            drag = null;
            fab.classList.remove('is-dragging');
            if (!moved) return;
            // Snap to the nearest corner, and remember it.
            var pos = (e.clientY < window.innerHeight / 2 ? 'top' : 'bottom') + '-' + (e.clientX < window.innerWidth / 2 ? 'left' : 'right');
            fab.style.left = fab.style.top = fab.style.right = fab.style.bottom = '';
            fab.__dragged = true;
            setPosition(pos, cfg.userOptions.indexOf('position') !== -1);
        });
        fab.addEventListener('click', function (e) {
            if (fab.__dragged) { fab.__dragged = false; e.preventDefault(); return; }
            setFloatingOpen(!root.classList.contains('is-open'));
        });
    }
    var sheetClose = root.querySelector('.bhab-sheet-close');
    if (sheetClose) sheetClose.addEventListener('click', function () { setFloatingOpen(false); fab.focus(); });

    // --- keyboard shortcuts and auto-hide -----------------------------------------------------
    if (cfg.shortcuts) {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K') && cfg.search && !root.hidden) {
                e.preventDefault();
                openSearch();
            }
        });
    }
    if (cfg.autoHide > 0) {
        var hideTimer = null;
        var arm = function () {
            clearTimeout(hideTimer);
            root.classList.remove('is-autohidden');
            hideTimer = setTimeout(function () { if (!state.openTop && !bar.contains(document.activeElement)) root.classList.add('is-autohidden'); }, cfg.autoHide * 1000);
        };
        document.addEventListener('mousemove', function (e) { if (e.clientY < 60) arm(); }, { passive: true });
        bar.addEventListener('focusin', arm);
        bar.addEventListener('mouseenter', arm);
        arm();
    }

    // --- start ----------------------------------------------------------------------------------
    if (mqMobile.matches && cfg.mobile === 'hidden') root.classList.add('bhab--mobile-hidden');
    layout();
    var onMq = function () { closeAll(); root.classList.toggle('bhab--mobile-hidden', mqMobile.matches && cfg.mobile === 'hidden'); layout(); };
    if (mqMobile.addEventListener) mqMobile.addEventListener('change', onMq); else if (mqMobile.addListener) mqMobile.addListener(onMq);
    var resizeTimer = null;
    window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(layout, 200); });
    // Badges and the bell count after the page has settled: one request each.
    idle(function () {
        loadIndicators();
        if (effectiveMode() === 'horizontal') loadNotices(false).catch(function () {});
    });

    // A small hook for themes and apps: open the search, or a menu by id.
    window.BasehimAdminBar = {
        openSearch: openSearch,
        open: function (id) {
            var b = bar.querySelector('.bhab-menu[data-bhab-id="' + id + '"] > .bhab-top');
            if (!b) return false;
            if (effectiveMode() === 'floating') setFloatingOpen(true);
            if (b.getAttribute('aria-controls')) openMenu(b, true); else b.click();
            return true;
        },
        close: function () { closeAll(); if (effectiveMode() === 'floating') setFloatingOpen(false); },
        refresh: function () { loadedPanels = {}; Array.prototype.forEach.call(root.querySelectorAll('.bhab-panel'), function (p) { if (!p.closest('[hidden]')) loadPanel(p); }); }
    };
})(window, document);
