/*
 * Admin header (1.2.121): content search and notifications.
 *
 *  - Search: [data-bh-search-open] or Ctrl/⌘ K opens <dialog id="bh-cmdk">.
 *    Results come from admin.bar_search (the same providers as the front-end
 *    admin bar, filtered for this user), grouped, two characters at least.
 *    Up/Down move through them, Enter opens one, Esc closes.
 *  - Notifications: the bell's count is loaded once the page is idle; the
 *    panel reloads when it is opened (at most once every 30 s). The items are
 *    live conditions (updates, comments waiting, failing tasks), so they go
 *    away when the condition does; there is nothing to mark as read.
 *
 * Both calls send the header's signed descriptor (data-ctx), which the
 * server accepts only for these two actions. Text is set with textContent.
 */
(function () {
    'use strict';

    var top = document.querySelector('[data-bh-top]');
    if (!top || !window.Basehim || !window.Basehim.ajax) return;
    var ctx = top.getAttribute('data-ctx') || '';
    var base = (document.body.getAttribute('data-base') || '').replace(/\/+$/, '');
    var I = function (name, cls) { return window.BasehimIcon ? window.BasehimIcon(name, cls || 'w-4 h-4') : ''; };

    function href(u) {
        u = String(u || '');
        if (u.charAt(0) === '/' && u.charAt(1) !== '/' && base && u.indexOf(base + '/') !== 0 && u !== base) return base + u;
        return u;
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function iconEl(tag, cls, name) {
        var n = el(tag, cls);
        n.innerHTML = I(name);
        n.setAttribute('aria-hidden', 'true');
        return n;
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------
    var dlg = document.getElementById('bh-cmdk');
    var input = dlg && dlg.querySelector('[data-bh-search-input]');
    var list = dlg && dlg.querySelector('[data-bh-search-list]');
    var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    document.querySelectorAll('[data-bh-kbd]').forEach(function (k) { k.textContent = mac ? '⌘K' : 'Ctrl K'; });

    var HINT = 'Type at least 2 characters to search the content you can edit.';
    var timer = 0, seq = 0, controller = null, lastQ = null, items = [], active = -1;

    function state(text, cls) {
        list.textContent = '';
        list.appendChild(el('p', cls || 'bh-cmdk__state', text));
        items = []; active = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }
    function setActive(i) {
        if (!items.length) return;
        if (i < 0) i = items.length - 1;
        if (i >= items.length) i = 0;
        if (active >= 0 && items[active]) items[active].setAttribute('aria-selected', 'false');
        active = i;
        items[i].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', items[i].id);
        items[i].scrollIntoView({ block: 'nearest' });
    }
    function render(groups, q) {
        list.textContent = '';
        items = []; active = -1;
        if (!groups.length) { state('No results for “' + q + '”. Try another word, or part of a title.'); return; }
        groups.forEach(function (g, gi) {
            var head = el('p', 'bh-cmdk__group', g.label);
            head.id = 'bh-cmdk-g' + gi;
            head.setAttribute('role', 'presentation');
            list.appendChild(head);
            (g.items || []).forEach(function (it) {
                var a = el('a', 'bh-cmdk__item');
                a.href = href(it.url);
                a.id = 'bh-cmdk-o' + items.length;
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');
                a.setAttribute('aria-describedby', head.id);
                a.tabIndex = -1;
                a.appendChild(iconEl('span', 'bh-cmdk__icon', it.icon || 'document-text'));
                var t = el('span', 'bh-cmdk__text');
                t.appendChild(el('span', 'bh-cmdk__title', it.title));
                if (it.meta) t.appendChild(el('span', 'bh-cmdk__meta', it.meta));
                a.appendChild(t);
                var go = iconEl('span', '', 'arrow-turn-down-left');
                go.className = 'bh-cmdk__go';
                a.appendChild(go);
                var idx = items.length;
                a.addEventListener('mousemove', function () { if (active !== idx) setActive(idx); });
                items.push(a);
                list.appendChild(a);
            });
        });
        input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
        if (items.length) setActive(0);
    }
    function run() {
        var q = input.value.trim();
        if (q === lastQ) return;
        lastQ = q;
        if (controller) controller.abort();
        controller = null;
        if (q.length < 2) { list.removeAttribute('aria-busy'); state(HINT); return; }
        var mine = ++seq;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        list.setAttribute('aria-busy', 'true');
        if (!items.length) state('Searching…');
        window.Basehim.ajax.request('admin.bar_search', { ctx: ctx, q: q }, { method: 'GET', signal: controller ? controller.signal : undefined })
            .then(function (d) {
                if (mine !== seq) return;
                list.removeAttribute('aria-busy');
                render((d && d.groups) || [], q);
            })
            .catch(function (err) {
                if (mine !== seq || (err && err.code === 'ABORTED')) return;
                list.removeAttribute('aria-busy');
                lastQ = null; // let the same words be tried again
                state((err && err.message) || 'Search is not available right now. Try again.', 'bh-cmdk__state bh-cmdk__state--error');
            });
    }
    function openSearch() {
        if (!dlg || typeof dlg.showModal !== 'function') return;
        document.body.click(); // closes any open header menu (the layout listens on document)
        if (!dlg.open) dlg.showModal();
        input.focus();
        input.select();
    }

    if (dlg && input && list) {
        document.addEventListener('click', function (e) {
            var o = e.target.closest && e.target.closest('[data-bh-search-open]');
            if (!o) return;
            e.preventDefault();
            openSearch();
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey) {
                // Leave Ctrl/⌘ K to a page's own dialog or rich-text editor that is in use.
                var other = document.querySelector('dialog[open]');
                if (other && other !== dlg) return;
                e.preventDefault();
                if (dlg.open) { input.focus(); input.select(); } else openSearch();
            }
        });
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 180);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                if (input.value.trim() !== lastQ) { run(); return; }
                if (active >= 0 && items[active]) {
                    if (e.metaKey || e.ctrlKey) window.open(items[active].href, '_blank', 'noopener');
                    else window.location.href = items[active].href;
                }
            }
        });
        // The browser returns focus to the opener when the dialog closes.
        dlg.addEventListener('close', function () {
            clearTimeout(timer);
            if (controller) controller.abort();
        });
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------
    var wrap = top.querySelector('[data-bh-notify]');
    var bell = wrap && wrap.querySelector('[data-bh-menu-btn]');
    var badge = wrap && wrap.querySelector('[data-bh-notify-count]');
    var nlist = wrap && wrap.querySelector('[data-bh-notify-list]');
    var loadedAt = 0, loading = null;
    var TONE_ICON = { danger: 'exclamation-triangle', warning: 'exclamation-circle', info: 'information-circle' };
    var SOURCE_ICON = { 'core.updates': 'arrow-path', 'core.comments': 'chat-bubble-left-right', 'core.tasks': 'clock', 'core.security': 'shield-check' };

    function paintBadge(rows) {
        var n = rows.length;
        var urgent = rows.some(function (r) { return r.tone === 'danger' || r.tone === 'warning'; });
        badge.hidden = n === 0;
        badge.textContent = n > 9 ? '9+' : String(n);
        badge.classList.toggle('is-quiet', !urgent);
        bell.setAttribute('aria-label', n === 0 ? 'Notifications' : 'Notifications (' + n + ')');
        bell.title = n === 0 ? 'Notifications' : n + (n === 1 ? ' notification' : ' notifications');
    }
    function nState(iconName, text, retry) {
        nlist.textContent = '';
        var p = el('div', 'bh-notify__state');
        if (iconName) p.innerHTML = I(iconName, 'w-6 h-6');
        p.appendChild(el('p', '', text));
        if (retry) {
            var b = el('button', 'bh-btn bh-btn--secondary bh-btn--sm bh-notify__retry', 'Try again');
            b.type = 'button';
            b.addEventListener('click', function (e) { e.stopPropagation(); load(true); });
            p.appendChild(b);
        }
        nlist.appendChild(p);
    }
    function paintList(rows) {
        nlist.textContent = '';
        if (!rows.length) { nState('check-circle', 'You’re all caught up.'); return; }
        rows.forEach(function (r) {
            var row = el(r.url ? 'a' : 'div', 'bh-notify__item bh-notify__item--' + (r.tone || 'info'));
            if (r.url) row.href = href(r.url);
            row.appendChild(iconEl('span', 'bh-notify__icon', SOURCE_ICON[r.source] || TONE_ICON[r.tone] || 'information-circle'));
            var body = el('span', 'bh-notify__body');
            body.appendChild(el('span', 'bh-notify__title', r.title));
            if (r.text) body.appendChild(el('span', 'bh-notify__text', r.text));
            row.appendChild(body);
            nlist.appendChild(row);
        });
    }
    function load(force) {
        if (loading) return loading;
        if (!force && loadedAt && Date.now() - loadedAt < 30000) return Promise.resolve();
        nlist.setAttribute('aria-busy', 'true');
        loading = window.Basehim.ajax.request('admin.bar_notifications', { ctx: ctx }, { method: 'GET' })
            .then(function (d) {
                var rows = (d && d.items) || [];
                loadedAt = Date.now();
                paintBadge(rows);
                paintList(rows);
            })
            .catch(function (err) {
                nState('exclamation-circle', (err && err.message) || 'Notifications could not be loaded.', true);
            })
            .then(function () { loading = null; nlist.removeAttribute('aria-busy'); });
        return loading;
    }

    if (wrap && bell && badge && nlist) {
        bell.addEventListener('click', function () {
            if (bell.getAttribute('aria-expanded') === 'true') load(false);
        });
        var start = function () { load(true); };
        if ('requestIdleCallback' in window) window.requestIdleCallback(start, { timeout: 2500 });
        else setTimeout(start, 600);
    }
})();
