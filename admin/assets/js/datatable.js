/*
 * Core Data Table — client renderer (vanilla, no dependencies).
 *
 * Server-renders the first page (works with no JS), then enhances: search,
 * filters, sortable columns, pagination, page size, row selection + context
 * bulk actions, a per-row action menu, a Screen Options panel (show/hide +
 * drag reorder + density), Table/List/Grid views, and loading/empty/error
 * states — all through the Basehim.ajax gateway. Writes reuse each feature's
 * existing CSRF-protected bulk route.
 */
(function () {
    'use strict';

    var SVG = {
        'chevron-up-down': 'M8 9l4-4 4 4M8 15l4 4 4-4',
        'chevron-up': 'M5 15l7-7 7 7',
        'chevron-down': 'M5 9l7 7 7-7',
        'chevron-left': 'M15 19l-7-7 7-7',
        'chevron-right': 'M9 5l7 7-7 7',
        'pencil-square': 'M16.86 4.49l1.69-1.69a1.875 1.875 0 112.65 2.65L6.83 19.82a4.5 4.5 0 01-1.9 1.13l-2.68.8.8-2.68a4.5 4.5 0 011.13-1.9L16.86 4.49z',
        'inbox': 'M4 8l2-4h12l2 4v8a2 2 0 01-2 2H6a2 2 0 01-2-2V8zM4 12h4l2 3h4l2-3h4',
        'trash': 'M6 7.5h12M9.75 7.5V6a1.5 1.5 0 011.5-1.5h1.5A1.5 1.5 0 0114.25 6v1.5m-7 0 .64 11a1.5 1.5 0 001.5 1.4h5.22a1.5 1.5 0 001.5-1.4l.64-11',
        'arrow-up-tray': 'M12 16.5V4.5m0 0L7.5 9M12 4.5 16.5 9M4.5 16.5v2A1.5 1.5 0 006 20h12a1.5 1.5 0 001.5-1.5v-2',
        'arrow-down-tray': 'M12 4.5v12m0 0L7.5 12M12 16.5 16.5 12M4.5 16.5v2A1.5 1.5 0 006 20h12a1.5 1.5 0 001.5-1.5v-2',
        'list-bullet': 'M8.25 6.75h12M8.25 12h12M8.25 17.25h12M3.75 6.75h.01M3.75 12h.01M3.75 17.25h.01',
        'table-cells': 'M3.75 7.5h16.5M3.75 12h16.5m-16.5 4.5h16.5M9 4.5v15M4.5 4.5h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V5.25a.75.75 0 01.75-.75z',
        'squares': 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'magnifying-glass': 'M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z',
        'alert': 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        'x-mark': 'M6 6l12 12M18 6L6 18',
        'funnel': 'M3 5h18l-7 8v5l-4 2v-7L3 5z',
        'bolt': 'M13 2 4 14h7l-1 8 9-12h-7l1-8z',
        'calendar': 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'chat': 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.12 2.99 2.7 3.23 1.09.16 2.19.28 3.3.37V21l4.18-4.18a1.14 1.14 0 01.78-.33 48.4 48.4 0 005.83-.5c1.58-.23 2.7-1.62 2.7-3.23V6.74c0-1.6-1.12-2.99-2.7-3.23A48.4 48.4 0 0012 3c-2.39 0-4.74.17-7.04.51-1.58.23-2.7 1.62-2.7 3.23v6.02z',
        'eye': 'M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'check': 'M4.5 12.75l6 6 9-13.5',
        'no-symbol': 'M18.364 5.636a9 9 0 11-12.728 12.728 9 9 0 0112.728-12.728M5.636 5.636l12.728 12.728',
        'arrow-up': 'M12 19.5V4.5m0 0L6.5 10M12 4.5 17.5 10',
        'arrow-down': 'M12 4.5v15m0 0L6.5 14M12 19.5 17.5 14',
        'adjustments': 'M4.5 6.75h10.5M18 6.75h1.5M4.5 12h1.5M9 12h10.5M4.5 17.25h10.5M18 17.25h1.5M15 4.5v4.5M7.5 9.75v4.5M15 15v4.5'
    };
    var DOTS = 'M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01';
    var VDOTS = 'M12 6h.01M12 12h.01M12 18h.01';
    function icon(name) {
        if (name === 'bars-3') return svg(DOTS, 3);
        if (name === 'ellipsis-vertical') return svg(VDOTS, 3);
        if (name === 'cog-6-tooth') name = 'cog';
        var d = SVG[name];
        if (!d) return window.BasehimIcon ? window.BasehimIcon(name, 'w-4 h-4') : '';
        return svg(d, 1.8);
    }
    function svg(d, w) {
        return '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + w + '" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    }
    function el(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }
    function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    // PHP json_encode of an empty assoc array is [] (a JS array), and setting
    // string keys on an array is silently dropped by JSON.stringify — which is
    // why filters never reached the server. Always work with a plain object.
    function toObj(v) { var o = {}; if (v && typeof v === 'object' && !Array.isArray(v)) { for (var k in v) if (Object.prototype.hasOwnProperty.call(v, k)) o[k] = v[k]; } return o; }
    function toArr(v) { if (Array.isArray(v)) return v.slice(); if (v == null || v === '') return []; return [v]; }

    function DataTable(root) {
        var cfg;
        try { cfg = JSON.parse(root.querySelector('[data-dt-config]').textContent); }
        catch (e) { if (window.console) console.error('DataTable config parse failed:', e); return; }

        var payload = cfg.initial || {};
        var catalog = payload.catalog || [];
        var columns = payload.columns || [];
        var selected = Object.create(null);   // id -> status
        var selecting = false;                 // touch select mode (1.2.116)

        var state = {
            page: (payload.meta && payload.meta.page) || 1,
            perPage: (payload.prefs && payload.prefs.page_size) || 20,
            sort: payload.sort || null,
            search: payload.search || '',
            filters: toObj(payload.filters),
            density: (payload.prefs && payload.prefs.density) || 'comfortable',
            view: (payload.prefs && payload.prefs.view) || 'table',
            order: (payload.prefs && payload.prefs.order) || [],
            visible: (payload.prefs && payload.prefs.visible) || [],
            // Sticky chrome toggles (1.2.110), on by default. Kept per-browser
            // in localStorage — a view-chrome choice, not a server-side pref.
            sticky: lsGet('sticky', true),
            stickyFoot: lsGet('stickyFoot', true)
        };

        var r = {
            body: root.querySelector('[data-dt-body]'),
            head: root.querySelector('[data-dt-head]'),
            cards: root.querySelector('[data-dt-cards]'),
            count: root.querySelector('[data-dt-count]'),
            pages: root.querySelector('[data-dt-pages]'),
            search: root.querySelector('[data-dt-search]'),
            bulk: root.querySelector('[data-dt-bulk]'),
            bulkCount: root.querySelector('[data-dt-bulk-count]'),
            bulkActions: root.querySelector('[data-dt-bulk-actions]'),
            bulkClear: root.querySelector('[data-dt-bulk-clear]'),
            selectAll: null,
            optionsBtn: root.querySelector('[data-dt-options]'),
            panel: root.querySelector('[data-dt-panel]'),
            perpage: root.querySelector('[data-dt-perpage]'),
            drawerBtn: root.querySelector('[data-dt-drawer-open]'),
            drawerBadge: root.querySelector('[data-dt-drawer-badge]'),
            toolAll: root.querySelector('[data-dt-selall-input]'),
            toolAllWrap: root.querySelector('[data-dt-selall]'),
            selectMode: root.querySelector('[data-dt-selectmode]'),
            wrap: root.querySelector('.bh-dt-wrap'),
            table: root.querySelector('.bh-dt-table'),
            foot: root.querySelector('.bh-dt-foot')
        };

        root.setAttribute('data-density', state.density);
        root.setAttribute('data-view', state.view);

        // Sticky toggles persist per-browser (not a server pref).
        var remeasureSticky = function () {};   // set by stickyChrome() below
        function lsKey(k) { return 'bh_dt_' + k + ':' + cfg.table; }
        function lsGet(k, def) { try { var v = window.localStorage.getItem(lsKey(k)); return v === null ? def : v === '1'; } catch (e) { return def; } }
        function lsSet(k, on) { try { window.localStorage.setItem(lsKey(k), on ? '1' : '0'); } catch (e) {} }
        function applySticky() {
            root.classList.toggle('bh-dt--sticky', !!state.sticky);
            root.classList.toggle('bh-dt--sticky-foot', !!state.stickyFoot);
            remeasureSticky();   // offsets depend on the sticky state being on
        }
        applySticky();

        // The floating bulk bar lives on <body> (1.2.104), so position:fixed is
        // relative to the viewport even when an ancestor is transformed, and
        // ticking a row can never shift the table. Escape clears the selection.
        if (r.bulk) {
            document.body.appendChild(r.bulk);
            if (r.bulkClear) r.bulkClear.addEventListener('click', clearSelection);   // also Done in select mode
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && (selectedIds().length || selecting)) { clearSelection(); }
            });
        }

        // Select mode (1.2.116): the toolbar's Select button, a tap on an item
        // while selecting, and a long press on an item to start.
        if (r.selectMode) r.selectMode.addEventListener('click', function () { if (selecting) clearSelection(); else setSelecting(true); });
        var lpTimer = null, lpX = 0, lpY = 0, lpFired = false;
        function cardOf(t) { var c = t && t.closest ? t.closest('.bh-dt-card[data-dt-rowid]') : null; return c && root.contains(c) ? c : null; }
        function toggleCard(card) { var cb = card.querySelector('.bh-dt-rowcheck'); if (cb) cb.click(); }
        root.addEventListener('click', function (e) {
            if (lpFired) { lpFired = false; e.preventDefault(); e.stopPropagation(); return; }   // the click that ends a long press
            if (!selecting) return;
            var card = cardOf(e.target); if (!card) return;
            if (e.target.closest('.bh-dt-check')) return;          // the tick itself toggles natively
            e.preventDefault(); e.stopPropagation();               // a tap selects instead of opening
            toggleCard(card);
        }, true);
        root.addEventListener('touchstart', function (e) {
            if (selecting || !e.touches || e.touches.length !== 1) return;
            var card = cardOf(e.target); if (!card || e.target.closest('button, .bh-dt-check')) return;
            lpX = e.touches[0].clientX; lpY = e.touches[0].clientY; lpFired = false;
            clearTimeout(lpTimer);
            lpTimer = setTimeout(function () {
                lpTimer = null;
                setSelecting(true);
                var cb = card.querySelector('.bh-dt-rowcheck'); if (cb && !cb.checked) cb.click();
                lpFired = true;   // after the tick's own click, or the guard below would swallow it
                try { if (navigator.vibrate) navigator.vibrate(10); } catch (err) {}
            }, 450);
        }, { passive: true });
        root.addEventListener('touchmove', function (e) {
            if (!lpTimer || !e.touches || !e.touches[0]) return;
            if (Math.abs(e.touches[0].clientX - lpX) > 10 || Math.abs(e.touches[0].clientY - lpY) > 10) { clearTimeout(lpTimer); lpTimer = null; }
        }, { passive: true });
        ['touchend', 'touchcancel'].forEach(function (t) { root.addEventListener(t, function () {
            clearTimeout(lpTimer); lpTimer = null;
            // Many browsers fire no click after a long press: don't let the flag
            // swallow the next real tap.
            if (lpFired) setTimeout(function () { lpFired = false; }, 400);
        }, { passive: true }); });
        root.addEventListener('contextmenu', function (e) { if ((lpTimer || lpFired) && cardOf(e.target)) e.preventDefault(); });

        // Shared floating menus (row actions, and a safety net for z-index).
        var menu = el('div', 'bh-dt-menu'); menu.style.display = 'none'; document.body.appendChild(menu);
        // Cards have no table header, so they get their own Select all (1.2.65):
        // shown wherever the cards are (List, Grid, phones and tablets).
        if (r.cards && r.cards.parentNode) {
            r.cardsHead = el('label', 'bh-dt-cardshead');
            r.cardsAll = el('input'); r.cardsAll.type = 'checkbox';
            r.cardsAll.addEventListener('change', function () { onSelectAll(r.cardsAll.checked); });
            r.cardsHead.appendChild(r.cardsAll);
            r.cardsHead.appendChild(el('span', '', 'Select all'));
            r.cards.parentNode.insertBefore(r.cardsHead, r.cards);
        }
        // The compact Select all in the toolbar row (1.2.108, phones/tablets).
        if (r.toolAll) r.toolAll.addEventListener('change', function () { onSelectAll(r.toolAll.checked); });

        function colById(id) { for (var i = 0; i < catalog.length; i++) if (catalog[i].id === id) return catalog[i]; return null; }

        // --- data load -------------------------------------------------------
        // Each load gets a number; a response that is not the latest one is
        // dropped (1.2.67), so a slow answer to an old search never replaces
        // the answer to the new one.
        var loadSeq = 0;

        // The address bar keeps the search, page, sort and filters (1.2.92),
        // which the server reads for the first page: a reload, Back from an
        // edit screen or a shared link shows the same rows. Only for the one
        // table of a page that has its own search box.
        function syncUrl() {
            // The media library drives its table from its own toolbar.
            if (!r.search || cfg.table === 'media.library' || !window.history || !history.replaceState || document.querySelectorAll('.bh-dt').length !== 1) return;
            try {
                var u = new URL(window.location.href);
                var set = function (k, v) { if (v === '' || v == null) u.searchParams.delete(k); else u.searchParams.set(k, String(v)); };
                set('q', state.search || '');
                set('page', state.page > 1 ? state.page : '');
                set('sort', state.sort || '');
                var f = toObj(state.filters);
                // Every single-value filter with a menu (1.2.101), status included.
                var menus = ['status'];
                root.querySelectorAll('[data-dt-filter]').forEach(function (sel) { menus.push(sel.getAttribute('data-dt-filter')); });
                menus.forEach(function (k) { set(k, typeof f[k] === 'string' ? f[k] : ''); });
                ['category', 'tag'].forEach(function (k) { set(k, toArr(f[k]).join(',')); });
                if (u.href !== window.location.href) history.replaceState(history.state, '', u.pathname + u.search + u.hash);
            } catch (e) { /* an old browser keeps the old behaviour */ }
        }

        // The skeleton shows for at least this long so a fast response still
        // reads as a load rather than a flicker (1.2.108).
        var SKEL_MIN = 300;
        function load() {
            var my = ++loadSeq;
            root.classList.add('is-loading');
            renderSkeleton();
            var skelAt = Date.now();
            // Run the render only once the skeleton has been shown long enough,
            // and only if this is still the latest load.
            var after = function (fn) {
                var wait = Math.max(0, SKEL_MIN - (Date.now() - skelAt));
                window.setTimeout(function () { if (my === loadSeq) fn(); }, wait);
            };
            window.Basehim.ajax.get('content.table', {
                table: cfg.table, page: state.page, per_page: state.perPage,
                sort: state.sort || '', search: state.search || '',
                filters: JSON.stringify(state.filters || {}),
                columns: JSON.stringify(columns ? columns.map(function (c) { return c.id; }) : [])
            }).then(function (data) {
                if (my !== loadSeq) return;
                after(function () {
                    payload = data; columns = data.columns || columns; catalog = data.catalog || catalog;
                    if (data.prefs) { state.order = data.prefs.order; state.visible = data.prefs.visible; }
                    state.sort = data.sort || null;
                    // Keep the current page across filter/sort/view changes, but
                    // if it now overruns the (smaller) result set, drop to the
                    // last page that has rows and reload once (skeleton stays up).
                    var m = data.meta || {};
                    if (m.last_page && state.page > m.last_page) { state.page = m.last_page; load(); return; }
                    renderHead(); renderBody(); renderFoot();
                    remeasureSticky();   // re-detect wide-table overflow after a (re)render
                    syncUrl();
                    if (drawerSync) drawerSync();
                    root.classList.remove('is-loading');
                    emit('bh:dt-loaded', { table: cfg.table, meta: data.meta || {}, sort: state.sort, search: state.search, filters: toObj(state.filters) });
                });
            }).catch(function (err) {
                if (my !== loadSeq) return;
                after(function () {
                    renderError(err && err.message ? err.message : 'Could not load the table.');
                    root.classList.remove('is-loading');
                });
            });
        }

        /**
         * Events on the table's root element (1.2.67), for a screen that hosts
         * a table and needs to drive it or react to it. See docs/DATA-TABLE.md.
         *   bh:dt-loaded  (out)  after each load: { table, meta, sort, search, filters }
         *   bh:dt-bulk    (out, cancelable) before a bulk action is submitted as
         *                 a form: { table, action, ids }. preventDefault() to
         *                 run it yourself (say, over AJAX).
         *   bh:dt-action  (out)  a declared action with `event: true` (1.2.85):
         *                 { table, action, id, key, row } — the page runs it.
         *   bh:dt-query   (in)   { search?, filters?, sort? } — merged, page 1, load
         *   bh:dt-reload  (in)   { clearSelection? } — reload the current page
         *   bh:dt-quickedit (in) { id } — open Quick Edit for a row on the current page
         */
        function emit(name, detail, cancelable) {
            var ev;
            try { ev = new CustomEvent(name, { bubbles: true, cancelable: !!cancelable, detail: detail }); }
            catch (e) { return true; }
            return root.dispatchEvent(ev);
        }
        root.addEventListener('bh:dt-query', function (e) {
            var d = (e && e.detail) || {};
            if (typeof d.search === 'string') { state.search = d.search.trim(); if (r.search && r.search.value !== d.search) r.search.value = d.search; }
            if (d.filters && typeof d.filters === 'object') {
                Object.keys(d.filters).forEach(function (k) {
                    var v = d.filters[k];
                    if (v === '' || v == null || (Array.isArray(v) && !v.length)) delete state.filters[k]; else state.filters[k] = v;
                });
            }
            if (typeof d.sort === 'string') state.sort = d.sort || null;
            state.page = 1;
            load();
        });
        root.addEventListener('bh:dt-reload', function (e) {
            if (e && e.detail && e.detail.clearSelection) clearSelection();
            load();
        });
        // bh:dt-quickedit (in) { id } — open Quick Edit for a row on this page (1.2.123:
        // the Categories/Tags deep link ?edit={id}, from the front-end admin bar).
        root.addEventListener('bh:dt-quickedit', function (e) {
            var id = e && e.detail ? Number(e.detail.id) : 0;
            if (!id || !payload.quick_edit) return;
            var row = (payload.rows || []).filter(function (x) { return Number(x.id) === id; })[0];
            if (row) quickEdit.open(row, null);
        });

        // --- header ----------------------------------------------------------
        function sortPartner(col) {
            if (!col.sortable || !col.sortable.length) return null;
            if (state.sort === col.sortable[0] && col.sortable[1]) return col.sortable[1];
            if (state.sort === col.sortable[1]) return null;
            return col.sortable[0];
        }
        function sortDir(col) {
            if (!col.sortable || col.sortable.indexOf(state.sort) === -1) return null;
            return (/(_az|_asc|oldest)$/).test(state.sort) ? 'ascending' : 'descending';
        }
        function alignCls(col) { return col.align === 'right' ? 'bh-dt-al-right' : (col.align === 'center' ? 'bh-dt-al-center' : ''); }
        function renderHead() {
            var tr = el('tr');
            var fc = frozenCount();
            var th0 = el('th', 'bh-dt-check bh-dt-frz bh-dt-frz--0'); var all = el('input'); all.type = 'checkbox';
            all.setAttribute('aria-label', 'Select all');
            all.addEventListener('change', function () { onSelectAll(all.checked); }); th0.appendChild(all); r.selectAll = all; tr.appendChild(th0);
            columns.forEach(function (col, i) {
                var cls = alignCls(col); if (col.id === 'thumb') cls += ' bh-dt-col-thumb';
                if (i < fc) cls += ' bh-dt-frz bh-dt-frz--' + (i + 1) + (i === fc - 1 ? ' bh-dt-frz--last' : '');
                var th = el('th', cls.trim());
                var label = col.id === 'thumb' ? '' : col.label;
                if (col.sortable && col.sortable.length) {
                    th.classList.add('bh-dt-sorth'); th.setAttribute('tabindex', '0'); th.setAttribute('role', 'button');
                    var dir = sortDir(col); if (dir) th.setAttribute('aria-sort', dir);
                    th.innerHTML = esc(label) + '<span class="bh-dt-caret">' + icon(dir === 'ascending' ? 'chevron-up' : (dir === 'descending' ? 'chevron-down' : 'chevron-up-down')) + '</span>';
                    var go = function () { state.sort = sortPartner(col); load(); };
                    th.addEventListener('click', go);
                    th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
                } else { th.textContent = label; }
                tr.appendChild(th);
            });
            tr.appendChild(el('th', 'bh-dt-rowactions'));
            r.head.innerHTML = ''; r.head.appendChild(tr);
            syncSelectAll();
        }

        // --- body ------------------------------------------------------------
        function renderBody() {
            var rows = payload.rows || [];
            r.body.innerHTML = ''; r.cards.innerHTML = '';
            if (r.cardsHead) r.cardsHead.hidden = !rows.length;
            if (r.toolAllWrap) r.toolAllWrap.hidden = !rows.length;
            if (r.selectMode) r.selectMode.hidden = !rows.length;
            if (!rows.length) { renderEmpty(); syncBulk(); syncSelectAll(); return; }
            rows.forEach(function (row) { r.body.appendChild(buildRow(row)); r.cards.appendChild(buildCard(row)); });
            syncBulk(); syncSelectAll();
        }
        function rowCheckbox(row) {
            var cb = el('input'); cb.type = 'checkbox'; cb.className = 'bh-dt-rowcheck'; cb.checked = selected[row.id] !== undefined;
            cb.addEventListener('change', function () {
                if (cb.checked) selected[row.id] = row.status || ''; else delete selected[row.id];
                // keep the twin (table row / card) in sync
                root.querySelectorAll('[data-dt-rowid="' + row.id + '"] .bh-dt-rowcheck').forEach(function (b) { b.checked = cb.checked; });
                markSelected(row.id, cb.checked);
                syncBulk(); syncSelectAll();
            });
            return cb;
        }
        /** The selected look on a row's table row and card (1.2.64). */
        function markSelected(id, on) {
            root.querySelectorAll('[data-dt-rowid="' + id + '"]').forEach(function (n) { n.classList.toggle('is-selected', !!on); });
        }
        function actionsButton(row) {
            var btn = el('button', 'bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-dt-actbtn', icon('ellipsis-vertical'));
            btn.type = 'button'; btn.title = 'Actions'; btn.setAttribute('aria-haspopup', 'true'); btn.setAttribute('aria-expanded', 'false');
            btn.addEventListener('click', function (e) { e.stopPropagation(); openMenu(btn, row); });
            return btn;
        }
        function buildRow(row) {
            var tr = el('tr'); tr.setAttribute('data-dt-row', row.id); tr.setAttribute('data-dt-rowid', row.id);
            var fc = frozenCount();
            var td0 = el('td', 'bh-dt-check bh-dt-frz bh-dt-frz--0'); td0.appendChild(rowCheckbox(row)); tr.appendChild(td0);
            // Comfortable density (1.2.99): the row's links, drawn across the
            // bottom of the whole row from its first cell (CSS positions them).
            var links = rowLinks(row);
            columns.forEach(function (col, i) {
                var cls = alignCls(col); if (col.id === 'views') cls += ' bh-dt-col-views';   // not .bh-dt-views: that is the view switch
                if (i < fc) cls += ' bh-dt-frz bh-dt-frz--' + (i + 1) + (i === fc - 1 ? ' bh-dt-frz--last' : '');
                var td = el('td', cls.trim()); td.innerHTML = (row.cells && row.cells[col.id]) || '';
                if (i === 0 && links) { td.classList.add('bh-dt-linkcell'); td.appendChild(links); }
                tr.appendChild(td);
            });
            if (links) tr.classList.add('has-rowlinks');
            var tda = el('td', 'bh-dt-rowactions'); tda.appendChild(actionsButton(row)); tr.appendChild(tda);
            if (selected[row.id] !== undefined) tr.classList.add('is-selected');
            return tr;
        }
        // Cards. Wide screens (List and Grid views) keep the 1.2.64 card; at
        // 820px and below, where cards are the only view, a compact card puts
        // each item on one line (1.2.65). Each column says where it goes on it
        // (`card`, see Column::CARD_SLOTS); tables that do not say get the
        // picture, title and status placed by id and every other column as a
        // label/value pair under the line.
        var cardsQuery = null;
        try { cardsQuery = window.matchMedia ? window.matchMedia('(max-width: 820px)') : null; } catch (e) { cardsQuery = null; }
        function compactCards() { return !!(cardsQuery && cardsQuery.matches); }
        function buildCard(row) { return compactCards() ? buildCompactCard(row) : buildFullCard(row); }
        /** Crossing 820px (a phone turned on its side) redraws the cards only. */
        function rebuildCards() {
            if (!r.cards || !(payload.rows || []).length) return;
            r.cards.innerHTML = '';
            payload.rows.forEach(function (row) { r.cards.appendChild(buildCard(row)); });
        }
        if (cardsQuery) {
            if (cardsQuery.addEventListener) cardsQuery.addEventListener('change', rebuildCards);
            else if (cardsQuery.addListener) cardsQuery.addListener(rebuildCards);
        }

        function cardSlot(col) {
            if (col.card) return col.card;
            if (col.id === 'thumb') return 'media';
            if (col.id === 'title') return 'title';
            if (col.id === 'status') return 'under';
            return 'row';
        }
        // Wide-table mode freezes the leading identity cells: the select cell
        // plus the data columns up to and including the primary (title) column,
        // so a thumbnail + title stay pinned while the rest scroll. Capped at
        // the first two data columns, so an unusual layout can't freeze half
        // the table; falls back to the first column when title isn't near the
        // front.
        function frozenCount() {
            var t = -1;
            for (var i = 0; i < columns.length; i++) { if (cardSlot(columns[i]) === 'title') { t = i; break; } }
            return (t >= 0 && t <= 1) ? t + 1 : 1;
        }
        /** A cell with nothing to show ('' or a lone dash) is left off the card. */
        function blankCell(html) {
            var t = String(html || '').replace(/<[^>]*>/g, '').replace(/&mdash;|&#8212;/g, '—').trim();
            // A picture or an avatar has no text but is not empty.
            return (t === '' || t === '—') && !/<(img|svg)\b|bh-thumb|bh-ava/i.test(String(html || ''));
        }
        /** Wide screens (List, Grid): title and status on top, every other column below (1.2.64). */
        function buildFullCard(row) {
            var card = el('div', 'bh-dt-card'); card.setAttribute('data-dt-rowid', row.id);
            var top = el('div', 'bh-dt-card__top');
            var cells = row.cells || {};
            // The tick (a <label>, so its whole area toggles the box). With a
            // thumbnail it sits ON the thumbnail and shows only while selecting
            // or hovering, so a list is not a column of empty boxes (1.2.116).
            var chk = el('label', 'bh-dt-check'); chk.appendChild(rowCheckbox(row));
            if (cells.thumb) {
                var thumb = el('span', 'bh-dt-card__thumb bh-dt-tickhost', cells.thumb);
                chk.classList.add('bh-dt-check--over'); thumb.appendChild(chk);
                card.classList.add('has-tick-media'); top.appendChild(thumb);
            } else {
                top.appendChild(chk);
            }
            var main = el('div', 'bh-dt-card__main');
            if (cells.title) main.appendChild(el('div', 'bh-dt-card__title', cells.title));
            if (cells.status) main.appendChild(el('div', 'bh-dt-card__badge', cells.status));
            top.appendChild(main);
            top.appendChild(actionsButton(row));
            card.appendChild(top);
            var rowsWrap = el('div', 'bh-dt-card__rows'); var any = false;
            columns.forEach(function (col) {
                if (['thumb', 'title', 'status'].indexOf(col.id) !== -1) return;
                var val = cells[col.id]; if (!val) return; any = true;
                var rr = el('div', 'bh-dt-card__row');
                rr.appendChild(el('span', 'bh-dt-card__key', esc(col.label)));
                rr.appendChild(el('span', 'bh-dt-card__val', val));
                rowsWrap.appendChild(rr);
            });
            if (any) card.appendChild(rowsWrap);
            if (selected[row.id] !== undefined) card.classList.add('is-selected');
            return card;
        }
        /** 820px and below: one line per item. */
        function buildCompactCard(row) {
            var card = el('div', 'bh-dt-card bh-dt-card--compact'); card.setAttribute('data-dt-rowid', row.id);
            var cells = row.cells || {};
            var slots = { media: [], title: [], sub: [], under: [], side: [], meta: [], row: [] };
            columns.forEach(function (col) {
                var slot = cardSlot(col), html = cells[col.id];
                if (slot === 'hide' || !slots[slot] || blankCell(html)) return;
                slots[slot].push({ col: col, html: html });
            });
            var join = function (list) { return list.map(function (x) { return x.html; }).join(' '); };

            var body = el('div', 'bh-dt-card__body');
            var chk = el('label', 'bh-dt-check bh-dt-card__check'); chk.appendChild(rowCheckbox(row));
            if (slots.media.length) {
                var media = el('div', 'bh-dt-card__media bh-dt-tickhost', join(slots.media));
                chk.classList.add('bh-dt-check--over'); media.appendChild(chk);   // the tick rides on the picture (1.2.116)
                body.appendChild(media); card.classList.add('has-media', 'has-tick-media');
            } else {
                body.appendChild(chk);
            }
            var main = el('div', 'bh-dt-card__main');
            if (slots.title.length) main.appendChild(el('div', 'bh-dt-card__title', join(slots.title)));
            if (slots.sub.length) main.appendChild(el('div', 'bh-dt-card__sub', join(slots.sub)));
            if (slots.under.length) main.appendChild(el('div', 'bh-dt-card__under', join(slots.under)));
            body.appendChild(main);
            if (slots.side.length || slots.meta.length) {
                var aside = el('div', 'bh-dt-card__aside');
                if (slots.side.length) aside.appendChild(el('div', 'bh-dt-card__side', join(slots.side)));
                if (slots.meta.length) {
                    var meta = el('div', 'bh-dt-card__meta');
                    slots.meta.forEach(function (x) {
                        var item = el('div', 'bh-dt-card__metaitem'); item.title = x.col.label;
                        // The icon stands for the label; the label is still read out.
                        item.innerHTML = (x.col.card_icon ? '<span class="bh-dt-card__icon" aria-hidden="true">' + icon(x.col.card_icon) + '</span>'
                                                           : '<span class="bh-dt-card__metakey" aria-hidden="true">' + esc(x.col.label) + '</span>')
                            + '<span class="bh-dt-sr">' + esc(x.col.label) + ': </span>'
                            + '<span class="bh-dt-card__metaval">' + x.html + '</span>';
                        meta.appendChild(item);
                    });
                    aside.appendChild(meta);
                }
                body.appendChild(aside);
            }
            body.appendChild(actionsButton(row));
            card.appendChild(body);

            if (slots.row.length) {
                var rowsWrap = el('div', 'bh-dt-card__rows');
                slots.row.forEach(function (x) {
                    var rr = el('div', 'bh-dt-card__row');
                    rr.appendChild(el('span', 'bh-dt-card__key', esc(x.col.label)));
                    rr.appendChild(el('span', 'bh-dt-card__val', x.html));
                    rowsWrap.appendChild(rr);
                });
                card.appendChild(rowsWrap);
            }
            if (selected[row.id] !== undefined) card.classList.add('is-selected');
            return card;
        }
        // Empty and error states (1.2.66): a large icon in a circle, centred
        // above the message. The same markup is printed by the partial on the
        // first page, so the server's paint and the script's match.
        function stateHtml(kind, iconName, title, text) {
            return '<div class="bh-dt-state bh-dt-state--' + kind + '" role="status">'
                + '<span class="bh-dt-state__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="' + SVG[iconName] + '"/></svg></span>'
                + '<p class="bh-dt-state__title">' + esc(title) + '</p><p class="bh-dt-state__text">' + esc(text) + '</p></div>';
        }
        function renderEmpty() {
            var noun = String(payload.label || '').toLowerCase() || 'items';
            var filtered = !!state.search || Object.keys(state.filters || {}).length > 0;
            var own = payload.empty || {};   // the table's own words (1.2.122), e.g. "Trash is empty"
            var h = filtered
                ? stateHtml('empty', 'magnifying-glass', 'No matches', 'No ' + noun + ' match the current search or filters.')
                : stateHtml('empty', (own.icon && SVG[own.icon]) ? own.icon : 'inbox', own.title || 'Nothing here yet', own.text || ('There are no ' + noun + ' to show yet.'));
            r.body.innerHTML = '<tr><td colspan="99">' + h + '</td></tr>'; r.cards.innerHTML = h;
        }
        function renderError(msg) {
            var h = stateHtml('error', 'alert', 'Something went wrong', msg);
            r.body.innerHTML = '<tr><td colspan="99">' + h + '</td></tr>'; r.cards.innerHTML = h;
        }

        // --- per-row action menu ---------------------------------------------
        /**
         * A row's actions, in the order both forms show them: the links under
         * the title (Comfortable density) and the ⋮ menu (Compact, and cards).
         */
        function rowItems(row) {
            var items = [];
            // The row on the site first (1.2.62; first since 1.2.63): View when it
            // is live, Preview otherwise (only shown to people who may edit it).
            if (row.link && row.link.url) items.push({ label: row.link.live ? 'View' : 'Preview', icon: 'eye', href: row.link.url, newTab: true });
            if (cfg.editBase) items.push({ label: 'Edit', icon: 'pencil-square', href: cfg.editBase.replace('{id}', row.id) });
            if (payload.quick_view) items.push({ label: 'Quick View', icon: 'document-magnifying-glass', view: true, id: row.id });
            if (payload.quick_edit) items.push({ label: 'Quick Edit', icon: 'bolt', quick: true, id: row.id });
            // Declared actions (1.2.85) replace the built-in ids below.
            declared().forEach(function (a) {
                if (!a.row || !offers(a, row.status, row.flags)) return;
                if (a.href) items.push({ label: a.label, icon: a.icon || 'arrow-top-right-on-square', href: base() + a.href.replace('{id}', encodeURIComponent(row.id)).replace('{key}', encodeURIComponent(row.key || '')) });
                else items.push({ label: a.label, icon: a.icon || 'bolt', danger: a.danger, declared: a, id: row.id, row: row });
            });
            var bulk = declared().length ? [] : (payload.bulk_actions || []);
            if (bulk.indexOf('publish') !== -1 && row.status !== 'published') items.push({ label: 'Publish', icon: 'arrow-up-tray', action: 'publish', id: row.id });
            if (bulk.indexOf('draft') !== -1 && row.status === 'published') items.push({ label: 'Move to Draft', icon: 'arrow-down-tray', action: 'draft', id: row.id });
            if (bulk.indexOf('approve') !== -1 && row.status !== 'approved') items.push({ label: 'Approve', icon: 'arrow-up-tray', action: 'approve', id: row.id });
            if (bulk.indexOf('pending') !== -1 && row.status === 'approved') items.push({ label: 'Unapprove', icon: 'arrow-down-tray', action: 'pending', id: row.id });
            if (bulk.indexOf('spam') !== -1 && row.status !== 'spam') items.push({ label: 'Spam', icon: 'no-symbol', action: 'spam', danger: true, id: row.id });
            if (bulk.indexOf('delete') !== -1) items.push({ label: (cfg.deleteConfirm ? 'Delete' : 'Move to Trash'), icon: 'trash', action: 'delete', danger: true, id: row.id });
            return items;
        }
        /** Carry out one of rowItems(); `opener` gets focus back afterwards. */
        function runItem(it, row, opener) {
            if (it.href) return;
            if (it.view) quickView.open(row, opener);
            else if (it.quick) quickEdit.open(row, opener);
            else if (it.declared && it.declared.event) emit('bh:dt-action', { table: cfg.table, action: it.declared.id, id: it.id, key: row.key || null, row: row });
            else if (it.declared) runBulk(it.declared.id, [it.id], [row]);
            else runBulk(it.action, [it.id]);
        }
        /**
         * Comfortable density (1.2.99): the row's actions as plain text links,
         * shown while the row is hovered or has focus, in place of the ⋮ menu.
         */
        function rowLinks(row) {
            var items = rowItems(row);
            if (!items.length) return null;
            var wrap = el('div', 'bh-dt-rowlinks');
            items.forEach(function (it) {
                var node = it.href ? el('a', 'bh-dt-rowlink') : el('button', 'bh-dt-rowlink');
                if (!it.href) node.type = 'button';
                if (it.danger) node.classList.add('bh-dt-rowlink--danger');
                node.textContent = it.label;
                if (it.href) {
                    node.href = it.href;
                    if (it.newTab) { node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener'); }
                } else {
                    node.addEventListener('click', function (e) { e.stopPropagation(); runItem(it, row, node); });
                }
                wrap.appendChild(node);
            });
            return wrap;
        }
        function openMenu(btn, row) {
            var items = rowItems(row);
            if (!items.length) return;
            // The same ⋮ again closes its menu (1.2.65); another ⋮ moves it.
            if (menuOpener === btn && menu.style.display !== 'none') { closeMenu(); return; }

            menu.innerHTML = '';
            items.forEach(function (it) {
                var node = it.href ? el('a', 'bh-dt-menu__item') : el('button', 'bh-dt-menu__item');
                if (it.danger) node.classList.add('bh-dt-menu__item--danger');
                node.innerHTML = icon(it.icon) + '<span>' + esc(it.label) + '</span>';
                if (it.href) {
                    node.href = it.href;
                    if (it.newTab) { node.target = '_blank'; node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener'); node.addEventListener('click', closeMenu); }
                }
                else node.addEventListener('click', function () { closeMenu(); runItem(it, row, btn); });
                menu.appendChild(node);
            });
            var b = btn.getBoundingClientRect();
            menu.style.display = 'block';
            var mw = menu.offsetWidth || 180;
            var left = b.right - mw + window.scrollX;
            if (left < 8) left = 8;
            menu.style.top = (b.bottom + window.scrollY + 4) + 'px';
            menu.style.left = left + 'px';
            if (menuOpener && menuOpener !== btn) menuOpener.setAttribute('aria-expanded', 'false');
            menuOpener = btn; btn.setAttribute('aria-expanded', 'true');
        }
        var menuOpener = null;
        function closeMenu() {
            menu.style.display = 'none';
            if (menuOpener) { menuOpener.setAttribute('aria-expanded', 'false'); menuOpener = null; }
        }
        document.addEventListener('click', function (e) { if (!menu.contains(e.target)) closeMenu(); });
        window.addEventListener('resize', closeMenu);
        root.addEventListener('scroll', closeMenu, true);

        // --- Quick Edit (1.2.61; a modal dialog since 1.2.63) ------------------
        // A form in a modal <dialog>, built from the field list the table's
        // QuickEditor returns (content.quick_edit). It sits in the page's top
        // layer and is never moved while open: an earlier inline version moved
        // itself on window resize, and on phones the keyboard opening resized
        // the window, took the focused field out of the page and closed the
        // keyboard again. Saving sends only the
        // fields that changed (content.quick_edit_save) and replaces that row
        // with the re-rendered one: the table's search, filters, sort, page and
        // selection stay as they are. The server checks everything again; this
        // only shows what the user may change.
        var quickEdit = (function () {
            var cur = null;   // { id, row, opener, panel, form, fields, initial, inputs, version, busy }
            var seq = 0;

            function columnIds() { return columns.map(function (c) { return c.id; }); }
            function rowEls(id) {
                return {
                    tr: r.body.querySelector('tr[data-dt-row="' + id + '"]'),
                    card: r.cards.querySelector('[data-dt-rowid="' + id + '"]')
                };
            }
            function text(tag, cls, t) { var n = el(tag, cls); n.textContent = t == null ? '' : String(t); return n; }

            /**
             * The dialog, made once per table: a header (title, the item's name,
             * Close), and a body that holds the form. Escape and a click on the
             * backdrop go through close(), which asks first about unsaved changes.
             */
            var dlg = null, dlgSub = null, dlgBody = null;
            function dialog() {
                if (dlg) return dlg;
                var tid = 'bh-qe-title-' + cfg.table.replace(/\W/g, '-');
                dlg = el('dialog', 'bh-qe');
                dlg.setAttribute('aria-labelledby', tid);
                var head = el('div', 'bh-qe__head');
                var titles = el('div', 'bh-qe__titles');
                var h = text('h2', 'bh-qe__title', 'Quick Edit'); h.id = tid; h.setAttribute('tabindex', '-1');
                dlgSub = text('p', 'bh-qe__sub', '');
                titles.appendChild(h); titles.appendChild(dlgSub);
                var x = el('button', 'bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-qe__x', icon('x-mark')); x.type = 'button'; x.setAttribute('aria-label', 'Close');
                x.addEventListener('click', function () { close(false); });
                head.appendChild(titles); head.appendChild(x);
                dlgBody = el('div', 'bh-qe__body');
                dlg.appendChild(head); dlg.appendChild(dlgBody);
                // Escape: the dialog's own cancel event, routed through close().
                dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });
                // A click on the backdrop (the dialog element itself, outside its box).
                dlg.addEventListener('mousedown', function (e) { dlg.__downOnBackdrop = e.target === dlg; });
                dlg.addEventListener('click', function (e) { if (e.target === dlg && dlg.__downOnBackdrop) close(false); });
                document.body.appendChild(dlg);
                return dlg;
            }
            function host() {
                var d = dialog();
                dlgBody.textContent = '';
                dlgBody.appendChild(cur.panel);
                dlgSub.textContent = cur.title || '';
                if (!d.open) {
                    if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
                }
                // Keep the keyboard closed on touch screens until a field is tapped.
                if (!finePointer()) { var h = d.querySelector('.bh-qe__title'); if (h) h.focus(); }
                return true;
            }
            function finePointer() {
                try { return !window.matchMedia || window.matchMedia('(pointer: fine)').matches; } catch (e) { return true; }
            }

            function open(row, opener) {
                if (cur && cur.id === row.id) { focusFirst(); return; }
                var go = function () { close(true); start(row, opener); };
                if (cur && isDirty()) {
                    ask('Discard your unsaved Quick Edit changes to "' + cur.title + '"?', { danger: true, confirmLabel: 'Discard', cancelLabel: 'Keep editing' })
                        .then(function (ok) { if (ok) go(); });
                    return;
                }
                go();
            }

            /** The row's title as text, for the dialog header while the form loads. */
            function rowTitle(row) {
                var holder = el('div'); holder.innerHTML = (row.cells && row.cells.title) || '';
                var t = holder.querySelector('.bh-dt-title');
                return (t ? t.textContent : '').trim();
            }

            function start(row, opener) {
                var my = ++seq;
                var panel = el('div', 'bh-dt-qe');
                panel.setAttribute('aria-busy', 'true');
                panel.appendChild(el('div', 'bh-dt-qe__loading', '<span class="bh-dt-spinner"></span>'));
                panel.lastChild.appendChild(text('span', '', 'Loading…'));
                cur = { id: row.id, row: row, opener: opener || null, panel: panel, title: rowTitle(row), busy: false, inputs: {}, errors: {} };
                host();
                window.Basehim.ajax.get('content.quick_edit', { table: cfg.table, id: row.id }).then(function (d) {
                    if (!cur || seq !== my) return;
                    build(d.form);
                }).catch(function (err) {
                    if (!cur || seq !== my) return;
                    panel.removeAttribute('aria-busy');
                    panel.innerHTML = '';
                    var box = el('div', 'bh-dt-qe__msg bh-dt-qe__msg--error'); box.setAttribute('role', 'alert');
                    box.appendChild(text('span', '', (err && err.message) || 'Quick Edit could not be opened.'));
                    var close1 = text('button', 'bh-btn bh-btn--secondary bh-btn--sm', 'Close'); close1.type = 'button';
                    close1.addEventListener('click', function () { close(true); });
                    box.appendChild(close1); panel.appendChild(box); close1.focus();
                });
            }

            function build(form) {
                var panel = cur.panel;
                cur.version = form.version;
                cur.fields = form.fields || [];
                cur.title = form.title || '';
                cur.initial = {};
                panel.removeAttribute('aria-busy');
                panel.innerHTML = '';
                if (dlgSub) dlgSub.textContent = cur.title;

                var f = el('form', 'bh-dt-qe__form'); f.noValidate = true; f.setAttribute('novalidate', '');
                var banner = el('div', 'bh-dt-qe__msg'); banner.hidden = true; banner.setAttribute('role', 'alert');
                f.appendChild(banner); cur.banner = banner;
                var grid = el('div', 'bh-dt-qe__grid');
                cur.fields.forEach(function (fd) { grid.appendChild(field(fd)); });
                f.appendChild(grid);

                var actions = el('div', 'bh-dt-qe__actions');
                var save = text('button', 'bh-btn bh-btn--primary bh-btn--sm', 'Save'); save.type = 'submit';
                var cancel = text('button', 'bh-btn bh-btn--secondary bh-btn--sm', 'Cancel'); cancel.type = 'button';
                cancel.addEventListener('click', function () { close(false); });
                actions.appendChild(cancel); actions.appendChild(save);
                f.appendChild(actions);
                cur.save = save;
                f.addEventListener('submit', function (e) { e.preventDefault(); submit(false); });
                // Escape is the dialog's cancel event (dialog()); where there is no
                // native dialog, handle it here.
                f.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !(dlg && typeof dlg.showModal === 'function')) { e.preventDefault(); close(false); } });
                panel.appendChild(f);
                cur.form = f;
                applyShowWhen();
                syncSave();
                focusFirst();
            }

            function fieldId(name) { return 'bh-dt-qe-' + cfg.table.replace(/\W/g, '-') + '-' + name; }

            function field(fd) {
                var wrap = el('div', 'bh-dt-qe__field' + (fd.wide ? ' is-wide' : '') + ' bh-dt-qe__field--' + fd.type);
                wrap.setAttribute('data-qe-field', fd.name);
                var id = fieldId(fd.name), input, value = fd.value;
                var label = text('label', 'bh-label', fd.label);
                label.htmlFor = id; label.setAttribute('for', id);

                var F = window.BasehimPostFields;
                if ((fd.type === 'categories' || fd.type === 'tags' || fd.type === 'publish') && F) {
                    // The block editor's own components (post-fields.js, 1.2.62).
                    wrap.classList.add('is-component');
                    var labelEl = text('span', 'bh-label', fd.label); labelEl.id = id + '-label';
                    var box = el('div', 'bh-dt-qe__component'); box.id = id;
                    box.setAttribute('aria-labelledby', id + '-label');
                    var comp;
                    if (fd.type === 'categories') comp = F.categories(box, Object.assign({}, fd.value, { label: fd.label, compact: true, filterAt: 10 }));
                    else if (fd.type === 'tags') comp = F.tags(box, Object.assign({}, fd.value, { immediate: false, names: null, compact: true, cloudLimit: 8, idPrefix: id }));
                    else comp = F.publish(box, fd.value || {}, { compact: true, names: false, idPrefix: id, targetStatus: function () { return cur && cur.inputs.status ? valueOf('status') : (fd.value || {}).status; } });
                    var errC = el('p', 'bh-dt-qe__error'); errC.id = id + '-error'; errC.hidden = true;
                    if (fd.type !== 'publish') wrap.appendChild(labelEl);
                    wrap.appendChild(box); wrap.appendChild(errC);
                    if (fd.span) wrap.style.setProperty ? wrap.style.setProperty('--qe-span', String(fd.span)) : (wrap.style['--qe-span'] = String(fd.span));
                    if (fd.span >= 7) wrap.classList.add('is-wide');
                    comp.onChange(function () { clearError(fd.name); applyShowWhen(); syncSave(); });
                    cur.inputs[fd.name] = { def: fd, comp: comp, input: box, wrap: wrap, error: errC };
                    cur.initial[fd.name] = comparableValue(fd.name, compValue(fd.type, comp));
                    return wrap;
                }
                if (fd.type === 'select') {
                    input = el('select', 'bh-select');
                    (fd.options || []).forEach(function (o) {
                        var opt = el('option'); opt.value = String(o.value); opt.textContent = o.label;
                        if (String(o.value) === String(value)) opt.selected = true;
                        input.appendChild(opt);
                    });
                    input.value = String(value == null ? '' : value);
                    cur.initial[fd.name] = input.value;
                } else if (fd.type === 'toggle') {
                    wrap.classList.add('is-toggle');
                    input = el('input', 'bh-dt-qe__check'); input.type = 'checkbox'; input.checked = !!value;
                    cur.initial[fd.name] = !!value;
                } else if (fd.type === 'checklist') {
                    label = text('span', 'bh-label', fd.label); label.id = id + '-label';
                    input = el('div', 'bh-dt-qe__checklist'); input.setAttribute('role', 'group'); input.setAttribute('aria-labelledby', id + '-label');
                    input.id = id;
                    var chosen = (value || []).map(String);
                    var opts = fd.options || [];
                    if (opts.length > 8) {
                        var filter = el('input', 'bh-input bh-input--sm bh-dt-qe__filter'); filter.type = 'search';
                        filter.placeholder = 'Filter ' + fd.label.toLowerCase() + '…'; filter.setAttribute('aria-label', 'Filter ' + fd.label.toLowerCase());
                        filter.addEventListener('input', function () {
                            var q = filter.value.trim().toLowerCase();
                            input.querySelectorAll('.bh-dt-qe__opt').forEach(function (n) { n.hidden = q !== '' && n.textContent.toLowerCase().indexOf(q) === -1; });
                        });
                        wrap.appendChild(label); wrap.appendChild(filter); label = null;
                    }
                    if (!opts.length) input.appendChild(text('p', 'bh-help', 'None yet.'));
                    opts.forEach(function (o) {
                        var row = el('label', 'bh-dt-qe__opt');
                        var cb = el('input'); cb.type = 'checkbox'; cb.value = String(o.value); cb.checked = chosen.indexOf(String(o.value)) !== -1;
                        row.appendChild(cb); row.appendChild(text('span', '', o.label));
                        input.appendChild(row);
                    });
                    cur.initial[fd.name] = chosen.slice().sort().join(',');
                } else if (fd.type === 'textarea') {
                    // A few lines of text (1.2.123: a term's description).
                    input = el('textarea', 'bh-textarea'); input.rows = 3;
                    if (fd.max) input.maxLength = fd.max;
                    if (fd.required) { input.required = true; input.setAttribute('aria-required', 'true'); }
                    input.value = value == null ? '' : String(value);
                    cur.initial[fd.name] = input.value;
                } else {
                    input = el('input', 'bh-input');
                    input.type = { number: 'number', date: 'date', time: 'time' }[fd.type] || 'text';
                    if (fd.type === 'slug') { input.setAttribute('spellcheck', 'false'); input.setAttribute('autocapitalize', 'off'); }
                    if (fd.type === 'time') input.step = 60;
                    if (fd.type === 'number') { if (fd.min != null) input.min = fd.min; if (fd.max != null) input.max = fd.max; input.step = 1; }
                    else if (fd.max) input.maxLength = fd.max;
                    if (fd.required) { input.required = true; input.setAttribute('aria-required', 'true'); }
                    input.value = value == null ? '' : String(value);
                    cur.initial[fd.name] = input.value;
                }
                if (fd.type !== 'checklist') input.id = id;
                input.name = fd.name;
                var described = [];
                if (fd.help) { var h = text('p', 'bh-help', fd.help); h.id = id + '-help'; described.push(h.id); }
                var err = el('p', 'bh-dt-qe__error'); err.id = id + '-error'; err.hidden = true;
                described.push(err.id);
                input.setAttribute('aria-describedby', described.join(' '));

                if (fd.type === 'toggle') { var lab = el('label', 'bh-dt-qe__togglelabel'); lab.appendChild(input); lab.appendChild(text('span', '', fd.label)); wrap.appendChild(lab); }
                else { if (label) wrap.appendChild(label); wrap.appendChild(input); }
                if (fd.help) wrap.appendChild(h);
                wrap.appendChild(err);

                var onChange = function () { clearError(fd.name); applyShowWhen(); syncSave(); };
                input.addEventListener('input', onChange);
                input.addEventListener('change', onChange);
                if (fd.placeholder) input.placeholder = fd.placeholder;
                if (fd.span) { if (wrap.style.setProperty) wrap.style.setProperty('--qe-span', String(fd.span)); else wrap.style['--qe-span'] = String(fd.span); }
                if (fd.span >= 7) wrap.classList.add('is-wide');
                cur.inputs[fd.name] = { def: fd, input: input, wrap: wrap, error: err };
                return wrap;
            }

            function compValue(type, comp) { return type === 'publish' ? comp.values() : comp.value(); }
            function valueOf(name) {
                var x = cur.inputs[name]; if (!x) return undefined;
                if (x.comp) return compValue(x.def.type, x.comp);
                if (x.def.type === 'toggle') return !!x.input.checked;
                if (x.def.type === 'checklist') {
                    var out = []; x.input.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { if (cb.checked) out.push(cb.value); });
                    return out;
                }
                return x.input.value;
            }
            /** A value in a form that compares equal when nothing really changed. */
            function comparableValue(name, v) {
                var t = cur.inputs[name] ? cur.inputs[name].def.type : null;
                if (t === 'checklist' || t === 'categories') return (v || []).map(String).sort().join(',');
                if (t === 'tags') return JSON.stringify([(v.ids || []).slice().sort(), (v.names || []).map(function (n) { return n.toLowerCase(); }).sort()]);
                if (t === 'publish') return JSON.stringify(v || {});
                return v;
            }
            function comparable(name, v) { return comparableValue(name, v); }
            function changes() {
                var out = {};
                Object.keys(cur.inputs).forEach(function (name) {
                    if (name === 'publish') return;
                    var v = valueOf(name);
                    if (comparable(name, v) !== cur.initial[name]) out[name] = v;
                });
                // Going out (Published / Scheduled): the Publish control's choice
                // travels with the status, as the editor's form sends it.
                var status = cur.inputs.status ? valueOf('status') : undefined;
                var pub = cur.inputs.publish;
                if (pub && (status === 'published' || status === 'scheduled')) {
                    var pv = valueOf('publish');
                    if ('status' in out || comparable('publish', pv) !== cur.initial.publish) {
                        out.status = status;
                        Object.keys(pv).forEach(function (k) { out[k] = pv[k]; });
                    }
                }
                return out;
            }
            /** Save says what it will do: Publish, Schedule, Publish now, or Save. */
            function syncSave() {
                if (!cur || !cur.save || cur.busy) return;
                var l = 'Save', pub = cur.inputs.publish, st = cur.inputs.status ? valueOf('status') : null;
                if (pub && (st === 'published' || st === 'scheduled')) {
                    var pl = pub.comp.label();
                    if (pl === 'Publish' || pl === 'Schedule' || pl === 'Publish now') l = pl;
                }
                cur.save.textContent = l;
            }
            function isDirty() { return !!(cur && cur.inputs && Object.keys(changes()).length); }

            function applyShowWhen() {
                Object.keys(cur.inputs).forEach(function (name) {
                    var x = cur.inputs[name], w = x.def.show_when;
                    if (!w || !cur.inputs[w.field]) return;
                    x.wrap.hidden = (w.in || []).indexOf(String(valueOf(w.field))) === -1;
                    if (!x.wrap.hidden && x.comp && x.comp.render) x.comp.render();
                });
            }
            function focusFirst() {
                if (!cur || !cur.form) return;
                // On a touch screen focusing a field opens the keyboard over the
                // form before the person has seen it: let them tap a field.
                if (!finePointer()) return;
                var first = cur.form.querySelector('input:not([type="hidden"]), select');
                if (first) first.focus();
            }

            function clearError(name) {
                var x = cur && cur.inputs[name]; if (!x) return;
                x.error.hidden = true; x.error.textContent = ''; x.input.removeAttribute('aria-invalid'); x.wrap.classList.remove('has-error');
                if (x.comp && x.comp.showError) x.comp.showError(null);
            }
            function showErrors(fields) {
                var firstBad = null;
                Object.keys(fields || {}).forEach(function (name) {
                    // The Publish control's three values report on the control.
                    var x = cur.inputs[name] || (/^publish_/.test(name) ? cur.inputs.publish : null);
                    var msg = [].concat(fields[name]).join(' ');
                    if (x && x.comp && x.comp.showError && x.def.type === 'publish') {
                        x.comp.showError(msg); x.wrap.hidden = false; x.wrap.classList.add('has-error');
                        if (!firstBad) firstBad = x.comp.fields && x.comp.fields.date;
                        return;
                    }
                    if (!x) { setBanner('error', msg); return; }
                    x.error.textContent = msg; x.error.hidden = false; x.wrap.hidden = false;
                    x.input.setAttribute('aria-invalid', 'true'); x.wrap.classList.add('has-error');
                    if (!firstBad) firstBad = x.input;
                });
                if (firstBad && firstBad.focus) firstBad.focus();
            }
            function setBanner(kind, msg) {
                if (!cur || !cur.banner) return;
                cur.banner.className = 'bh-dt-qe__msg' + (kind ? ' bh-dt-qe__msg--' + kind : '');
                cur.banner.textContent = msg || '';
                cur.banner.hidden = !msg;
            }

            function submit(confirmed) {
                if (!cur || cur.busy || !cur.form) return;
                var values = changes();
                Object.keys(cur.inputs).forEach(clearError);
                setBanner('', '');
                if (!Object.keys(values).length) { close(false); return; }
                // Client-side checks first, so a typo costs no round trip.
                var local = {};
                Object.keys(values).forEach(function (name) {
                    if (!cur.inputs[name]) return;   // publish_mode / _date / _time belong to the Publish control
                    var d = cur.inputs[name].def, v = values[name];
                    if (d.required && typeof v === 'string' && v.trim() === '') local[name] = d.label + ' is required.';
                });
                if (Object.keys(local).length) { showErrors(local); return; }
                // The Publish control checks its own date and time (as in the editor).
                var pub = cur.inputs.publish;
                if (pub && values.publish_mode !== undefined && pub.comp.check()) { pub.wrap.classList.add('has-error'); return; }

                var st = cur.inputs.status && cur.inputs.status.def.confirm;
                var msg = null;
                if (st && values.status !== undefined && st.from.indexOf(cur.initial.status) !== -1 && st.to.indexOf(values.status) !== -1) msg = st.message;
                else if (pub && values.publish_mode !== undefined) msg = pub.comp.confirmMessage();
                if (!confirmed && msg) {
                    ask(msg, { danger: true, confirmLabel: 'Continue', cancelLabel: 'Cancel' }).then(function (ok) { if (ok) submit(true); });
                    return;
                }
                send(values, !!confirmed);
            }

            function send(values, confirmed) {
                var my = cur;
                my.busy = true;
                my.panel.setAttribute('aria-busy', 'true');
                var loader = window.bhLoader ? window.bhLoader(my.save, 'Saving…') : null;
                if (!loader) { my.save.disabled = true; }
                var body = { table: cfg.table, id: my.id, version: my.version, values: JSON.stringify(values), columns: JSON.stringify(columnIds()) };
                if (confirmed) body.confirm = true;
                window.Basehim.ajax.request('content.quick_edit_save', body, { timeout: 30000 }).then(function (d) {
                    if (loader) loader.done('Saved');
                    replaceRow(d.row);
                    var notes = d.notices || [];
                    if (window.bhToast) window.bhToast(notes.length ? 'Saved. ' + notes.join(' ') : 'Saved', notes.length ? 'info' : 'success');
                    if (cur === my) close(true, true);
                }).catch(function (err) {
                    if (cur !== my) return;
                    if (loader) loader.fail('Not saved');
                    var code = err && err.code;
                    if (code === 'CONFIRM_REQUIRED') {
                        ask(err.message, { danger: true, confirmLabel: 'Continue', cancelLabel: 'Cancel' }).then(function (ok) { if (ok) { my.busy = false; send(values, true); } });
                        return;
                    }
                    if (err && err.fields && Object.keys(err.fields).length) showErrors(err.fields);
                    var msg = (err && err.message) || 'Not saved.';
                    if (code === 'NETWORK_ERROR' || code === 'TIMEOUT' || code === 'BAD_RESPONSE' || code === 'INTERNAL_ERROR') {
                        msg = 'Not saved: ' + msg + ' Your changes are still here; try again.';
                    }
                    setBanner('error', msg);
                }).then(function () {
                    my.busy = false;
                    my.panel.removeAttribute('aria-busy');
                    if (!loader && my.save) my.save.disabled = false;
                });
            }

            /** Swap the row (and its card) for the re-rendered one; keep selection. */
            function replaceRow(row) {
                if (!row || row.id == null) return;
                var rows = payload.rows || [];
                for (var i = 0; i < rows.length; i++) if (rows[i].id === row.id) rows[i] = row;
                if (selected[row.id] !== undefined) selected[row.id] = row.status || '';
                var els = rowEls(row.id);
                if (els.tr) { var tr = buildRow(row); els.tr.parentNode.replaceChild(tr, els.tr); tr.classList.add('is-updated'); }
                if (els.card) { var c = buildCard(row); els.card.parentNode.replaceChild(c, els.card); c.classList.add('is-updated'); }
                syncBulk();
            }

            function close(force, saved) {
                if (!cur) return;
                if (!force && isDirty() && !saved) {
                    var c0 = cur;
                    ask('Discard your unsaved Quick Edit changes?', { danger: true, confirmLabel: 'Discard', cancelLabel: 'Keep editing' })
                        .then(function (ok) { if (ok && cur === c0) close(true); });
                    return;
                }
                var c = cur; cur = null; seq++;
                if (c.panel.parentNode) c.panel.parentNode.removeChild(c.panel);
                if (dlg) { if (typeof dlg.close === 'function' && dlg.open) dlg.close(); else dlg.removeAttribute('open'); }
                // Back to where the user was: the row's Quick Edit link or its
                // actions button, whichever this density shows.
                var els = rowEls(c.id);
                var shown = function (n) { return n && n.offsetParent !== null ? n : null; };
                var qlink = els.tr ? Array.prototype.filter.call(els.tr.querySelectorAll('.bh-dt-rowlink'), function (n) { return n.textContent === 'Quick Edit'; })[0] : null;
                var back = shown(qlink) || shown(els.tr && els.tr.querySelector('.bh-dt-actbtn')) || shown(els.card && els.card.querySelector('.bh-dt-actbtn')) || c.opener;
                if (back && back.focus) back.focus();
            }

            function ask(message, opts) {
                return window.bhConfirm ? window.bhConfirm(message, opts) : Promise.resolve(window.confirm(message));
            }

            return {
                open: open,
                close: close,
                isOpen: function () { return !!cur; },
                isDirty: isDirty
            };
        })();
        // --- Quick View (1.2.101) ----------------------------------------------
        // A read-only dialog with one row's details, from the table's
        // quick_view callable (content.quick_view). Same dialog as Quick Edit;
        // everything is drawn as text, links only from http(s) or root-relative
        // addresses the server already checked.
        var quickView = (function () {
            var dlg = null, head = null, body = null, seq = 0, opener = null;
            function text(tag, cls, t) { var n = el(tag, cls); n.textContent = t == null ? '' : String(t); return n; }
            function dialog() {
                if (dlg) return dlg;
                var tid = 'bh-qv-title-' + cfg.table.replace(/\W/g, '-');
                dlg = el('dialog', 'bh-qe bh-qv');
                dlg.setAttribute('aria-labelledby', tid);
                head = el('div', 'bh-qe__head bh-qv__head');
                body = el('div', 'bh-qe__body bh-qv__body');
                dlg.appendChild(head); dlg.appendChild(body);
                dlg.__tid = tid;
                dlg.addEventListener('close', function () { if (opener && opener.focus) { try { opener.focus(); } catch (e) {} } });
                dlg.addEventListener('mousedown', function (e) { dlg.__downOnBackdrop = e.target === dlg; });
                dlg.addEventListener('click', function (e) { if (e.target === dlg && dlg.__downOnBackdrop) dlg.close(); });
                document.body.appendChild(dlg);
                return dlg;
            }
            function header(v) {
                head.textContent = '';
                var media = el('span', 'bh-qv__media');
                media.innerHTML = icon(v.icon || 'document-text');
                if (v.image) {
                    var img = el('img'); img.alt = ''; img.loading = 'lazy';
                    img.addEventListener('load', function () { media.textContent = ''; media.appendChild(img); media.classList.add('has-image'); });
                    img.src = v.image;
                }
                var titles = el('div', 'bh-qe__titles');
                var h = text('h2', 'bh-qe__title', v.title || 'Quick View'); h.id = dlg.__tid; h.setAttribute('tabindex', '-1');
                titles.appendChild(h);
                if (v.subtitle) titles.appendChild(text('p', 'bh-qv__sub', v.subtitle));
                if (v.badges && v.badges.length) {
                    var bw = el('div', 'bh-qv__badges');
                    v.badges.forEach(function (b) { bw.appendChild(text('span', 'bh-badge bh-badge--' + b.tone, b.label)); });
                    titles.appendChild(bw);
                }
                var x = el('button', 'bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-qe__x', icon('x-mark')); x.type = 'button'; x.setAttribute('aria-label', 'Close');
                x.addEventListener('click', function () { dlg.close(); });
                head.appendChild(media); head.appendChild(titles); head.appendChild(x);
            }
            function render(v) {
                header(v);
                body.textContent = '';
                (v.sections || []).forEach(function (sec) {
                    var box = el('section', 'bh-qv__section');
                    if (sec.title) box.appendChild(text('h3', 'bh-qv__heading', sec.title));
                    var dl = el('dl', 'bh-qv__list');
                    sec.items.forEach(function (it) {
                        var row = el('div', 'bh-qv__item' + (it.wide ? ' bh-qv__item--wide' : ''));
                        // A wide item may carry no label (a notes block); an
                        // ordinary one always shows its label.
                        if (it.label || !it.wide) row.appendChild(text('dt', '', it.label));
                        var dd = el('dd', it.mono ? 'is-mono' : '');
                        if (it.url) {
                            var a = text('a', '', it.value || it.url); a.href = it.url;
                            if (/^https?:/i.test(it.url)) { a.target = '_blank'; a.rel = 'noopener'; }
                            dd.appendChild(a);
                        } else dd.textContent = it.value === '' ? '—' : it.value;
                        row.appendChild(dd); dl.appendChild(row);
                    });
                    box.appendChild(dl); body.appendChild(box);
                });
                if (v.links && v.links.length) {
                    var foot = el('div', 'bh-qv__foot');
                    v.links.forEach(function (l, i) {
                        var a = text('a', 'bh-btn bh-btn--sm ' + (i === 0 ? 'bh-btn--primary' : 'bh-btn--secondary'), l.label); a.href = l.url;
                        if (l.new_tab) { a.target = '_blank'; a.rel = 'noopener'; }
                        foot.appendChild(a);
                    });
                    body.appendChild(foot);
                }
            }
            function open(row, from) {
                var my = ++seq; opener = from || null;
                var d = dialog();
                head.textContent = ''; body.textContent = '';
                var load = el('div', 'bh-dt-qe__loading bh-qv__loading', '<span class="bh-dt-spinner"></span>');
                load.appendChild(text('span', '', 'Loading…'));
                body.appendChild(load);
                var x = el('button', 'bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-qe__x', icon('x-mark')); x.type = 'button'; x.setAttribute('aria-label', 'Close');
                x.addEventListener('click', function () { d.close(); });
                var t = el('div', 'bh-qe__titles'); var h = text('h2', 'bh-qe__title', 'Quick View'); h.id = d.__tid; t.appendChild(h);
                head.appendChild(t); head.appendChild(x);
                if (!d.open) { if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', ''); }
                window.Basehim.ajax.get('content.quick_view', { table: cfg.table, id: row.id }).then(function (res) {
                    if (seq !== my) return;
                    render(res.view || {});
                    var ht = d.querySelector('.bh-qe__title'); if (ht) ht.focus();
                }).catch(function (err) {
                    if (seq !== my) return;
                    body.textContent = '';
                    var box = el('div', 'bh-dt-qe__msg bh-dt-qe__msg--error bh-qv__error'); box.setAttribute('role', 'alert');
                    box.appendChild(text('span', '', (err && err.message) || 'The details could not be loaded.'));
                    body.appendChild(box);
                });
            }
            return { open: open };
        })();

        // Leaving the page with unsaved Quick Edit changes asks first.
        window.addEventListener('beforeunload', function (e) { if (quickEdit.isDirty()) { e.preventDefault(); e.returnValue = ''; } });

        // --- footer / pagination ---------------------------------------------
        function renderFoot() {
            var m = payload.meta || { page: 1, last_page: 1, total: 0, per_page: state.perPage };
            var from = m.total ? ((m.page - 1) * m.per_page + 1) : 0;
            var to = Math.min(m.page * m.per_page, m.total);
            if (r.count) renderCount(r.count, from, to, m.total);
            if (!r.pages) return;
            r.pages.innerHTML = '';
            // One page needs no pager (1.2.115: it showed a lone "1" between
            // two dead arrows, even with nothing found).
            r.pages.hidden = (m.last_page || 1) <= 1;
            if (r.pages.hidden) return;
            var mk = function (html, page, opts) {
                // Core small buttons (1.2.115): the current page is primary, the rest secondary.
                var b = el('button', 'bh-btn bh-btn--sm bh-dt-page ' + (opts && opts.current ? 'bh-btn--primary is-current' : 'bh-btn--secondary'), html);
                if (opts && opts.current) b.setAttribute('aria-current', 'page');
                if (opts && opts.disabled) b.disabled = true; else b.addEventListener('click', function () { state.page = page; scrollToListTop(); load(); });
                return b;
            };
            r.pages.appendChild(mk(icon('chevron-left'), m.page - 1, { disabled: m.page <= 1 }));
            pageWindow(m.page, m.last_page).forEach(function (p) {
                if (p === '…') { r.pages.appendChild(el('span', 'bh-dt-page bh-dt-page--gap', '…')); return; }
                r.pages.appendChild(mk(String(p), p, { current: p === m.page }));
            });
            r.pages.appendChild(mk(icon('chevron-right'), m.page + 1, { disabled: m.page >= m.last_page }));
        }
        function narrowScreen() {
            try { return !!(window.matchMedia && window.matchMedia('(max-width: 600px)').matches); } catch (e) { return false; }
        }
        // --- skeleton loading -----------------------------------------------
        // Shimmer placeholders shaped like the current view (same columns in the
        // table, same card shape in cards) at the current rows-per-page, so the
        // real data lands exactly where the skeleton sat (1.2.107). Both the
        // table body and the cards are filled; CSS shows only the active view,
        // as it does for the real rows/cards.
        function skelBar(width, extra) { var b = el('span', 'bh-dt-skel__bar' + (extra ? ' ' + extra : '')); if (width) b.style.width = width; return b; }
        function renderSkeleton() {
            var per = Math.min(Math.max(parseInt(state.perPage, 10) || 10, 3), 12);
            var i, c;
            if (r.body) {
                r.body.innerHTML = '';
                var cols = columns || [];
                for (i = 0; i < per; i++) {
                    var tr = el('tr', 'bh-dt-skelrow');
                    tr.appendChild(el('td', 'bh-dt-check'));
                    for (c = 0; c < cols.length; c++) {
                        var col = cols[c], td = el('td', alignCls(col));
                        if (col.id === 'thumb') {
                            var tb = el('span', 'bh-dt-skel__thumb'); tb.style.width = '2rem'; tb.style.height = '2rem';
                            td.classList.add('bh-dt-col-thumb'); td.appendChild(tb); tr.appendChild(td); continue;
                        }
                        var cell = el('div', 'bh-dt-skelcell');
                        cell.appendChild(skelBar(c === 0 ? '72%' : '54%'));
                        if (c === 0) cell.appendChild(skelBar('42%', 'bh-dt-skel__bar--sm'));
                        td.appendChild(cell); tr.appendChild(td);
                    }
                    tr.appendChild(el('td', 'bh-dt-rowactions'));
                    r.body.appendChild(tr);
                }
            }
            if (r.cards) {
                r.cards.innerHTML = '';
                for (i = 0; i < per; i++) {
                    var card = el('div', 'bh-dt-skelcard');
                    card.appendChild(el('span', 'bh-dt-skel__dot'));
                    var thumb = el('span', 'bh-dt-skel__thumb'); thumb.style.width = '2.5rem'; thumb.style.height = '2.5rem';
                    card.appendChild(thumb);
                    var main = el('div', 'bh-dt-skelcard__main');
                    main.appendChild(skelBar('78%'));
                    main.appendChild(skelBar('44%', 'bh-dt-skel__bar--sm'));
                    card.appendChild(main);
                    r.cards.appendChild(card);
                }
            }
        }
        /** "Showing 1–20 of 50 posts" — the table's own noun (1.2.65). */
        function countText(from, to, total) {
            var noun = String(payload.label || '').toLowerCase() || 'results';
            if (!total) return 'No ' + noun;
            return 'Showing ' + from + '–' + to + ' of ' + total + ' ' + noun;
        }
        // The count as parts, so phones can drop the words and keep the
        // numbers on one row with the pager ("1–8 of 25"; 1.2.115).
        function renderCount(node, from, to, total) {
            var noun = String(payload.label || '').toLowerCase() || 'results';
            node.innerHTML = '';
            if (!total) { node.textContent = countText(from, to, total); return; }
            node.appendChild(el('span', 'bh-dt-count__lead', 'Showing '));
            node.appendChild(document.createTextNode(from + '–' + to + ' of ' + total));
            node.appendChild(el('span', 'bh-dt-count__noun', ' ' + esc(noun)));
        }
        function pageWindow(cur, last) {
            var out = [], i;
            // Phones: three numbers around the current page, as space allows (1.2.65).
            if (narrowScreen()) {
                var from = Math.max(1, Math.min(cur - 1, last - 2)), to = Math.min(last, from + 2);
                for (i = from; i <= to; i++) out.push(i);
                return out;
            }
            if (last <= 7) { for (i = 1; i <= last; i++) out.push(i); return out; }
            out.push(1); if (cur > 3) out.push('…');
            for (i = Math.max(2, cur - 1); i <= Math.min(last - 1, cur + 1); i++) out.push(i);
            if (cur < last - 2) out.push('…'); out.push(last); return out;
        }

        // --- selection + context bulk ----------------------------------------
        function selectedIds() { return Object.keys(selected); }
        // Each bulk action's leading icon, by action id. A declared action can
        // carry its own `icon`; these are the built-ins.
        var BULK_ICON = { publish: 'check', approve: 'check', draft: 'arrow-down-tray', pending: 'arrow-down-tray', spam: 'no-symbol', delete: 'trash' };
        function syncBulk() {
            var ids = selectedIds(), n = ids.length;
            root.classList.toggle('has-selection', n > 0);   // desktop: every tick shows once one is ticked (1.2.116)
            if (r.bulkCount) {
                r.bulkCount.innerHTML = '';
                if (!n && selecting) {
                    r.bulkCount.appendChild(el('span', 'bh-dt-bulk__hint', 'Tap items to select'));
                } else {
                    r.bulkCount.appendChild(el('span', 'bh-dt-bulk__n', String(n)));
                    r.bulkCount.appendChild(el('span', '', 'selected'));
                }
            }
            if (r.bulk) r.bulk.classList.toggle('is-active', n > 0 || selecting);
            if (r.bulkClear) {
                r.bulkClear.hidden = n === 0 && !selecting;
                r.bulkClear.setAttribute('aria-label', selecting ? 'Done selecting' : 'Clear selection');
            }
            if (!r.bulkActions) return;
            r.bulkActions.innerHTML = '';
            if (!n) return;
            var statuses = ids.map(function (id) { return selected[id]; });
            var anyPublished = statuses.indexOf('published') !== -1;
            var anyUnpublished = statuses.some(function (s) { return s !== 'published'; });
            var bulk = declared().length ? [] : (payload.bulk_actions || []);
            var btns = [];
            declared().forEach(function (a) {
                if (!a.bulk || a.href || a.event) return;
                var ok = ids.filter(function (id) { return offers(a, selected[id], null); });
                if (ok.length) btns.push([a.id, a.label + (ok.length < n ? ' (' + ok.length + ')' : ''), a.tone, ok, a.icon || BULK_ICON[a.id]]);
            });
            if (bulk.indexOf('publish') !== -1 && anyUnpublished) btns.push(['publish', 'Publish', 'publish', null, BULK_ICON.publish]);
            if (bulk.indexOf('draft') !== -1 && anyPublished) btns.push(['draft', 'Unpublish', 'neutral', null, BULK_ICON.draft]);
            if (bulk.indexOf('approve') !== -1) btns.push(['approve', 'Approve', 'publish', null, BULK_ICON.approve]);
            if (bulk.indexOf('pending') !== -1) btns.push(['pending', 'Unapprove', 'neutral', null, BULK_ICON.pending]);
            if (bulk.indexOf('spam') !== -1) btns.push(['spam', 'Spam', 'danger', null, BULK_ICON.spam]);
            if (bulk.indexOf('delete') !== -1) btns.push(['delete', 'Delete', 'danger', null, BULK_ICON.delete]);
            btns.forEach(function (b) {
                // Core buttons (1.2.115): the bulk bar used its own pill buttons with a
                // tinted Delete; it now speaks the admin's button language.
                var tone = { publish: 'primary', neutral: 'secondary', danger: 'danger' }[b[2]] || 'secondary';
                var btn = el('button', 'bh-btn bh-btn--sm bh-btn--' + tone + ' bh-dt-bulkbtn');
                btn.type = 'button';
                if (b[4]) btn.innerHTML = icon(b[4]);
                btn.appendChild(el('span', '', esc(b[1])));
                btn.addEventListener('click', function () { runBulk(b[0], b[3] || selectedIds()); });
                r.bulkActions.appendChild(btn);
            });
        }
        function clearSelection() {
            selected = Object.create(null);
            root.querySelectorAll('.bh-dt-rowcheck').forEach(function (cb) { cb.checked = false; });
            root.querySelectorAll('.is-selected[data-dt-rowid]').forEach(function (n) { n.classList.remove('is-selected'); });
            selecting = false; syncSelecting();   // clearing also leaves select mode
            syncBulk(); syncSelectAll();
        }
        // --- select mode (touch, 1.2.116) ------------------------------------
        // Phones show no tick boxes at rest. "Select" in the toolbar (or a long
        // press on an item) enters select mode: the ticks appear — on each
        // picture where there is one — a tap anywhere on an item toggles it,
        // and the bulk bar shows with Done. Desktop instead reveals a row's tick
        // on hover or focus (CSS), so this mode is a touch affordance.
        function syncSelecting() {
            root.classList.toggle('is-selecting', selecting);
            if (r.selectMode) r.selectMode.setAttribute('aria-pressed', selecting ? 'true' : 'false');
        }
        function setSelecting(on) {
            selecting = !!on; syncSelecting(); syncBulk();
        }
        /** The table's header box and the cards' Select all show the same state. */
        function syncSelectAll() {
            var rows = payload.rows || [];
            var checked = rows.filter(function (row) { return selected[row.id] !== undefined; }).length;
            [r.selectAll, r.cardsAll, r.toolAll].forEach(function (box) {
                if (!box) return;
                box.checked = rows.length > 0 && checked === rows.length;
                box.indeterminate = checked > 0 && checked < rows.length;
            });
        }
        function onSelectAll(on) {
            (payload.rows || []).forEach(function (row) { if (on) selected[row.id] = row.status || ''; else delete selected[row.id]; markSelected(row.id, on); });
            root.querySelectorAll('.bh-dt-rowcheck').forEach(function (cb) { cb.checked = on; });
            syncBulk(); syncSelectAll();
        }
        /** The admin's base path, for a declared action's href. */
        function base() { return cfg.base || ''; }
        /** The table's declared actions (1.2.85), or none. */
        function declared() { return payload.actions || []; }
        /** Whether a declared action applies to a row with this status / flags. */
        function offers(a, status, flags) {
            if (a.when && a.when.length && a.when.indexOf(String(status || '')) === -1) return false;
            if (a.flag && flags && flags.indexOf(a.flag) === -1) return false;
            if (a.flag && !flags) return true;   // bulk: the server checks each row
            return true;
        }
        function runBulk(action, ids, rows) {
            if (!action || !ids || !ids.length || (!cfg.bulkEndpoint && !cfg.bulkEvents)) return;
            var decl = declared().filter(function (a) { return a.id === action; })[0];
            if (decl) {
                if (decl.confirm && window.bhConfirm) {
                    window.bhConfirm(decl.confirm.replace('{n}', ids.length).replace('{s}', ids.length === 1 ? '' : 's'), { danger: !!decl.danger, confirmLabel: decl.label })
                        .then(function (ok) { if (ok) submitBulk(action, ids); });
                    return;
                }
                submitBulk(action, ids);
                return;
            }
            if (action === 'delete' && window.bhConfirm) {
                var dmsg = cfg.deleteConfirm ? cfg.deleteConfirm.replace('{n}', ids.length) : ('Move ' + ids.length + ' item(s) to trash?');
                window.bhConfirm(dmsg).then(function (ok) { if (ok) submitBulk(action, ids); });
                return;
            }
            submitBulk(action, ids);
        }
        function submitBulk(action, ids) {
            if (!emit('bh:dt-bulk', { table: cfg.table, action: action, ids: ids.slice() }, true)) return;
            if (!cfg.bulkEndpoint) return;
            var form = el('form'); form.method = 'POST'; form.action = cfg.bulkEndpoint; form.style.display = 'none';
            form.appendChild(hidden('_csrf', cfg.csrf)); form.appendChild(hidden('bulk_action', action));
            ids.forEach(function (id) { form.appendChild(hidden('ids[]', id)); });
            document.body.appendChild(form); form.submit();
        }
        function hidden(name, value) { var i = el('input'); i.type = 'hidden'; i.name = name; i.value = value; return i; }

        // --- view toggle -----------------------------------------------------
        function setView(v) {
            if (['table', 'list', 'grid'].indexOf(v) === -1 || v === state.view) return;
            state.view = v; root.setAttribute('data-view', v);
            root.querySelectorAll('[data-dt-view]').forEach(function (btn) {
                var on = btn.getAttribute('data-dt-view') === v; btn.classList.toggle('is-active', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            savePrefs(true);
        }

        // --- Screen Options panel (works on a draft until Apply) -------------
        var draft = null;
        function renderPanel() {
            if (!r.panel) return;
            draft = { visible: state.visible.slice(), order: (state.order && state.order.length ? state.order.slice() : catalog.map(function (c) { return c.id; })), perPage: state.perPage, density: state.density };
            var list = el('ul', 'bh-dt-collist');
            draft.order.forEach(function (id) {
                var c = colById(id); if (!c) return;
                var li = el('li', 'bh-dt-col'); li.setAttribute('draggable', 'true'); li.setAttribute('data-col', id);
                li.appendChild(el('span', 'bh-dt-col__grip', icon('bars-3')));
                var cb = el('input'); cb.type = 'checkbox';
                cb.checked = draft.visible.indexOf(id) !== -1;      // reflect the DRAFT, not defaults
                if (c.can_hide === false) { cb.checked = true; cb.disabled = true; }
                cb.addEventListener('change', function () {
                    if (cb.checked) { if (draft.visible.indexOf(id) === -1) draft.visible.push(id); }
                    else draft.visible = draft.visible.filter(function (x) { return x !== id; });
                });
                li.appendChild(cb);
                li.appendChild(el('span', 'bh-dt-col__name', esc(c.label || id)));
                wireDrag(li, list);
                list.appendChild(li);
            });
            var sizeSel = el('select', 'bh-select bh-select--auto bh-select--sm');
            (payload.page_sizes || [state.perPage]).forEach(function (s) {
                var o = el('option'); o.value = s; o.textContent = s; if (s === draft.perPage) o.selected = true; sizeSel.appendChild(o);
            });
            sizeSel.addEventListener('change', function () { draft.perPage = parseInt(sizeSel.value, 10) || draft.perPage; });
            var densSel = el('select', 'bh-select bh-select--auto bh-select--sm');
            [['comfortable', 'Comfortable'], ['compact', 'Compact']].forEach(function (d) {
                var o = el('option'); o.value = d[0]; o.textContent = d[1]; if (d[0] === draft.density) o.selected = true; densSel.appendChild(o);
            });
            densSel.addEventListener('change', function () { draft.density = densSel.value; });

            r.panel.innerHTML = '';
            r.panel.appendChild(el('div', 'bh-dt-panel__title', icon('cog-6-tooth') + '<span>Screen Options</span>'));
            r.panel.appendChild(el('p', 'bh-dt-panel__desc', 'Choose which columns to show and how many rows per page.'));
            r.panel.appendChild(el('div', 'bh-dt-panel__label', 'Columns'));
            r.panel.appendChild(list);
            r.panel.appendChild(el('hr', 'bh-dt-panel__sep'));
            var row1 = el('div', 'bh-dt-panel__row'); row1.appendChild(el('span', '', 'Rows per page')); row1.appendChild(sizeSel); r.panel.appendChild(row1);
            // Density only changes table rows; hidden where only cards show.
            var row2 = el('div', 'bh-dt-panel__row bh-dt-panel__row--density'); row2.appendChild(el('span', '', 'Density')); row2.appendChild(densSel); r.panel.appendChild(row2);
            // Sticky chrome toggles (1.2.110), on by default. These apply at once
            // (a per-browser view choice), not via Apply.
            r.panel.appendChild(el('hr', 'bh-dt-panel__sep'));
            r.panel.appendChild(el('div', 'bh-dt-panel__label', 'Sticky'));
            r.panel.appendChild(stickyRow('Header & controls', state.sticky, function (on) { state.sticky = on; lsSet('sticky', on); applySticky(); }));
            r.panel.appendChild(stickyRow('Pagination', state.stickyFoot, function (on) { state.stickyFoot = on; lsSet('stickyFoot', on); applySticky(); }));
            var actions = el('div', 'bh-dt-panel__actions');
            var apply = el('button', 'bh-btn bh-btn--primary bh-btn--sm', 'Apply'); apply.addEventListener('click', applyPanel);
            var reset = el('a', '', 'Reset to default'); reset.href = '#'; reset.className = 'bh-dt-reset';
            reset.addEventListener('click', function (e) { e.preventDefault(); resetPrefs(); });
            actions.appendChild(apply); actions.appendChild(reset); r.panel.appendChild(actions);
        }
        function stickyRow(label, on, cb) {
            var row = el('div', 'bh-dt-panel__row');
            row.appendChild(el('span', '', label));
            var sw = el('label', 'bh-switch');
            var inp = el('input'); inp.type = 'checkbox'; inp.setAttribute('role', 'switch'); inp.className = 'bh-switch__input'; inp.checked = !!on;
            inp.addEventListener('change', function () { cb(inp.checked); });
            sw.appendChild(inp); sw.appendChild(el('span', 'bh-switch__track'));
            row.appendChild(sw);
            return row;
        }
        function wireDrag(li, list) {
            li.addEventListener('dragstart', function () { li.classList.add('is-dragging'); });
            li.addEventListener('dragend', function () {
                li.classList.remove('is-dragging');
                draft.order = Array.prototype.map.call(list.querySelectorAll('[data-col]'), function (n) { return n.getAttribute('data-col'); });
            });
            list.addEventListener('dragover', function (e) {
                e.preventDefault(); var dragging = list.querySelector('.is-dragging'); if (!dragging) return;
                var after = null;
                Array.prototype.forEach.call(list.querySelectorAll('[data-col]:not(.is-dragging)'), function (n) {
                    var box = n.getBoundingClientRect(); if (e.clientY - box.top - box.height / 2 < 0 && after === null) after = n;
                });
                if (after) list.insertBefore(dragging, after); else list.appendChild(dragging);
            });
        }
        function currentPrefs(over) {
            var p = {
                visible: state.visible, order: (state.order && state.order.length) ? state.order : columns.map(function (c) { return c.id; }),
                page_size: state.perPage, density: state.density, view: state.view, sort: state.sort
            };
            if (over) for (var k in over) p[k] = over[k];
            return p;
        }
        function rebuildVisibleColumns() {
            var order = (state.order && state.order.length) ? state.order : catalog.map(function (c) { return c.id; });
            columns = order.map(colById).filter(function (c) { return c && (state.visible.indexOf(c.id) !== -1 || c.can_hide === false); });
        }
        function applyPanel() {
            state.visible = draft.visible.slice(); state.order = draft.order.slice();
            state.perPage = draft.perPage; state.density = draft.density;
            root.setAttribute('data-density', state.density);
            if (r.perpage) r.perpage.value = String(state.perPage);
            closePanel(); rebuildVisibleColumns();
            scrollToListTop();   // a changed row count starts at the first row
            // Save first, THEN reload — so the reload reads the saved prefs and
            // does not race the write (which would revive a just-hidden column).
            savePrefs(false).then(load);
        }
        function savePrefs(quiet) {
            return window.Basehim.ajax.request('content.table_prefs', { table: cfg.table, prefs: JSON.stringify(currentPrefs()) })
                .then(function (d) {
                    if (d && d.prefs) { state.visible = d.prefs.visible; state.order = d.prefs.order; }
                    if (!quiet && window.bhToast) window.bhToast('View saved');
                })
                .catch(function (err) { if (window.bhToast) window.bhToast(err.message, 'error'); });
        }
        function resetPrefs() {
            window.Basehim.ajax.request('content.table_prefs_reset', { table: cfg.table })
                .then(function (d) {
                    if (d.prefs) { state.visible = d.prefs.visible; state.order = d.prefs.order; state.perPage = d.prefs.page_size; state.density = d.prefs.density; state.view = d.prefs.view; state.sort = d.prefs.sort; }
                    root.setAttribute('data-density', state.density); root.setAttribute('data-view', state.view);
                    root.querySelectorAll('[data-dt-view]').forEach(function (btn) { var on = btn.getAttribute('data-dt-view') === state.view; btn.classList.toggle('is-active', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); });
                    if (r.perpage) r.perpage.value = String(state.perPage);
                    closePanel(); state.page = 1; rebuildVisibleColumns(); load();
                }).catch(function (err) { if (window.bhToast) window.bhToast(err.message, 'error'); });
        }
        function openPanel() {
            renderPanel(); r.panel.classList.add('is-open');
            if (r.optionsBtn) r.optionsBtn.setAttribute('aria-expanded', 'true');
            // Cap the panel to the space below its top so its lower items (and
            // Apply) stay reachable by scrolling it, never clipped off-screen.
            try {
                var top = r.panel.getBoundingClientRect().top;
                r.panel.style.setProperty('--dt-panel-max', Math.max(180, (window.innerHeight || 0) - top - 16) + 'px');
            } catch (e) {}
        }
        function closePanel() { if (r.panel) r.panel.classList.remove('is-open'); if (r.optionsBtn) r.optionsBtn.setAttribute('aria-expanded', 'false'); }

        // --- filters: status (single select) --------------------------------
        root.querySelectorAll('[data-dt-filter]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var field = sel.getAttribute('data-dt-filter');
                if (sel.value) state.filters[field] = sel.value; else delete state.filters[field];
                state.page = 1;   // a new filter starts at its first page (1.2.67)
                load();
            });
        });

        // --- filters: category / tag (multi-select with search) -------------
        var termPanels = [];   // close-others support
        function getSel(field) { return toArr(state.filters[field]).map(String); }
        function setSel(field, arr) {
            if (arr && arr.length) state.filters[field] = arr.slice(); else delete state.filters[field];
        }
        function buildTermFilter(host) {
            var taxonomy = host.getAttribute('data-dt-termfilter');
            var field = taxonomy;                       // filter key == taxonomy slug
            var label = host.getAttribute('data-dt-label') || taxonomy;
            var conf = (cfg.filters && cfg.filters[taxonomy]) || { terms: [], total: 0 };
            var loaded = (conf.terms || []).slice();     // [{id,name,slug,count}]
            var total = conf.total || loaded.length;
            var offline = total <= loaded.length;        // everything already here?
            var names = {};                              // id -> name, for chips/kept selections
            loaded.forEach(function (t) { names[String(t.id)] = t.name; });

            host.classList.add('bh-dt-filter');
            var btn = el('button', 'bh-btn bh-btn--secondary bh-dt-filterbtn');
            btn.type = 'button'; btn.setAttribute('aria-haspopup', 'true'); btn.setAttribute('aria-expanded', 'false');
            var badge = el('span', 'bh-dt-filterbadge'); badge.style.display = 'none';
            btn.innerHTML = icon('funnel') + '<span>' + esc(label) + '</span>';
            btn.appendChild(badge);
            var panel = el('div', 'bh-dt-panel bh-dt-filterpanel');
            host.appendChild(btn); host.appendChild(panel);

            var search = el('input', 'bh-input bh-dt-filtersearch');
            search.type = 'search'; search.placeholder = 'Search ' + label.toLowerCase() + '…';
            var listWrap = el('div', 'bh-dt-termlist');
            var foot = el('div', 'bh-dt-filterfoot');
            var clear = el('button', 'bh-btn bh-btn--ghost bh-btn--sm bh-dt-filterclear', 'Clear'); clear.type = 'button';   // was the round bulk-close class (1.2.115)
            var hint = el('span', 'bh-dt-filterhint', '');
            foot.appendChild(hint); foot.appendChild(clear);

            function updateBadge() {
                var n = getSel(field).length;
                if (n) { badge.textContent = n; badge.style.display = ''; btn.classList.add('is-active'); }
                else { badge.style.display = 'none'; btn.classList.remove('is-active'); }
                clear.disabled = !n;   // nothing to clear (1.2.115)
            }
            function renderList(terms) {
                var sel = getSel(field), selSet = {}; sel.forEach(function (id) { selSet[id] = true; });
                // Keep any selected term that the current search dropped, so it
                // can still be seen and unticked.
                var shown = {}, out = [];
                terms.forEach(function (t) { shown[String(t.id)] = true; out.push(t); });
                sel.forEach(function (id) { if (!shown[id]) out.unshift({ id: id, name: names[id] || ('#' + id), count: null }); });
                listWrap.innerHTML = '';
                if (!out.length) { listWrap.appendChild(el('p', 'bh-dt-termempty', 'No matches.')); return; }
                out.forEach(function (t) {
                    var id = String(t.id);
                    var row = el('label', 'bh-dt-term');
                    var cb = el('input'); cb.type = 'checkbox'; cb.value = id; cb.checked = !!selSet[id];
                    cb.addEventListener('change', function () {
                        var cur = getSel(field);
                        if (cb.checked) { if (cur.indexOf(id) === -1) cur.push(id); names[id] = t.name; }
                        else cur = cur.filter(function (x) { return x !== id; });
                        setSel(field, cur); updateBadge(); load();
                    });
                    row.appendChild(cb);
                    row.appendChild(el('span', 'bh-dt-term__name', esc(t.name)));
                    if (t.count != null) row.appendChild(el('span', 'bh-dt-term__count', String(t.count)));
                    listWrap.appendChild(row);
                });
            }
            function offlineFilter(q) {
                q = q.toLowerCase();
                return loaded.filter(function (t) {
                    return !q || (t.name && t.name.toLowerCase().indexOf(q) !== -1) || (t.slug && t.slug.toLowerCase().indexOf(q) !== -1);
                });
            }
            var runSearch = debounce(function () {
                var q = search.value.trim();
                if (offline) { renderList(offlineFilter(q)); return; }
                hint.textContent = 'Searching…';
                window.Basehim.ajax.get('content.table_terms', {
                    taxonomy: taxonomy, q: q, limit: conf.limit || 50, selected: getSel(field).join(',')
                }).then(function (d) {
                    (d.terms || []).forEach(function (t) { names[String(t.id)] = t.name; });
                    hint.textContent = ''; renderList(d.terms || []);
                }).catch(function () { hint.textContent = 'Search failed.'; });
            }, 250);
            search.addEventListener('input', runSearch);
            clear.addEventListener('click', function () { setSel(field, []); updateBadge(); renderList(offline ? loaded : loaded); load(); });

            panel.appendChild(search);
            panel.appendChild(listWrap);
            panel.appendChild(foot);

            function open() { closeTermPanels(); renderList(offline ? offlineFilter(search.value.trim()) : loaded); panel.classList.add('is-open'); btn.setAttribute('aria-expanded', 'true'); if (!offline && search.value.trim()) runSearch(); search.focus(); }
            function close() { panel.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false'); }
            btn.addEventListener('click', function (e) { e.stopPropagation(); if (panel.classList.contains('is-open')) close(); else open(); });
            document.addEventListener('click', function (e) { if (!panel.contains(e.target) && !btn.contains(e.target)) close(); });
            termPanels.push(close);
            // The drawer shows this filter expanded inline (no popover); it calls
            // this to populate the list, since the popover's own open() won't run.
            host.__dtRenderInline = function () { renderList(offline ? offlineFilter(search.value.trim()) : loaded); };
            updateBadge();
        }
        function closeTermPanels() { termPanels.forEach(function (c) { c(); }); if (r.panel) closePanel(); }
        root.querySelectorAll('[data-dt-termfilter]').forEach(buildTermFilter);
        if (r.search) r.search.addEventListener('input', debounce(function () { state.search = r.search.value.trim(); state.page = 1; load(); }, 300));
        // On a phone the search grows to fill the row when focused and the other
        // controls fold away (1.2.108); it stays expanded while it has text.
        if (r.search) {
            var toolbar = root.querySelector('.bh-dt-toolbar');
            var setSearching = function (on) { if (toolbar) toolbar.classList.toggle('is-searching', on); };
            // Typing (focused) is separate from "expanded with text": phones stop
            // pinning the pager while the keyboard is up (1.2.117).
            r.search.addEventListener('focus', function () { root.classList.add('is-typing'); });
            r.search.addEventListener('blur', function () { root.classList.remove('is-typing'); });
            r.search.addEventListener('focus', function () { setSearching(true); });
            r.search.addEventListener('blur', function () { setSearching(r.search.value.trim() !== ''); });
        }
        root.querySelectorAll('[data-dt-view]').forEach(function (btn) {
            var on = btn.getAttribute('data-dt-view') === state.view; btn.classList.toggle('is-active', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.addEventListener('click', function () { setView(btn.getAttribute('data-dt-view')); });
        });
        if (r.perpage) r.perpage.addEventListener('change', function () {
            state.perPage = parseInt(r.perpage.value, 10) || state.perPage; scrollToListTop(); savePrefs(true).then(load);
        });
        if (r.optionsBtn) {
            r.optionsBtn.addEventListener('click', function (e) { e.stopPropagation(); if (r.panel.classList.contains('is-open')) closePanel(); else openPanel(); });
            document.addEventListener('click', function (e) { if (r.panel && !r.panel.contains(e.target) && !r.optionsBtn.contains(e.target)) closePanel(); });
        }

        // --- Filter & sort drawer (phones/tablets) --------------------------
        // One button replaces the inline filters, the view switch and Screen
        // Options on small screens (1.2.107). It opens a right-hand sheet with
        // a Sort control (the sort normally lives in the table header, which
        // phones don't show), every filter moved in from the toolbar, and
        // rows-per-page. Desktop never sees it.
        var drawerSync = null;
        (function buildDrawer() {
            if (!r.drawerBtn) return;

            // Sortable columns, from the catalog so a hidden-but-sortable column
            // is still offered. Tokens: ascending ends _az/_asc/oldest.
            function sortCols() { return (catalog || []).filter(function (c) { return c.sortable && c.sortable.length; }); }
            function ascToken(c) { for (var i = 0; i < c.sortable.length; i++) if (/(_az|_asc|oldest)$/.test(c.sortable[i])) return c.sortable[i]; return c.sortable[0]; }
            function descToken(c) { for (var i = 0; i < c.sortable.length; i++) if (!/(_az|_asc|oldest)$/.test(c.sortable[i])) return c.sortable[i]; return c.sortable[1] || c.sortable[0]; }
            function activeSortCol() { var cs = sortCols(); for (var i = 0; i < cs.length; i++) if (cs[i].sortable.indexOf(state.sort) !== -1) return cs[i]; return null; }

            var drawer = el('div', 'bh-dt-drawer');
            drawer.setAttribute('role', 'dialog'); drawer.setAttribute('aria-modal', 'true'); drawer.setAttribute('aria-label', 'Filter and sort');
            var overlay = el('div', 'bh-dt-drawer__overlay');
            var panel = el('div', 'bh-dt-drawer__panel');
            var head = el('div', 'bh-dt-drawer__head');
            head.appendChild(el('h2', 'bh-dt-drawer__title', icon('adjustments') + '<span>Filter &amp; sort</span>'));
            var xBtn = el('button', 'bh-btn bh-btn--ghost bh-btn--icon bh-dt-drawer__x', icon('x-mark')); xBtn.type = 'button'; xBtn.setAttribute('aria-label', 'Close');
            head.appendChild(xBtn);
            var body = el('div', 'bh-dt-drawer__body');
            var foot = el('div', 'bh-dt-drawer__foot');

            // Sort section. The select's value is tracked explicitly in
            // `sortChoice` rather than derived from state.sort, because the
            // server echoes the *effective* sort — so choosing "Default order"
            // (which sends no sort) would otherwise snap the control back to the
            // default column (1.2.108 fix).
            var sortSel, sortUp, sortDown;
            var defaultSort = payload.sort || null;
            var sortChoice = (state.sort && state.sort !== defaultSort && activeSortCol()) ? activeSortCol().id : '';
            if (sortCols().length) {
                var sSec = el('div', 'bh-dt-drawer__sec');
                sSec.appendChild(el('div', 'bh-dt-drawer__label', icon('adjustments') + '<span>Sort by</span>'));
                var sRow = el('div', 'bh-dt-sort');
                sortSel = el('select', 'bh-select bh-dt-sort__sel');
                var o0 = el('option'); o0.value = ''; o0.textContent = 'Default order'; sortSel.appendChild(o0);
                sortCols().forEach(function (c) { var o = el('option'); o.value = c.id; o.textContent = c.label || c.id; sortSel.appendChild(o); });
                var dir = el('div', 'bh-seg bh-seg--icon bh-dt-sortdir'); dir.setAttribute('role', 'group'); dir.setAttribute('aria-label', 'Sort direction');
                sortUp = el('button', '', icon('arrow-up')); sortUp.type = 'button'; sortUp.setAttribute('aria-label', 'Ascending');
                sortDown = el('button', '', icon('arrow-down')); sortDown.type = 'button'; sortDown.setAttribute('aria-label', 'Descending');
                dir.appendChild(sortUp); dir.appendChild(sortDown);
                sRow.appendChild(sortSel); sRow.appendChild(dir); sSec.appendChild(sRow); body.appendChild(sSec);

                var applySort = function (id, wantDesc) {
                    sortChoice = id || '';
                    if (!id) { state.sort = null; }
                    else { var c = colById(id); if (!c || !c.sortable) return; state.sort = wantDesc ? descToken(c) : ascToken(c); }
                    state.page = 1; syncSort(); savePrefs(true); load();
                };
                sortSel.addEventListener('change', function () { applySort(sortSel.value, false); });
                sortUp.addEventListener('click', function () { if (sortChoice) applySort(sortChoice, false); });
                sortDown.addEventListener('click', function () { if (sortChoice) applySort(sortChoice, true); });
            }

            // Filters section — the toolbar's controls move in on open.
            var fSec = el('div', 'bh-dt-drawer__sec');
            fSec.appendChild(el('div', 'bh-dt-drawer__label', icon('funnel') + '<span>Filters</span>'));
            var fHost = el('div', 'bh-dt-drawer__filters'); fSec.appendChild(fHost);

            // Rows per page lives in Screen Options (restored on mobile in
            // 1.2.108), so it is not duplicated here.
            body.appendChild(fSec);
            var clearAll = el('button', 'bh-btn bh-btn--ghost', 'Clear all'); clearAll.type = 'button';
            var done = el('button', 'bh-btn bh-btn--primary', 'Done'); done.type = 'button';
            foot.appendChild(clearAll); foot.appendChild(done);
            panel.appendChild(head); panel.appendChild(body); panel.appendChild(foot);
            drawer.appendChild(overlay); drawer.appendChild(panel);
            document.body.appendChild(drawer);

            // Move the toolbar's filter controls in/out, each with a label. A
            // hidden slot marks every control's home so it returns exactly there.
            var moved = [];
            function filterControls() {
                var out = [];
                root.querySelectorAll('.bh-dt-toolbar > .bh-select[data-dt-filter]').forEach(function (n) { out.push({ node: n, label: n.getAttribute('aria-label') || 'Filter' }); });
                root.querySelectorAll('.bh-dt-toolbar > [data-dt-termfilter]').forEach(function (n) { out.push({ node: n, label: n.getAttribute('data-dt-label') || n.getAttribute('data-dt-termfilter') }); });
                return out;
            }
            function moveIn() {
                if (moved.length) return;
                filterControls().forEach(function (f) {
                    var slot = el('span'); slot.hidden = true; f.node.parentNode.insertBefore(slot, f.node);
                    var field = el('div', 'bh-dt-drawer__field');
                    field.appendChild(el('label', 'bh-dt-drawer__sublabel', esc(f.label)));
                    field.appendChild(f.node);
                    fHost.appendChild(field);
                    // Term filters show expanded inline in the drawer; populate
                    // their list now (their popover open() won't fire here).
                    if (typeof f.node.__dtRenderInline === 'function') f.node.__dtRenderInline();
                    moved.push({ node: f.node, slot: slot });
                });
                if (!moved.length) fHost.appendChild(el('p', 'bh-dt-drawer__empty', 'No filters for this list.'));
            }
            function moveOut() {
                moved.forEach(function (m) { m.slot.parentNode.insertBefore(m.node, m.slot); m.slot.parentNode.removeChild(m.slot); });
                moved = []; fHost.innerHTML = '';
            }

            function syncSort() {
                if (!sortSel) return;
                // The select follows the explicit choice, not the server echo.
                sortSel.value = sortChoice;
                var c = sortChoice ? colById(sortChoice) : null;
                var tok = c && c.sortable ? c.sortable : [];
                var asc = c && tok.indexOf(state.sort) !== -1 && /(_az|_asc|oldest)$/.test(state.sort);
                var desc = c && tok.indexOf(state.sort) !== -1 && !asc;
                sortUp.classList.toggle('is-active', !!asc); sortDown.classList.toggle('is-active', !!desc);
                sortUp.setAttribute('aria-pressed', asc ? 'true' : 'false'); sortDown.setAttribute('aria-pressed', desc ? 'true' : 'false');
                sortUp.disabled = sortDown.disabled = !c;
            }
            function activeCount() {
                // Active filters only — the sort (which has a default) is not a
                // filter, so it does not raise the badge.
                var n = 0; for (var k in state.filters) if (Object.prototype.hasOwnProperty.call(state.filters, k)) { var v = state.filters[k]; if (v != null && v !== '' && !(Array.isArray(v) && !v.length)) n++; }
                return n;
            }
            function syncBadge() {
                if (!r.drawerBadge) return;
                var n = activeCount();
                if (n) { r.drawerBadge.textContent = n; r.drawerBadge.hidden = false; }
                else r.drawerBadge.hidden = true;
            }
            drawerSync = function () { syncSort(); syncBadge(); };

            // Simple focus trap while open.
            function trap(e) {
                if (e.key !== 'Tab') return;
                var f = panel.querySelectorAll('button, select, input, a[href], [tabindex]:not([tabindex="-1"])');
                f = Array.prototype.filter.call(f, function (n) { return !n.disabled && n.offsetParent !== null; });
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
            var lastFocus = null, closeTimer = null;
            // A CSS transition cannot run from display:none, so the panel is
            // mounted (display set) first, then .is-open is added on the next
            // frame so the slide-in animates; closing reverses it and unmounts
            // after the transition (1.2.108, smoother open/close).
            function open() {
                if (drawer.classList.contains('is-open')) return;
                if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
                lastFocus = document.activeElement;
                moveIn(); syncSort();
                drawer.style.display = 'block';
                // two frames so the browser paints the closed state first
                requestAnimationFrame(function () { requestAnimationFrame(function () {
                    drawer.classList.add('is-open');
                }); });
                r.drawerBtn.setAttribute('aria-expanded', 'true');
                document.documentElement.style.overflow = 'hidden';
                document.addEventListener('keydown', trap);
                setTimeout(function () { xBtn.focus(); }, 60);
            }
            function close() {
                if (!drawer.classList.contains('is-open')) return;
                drawer.classList.remove('is-open'); r.drawerBtn.setAttribute('aria-expanded', 'false');
                document.documentElement.style.overflow = '';
                document.removeEventListener('keydown', trap);
                // Unmount and return the controls once the slide-out finishes.
                closeTimer = setTimeout(function () { drawer.style.display = 'none'; moveOut(); closeTimer = null; }, 340);
                if (lastFocus && lastFocus.focus) lastFocus.focus();
            }
            r.drawerBtn.addEventListener('click', function () { drawer.classList.contains('is-open') ? close() : open(); });
            overlay.addEventListener('click', close);
            xBtn.addEventListener('click', close);
            done.addEventListener('click', close);
            clearAll.addEventListener('click', function () {
                state.filters = {}; state.sort = null; state.search = ''; sortChoice = '';
                if (r.search) r.search.value = '';
                root.querySelectorAll('[data-dt-filter]').forEach(function (s) { s.value = ''; });
                root.querySelectorAll('[data-dt-termfilter] .bh-dt-filterbadge').forEach(function (b) { b.style.display = 'none'; });
                root.querySelectorAll('[data-dt-termfilter] .bh-dt-filterbtn').forEach(function (b) { b.classList.remove('is-active'); });
                // Repopulate the inline term lists so their ticks clear too.
                root.querySelectorAll('[data-dt-termfilter]').forEach(function (h) { if (typeof h.__dtRenderInline === 'function') h.__dtRenderInline(); });
                state.page = 1; syncSort(); savePrefs(true); load();
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('is-open')) close(); });
            // Growing back to desktop while open would leave the filters stranded
            // in the hidden drawer; close it so they return to the toolbar.
            try { window.matchMedia('(min-width: 821px)').addEventListener('change', function (ev) { if (ev.matches && drawer.classList.contains('is-open')) close(); }); } catch (e) {}
            syncBadge();
        })();

        // --- Sticky chrome (1.2.110) ---------------------------------------
        // The controls and the table header stick under the app's top bar and
        // the pagination sticks to the bottom (CSS). Here we keep the header's
        // sticky offset in step with the controls' live height, and flag the
        // controls "stuck" so they can shrink.
        (function stickyChrome() {
            var topEl = root.querySelector('.bh-dt-top');
            if (!topEl) return;

            // The app's sticky top bar height — measured from the real element,
            // never assumed, so the controls sit exactly under it on any screen.
            function appBarHeight() {
                var main = root.closest ? root.closest('.bh-main') : null;
                var hdr = main ? main.querySelector(':scope > header') : null;
                if (hdr) { try { if (getComputedStyle(hdr).position === 'sticky') return Math.round(hdr.getBoundingClientRect().height); } catch (e) {} }
                return 0;
            }
            // Measure the offsets: the top-bar height, and the controls' height
            // WHEN SHRUNK (transitions suppressed), so the sticky header offset
            // is a constant and never jumps while the controls shrink.
            function measure() {
                try {
                    root.style.setProperty('--dt-sticky-top', appBarHeight() + 'px');
                    var hadStuck = topEl.classList.contains('is-stuck');
                    topEl.classList.add('bh-dt-measuring'); topEl.classList.add('is-stuck');
                    // Fractional height (getBoundingClientRect, not the integer
                    // offsetHeight): on a 2x display a rounded height leaves a
                    // sub-pixel gap between the controls and the sticky header.
                    var h = topEl.getBoundingClientRect().height;
                    if (!hadStuck) topEl.classList.remove('is-stuck');
                    topEl.classList.remove('bh-dt-measuring');
                    root.style.setProperty('--dt-top-h', h + 'px');
                } catch (e) {}
                measureGrid();
            }

            // Wide-table mode (1.2.114): when the table is wider than its wrap
            // (too many columns to fit), the wrap becomes a self-contained
            // scroll box — the header row freezes to its top and the first
            // column freezes to its left (CSS, gated on .bh-dt--xscroll). Here
            // we detect the overflow, measure the left offset of the frozen
            // first data column (the width of the checkbox cell before it, 0
            // when selection is off), and bound the box to the viewport.
            function measureGrid() {
                try {
                    if (!r.wrap || !r.table) return;
                    // Measure the table as it lays out normally: grid mode keeps its
                    // cells on one line, which widens the table, so measuring with
                    // the mode on would keep it on forever (1.2.115). Read in the
                    // same frame, so nothing paints in between.
                    var was = root.classList.contains('bh-dt--xscroll');
                    if (was) root.classList.remove('bh-dt--xscroll');
                    var overflow = (r.table.scrollWidth - r.wrap.clientWidth) > 1;
                    root.classList.toggle('bh-dt--xscroll', overflow);
                    if (!overflow) { r.wrap.classList.remove('has-more-right', 'is-xscrolled'); return; }
                    // Cumulative left offset for each frozen leading cell: cell k
                    // sits at the summed width of the frozen cells before it,
                    // measured from the header row (columns share widths).
                    var frz = r.head ? r.head.querySelectorAll('.bh-dt-frz') : [];
                    var left = 0;
                    for (var k = 0; k < frz.length; k++) {
                        root.style.setProperty('--dt-frzL-' + k, Math.round(left) + 'px');
                        left += (frz[k].offsetParent !== null) ? frz[k].getBoundingClientRect().width : 0;
                    }
                    var top = parseFloat(getComputedStyle(root).getPropertyValue('--dt-sticky-top')) || 0;
                    var th = parseFloat(getComputedStyle(root).getPropertyValue('--dt-top-h')) || 0;
                    var footH = r.foot ? r.foot.getBoundingClientRect().height : 0;
                    var avail = (window.innerHeight || 800) - top - th - footH - 24;
                    root.style.setProperty('--dt-grid-max', Math.max(260, Math.round(avail)) + 'px');
                    updateXShadow();
                } catch (e) {}
            }
            // Show the frozen column's right-edge shadow only once scrolled, so
            // it reads as "more columns hide under here".
            function updateXShadow() {
                try { if (r.wrap) { var w = r.wrap; w.classList.toggle('is-xscrolled', w.scrollLeft > 0); w.classList.toggle('has-more-right', w.scrollLeft + w.clientWidth < w.scrollWidth - 1); } } catch (e) {}
            }
            if (r.wrap && r.wrap.addEventListener) {
                r.wrap.addEventListener('scroll', function () {
                    (window.requestAnimationFrame || function (f) { f(); })(updateXShadow);
                }, { passive: true });
            }
            var ticking = false;
            function updateStuck() {
                ticking = false;
                if (!root.classList.contains('bh-dt--sticky')) { topEl.classList.remove('is-stuck'); return; }
                var off = parseFloat(getComputedStyle(root).getPropertyValue('--dt-sticky-top')) || 0;
                try { topEl.classList.toggle('is-stuck', root.getBoundingClientRect().top <= off + 0.5); } catch (e) {}
            }
            function onScroll() { if (ticking) return; ticking = true; (window.requestAnimationFrame || function (f) { f(); })(updateStuck); }

            remeasureSticky = function () { measure(); onScroll(); };
            measure(); updateStuck();
            if (window.addEventListener) {
                window.addEventListener('scroll', onScroll, { passive: true });
                var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { measure(); updateStuck(); }, 120); });
            }
        })();

        // Scroll the list back to its first row after a page change, so the
        // reader starts at the top of the new page instead of mid-list (1.2.110).
        // Uses the MEASURED top-bar offset; only ever scrolls up.
        function scrollToListTop() {
            try {
                if (typeof window.scrollTo !== 'function' || !root.getBoundingClientRect) return;
                var off = parseFloat(getComputedStyle(root).getPropertyValue('--dt-sticky-top')) || 0;
                var cur = window.pageYOffset || 0;
                var y = root.getBoundingClientRect().top + cur - off;
                if (cur > y + 2) {
                    try { window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' }); }
                    catch (e) { window.scrollTo(0, Math.max(0, y)); }
                }
            } catch (e) {}
        }

        renderHead(); renderBody(); renderFoot();
        remeasureSticky();   // initial wide-table overflow detection
    }

    function init() {
        document.querySelectorAll('.bh-dt').forEach(function (root) {
            if (root.__dt || !root.querySelector('[data-dt-config]')) return;
            root.__dt = true;
            try { new DataTable(root); } catch (e) { if (window.console) console.error('DataTable:', e); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.BasehimDataTable = { init: init };
})();
