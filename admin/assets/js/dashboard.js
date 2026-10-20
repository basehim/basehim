/*
 * Basehim dashboard (1.2.68) — see docs/DASHBOARD-WIDGETS.md.
 *
 * Builds the cards from window.BH_DASHBOARD (the user's layout and the Add
 * Widgets catalogue), loads every card's data in one batched request through
 * the AJAX gateway, and runs the customize mode: drag (pointer and touch),
 * move up/down (buttons and arrow keys), sizes, add, remove, restore, reset
 * and the site default.
 *
 * Safety: everything a widget returns is put on the page with textContent or
 * as attributes this file sets; links must be site paths or http(s). The only
 * HTML inserted is the 'html' view, which the server has sanitized, or a
 * pre-1.2.68 widget's own render output.
 *
 * The database is the source of truth. Customize mode edits a staged copy in
 * memory and saves it with widgets.dashboard_save; Cancel throws it away.
 */
(function (window, document) {
    'use strict';

    var BOOT = window.BH_DASHBOARD;
    var root = document.querySelector('[data-bh-dash]');
    if (!BOOT || !root || !window.Basehim || !window.Basehim.ajax) return;

    var ajax = window.Basehim.ajax;
    var grid = root.querySelector('[data-bh-dash-grid]');
    var live = root.querySelector('[data-bh-dash-live]');
    var ICONS = BOOT.icons || {};
    var SIZE_LABELS = { 'small': '¼', 'third': '⅓', 'half': '½', 'two-thirds': '⅔', 'full': 'Full' };
    var SIZE_NAMES = { 'small': 'a quarter', 'third': 'a third', 'half': 'half', 'two-thirds': 'two thirds', 'full': 'the full width' };
    var REFRESH_COOLDOWN = 4000;

    var state = {
        items: clone(BOOT.layout.items || []),
        customized: !!BOOT.layout.customized,
        catalog: indexCatalog(BOOT.catalog || []),
        hasSiteDefault: !!BOOT.hasSiteDefault,
        editing: false,
        staged: null,      // the layout as it was when Customize was opened
        dirty: false
    };
    var data = {};         // id → last result (kept across re-renders)
    var inflight = {};     // id → true while a request runs
    var lastForced = {};   // id → time of the last manual refresh
    var legacyNodes = {};  // id → holder of a legacy widget's server-rendered HTML

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function clone(v) { return JSON.parse(JSON.stringify(v)); }
    function indexCatalog(list) { var m = {}; list.forEach(function (w) { m[w.key] = w; }); return m; }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null && text !== '') n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var span = el('span', 'bh-dash-ico');
        span.setAttribute('aria-hidden', 'true');
        // Icons are the server's own SVG set (printed by the view), never widget data.
        span.innerHTML = ICONS[name] || ICONS['question-mark-circle'] || '';
        return span;
    }
    function iconButton(name, label, cls) {
        var b = el('button', 'bh-dash-icon-btn' + (cls ? ' ' + cls : ''));
        b.type = 'button';
        b.setAttribute('aria-label', label);
        b.title = label;
        b.appendChild(icon(name));
        return b;
    }
    function safeHref(v) {
        if (typeof v !== 'string' || !v) return null;
        return /^(\/(?!\/)|https?:\/\/)/i.test(v) ? v : null;
    }
    function link(href, cls, text) {
        var a = el('a', cls, text);
        var h = safeHref(href);
        if (h) a.href = h;
        if (h && /^https?:\/\//i.test(h) && h.indexOf(location.origin) !== 0) { a.target = '_blank'; a.rel = 'noopener'; }
        return a;
    }
    function fmtNumber(v) {
        if (typeof v === 'number' && isFinite(v)) {
            try { return new Intl.NumberFormat().format(v); } catch (e) { return String(v); }
        }
        return String(v === undefined || v === null ? '' : v);
    }
    function ago(v) {
        if (!v) return '';
        if (window.BasehimTime && typeof window.BasehimTime.ago === 'function') {
            try { return window.BasehimTime.ago(v); } catch (e) { /* fall through */ }
        }
        return String(v);
    }
    function fullTime(v) {
        if (window.BasehimTime && typeof window.BasehimTime.format === 'function') {
            try { return window.BasehimTime.format(v); } catch (e) { /* fall through */ }
        }
        return String(v);
    }
    function announce(msg) {
        if (!live) return;
        live.textContent = '';
        setTimeout(function () { live.textContent = msg; }, 30);
    }
    function toast(msg, type) { if (typeof window.bhToast === 'function') window.bhToast(msg, type || 'success'); }
    function confirmAsk(msg, opts) {
        if (typeof window.bhConfirm === 'function') return window.bhConfirm(msg, opts || {});
        return Promise.resolve(window.confirm(msg));
    }
    function busy(btn, on, label) { if (btn && typeof window.bhBusy === 'function') window.bhBusy(btn, on, label); }
    function widgetOf(item) { return state.catalog[item.widget] || null; }
    function visibleItems() { return state.items.filter(function (i) { return i.visible && widgetOf(i); }); }
    function findItem(id) { for (var i = 0; i < state.items.length; i++) if (state.items[i].id === id) return state.items[i]; return null; }
    function cardEl(id) { return grid.querySelector('.bh-dcard[data-id="' + cssEsc(id) + '"]'); }
    function cssEsc(s) { return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/[^a-z0-9_-]/gi, '\\$&'); }
    function tempId() { return 'new-' + Math.random().toString(36).slice(2, 10); }
    function markDirty() { state.dirty = true; }

    // Legacy widget HTML printed by the server: keep the nodes so moving cards
    // around never re-creates them (their scripts already ran once).
    (BOOT.legacy || []).forEach(function (id) {
        var holder = document.querySelector('[data-bh-dash-legacy="' + cssEsc(id) + '"]');
        if (holder) {
            holder.hidden = false;
            holder.removeAttribute('data-bh-dash-legacy');
            holder.className = 'bh-dview bh-dview--html';
            legacyNodes[id] = holder;
            data[id] = { ok: true, view: 'html', legacy: true, empty: holder.textContent.trim() === '' && !holder.querySelector('img,svg,canvas,iframe,table,video') };
        }
    });

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    function render() {
        root.classList.toggle('is-editing', state.editing);
        var editbar = root.querySelector('[data-bh-dash-editbar]');
        editbar.hidden = !state.editing;
        root.querySelector('[data-bh-dash-view-actions]').hidden = state.editing;

        // Detach legacy holders before clearing, so they survive the rebuild.
        Object.keys(legacyNodes).forEach(function (id) {
            var n = legacyNodes[id];
            if (n.parentNode) n.parentNode.removeChild(n);
        });
        grid.textContent = '';
        var cards = visibleItems();
        cards.forEach(function (item, idx) { grid.appendChild(buildCard(item, idx, cards.length)); });

        var blank = root.querySelector('[data-bh-dash-blank]');
        blank.hidden = cards.length > 0 || state.editing;
        if (state.editing && cards.length === 0) {
            var hint = el('div', 'bh-dash-drop-hint');
            hint.appendChild(el('p', 'bh-dash-drop-hint__title', 'No widgets on your dashboard'));
            hint.appendChild(el('p', 'bh-dash-drop-hint__text', 'Use Add widgets to choose what appears here.'));
            grid.appendChild(hint);
        }
        if (isDrawerOpen()) renderCatalog();
    }

    function buildCard(item, idx, total) {
        var w = widgetOf(item);
        var card = el('article', 'bh-dcard bh-dcard--' + item.size);
        card.dataset.id = item.id;
        card.dataset.widget = item.widget;
        card.setAttribute('aria-labelledby', 'bh-dcard-t-' + item.id);
        if (item.collapsed && !state.editing) card.classList.add('is-collapsed');

        var head = el('header', 'bh-dcard__head');
        if (state.editing) {
            var handle = iconButton('bars-2', 'Move ' + w.title + ' (drag, or use the arrow keys)', 'bh-dcard__handle');
            handle.addEventListener('pointerdown', startDrag);
            handle.addEventListener('keydown', function (e) { keyMove(e, item.id); });
            head.appendChild(handle);
        } else {
            head.appendChild(icon(w.icon));
        }
        var title = el('h2', 'bh-dcard__title', w.title);
        title.id = 'bh-dcard-t-' + item.id;
        head.appendChild(title);

        var tools = el('div', 'bh-dcard__tools');
        var strip = null;
        if (state.editing) {
            if (w.removable) {
                var rm = iconButton('x-mark', 'Remove ' + w.title, 'bh-dcard__remove');
                rm.addEventListener('click', function () { stagedRemove(item.id); });
                tools.appendChild(rm);
            }
            // Size and position live in a strip at the foot of the card, so a
            // narrow card keeps room for its title.
            strip = el('div', 'bh-dcard__edit');
            if (w.sizes.length > 1) strip.appendChild(sizePicker(item, w));
            else strip.appendChild(el('span', 'bh-dcard__onesize', 'One size'));
            var moves = el('div', 'bh-dcard__moves');
            var up = iconButton('arrow-up', 'Move ' + w.title + ' earlier', 'bh-dcard__move bh-dcard__move--up');
            up.disabled = idx === 0;
            up.addEventListener('click', function () { moveBy(item.id, -1); });
            var down = iconButton('arrow-down', 'Move ' + w.title + ' later', 'bh-dcard__move bh-dcard__move--down');
            down.disabled = idx === total - 1;
            down.addEventListener('click', function () { moveBy(item.id, 1); });
            moves.appendChild(up);
            moves.appendChild(down);
            strip.appendChild(moves);
        } else {
            if (w.refreshable) {
                var rf = iconButton('arrow-path', 'Refresh ' + w.title, 'bh-dcard__refresh');
                rf.addEventListener('click', function () { refresh(item.id, true); });
                tools.appendChild(rf);
            }
            if (w.collapsible) {
                var cl = iconButton('chevron-down', (item.collapsed ? 'Expand ' : 'Collapse ') + w.title, 'bh-dcard__collapse');
                cl.setAttribute('aria-expanded', item.collapsed ? 'false' : 'true');
                cl.addEventListener('click', function () { toggleCollapse(item.id); });
                tools.appendChild(cl);
            }
            var menu = buildMenu(item, w);
            if (menu) tools.appendChild(menu);
        }
        head.appendChild(tools);
        card.appendChild(head);

        var body = el('div', 'bh-dcard__body');
        body.id = 'bh-dcard-b-' + item.id;
        card.appendChild(body);
        if (!state.editing || !String(item.id).startsWith('new-')) {
            paintBody(card, item);
        } else {
            body.appendChild(stateBlock('information-circle', 'Save to load this widget', 'Its data appears once you save your changes.', 'info'));
        }
        if (strip) card.appendChild(strip);
        return card;
    }

    function sizePicker(item, w) {
        var group = el('div', 'bh-dcard__sizes');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', 'Width of ' + w.title);
        w.sizes.forEach(function (s) {
            var b = el('button', 'bh-dcard__size' + (s === item.size ? ' is-on' : ''), SIZE_LABELS[s] || s);
            b.type = 'button';
            b.setAttribute('aria-pressed', s === item.size ? 'true' : 'false');
            b.title = 'Use ' + (SIZE_NAMES[s] || s) + ' of the width (' + BOOT.sizes[s] + ' of 12 columns)';
            b.setAttribute('aria-label', b.title);
            b.addEventListener('click', function () {
                if (item.size === s) return;
                item.size = s;
                markDirty();
                var card = cardEl(item.id);
                if (card) {
                    Object.keys(BOOT.sizes).forEach(function (k) { card.classList.remove('bh-dcard--' + k); });
                    card.classList.add('bh-dcard--' + s);
                    group.querySelectorAll('.bh-dcard__size').forEach(function (x) {
                        var on = x === b;
                        x.classList.toggle('is-on', on);
                        x.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                }
                announce(w.title + ' now uses ' + (SIZE_NAMES[s] || s) + ' of the width.');
            });
            group.appendChild(b);
        });
        return group;
    }

    // ------------------------------------------------------------------
    // Overflow menu (actions, settings, remove)
    // ------------------------------------------------------------------

    function buildMenu(item, w) {
        var entries = [];
        (w.actions || []).forEach(function (a) { entries.push({ kind: 'action', a: a }); });
        if (w.settings) entries.push({ kind: 'settings' });
        if (w.removable) entries.push({ kind: 'remove' });
        if (!entries.length) return null;

        var wrap = el('div', 'bh-dmenu');
        var btn = iconButton('ellipsis-vertical', 'More for ' + w.title, 'bh-dmenu__btn');
        btn.setAttribute('aria-haspopup', 'true');
        btn.setAttribute('aria-expanded', 'false');
        var list = el('div', 'bh-dmenu__list');
        list.setAttribute('role', 'menu');
        list.hidden = true;

        var sepAdded = false;
        entries.forEach(function (e) {
            var node;
            if (e.kind === 'action') {
                if (e.a.url) {
                    node = link(e.a.url, 'bh-dmenu__item', '');
                } else {
                    node = el('button', 'bh-dmenu__item' + (e.a.danger ? ' is-danger' : ''));
                    node.type = 'button';
                    node.addEventListener('click', function () { closeMenus(); runAction(item.id, e.a, node); });
                }
                if (e.a.icon && ICONS[e.a.icon]) node.appendChild(icon(e.a.icon));
                node.appendChild(el('span', '', e.a.label));
            } else {
                if (!sepAdded && entries[0].kind === 'action') { list.appendChild(el('div', 'bh-dmenu__sep')); }
                sepAdded = true;
                node = el('button', 'bh-dmenu__item' + (e.kind === 'remove' ? ' is-danger' : ''));
                node.type = 'button';
                node.appendChild(icon(e.kind === 'settings' ? 'cog-6-tooth' : 'eye-slash'));
                node.appendChild(el('span', '', e.kind === 'settings' ? 'Settings' : 'Remove from dashboard'));
                node.addEventListener('click', function () {
                    closeMenus();
                    if (e.kind === 'settings') openSettings(item.id); else removeNow(item.id);
                });
            }
            node.setAttribute('role', 'menuitem');
            list.appendChild(node);
        });

        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var open = list.hidden;
            closeMenus();
            if (open) {
                list.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                wrap.classList.add('is-open');
                var first = list.querySelector('.bh-dmenu__item');
                if (first) first.focus();
            }
        });
        list.addEventListener('keydown', function (ev) {
            var items = Array.prototype.slice.call(list.querySelectorAll('.bh-dmenu__item'));
            var i = items.indexOf(document.activeElement);
            if (ev.key === 'ArrowDown') { ev.preventDefault(); items[(i + 1) % items.length].focus(); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
            else if (ev.key === 'Escape') { ev.preventDefault(); closeMenus(); btn.focus(); }
        });
        wrap.appendChild(btn);
        wrap.appendChild(list);
        return wrap;
    }

    function closeMenus() {
        root.querySelectorAll('.bh-dmenu.is-open').forEach(function (m) {
            m.classList.remove('is-open');
            m.querySelector('.bh-dmenu__list').hidden = true;
            m.querySelector('.bh-dmenu__btn').setAttribute('aria-expanded', 'false');
        });
    }
    document.addEventListener('click', function (e) { if (!e.target.closest || !e.target.closest('.bh-dmenu')) closeMenus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(); });

    // ------------------------------------------------------------------
    // Card bodies: states and views
    // ------------------------------------------------------------------

    function paintBody(card, item) {
        var body = card.querySelector('.bh-dcard__body');
        if (!body) return;
        var w = widgetOf(item);
        body.textContent = '';
        card.classList.remove('is-loading');
        if (item.collapsed && !state.editing) return;

        var res = data[item.id];
        if (!res || res.pending) {
            card.classList.add('is-loading');
            body.setAttribute('aria-busy', 'true');
            body.appendChild(skeleton(w));
            return;
        }
        body.removeAttribute('aria-busy');
        if (!res.ok) {
            if (res.state === 'unavailable') {
                body.appendChild(stateBlock('no-symbol', 'Not available right now', res.message, 'muted'));
            } else if (res.state === 'gone') {
                body.appendChild(stateBlock('information-circle', 'This widget has gone', res.message, 'muted'));
            } else {
                var err = stateBlock('exclamation-triangle', "Couldn't load this widget", res.message || 'Something went wrong.', 'error');
                var retry = el('button', 'bh-btn bh-btn--secondary bh-btn--sm', 'Try again');
                retry.type = 'button';
                retry.addEventListener('click', function () { refresh(item.id, true); });
                err.appendChild(retry);
                body.appendChild(err);
            }
            return;
        }
        if (res.legacy && legacyNodes[item.id]) {
            body.appendChild(legacyNodes[item.id]);
            return;
        }
        if (res.empty) {
            var e = w.empty || {};
            var block = stateBlock(w.icon, e.title || 'Nothing here yet', e.text || '', 'empty');
            if (e.action && safeHref(e.action.url)) {
                block.appendChild(link(e.action.url, 'bh-btn bh-btn--secondary bh-btn--sm', e.action.label));
            }
            body.appendChild(block);
            return;
        }
        try {
            body.appendChild(VIEWS[res.view] ? VIEWS[res.view](res.data || {}, res) : stateBlock('exclamation-triangle', 'Unknown view', res.view, 'error'));
            if (res.data && (res.data.footer || res.data.summary) && res.view !== 'status') body.appendChild(footer(res.data));
        } catch (ex) {
            body.textContent = '';
            body.appendChild(stateBlock('exclamation-triangle', "Couldn't show this widget", 'Its data was not in a shape the dashboard understands.', 'error'));
            if (window.console) console.error('[dashboard] ' + item.widget, ex);
        }
    }

    function stateBlock(iconName, title, text, tone) {
        var b = el('div', 'bh-dstate bh-dstate--' + (tone || 'muted'));
        b.appendChild(icon(iconName));
        b.appendChild(el('p', 'bh-dstate__title', title));
        if (text) b.appendChild(el('p', 'bh-dstate__text', text));
        return b;
    }

    function skeleton(w) {
        var s = el('div', 'bh-dskel');
        s.setAttribute('aria-label', 'Loading ' + w.title);
        var rows = 3;
        for (var i = 0; i < rows; i++) {
            var r = el('span', 'bh-dskel__row');
            r.style.width = (92 - i * 18) + '%';
            s.appendChild(r);
        }
        return s;
    }

    function footer(d) {
        var f = el('div', 'bh-dcard__foot');
        if (d.summary) f.appendChild(el('span', '', d.summary));
        if (d.footer && d.footer.text) f.appendChild(el('span', '', d.footer.text));
        if (d.footer && d.footer.link) f.appendChild(link(d.footer.link.url, 'bh-dcard__footlink', d.footer.link.label));
        return f;
    }

    /**
     * Swap each <script> in a fragment for a fresh one with the same
     * attributes and code. A script element created by the parser through
     * innerHTML is marked "already started" and never runs; one created with
     * createElement runs when it is inserted into the document. The fragment
     * is inserted after the card's old content has been removed, so a widget
     * script that looks itself up by id finds the new markup.
     */
    function activateScripts(container) {
        container.querySelectorAll('script').forEach(function (old) {
            var type = (old.getAttribute('type') || '').trim().toLowerCase();
            // Leave data blocks (application/json, text/template…) alone.
            if (type && type !== 'text/javascript' && type !== 'module' && type !== 'application/javascript') return;
            var s = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                s.setAttribute(old.attributes[i].name, old.attributes[i].value);
            }
            s.text = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
    }

    function badge(b) {
        if (!b || !b.text) return null;
        return el('span', 'bh-dtone bh-dtone--' + (b.tone || 'slate'), b.text);
    }

    var VIEWS = {
        stat: function (d) {
            var wrap = el('div', 'bh-dview bh-dview--stat');
            (d.items || []).forEach(function (it) {
                var tile = it.href ? link(it.href, 'bh-dstat', '') : el('div', 'bh-dstat');
                tile.classList.add('bh-dstat--' + (it.tone || 'slate'));
                tile.appendChild(el('span', 'bh-dstat__label', it.label));
                tile.appendChild(el('span', 'bh-dstat__value', fmtNumber(it.value)));
                if (it.trend && it.trend.text) {
                    var tr = el('span', 'bh-dstat__trend ' + (it.trend.good ? 'is-good' : 'is-bad'), (it.trend.direction === 'up' ? '↑ ' : it.trend.direction === 'down' ? '↓ ' : '') + it.trend.text);
                    tile.appendChild(tr);
                }
                if (it.hint) tile.appendChild(el('span', 'bh-dstat__hint', it.hint));
                wrap.appendChild(tile);
            });
            return wrap;
        },
        list: function (d) {
            var ul = el('ul', 'bh-dview bh-dview--list');
            (d.items || []).forEach(function (it) {
                var li = el('li', 'bh-dlist__row');
                var main = el('div', 'bh-dlist__main');
                main.appendChild(it.href ? link(it.href, 'bh-dlist__title', it.title) : el('span', 'bh-dlist__title', it.title));
                var meta = [it.meta, it.time ? ago(it.time) : ''].filter(Boolean).join(' · ');
                if (meta) {
                    var m = el('span', 'bh-dlist__meta', meta);
                    if (it.time) m.title = fullTime(it.time);
                    main.appendChild(m);
                }
                li.appendChild(main);
                var bd = badge(it.badge);
                if (bd) li.appendChild(bd);
                ul.appendChild(li);
            });
            return ul;
        },
        table: function (d) {
            var scroll = el('div', 'bh-dview bh-dview--table');
            var t = el('table', 'bh-dtable');
            var thead = el('thead'), tr = el('tr');
            (d.columns || []).forEach(function (c) {
                var th = el('th', 'is-' + c.align, c.label);
                th.scope = 'col';
                tr.appendChild(th);
            });
            thead.appendChild(tr);
            t.appendChild(thead);
            var tb = el('tbody');
            (d.rows || []).forEach(function (r) {
                var row = el('tr');
                (d.columns || []).forEach(function (c, i) {
                    var td = el('td', 'is-' + c.align);
                    var v = typeof r[c.key] === 'number' ? fmtNumber(r[c.key]) : r[c.key];
                    if (i === 0 && r._href) td.appendChild(link(r._href, 'bh-dtable__link', v)); else td.textContent = v === undefined ? '' : String(v);
                    row.appendChild(td);
                });
                tb.appendChild(row);
            });
            t.appendChild(tb);
            scroll.appendChild(t);
            return scroll;
        },
        chart: function (d) {
            var wrap = el('div', 'bh-dview bh-dview--chart');
            var series = d.series || [];
            var labels = d.labels || [];
            var max = 0;
            series.forEach(function (s) { s.values.forEach(function (v) { if (v > max) max = v; }); });
            max = max || 1;
            var unit = d.unit ? ' ' + d.unit : '';
            var desc = series.map(function (s) {
                return s.name + ': ' + s.values.map(function (v, i) { return labels[i] + ' ' + fmtNumber(v) + unit; }).join(', ');
            }).join('. ');
            var plot = el('div', 'bh-dchart bh-dchart--' + d.type);
            plot.setAttribute('role', 'img');
            plot.setAttribute('aria-label', desc);
            var axis = el('div', 'bh-dchart__axis');
            axis.appendChild(el('span', '', fmtNumber(max)));
            axis.appendChild(el('span', '', '0'));
            plot.appendChild(axis);
            var area = el('div', 'bh-dchart__area');
            if (d.type === 'line') {
                var svgNS = 'http://www.w3.org/2000/svg';
                var svg = document.createElementNS(svgNS, 'svg');
                svg.setAttribute('viewBox', '0 0 100 100');
                svg.setAttribute('preserveAspectRatio', 'none');
                svg.setAttribute('class', 'bh-dchart__svg');
                series.forEach(function (s, si) {
                    var n = Math.max(1, s.values.length - 1);
                    var pts = s.values.map(function (v, i) { return (i / n * 100).toFixed(2) + ',' + (100 - v / max * 96).toFixed(2); }).join(' ');
                    var pl = document.createElementNS(svgNS, 'polyline');
                    pl.setAttribute('points', pts);
                    pl.setAttribute('class', 'bh-dchart__line bh-dchart__s' + si);
                    pl.setAttribute('vector-effect', 'non-scaling-stroke');
                    svg.appendChild(pl);
                });
                area.appendChild(svg);
            } else {
                labels.forEach(function (lab, i) {
                    var col = el('div', 'bh-dchart__col');
                    series.forEach(function (s, si) {
                        var v = s.values[i] || 0;
                        var bar = el('span', 'bh-dchart__bar bh-dchart__s' + si);
                        bar.style.height = (v / max * 100) + '%';
                        bar.title = lab + ': ' + fmtNumber(v) + unit;
                        if (v > 0) bar.appendChild(el('span', 'bh-dchart__val', fmtNumber(v)));
                        col.appendChild(bar);
                    });
                    area.appendChild(col);
                });
            }
            plot.appendChild(area);
            wrap.appendChild(plot);
            var xl = el('div', 'bh-dchart__labels');
            labels.forEach(function (lab, i) {
                var span = el('span', '', lab);
                // Thin the labels when there are many, keeping first and last.
                if (labels.length > 8 && i % 2 === 1 && i !== labels.length - 1) span.classList.add('is-thin');
                xl.appendChild(span);
            });
            wrap.appendChild(xl);
            if (series.length > 1) {
                var legend = el('div', 'bh-dchart__legend');
                series.forEach(function (s, si) {
                    var k = el('span', 'bh-dchart__key');
                    k.appendChild(el('span', 'bh-dchart__swatch bh-dchart__s' + si));
                    k.appendChild(document.createTextNode(s.name));
                    legend.appendChild(k);
                });
                wrap.appendChild(legend);
            }
            return wrap;
        },
        feed: function (d) {
            var ol = el('ol', 'bh-dview bh-dview--feed');
            (d.items || []).forEach(function (it) {
                var li = el('li', 'bh-dfeed__row bh-dfeed__row--' + (it.tone || 'slate'));
                var line = el('p', 'bh-dfeed__line');
                if (it.actor) line.appendChild(el('strong', '', it.actor + ' '));
                line.appendChild(it.href ? link(it.href, 'bh-dfeed__text', it.text) : el('span', 'bh-dfeed__text', it.text));
                li.appendChild(line);
                if (it.quote) li.appendChild(el('p', 'bh-dfeed__quote', it.quote));
                if (it.time) {
                    var tm = el('time', 'bh-dfeed__time', ago(it.time));
                    tm.title = fullTime(it.time);
                    tm.setAttribute('datetime', String(it.time).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(it.time) ? '' : 'Z'));
                    li.appendChild(tm);
                }
                ol.appendChild(li);
            });
            return ol;
        },
        status: function (d) {
            var wrap = el('div', 'bh-dview bh-dview--status');
            if (d.summary) wrap.appendChild(el('p', 'bh-dstatus__summary', d.summary));
            var ul = el('ul', 'bh-dstatus');
            var names = { ok: ['check-circle', 'OK'], warn: ['exclamation-triangle', 'Warning'], error: ['x-circle', 'Problem'], unknown: ['question-mark-circle', 'Unknown'] };
            (d.items || []).forEach(function (it) {
                var li = el('li', 'bh-dstatus__row is-' + it.state);
                var ic = icon(names[it.state][0]);
                li.appendChild(ic);
                var txt = el('div', 'bh-dstatus__text');
                var lab = it.href ? link(it.href, 'bh-dstatus__label', it.label) : el('span', 'bh-dstatus__label', it.label);
                txt.appendChild(lab);
                if (it.detail) txt.appendChild(el('span', 'bh-dstatus__detail', it.detail));
                li.appendChild(txt);
                li.appendChild(el('span', 'bh-dash-sr', names[it.state][1]));
                ul.appendChild(li);
            });
            wrap.appendChild(ul);
            if (d.footer) wrap.appendChild(footer({ footer: d.footer }));
            return wrap;
        },
        html: function (d, res) {
            var box = el('div', 'bh-dview bh-dview--html');
            if (res && res.fromEndpoint) {
                // An endpoint's HTML has not been through the server's sanitizer.
                box.textContent = 'This widget returned HTML from its own endpoint, which the dashboard does not display.';
                return box;
            }
            // Sanitized by the server (HtmlSanitizer), or a legacy widget's own output.
            box.innerHTML = String(d.html || '');
            // A widget written before 1.2.68 renders HTML with its own inline
            // <script> that loads and draws its data (CloudHim's Analytics card
            // does). Scripts set through innerHTML never run, so on Refresh or
            // Expand such a card sat at its own "Loading…" for good. Re-create
            // them so they run when the card is put on the page, as they did
            // on the first load. Only for 'trusted' HTML — a widget's own
            // render output; sanitized HTML has no scripts to run.
            if (d.trusted) activateScripts(box);
            return box;
        }
    };

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    function needsData(item) {
        var w = widgetOf(item);
        if (!w || !item.visible || item.collapsed) return false;
        if (String(item.id).startsWith('new-')) return false;
        var r = data[item.id];
        return !r || r.stale;
    }

    function load(ids, force) {
        ids = ids.filter(function (id) { return !inflight[id]; });
        if (!ids.length) return Promise.resolve();
        ids.forEach(function (id) {
            inflight[id] = true;
            // A card with content keeps it while it refreshes; others show the skeleton.
            var had = !!(data[id] && data[id].ok && !data[id].legacy);
            if (!had) data[id] = { pending: true };
            var item = findItem(id), card = cardEl(id);
            if (item && card) {
                card.classList.add('is-refreshing');
                if (!had) paintBody(card, item);
            }
        });
        // Batches of at most 30, the server's limit.
        var chunks = [];
        for (var i = 0; i < ids.length; i += 30) chunks.push(ids.slice(i, i + 30));
        return Promise.all(chunks.map(function (chunk) {
            return ajax.request('widgets.dashboard_data', { ids: chunk.join(','), force: force ? '1' : '0' }, { method: 'GET', timeout: 45000 })
                .then(function (res) {
                    var results = (res && res.widgets) || {};
                    return Promise.all(chunk.map(function (id) {
                        var r = results[id] || { ok: false, state: 'error', message: 'No answer for this widget.' };
                        if (r.ok && r.mode === 'endpoint') return loadEndpoint(id, r);
                        data[id] = r;
                        return null;
                    }));
                })
                .catch(function (err) {
                    chunk.forEach(function (id) {
                        data[id] = { ok: false, state: 'error', message: (err && err.status === 429)
                            ? 'Too many refreshes. Wait a moment and try again.'
                            : ((err && err.message) || "Couldn't reach the server.") };
                    });
                })
                .then(function () {
                    chunk.forEach(function (id) {
                        delete inflight[id];
                        var item = findItem(id), card = cardEl(id);
                        if (item && card) { card.classList.remove('is-refreshing'); paintBody(card, item); }
                    });
                });
        }));
    }

    // A widget whose data comes from its app's own AJAX action.
    function loadEndpoint(id, r) {
        return ajax.request(r.endpoint, { instance: id, settings: JSON.stringify(r.settings || {}) }, { method: 'GET' })
            .then(function (payload) {
                payload = payload || {};
                var view = payload.view;
                if (!VIEWS[view]) throw new Error('The widget returned no view the dashboard knows.');
                var items = payload.items || payload.rows || payload.series || null;
                data[id] = { ok: true, view: view, data: payload, fromEndpoint: true,
                             empty: payload.empty === true || (Array.isArray(items) && items.length === 0) };
            })
            .catch(function (err) {
                data[id] = { ok: false, state: err && err.code === 'UNAVAILABLE' ? 'unavailable' : 'error',
                             message: (err && err.message) || "Couldn't load this widget." };
            });
    }

    function loadVisible() {
        load(visibleItems().filter(needsData).map(function (i) { return i.id; }), false);
    }

    function refresh(id, manual) {
        if (inflight[id]) return;
        var now = Date.now();
        if (manual && lastForced[id] && now - lastForced[id] < REFRESH_COOLDOWN) {
            announce('Just refreshed. Wait a few seconds before refreshing again.');
            return;
        }
        if (manual) lastForced[id] = now;
        if (legacyNodes[id]) { delete legacyNodes[id]; delete data[id]; }  // fresh HTML from the server now
        load([id], !!manual).then(function () {
            var item = findItem(id), w = item && widgetOf(item);
            if (manual && w) announce(w.title + ' refreshed.');
        });
    }

    // Auto-refresh: only cards that ask for it, only while the tab is visible
    // and nobody is customizing, batched into one request per tick.
    var lastAuto = {};
    setInterval(function () {
        if (document.hidden || state.editing) return;
        var due = [];
        var now = Date.now();
        visibleItems().forEach(function (item) {
            var w = widgetOf(item);
            if (!w.refresh || item.collapsed || inflight[item.id] || legacyNodes[item.id]) return;
            var last = lastAuto[item.id] || window.performance.timing.navigationStart || now;
            if (now - last >= w.refresh * 1000) { due.push(item.id); lastAuto[item.id] = now; }
        });
        if (due.length) load(due, false).catch(function () {});
    }, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !state.editing) loadVisible(); });

    // ------------------------------------------------------------------
    // View-mode changes (saved at once)
    // ------------------------------------------------------------------

    function toggleCollapse(id) {
        var item = findItem(id);
        if (!item) return;
        var prev = item.collapsed;
        item.collapsed = !prev;
        render();
        if (!item.collapsed) loadVisible();
        ajax.request('widgets.dashboard_update', { id: id, collapsed: item.collapsed })
            .then(function () { state.customized = true; })
            .catch(function (err) {
                item.collapsed = prev;
                render();
                toast(err.message || 'That change was not saved.', 'error');
            });
    }

    function removeNow(id) {
        var item = findItem(id), w = item && widgetOf(item);
        if (!item) return;
        item.visible = false;
        render();
        ajax.request('widgets.dashboard_update', { id: id, visible: false })
            .then(function () {
                state.customized = true;
                if (state.catalog[item.widget]) state.catalog[item.widget].placed = 0;
                toast(w.title + ' removed. You can add it back from Customize.');
            })
            .catch(function (err) {
                item.visible = true;
                render();
                toast(err.message || 'The widget was not removed.', 'error');
            });
    }

    function runAction(id, action, btn) {
        var go = action.confirm ? confirmAsk(action.confirm, { danger: !!action.danger, confirmLabel: action.label }) : Promise.resolve(true);
        go.then(function (yes) {
            if (!yes) return;
            ajax.request('widgets.dashboard_action', { id: id, action: action.id })
                .then(function (res) {
                    toast(res.message || 'Done.');
                    if (res.refresh) refresh(id, false);
                })
                .catch(function (err) { toast(err.message || 'That action did not work.', 'error'); });
        });
    }

    // ------------------------------------------------------------------
    // Settings dialog
    // ------------------------------------------------------------------

    var dialog = document.querySelector('[data-bh-dash-settings]');
    var settingsFor = null;

    function openSettings(id) {
        var item = findItem(id), w = item && widgetOf(item);
        if (!item || !w || !w.settings || !dialog) return;
        settingsFor = id;
        dialog.querySelector('[data-bh-dash-settings-title]').textContent = w.title + ' settings';
        var box = dialog.querySelector('[data-bh-dash-settings-fields]');
        box.textContent = '';
        (w.fields || []).forEach(function (f) {
            var wrap = el('div', 'bh-dfield');
            var fid = 'bh-dset-' + f.key;
            var value = item.settings ? item.settings[f.key] : undefined;
            var input;
            if (f.type === 'checkbox') {
                wrap.classList.add('bh-dfield--check');
                input = el('input');
                input.type = 'checkbox';
                input.checked = !!value;
                input.id = fid;
                var lab = el('label', 'bh-dfield__check');
                lab.setAttribute('for', fid);
                lab.appendChild(input);
                lab.appendChild(el('span', '', f.label));
                wrap.appendChild(lab);
            } else {
                var label = el('label', 'bh-label', f.label);
                label.setAttribute('for', fid);
                wrap.appendChild(label);
                if (f.type === 'select') {
                    input = el('select', 'bh-select');
                    Object.keys(f.options || {}).forEach(function (k) {
                        var o = el('option', '', f.options[k]);
                        o.value = k;
                        if (String(value) === k) o.selected = true;
                        input.appendChild(o);
                    });
                } else if (f.type === 'textarea') {
                    input = el('textarea', 'bh-textarea');
                    input.value = value === undefined || value === null ? '' : String(value);
                } else {
                    input = el('input', 'bh-input');
                    input.type = f.type === 'number' ? 'number' : (f.type === 'url' ? 'url' : (f.type === 'color' ? 'color' : 'text'));
                    if (f.min !== undefined) input.min = f.min;
                    if (f.max !== undefined) input.max = f.max;
                    if (f.step !== undefined) input.step = f.step;
                    input.value = value === undefined || value === null ? '' : String(value);
                }
                input.id = fid;
                wrap.appendChild(input);
            }
            input.name = f.key;
            input.dataset.type = f.type;
            if (f.help) wrap.appendChild(el('p', 'bh-help', f.help));
            var err = el('p', 'bh-dfield__error');
            err.hidden = true;
            err.id = fid + '-err';
            wrap.appendChild(err);
            box.appendChild(wrap);
        });
        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
        var first = box.querySelector('input,select,textarea');
        if (first) first.focus();
    }

    function closeSettings() {
        settingsFor = null;
        if (dialog.open && typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }

    if (dialog) {
        dialog.querySelectorAll('[data-bh-dash-settings-cancel]').forEach(function (b) { b.addEventListener('click', closeSettings); });
        dialog.querySelector('[data-bh-dash-settings-form]').addEventListener('submit', function (e) {
            e.preventDefault();
            var id = settingsFor, item = findItem(id);
            if (!item) return closeSettings();
            var values = {};
            dialog.querySelectorAll('[data-bh-dash-settings-fields] [name]').forEach(function (inp) {
                var t = inp.dataset.type;
                values[inp.name] = t === 'checkbox' ? inp.checked : (t === 'number' ? (inp.value === '' ? '' : Number(inp.value)) : inp.value);
                inp.removeAttribute('aria-invalid');
                var er = document.getElementById(inp.id + '-err');
                if (er) { er.hidden = true; er.textContent = ''; }
            });
            var btn = dialog.querySelector('[data-bh-dash-settings-save]');
            busy(btn, true, 'Saving…');
            ajax.request('widgets.dashboard_update', { id: id, settings: JSON.stringify(values) })
                .then(function (res) {
                    item.settings = (res.item && res.item.settings) || values;
                    state.customized = true;
                    closeSettings();
                    toast('Settings saved.');
                    refresh(id, false);
                })
                .catch(function (err) {
                    var fields = (err && err.fields) || {};
                    var any = false;
                    Object.keys(fields).forEach(function (k) {
                        var inp = dialog.querySelector('[name="' + cssEsc(k) + '"]');
                        var er = inp && document.getElementById(inp.id + '-err');
                        if (inp && er) {
                            inp.setAttribute('aria-invalid', 'true');
                            inp.setAttribute('aria-describedby', er.id);
                            er.textContent = fields[k].join(' ');
                            er.hidden = false;
                            if (!any) inp.focus();
                            any = true;
                        }
                    });
                    if (!any) toast(err.message || 'The settings were not saved.', 'error');
                })
                .then(function () { busy(btn, false); });
        });
    }

    // ------------------------------------------------------------------
    // Customize mode (staged)
    // ------------------------------------------------------------------

    function enterEdit() {
        if (state.editing) return;
        closeMenus();
        state.staged = clone(state.items);
        state.editing = true;
        state.dirty = false;
        render();
        announce('Customize mode. Move, resize, add or remove widgets, then save your changes.');
        var first = grid.querySelector('.bh-dcard__handle') || root.querySelector('[data-bh-dash-add]');
        if (first) first.focus();
    }

    function leaveEdit(restore) {
        if (restore && state.staged) state.items = state.staged;
        state.staged = null;
        state.editing = false;
        state.dirty = false;
        closeDrawer();
        render();
        loadVisible();
    }

    function stagedRemove(id) {
        var item = findItem(id), w = item && widgetOf(item);
        if (!item) return;
        if (String(id).startsWith('new-')) {
            state.items = state.items.filter(function (i) { return i.id !== id; });
        } else {
            item.visible = false;
        }
        markDirty();
        render();
        announce(w.title + ' removed. Save to keep this change.');
    }

    function moveBy(id, delta) {
        var vis = visibleItems();
        var from = vis.findIndex(function (i) { return i.id === id; });
        var to = from + delta;
        if (from < 0 || to < 0 || to >= vis.length) return;
        var moved = vis.splice(from, 1)[0];
        vis.splice(to, 0, moved);
        applyOrder(vis.map(function (i) { return i.id; }));
        render();
        var card = cardEl(id);
        var btn = card && card.querySelector(delta < 0 ? '.bh-dcard__move--up' : '.bh-dcard__move--down');
        if (btn && btn.disabled) btn = card.querySelector('.bh-dcard__handle');
        if (btn) btn.focus();
        announce(widgetOf(moved).title + ' moved to position ' + (to + 1) + ' of ' + vis.length + '.');
    }

    function keyMove(e, id) {
        var map = { ArrowUp: -1, ArrowLeft: -1, ArrowDown: 1, ArrowRight: 1 };
        if (!(e.key in map)) return;
        e.preventDefault();
        moveBy(id, map[e.key]);
        var h = cardEl(id) && cardEl(id).querySelector('.bh-dcard__handle');
        if (h) h.focus();
    }

    // The visible cards in this order; hidden ones keep their place after them.
    function applyOrder(ids) {
        var byId = {};
        state.items.forEach(function (i) { byId[i.id] = i; });
        var next = ids.map(function (id) { return byId[id]; }).filter(Boolean);
        state.items.forEach(function (i) { if (ids.indexOf(i.id) === -1) next.push(i); });
        state.items = next;
        markDirty();
    }

    function save() {
        var btn = root.querySelector('[data-bh-dash-save]');
        if (!state.dirty) { leaveEdit(false); return Promise.resolve(true); }
        var layout = state.items.filter(function (i) { return widgetOf(i); }).map(function (i) {
            return { id: i.id, widget: i.widget, size: i.size, visible: !!i.visible, collapsed: !!i.collapsed };
        });
        busy(btn, true, 'Saving…');
        return ajax.request('widgets.dashboard_save', { layout: JSON.stringify(layout) }, { idempotencyKey: true })
            .then(function (res) {
                applyState(res);
                state.staged = null;
                state.editing = false;
                state.dirty = false;
                closeDrawer();
                render();
                loadVisible();
                toast('Dashboard saved.');
                return true;
            })
            .catch(function (err) {
                toast(err.message || 'Your dashboard was not saved.', 'error');
                return false;
            })
            .then(function (ok) { busy(btn, false); return ok; });
    }

    function applyState(res) {
        if (!res || !res.layout) return;
        state.items = res.layout.items || [];
        state.customized = !!res.layout.customized;
        state.catalog = indexCatalog(res.catalog || []);
        state.hasSiteDefault = !!res.has_site_default;
        // Data for cards that kept their id is still good; new ids load fresh.
        var keep = {};
        state.items.forEach(function (i) { keep[i.id] = true; });
        Object.keys(data).forEach(function (id) { if (!keep[id]) delete data[id]; });
    }

    function resetLayout() {
        confirmAsk('Reset your dashboard to the site default? Your layout and widget settings will be replaced.', { danger: true, confirmLabel: 'Reset' })
            .then(function (yes) {
                if (!yes) return;
                var btn = root.querySelector('[data-bh-dash-reset]');
                busy(btn, true, 'Resetting…');
                ajax.request('widgets.dashboard_reset', {})
                    .then(function (res) {
                        data = {};
                        Object.keys(legacyNodes).forEach(function (id) { delete legacyNodes[id]; });
                        applyState(res);
                        state.staged = null;
                        state.editing = false;
                        state.dirty = false;
                        closeDrawer();
                        render();
                        loadVisible();
                        toast('Dashboard reset to the default.');
                    })
                    .catch(function (err) { toast(err.message || 'The dashboard was not reset.', 'error'); })
                    .then(function () { busy(btn, false); });
            });
    }

    function useAsSiteDefault() {
        confirmAsk('Make this layout the default for everyone who has not customized their dashboard? Each person still sees only the widgets they have access to.', { confirmLabel: 'Use as site default' })
            .then(function (yes) {
                if (!yes) return;
                // The server copies the saved layout, so save first.
                (state.dirty ? save() : Promise.resolve(true)).then(function (ok) {
                    if (!ok) return;
                    ajax.request('widgets.dashboard_default', {})
                        .then(function (res) {
                            state.hasSiteDefault = !!res.has_site_default;
                            if (state.editing) leaveEdit(false);
                            toast('Saved as the site default.');
                        })
                        .catch(function (err) { toast(err.message || 'The site default was not saved.', 'error'); });
                });
            });
    }

    // ------------------------------------------------------------------
    // Drag and drop (pointer events: mouse, pen and touch)
    // ------------------------------------------------------------------

    var drag = null;

    function startDrag(e) {
        if (e.button !== undefined && e.button !== 0) return;
        var handle = e.currentTarget;
        var card = handle.closest('.bh-dcard');
        if (!card) return;
        e.preventDefault();
        var rect = card.getBoundingClientRect();
        var ph = el('div', card.className.replace(/\bbh-dcard\b/, 'bh-dcard bh-dcard--placeholder'));
        ph.style.height = rect.height + 'px';
        card.parentNode.insertBefore(ph, card.nextSibling);
        drag = { card: card, ph: ph, dx: e.clientX - rect.left, dy: e.clientY - rect.top, id: card.dataset.id, handle: handle, pid: e.pointerId };
        card.classList.add('is-dragging');
        card.style.width = rect.width + 'px';
        card.style.height = rect.height + 'px';
        card.style.left = rect.left + 'px';
        card.style.top = rect.top + 'px';
        try { handle.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
        handle.addEventListener('pointermove', onDrag);
        handle.addEventListener('pointerup', endDrag);
        handle.addEventListener('pointercancel', endDrag);
    }

    function onDrag(e) {
        if (!drag) return;
        drag.card.style.left = (e.clientX - drag.dx) + 'px';
        drag.card.style.top = (e.clientY - drag.dy) + 'px';
        // Scroll near the edges so a long dashboard can be crossed. The top
        // edge is the bottom of the sticky customize bar, which covers cards.
        var bar = root.querySelector('[data-bh-dash-editbar]');
        var top = bar ? Math.max(0, bar.getBoundingClientRect().bottom) : 0;
        var edge = 56;
        if (e.clientY < top + edge) window.scrollBy(0, -14); else if (e.clientY > window.innerHeight - edge) window.scrollBy(0, 14);

        var under = document.elementFromPoint(e.clientX, e.clientY);
        var target = under && under.closest ? under.closest('.bh-dcard:not(.is-dragging):not(.bh-dcard--placeholder)') : null;
        if (!target || target.parentNode !== grid) return;
        var r = target.getBoundingClientRect();
        // Above-left of the diagonal → before; below-right → after.
        var before = (e.clientX - r.left) / r.width + (e.clientY - r.top) / r.height < 1;
        var ref = before ? target : target.nextSibling;
        if (ref !== drag.ph) grid.insertBefore(drag.ph, ref);
    }

    function endDrag() {
        if (!drag) return;
        var d = drag;
        drag = null;
        d.handle.removeEventListener('pointermove', onDrag);
        d.handle.removeEventListener('pointerup', endDrag);
        d.handle.removeEventListener('pointercancel', endDrag);
        try { d.handle.releasePointerCapture(d.pid); } catch (x) { /* ignore */ }
        grid.insertBefore(d.card, d.ph);
        grid.removeChild(d.ph);
        d.card.classList.remove('is-dragging');
        d.card.style.cssText = '';
        var ids = Array.prototype.map.call(grid.querySelectorAll('.bh-dcard[data-id]'), function (c) { return c.dataset.id; });
        var before = visibleItems().map(function (i) { return i.id; }).join(',');
        if (ids.join(',') !== before) {
            applyOrder(ids);
            render();
            var pos = ids.indexOf(d.id);
            var item = findItem(d.id);
            announce(widgetOf(item).title + ' moved to position ' + (pos + 1) + ' of ' + ids.length + '.');
            var h = cardEl(d.id) && cardEl(d.id).querySelector('.bh-dcard__handle');
            if (h) h.focus();
        }
    }

    // ------------------------------------------------------------------
    // Add widgets drawer
    // ------------------------------------------------------------------

    var drawer = document.querySelector('[data-bh-dash-drawer]');
    var drawerReturn = null;

    function isDrawerOpen() { return drawer && !drawer.hidden; }

    function openDrawer() {
        if (!state.editing) enterEdit();
        drawerReturn = document.activeElement;
        drawer.hidden = false;
        document.documentElement.classList.add('bh-dash-noscroll');
        renderCatalog();
        var s = drawer.querySelector('[data-bh-dash-search]');
        if (s) { s.value = ''; s.focus(); }
    }

    function closeDrawer() {
        if (!drawer || drawer.hidden) return;
        drawer.hidden = true;
        document.documentElement.classList.remove('bh-dash-noscroll');
        if (drawerReturn && drawerReturn.focus && document.contains(drawerReturn)) drawerReturn.focus();
    }

    function renderCatalog() {
        var box = drawer.querySelector('[data-bh-dash-catalog]');
        var q = (drawer.querySelector('[data-bh-dash-search]').value || '').trim().toLowerCase();
        box.textContent = '';
        var groups = {};
        var order = [];
        Object.keys(state.catalog).forEach(function (key) {
            var w = state.catalog[key];
            if (q && (w.title + ' ' + w.description + ' ' + w.owner_label).toLowerCase().indexOf(q) === -1) return;
            var g = w.owner === 'core' ? '' : w.owner_label;
            if (!groups[g]) { groups[g] = []; order.push(g); }
            groups[g].push(w);
        });
        order.sort(function (a, b) { return a === '' ? -1 : b === '' ? 1 : a.localeCompare(b); });
        if (!order.length) {
            box.appendChild(stateBlock('magnifying-glass', q ? 'No widgets match' : 'No widgets to add', q ? 'Try a different word.' : 'Apps you install can add widgets here.', 'muted'));
            return;
        }
        order.forEach(function (g) {
            var sec = el('section', 'bh-dcat');
            sec.appendChild(el('h3', 'bh-dcat__title', g === '' ? 'Basehim' : g));
            var ul = el('ul', 'bh-dcat__list');
            groups[g].forEach(function (w) { ul.appendChild(catalogEntry(w)); });
            sec.appendChild(ul);
            box.appendChild(sec);
        });
    }

    function catalogEntry(w) {
        var li = el('li', 'bh-dcat__item');
        li.dataset.key = w.key;
        var ic = icon(w.icon);
        ic.classList.add('bh-dcat__icon');
        li.appendChild(ic);
        var text = el('div', 'bh-dcat__text');
        var name = el('p', 'bh-dcat__name', w.title);
        if (w.new) name.appendChild(el('span', 'bh-dtone bh-dtone--blue', 'New'));
        text.appendChild(name);
        if (w.description) text.appendChild(el('p', 'bh-dcat__desc', w.description));
        var facts = [];
        facts.push(w.sizes.length > 1 ? w.sizes.length + ' sizes' : 'One size');
        if (w.settings) facts.push('Has settings');
        if (w.refresh) facts.push('Updates every ' + Math.round(w.refresh / 60) + ' min');
        text.appendChild(el('p', 'bh-dcat__facts', facts.join(' · ')));
        li.appendChild(text);

        var placed = state.items.filter(function (i) { return i.widget === w.key && i.visible; }).length;
        var hidden = state.items.filter(function (i) { return i.widget === w.key && !i.visible; });
        var btn = el('button', 'bh-btn bh-btn--sm ' + (placed && !w.multiple ? 'bh-btn--ghost' : 'bh-btn--secondary'));
        btn.type = 'button';
        if (placed && !w.multiple) {
            btn.disabled = true;
            btn.appendChild(icon('check'));
            btn.appendChild(document.createTextNode('Added'));
        } else if (hidden.length && !placed) {
            btn.appendChild(icon('arrow-uturn-left'));
            btn.appendChild(document.createTextNode('Restore'));
            btn.setAttribute('aria-label', 'Restore ' + w.title);
            btn.addEventListener('click', function () { addWidget(w, hidden[0].id); });
        } else {
            btn.appendChild(icon('plus'));
            btn.appendChild(document.createTextNode(placed ? 'Add another' : 'Add'));
            btn.setAttribute('aria-label', 'Add ' + w.title);
            btn.addEventListener('click', function () { addWidget(w, null); });
        }
        li.appendChild(btn);
        return li;
    }

    function focusCatalog(key) {
        var b = drawer.querySelector('[data-key="' + cssEsc(key) + '"] .bh-btn');
        if (b) b.focus();
        else { var s = drawer.querySelector('[data-bh-dash-search]'); if (s) s.focus(); }
    }

    function addWidget(w, restoreId) {
        var item;
        if (restoreId) {
            item = findItem(restoreId);
            item.visible = true;
            // To the end of the visible cards.
            var vis = visibleItems().filter(function (i) { return i.id !== restoreId; }).map(function (i) { return i.id; });
            vis.push(restoreId);
            applyOrder(vis);
        } else {
            item = { id: tempId(), widget: w.key, size: w.size, visible: true, collapsed: false, settings: {} };
            var ids = visibleItems().map(function (i) { return i.id; });
            state.items.push(item);
            ids.push(item.id);
            applyOrder(ids);
        }
        markDirty();
        render();
        announce(w.title + (restoreId ? ' restored' : ' added') + ' at the end of your dashboard. Save to keep it.');
        var card = cardEl(item.id);
        if (card) {
            card.classList.add('is-new');
            setTimeout(function () { card.classList.remove('is-new'); }, 1600);
            if (window.matchMedia('(max-width: 639px)').matches) closeDrawer(); else if (isDrawerOpen()) focusCatalog(w.key);
            card.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
        if (restoreId && data[restoreId] === undefined) { /* loads after save */ }
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    root.querySelector('[data-bh-dash-customize]').addEventListener('click', enterEdit);
    root.querySelector('[data-bh-dash-add]').addEventListener('click', openDrawer);
    root.querySelector('[data-bh-dash-add-blank]').addEventListener('click', openDrawer);
    root.querySelector('[data-bh-dash-cancel]').addEventListener('click', function () {
        if (!state.dirty) return leaveEdit(true);
        confirmAsk('Discard your changes to the dashboard?', { danger: true, confirmLabel: 'Discard changes', cancelLabel: 'Keep editing' })
            .then(function (yes) { if (yes) leaveEdit(true); });
    });
    root.querySelector('[data-bh-dash-save]').addEventListener('click', save);
    root.querySelector('[data-bh-dash-reset]').addEventListener('click', resetLayout);
    var sd = root.querySelector('[data-bh-dash-sitedefault]');
    if (sd) sd.addEventListener('click', useAsSiteDefault);
    if (drawer) {
        drawer.querySelectorAll('[data-bh-dash-drawer-close]').forEach(function (b) { b.addEventListener('click', closeDrawer); });
        drawer.querySelector('[data-bh-dash-search]').addEventListener('input', renderCatalog);
        // On the document: a button the user pressed may have been redrawn,
        // leaving focus on <body>, and Escape must still close the panel.
        document.addEventListener('keydown', function (e) {
            if (!isDrawerOpen()) return;
            if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); return; }
            if (e.key !== 'Tab') return;
            // Keep focus inside the panel while it is open.
            var f = drawer.querySelectorAll('button:not([disabled]), input, a[href]');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    }
    window.addEventListener('beforeunload', function (e) {
        if (state.editing && state.dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    render();
    loadVisible();

    // A small handle for app scripts and tests: read-only state and refresh.
    window.Basehim.dashboard = {
        refresh: function (id) { refresh(id, false); },
        items: function () { return clone(state.items); },
        editing: function () { return state.editing; }
    };
})(window, document);
