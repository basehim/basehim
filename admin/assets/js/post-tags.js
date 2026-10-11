/*
 * Post editor tag box (1.2.47).
 *
 *  - Typing suggests existing tags (terms.tag_suggest). Enter or a comma adds
 *    the highlighted suggestion, or the typed name; pasting "a, b, c" adds three.
 *  - On a saved post, adding or removing an existing tag is stored at once
 *    (posts.tag_add / posts.tag_remove). On a post that has never been saved,
 *    chips are kept in the form and saved with it.
 *  - A name that is not a tag yet shows as "(new)" and is created when the
 *    post is published (PostEditorService::settleNewTags()).
 *  - "Most used" and "Recently used" tags (terms.tag_overview) are one click.
 *
 * The chips carry hidden term_ids[] / new_tags[] inputs, so the form's own
 * Publish / Update always sends the current set.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-bh-tags]');
    if (!root) return;
    var chipsEl = root.querySelector('[data-bh-tags-chips]');
    var input = root.querySelector('[data-bh-tags-input]');
    var list = root.querySelector('[data-bh-tags-suggest]');
    var postId = parseInt(root.getAttribute('data-post-id'), 10) || null;
    var ajax = window.Basehim && window.Basehim.ajax;
    if (!chipsEl || !input || !list || !ajax) return;

    var results = [];          // the suggestions on screen: [{id, name, count} | {create: name}]
    var active = -1;           // highlighted suggestion
    var timer = null, inflight = null, seq = 0;

    function toast(msg, type) { if (window.bhToast) window.bhToast(msg, type || 'error'); }
    function lower(s) { return String(s || '').toLocaleLowerCase(); }
    function clean(s) { return String(s || '').replace(/[\u0000-\u001F\u007F,]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100); }
    function markDirty() { try { window.BasehimEditorDirty = true; } catch (e) {} }

    // ── chips ─────────────────────────────────────────────────────────────────

    function chosen() {
        var ids = [], names = [];
        Array.prototype.forEach.call(chipsEl.querySelectorAll('.bh-tag'), function (c) {
            var id = parseInt(c.getAttribute('data-id'), 10);
            if (id) ids.push(id);
            names.push(lower(c.querySelector('.bh-tag__name').textContent));
        });
        return { ids: ids, names: names };
    }

    function chip(tag) {
        var el = document.createElement('span');
        el.className = 'bh-tag' + (tag.id ? '' : ' is-new');
        if (tag.id) el.setAttribute('data-id', String(tag.id));
        else { el.setAttribute('data-name', tag.name); el.title = 'Created when the post is published'; }
        var name = document.createElement('span');
        name.className = 'bh-tag__name';
        name.textContent = tag.name;
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'bh-tag__x';
        x.setAttribute('aria-label', 'Remove tag ' + tag.name);
        x.textContent = '×';
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = tag.id ? 'term_ids[]' : 'new_tags[]';
        hidden.value = tag.id ? String(tag.id) : tag.name;
        // On a saved post the server already has it: not unsaved work.
        if (postId) hidden.setAttribute('data-bh-autosaved', '');
        el.appendChild(name); el.appendChild(x); el.appendChild(hidden);
        return el;
    }

    /** Replace the chips with the server's view of the post's tags. */
    function renderState(tags, pending) {
        chipsEl.textContent = '';
        (tags || []).forEach(function (t) { chipsEl.appendChild(chip({ id: t.id, name: t.name })); });
        (pending || []).forEach(function (n) { chipsEl.appendChild(chip({ id: null, name: n })); });
        syncClouds();
    }

    function add(tag) {
        var name = clean(tag.name);
        if (!tag.id && name === '') return;
        var have = chosen();
        if ((tag.id && have.ids.indexOf(tag.id) >= 0) || have.names.indexOf(lower(name)) >= 0) {
            toast('“' + name + '” is already added.', 'info');
            return;
        }
        var el = chip({ id: tag.id || null, name: name });
        chipsEl.appendChild(el);
        syncClouds();
        if (!postId) { markDirty(); return; }

        el.classList.add('is-busy');
        ajax.request('posts.tag_add', tag.id ? { post_id: postId, term_id: tag.id } : { post_id: postId, name: name })
            .then(function (d) { renderState(d.tags, d.pending_tags); })
            .catch(function (err) { el.remove(); syncClouds(); toast(err.message || 'Could not add the tag.'); });
    }

    function remove(el) {
        if (!postId) { el.remove(); syncClouds(); markDirty(); return; }
        var id = parseInt(el.getAttribute('data-id'), 10);
        el.classList.add('is-busy');
        ajax.request('posts.tag_remove', id ? { post_id: postId, term_id: id } : { post_id: postId, name: el.getAttribute('data-name') || '' })
            .then(function (d) { renderState(d.tags, d.pending_tags); })
            .catch(function (err) { el.classList.remove('is-busy'); toast(err.message || 'Could not remove the tag.'); });
    }

    chipsEl.addEventListener('click', function (e) {
        var x = e.target.closest('.bh-tag__x');
        if (!x) return;
        var el = x.closest('.bh-tag');
        if (el && !el.classList.contains('is-busy')) remove(el);
        input.focus();
    });

    // ── suggestions ───────────────────────────────────────────────────────────

    function hide() {
        list.hidden = true; list.textContent = ''; results = []; active = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function highlight(i) {
        var opts = list.querySelectorAll('.bh-tags__opt');
        if (!opts.length) return;
        active = (i + opts.length) % opts.length;
        Array.prototype.forEach.call(opts, function (o, n) { o.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant', opts[active].id);
        opts[active].scrollIntoView({ block: 'nearest' });
    }

    function show(q, tags) {
        var have = chosen();
        results = tags.filter(function (t) { return have.ids.indexOf(t.id) < 0; });
        var exact = tags.some(function (t) { return lower(t.name) === lower(q); }) || have.names.indexOf(lower(q)) >= 0;
        if (!exact) results.push({ create: q });
        list.textContent = '';
        results.forEach(function (r, i) {
            var li = document.createElement('li');
            li.className = 'bh-tags__opt';
            li.id = 'bh-tags-opt-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            if (r.create) {
                li.appendChild(document.createTextNode('Add new tag “' + r.create + '”'));
            } else {
                var name = document.createElement('span');
                var at = lower(r.name).indexOf(lower(q));
                if (at >= 0 && q) {
                    name.appendChild(document.createTextNode(r.name.slice(0, at)));
                    var m = document.createElement('mark'); m.textContent = r.name.slice(at, at + q.length);
                    name.appendChild(m);
                    name.appendChild(document.createTextNode(r.name.slice(at + q.length)));
                } else {
                    name.textContent = r.name;
                }
                var count = document.createElement('small');
                count.textContent = r.count === 1 ? '1 post' : (r.count || 0) + ' posts';
                li.appendChild(name); li.appendChild(count);
            }
            li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
            list.appendChild(li);
        });
        list.hidden = results.length === 0;
        input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
        active = -1;
        if (results.length) highlight(0);
    }

    function lookup() {
        var q = clean(input.value);
        if (q === '') { hide(); return; }
        if (inflight) inflight.abort();
        inflight = typeof AbortController === 'function' ? new AbortController() : null;
        var mine = ++seq;
        ajax.get('terms.tag_suggest', { q: q, limit: 8, exclude: chosen().ids.join(',') }, inflight ? { signal: inflight.signal } : {})
            .then(function (d) { if (mine === seq && clean(input.value) === q) show(q, d.tags || []); })
            .catch(function (err) { if (err && err.code !== 'ABORTED' && mine === seq) show(q, []); });
    }

    function pick(i) {
        var r = results[i];
        if (!r) return;
        add(r.create ? { name: r.create } : { id: r.id, name: r.name });
        input.value = '';
        hide();
    }

    /** Add what is typed: the highlighted suggestion, an exact match, or a new name. */
    function commit() {
        var q = clean(input.value);
        if (active >= 0 && results[active]) { pick(active); return; }
        if (q === '') return;
        var match = results.filter(function (r) { return !r.create && lower(r.name) === lower(q); })[0];
        add(match ? { id: match.id, name: match.name } : { name: q });
        input.value = '';
        hide();
    }

    input.addEventListener('input', function () {
        if (input.value.indexOf(',') >= 0) {
            var parts = input.value.split(',');
            input.value = parts.pop();
            parts.forEach(function (p) { if (clean(p)) add({ name: clean(p) }); });
        }
        clearTimeout(timer);
        timer = setTimeout(lookup, 180);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); commit(); return; }
        if (e.key === 'ArrowDown') { if (!list.hidden) { e.preventDefault(); highlight(active + 1); } return; }
        if (e.key === 'ArrowUp') { if (!list.hidden) { e.preventDefault(); highlight(active - 1); } return; }
        if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); e.stopPropagation(); hide(); } return; }
        if (e.key === 'Tab' && !list.hidden && active >= 0 && clean(input.value)) { e.preventDefault(); pick(active); return; }
        if (e.key === 'Backspace' && input.value === '') {
            // Move to the last chip's remove button rather than deleting at once:
            // on a saved post, removal is stored immediately.
            var last = chipsEl.querySelector('.bh-tag:last-child .bh-tag__x');
            if (last) { e.preventDefault(); last.focus(); }
        }
    });
    input.addEventListener('blur', function () { setTimeout(hide, 150); });

    // ── most used / recently used ─────────────────────────────────────────────

    var clouds = {};
    function syncClouds() {
        var have = chosen();
        Object.keys(clouds).forEach(function (k) {
            Array.prototype.forEach.call(clouds[k].querySelectorAll('.bh-tags__pick'), function (b) {
                var id = parseInt(b.getAttribute('data-id'), 10);
                b.disabled = have.ids.indexOf(id) >= 0;
                b.setAttribute('aria-pressed', b.disabled ? 'true' : 'false');
            });
        });
    }
    function renderCloud(key, tags) {
        var group = root.querySelector('[data-bh-tags-group="' + key + '"]');
        if (!group || !tags || !tags.length) return;
        var cloud = group.querySelector('.bh-tags__cloud');
        cloud.textContent = '';
        tags.forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'bh-tags__pick';
            b.setAttribute('data-id', String(t.id));
            b.textContent = t.name;
            if (typeof t.count === 'number') {
                var c = document.createElement('small'); c.textContent = ' ' + t.count;
                b.appendChild(c);
                b.title = t.count === 1 ? 'Used on 1 post' : 'Used on ' + t.count + ' posts';
            }
            b.addEventListener('click', function () { add({ id: t.id, name: t.name }); });
            cloud.appendChild(b);
        });
        clouds[key] = cloud;
        group.hidden = false;
    }
    ajax.get('terms.tag_overview', { limit: 12 })
        .then(function (d) { renderCloud('most_used', d.most_used); renderCloud('recent', d.recent); syncClouds(); })
        .catch(function () { /* the box works without them */ });

    // ── after Save draft (AJAX) created or saved the post ────────────────────

    document.addEventListener('bh:post-saved', function (e) {
        var d = e.detail || {};
        if (!d.id) return;
        postId = d.id;
        root.setAttribute('data-post-id', String(d.id));
        renderState(d.tags || [], d.pending_tags || []);
    });
})();
