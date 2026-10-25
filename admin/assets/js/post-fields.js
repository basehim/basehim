/*
 * Basehim — shared post fields (1.2.62).
 *
 * The block editor's sidebar and Quick Edit in the Posts/Pages lists draw
 * these three fields with the same code, so they look and behave the same:
 *
 *   BasehimPostFields.categories(host, cfg) — a category checklist (with a
 *       filter box once the list is long).
 *   BasehimPostFields.tags(host, cfg) — tag chips with typeahead, "Most used"
 *       and "Recently used", and new names kept until the post is published.
 *   BasehimPostFields.publish(host, cfg, opts) — publish now / schedule, or
 *       keep / change the date of a live post, in the SITE timezone.
 *
 * Each renders into `host` and returns a small API (value(), onChange(), …).
 * The server builds `cfg` (PostEditorService::categoriesField()/tagsField(),
 * PostPublishing::panel()) and checks every value again on save: nothing
 * here is a security boundary.
 */
(function () {
  'use strict';

  var uid = 0;
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = String(text); return n; }
  function lower(s) { return String(s || '').toLocaleLowerCase(); }
  /**
   * Class names for the screen a field sits on: `skin: 'editor'` uses the block
   * editor's (bhe-*), anything else the admin's (bh-*), so neither stylesheet
   * restyles the other's fields.
   */
  function skinCls(skin, kind) {
    var editor = skin === 'editor';
    return { input: editor ? 'bhe-input' : 'bh-input', label: editor ? 'bhe-label' : 'bh-label', help: editor ? 'bhe-help' : 'bh-help', seg: editor ? 'bhe-segmented ' : '' }[kind];
  }
  function emitter() {
    var fns = [];
    return { on: function (fn) { if (typeof fn === 'function') fns.push(fn); }, fire: function (v) { fns.forEach(function (fn) { try { fn(v); } catch (e) { if (window.console) console.error(e); } }); } };
  }

  // ===========================================================================
  // Categories
  // ===========================================================================
  /**
   * cfg: options [{id, name}], selected [ids], name ('term_ids[]'), label,
   *      createUrl (shown when there are none), filterAt (8), compact.
   */
  function categories(host, cfg) {
    cfg = cfg || {};
    var id = 'bh-terms-' + (++uid);
    var opts = cfg.options || [];
    var chosen = (cfg.selected || []).map(String);
    var changed = emitter();
    host.textContent = '';
    host.classList.add('bh-terms');
    if (cfg.compact) host.classList.add('bh-terms--compact');

    var filter = null;
    if (opts.length > (cfg.filterAt || 8)) {
      filter = el('input', (cfg.skin === 'editor' ? 'bhe-input' : 'bh-input bh-input--sm') + ' bh-terms__filter');
      filter.type = 'search';
      filter.placeholder = 'Filter ' + lower(cfg.label || 'categories') + '…';
      filter.setAttribute('aria-label', 'Filter ' + lower(cfg.label || 'categories'));
      host.appendChild(filter);
    }
    var list = el('div', 'bh-terms__list');
    list.id = id;
    list.setAttribute('role', 'group');
    list.setAttribute('aria-label', cfg.label || 'Categories');
    host.appendChild(list);

    if (!opts.length) {
      var empty = el('p', 'bh-terms__empty', 'No categories yet. ');
      if (cfg.createUrl) { var a = el('a', '', 'Create one'); a.href = cfg.createUrl; empty.appendChild(a); empty.appendChild(document.createTextNode('.')); }
      list.appendChild(empty);
    }
    opts.forEach(function (o) {
      var row = el('label', 'bh-terms__opt');
      var cb = el('input'); cb.type = 'checkbox'; cb.value = String(o.id);
      if (cfg.name) cb.name = cfg.name;
      cb.checked = chosen.indexOf(String(o.id)) !== -1;
      cb.addEventListener('change', function () { changed.fire(api.value()); });
      row.appendChild(cb); row.appendChild(el('span', 'bh-terms__name', o.name));
      list.appendChild(row);
    });
    if (filter) filter.addEventListener('input', function () {
      var q = lower(filter.value.trim());
      list.querySelectorAll('.bh-terms__opt').forEach(function (n) { n.hidden = q !== '' && lower(n.textContent).indexOf(q) === -1; });
    });

    var api = {
      el: host,
      focusTarget: function () { return filter || list.querySelector('input'); },
      /** The ticked category ids, as strings, in list order. */
      value: function () {
        var out = []; list.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { if (cb.checked) out.push(cb.value); });
        return out;
      },
      set: function (ids) { ids = (ids || []).map(String); list.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = ids.indexOf(cb.value) !== -1; }); },
      onChange: changed.on
    };
    return api;
  }

  // ===========================================================================
  // Tags
  // ===========================================================================
  var overview = null;   // terms.tag_overview, fetched once per page
  function tagOverview(ajax) {
    if (!overview) overview = ajax.get('terms.tag_overview', { limit: 12 }).catch(function () { overview = null; return null; });
    return overview;
  }

  /**
   * cfg: post_id, can_create, tags [{id,name}], pending [names],
   *      immediate (true: on a saved post, add/remove is stored at once over
   *      posts.tag_add / posts.tag_remove, as in the editor; false: changes
   *      wait for the form's own Save, as in Quick Edit),
   *      names ({ids: 'term_ids[]', names: 'new_tags[]'} — hidden inputs; omit for none),
   *      idPrefix, clouds (true), compact, typeLabel ('post'), skin ('editor' | 'admin').
   */
  function tags(host, cfg) {
    cfg = cfg || {};
    var ajax = window.Basehim && window.Basehim.ajax;
    var prefix = cfg.idPrefix || ('bh-tags-' + (++uid));
    var postId = cfg.post_id || null;
    var immediate = !!cfg.immediate;
    var names = cfg.names === undefined ? { ids: 'term_ids[]', names: 'new_tags[]' } : cfg.names;
    var noun = cfg.typeLabel || 'post';
    var changed = emitter();
    var results = [], active = -1, timer = null, inflight = null, seq = 0;

    host.textContent = '';
    host.classList.add('bh-tags');
    if (cfg.compact) host.classList.add('bh-tags--compact');
    host.setAttribute('data-bh-tags', '');

    var chipsEl = el('div', 'bh-tags__chips'); chipsEl.setAttribute('data-bh-tags-chips', '');
    var field = el('div', 'bh-tags__field');
    var label = el('label', skinCls(cfg.skin, 'label') + ' bh-tags__label', cfg.label || 'Add a tag');
    var input = el('input', skinCls(cfg.skin, 'input') + ' bh-tags__input');
    input.type = 'text'; input.id = prefix + '-input'; label.setAttribute('for', input.id); label.htmlFor = input.id;
    input.autocomplete = 'off'; input.setAttribute('autocomplete', 'off'); input.setAttribute('spellcheck', 'false'); input.maxLength = 100;
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', prefix + '-suggest'); input.placeholder = 'Type to search tags';
    input.setAttribute('data-bh-tags-input', '');
    var list = el('ul', 'bh-tags__suggest'); list.id = prefix + '-suggest'; list.setAttribute('role', 'listbox'); list.hidden = true;
    list.setAttribute('data-bh-tags-suggest', '');
    field.appendChild(label); field.appendChild(input); field.appendChild(list);
    var hint = el('p', 'bh-tags__hint', 'Separate with commas or press Enter. ' + (cfg.can_create
      ? 'New tags are created when the ' + noun + ' is published.'
      : 'New tags are created when an editor publishes the ' + noun + '.'));
    host.appendChild(chipsEl); host.appendChild(field); host.appendChild(hint);
    var groups = {};
    if (cfg.clouds !== false) {
      [['most_used', 'Most used'], ['recent', 'Recently used']].forEach(function (g) {
        var box = el('div', 'bh-tags__group'); box.hidden = true; box.setAttribute('data-bh-tags-group', g[0]);
        box.appendChild(el('span', 'bh-tags__group-title', g[1]));
        var cloud = el('div', 'bh-tags__cloud'); box.appendChild(cloud);
        host.appendChild(box); groups[g[0]] = { box: box, cloud: cloud };
      });
    }

    function toast(msg, type) { if (window.bhToast) window.bhToast(msg, type || 'error'); }
    function clean(s) { return String(s || '').replace(/[\u0000-\u001F\u007F,]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100); }
    function chosen() {
      var ids = [], n = [];
      chipsEl.querySelectorAll('.bh-tag').forEach(function (c) {
        var id = parseInt(c.getAttribute('data-id'), 10);
        if (id) ids.push(id);
        n.push(lower(c.querySelector('.bh-tag__name').textContent));
      });
      return { ids: ids, names: n };
    }
    function chip(tag) {
      var c = el('span', 'bh-tag' + (tag.id ? '' : ' is-new'));
      if (tag.id) c.setAttribute('data-id', String(tag.id));
      else { c.setAttribute('data-name', tag.name); c.title = 'Created when the ' + noun + ' is published'; }
      c.appendChild(el('span', 'bh-tag__name', tag.name));
      var x = el('button', 'bh-tag__x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Remove tag ' + tag.name);
      c.appendChild(x);
      if (names) {
        var h = el('input'); h.type = 'hidden';
        h.name = tag.id ? names.ids : names.names; h.value = tag.id ? String(tag.id) : tag.name;
        // Stored on the server already (immediate, saved post): not unsaved work.
        if (immediate && postId) h.setAttribute('data-bh-autosaved', '');
        c.appendChild(h);
      }
      return c;
    }
    function renderState(t, pending) {
      chipsEl.textContent = '';
      (t || []).forEach(function (x) { chipsEl.appendChild(chip({ id: x.id, name: x.name })); });
      (pending || []).forEach(function (n) { chipsEl.appendChild(chip({ id: null, name: n })); });
      syncClouds();
    }
    function add(tag) {
      var name = clean(tag.name);
      if (!tag.id && name === '') return;
      var have = chosen();
      if ((tag.id && have.ids.indexOf(tag.id) >= 0) || have.names.indexOf(lower(name)) >= 0) { toast('“' + name + '” is already added.', 'info'); return; }
      var c = chip({ id: tag.id || null, name: name });
      chipsEl.appendChild(c);
      syncClouds();
      if (!(immediate && postId && ajax)) { changed.fire(api.value()); return; }
      c.classList.add('is-busy');
      ajax.request('posts.tag_add', tag.id ? { post_id: postId, term_id: tag.id } : { post_id: postId, name: name })
        .then(function (d) { renderState(d.tags, d.pending_tags); changed.fire(api.value()); })
        .catch(function (err) { c.remove(); syncClouds(); toast((err && err.message) || 'Could not add the tag.'); });
    }
    function remove(c) {
      if (!(immediate && postId && ajax)) { c.remove(); syncClouds(); changed.fire(api.value()); return; }
      var id = parseInt(c.getAttribute('data-id'), 10);
      c.classList.add('is-busy');
      ajax.request('posts.tag_remove', id ? { post_id: postId, term_id: id } : { post_id: postId, name: c.getAttribute('data-name') || '' })
        .then(function (d) { renderState(d.tags, d.pending_tags); changed.fire(api.value()); })
        .catch(function (err) { c.classList.remove('is-busy'); toast((err && err.message) || 'Could not remove the tag.'); });
    }
    chipsEl.addEventListener('click', function (e) {
      var x = e.target.closest('.bh-tag__x'); if (!x) return;
      var c = x.closest('.bh-tag'); if (c && !c.classList.contains('is-busy')) remove(c);
      input.focus();
    });

    function hide() { list.hidden = true; list.textContent = ''; results = []; active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    function highlight(i) {
      var o = list.querySelectorAll('.bh-tags__opt'); if (!o.length) return;
      active = (i + o.length) % o.length;
      o.forEach(function (x, n) { x.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
      input.setAttribute('aria-activedescendant', o[active].id);
      if (o[active].scrollIntoView) o[active].scrollIntoView({ block: 'nearest' });
    }
    function show(q, found) {
      var have = chosen();
      results = found.filter(function (t) { return have.ids.indexOf(t.id) < 0; });
      var exact = found.some(function (t) { return lower(t.name) === lower(q); }) || have.names.indexOf(lower(q)) >= 0;
      if (!exact) results.push({ create: q });
      list.textContent = '';
      results.forEach(function (r, i) {
        var li = el('li', 'bh-tags__opt'); li.id = prefix + '-opt-' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false');
        if (r.create) li.appendChild(document.createTextNode('Add new tag “' + r.create + '”'));
        else {
          var nm = el('span'); var at = lower(r.name).indexOf(lower(q));
          if (at >= 0 && q) { nm.appendChild(document.createTextNode(r.name.slice(0, at))); nm.appendChild(el('mark', '', r.name.slice(at, at + q.length))); nm.appendChild(document.createTextNode(r.name.slice(at + q.length))); }
          else nm.textContent = r.name;
          li.appendChild(nm); li.appendChild(el('small', '', r.count === 1 ? '1 post' : (r.count || 0) + ' posts'));
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
      if (q === '' || !ajax) { hide(); return; }
      if (inflight) inflight.abort();
      inflight = typeof AbortController === 'function' ? new AbortController() : null;
      var mine = ++seq;
      ajax.get('terms.tag_suggest', { q: q, limit: 8, exclude: chosen().ids.join(',') }, inflight ? { signal: inflight.signal } : {})
        .then(function (d) { if (mine === seq && clean(input.value) === q) show(q, (d && d.tags) || []); })
        .catch(function (err) { if (err && err.code !== 'ABORTED' && mine === seq) show(q, []); });
    }
    function pick(i) { var r = results[i]; if (!r) return; add(r.create ? { name: r.create } : { id: r.id, name: r.name }); input.value = ''; hide(); }
    function commit() {
      var q = clean(input.value);
      if (active >= 0 && results[active]) { pick(active); return; }
      if (q === '') return;
      var match = results.filter(function (r) { return !r.create && lower(r.name) === lower(q); })[0];
      add(match ? { id: match.id, name: match.name } : { name: q });
      input.value = ''; hide();
    }
    input.addEventListener('input', function () {
      if (input.value.indexOf(',') >= 0) {
        var parts = input.value.split(',');
        input.value = parts.pop();
        parts.forEach(function (p) { if (clean(p)) add({ name: clean(p) }); });
      }
      clearTimeout(timer); timer = setTimeout(lookup, 180);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); commit(); return; }
      if (e.key === 'ArrowDown') { if (!list.hidden) { e.preventDefault(); highlight(active + 1); } return; }
      if (e.key === 'ArrowUp') { if (!list.hidden) { e.preventDefault(); highlight(active - 1); } return; }
      if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); e.stopPropagation(); hide(); } return; }
      if (e.key === 'Tab' && !list.hidden && active >= 0 && clean(input.value)) { e.preventDefault(); pick(active); return; }
      if (e.key === 'Backspace' && input.value === '') {
        var last = chipsEl.querySelector('.bh-tag:last-child .bh-tag__x');
        if (last) { e.preventDefault(); last.focus(); }
      }
    });
    input.addEventListener('blur', function () { setTimeout(hide, 150); });

    function syncClouds() {
      var have = chosen();
      Object.keys(groups).forEach(function (k) {
        groups[k].cloud.querySelectorAll('.bh-tags__pick').forEach(function (b) {
          var id = parseInt(b.getAttribute('data-id'), 10);
          b.disabled = have.ids.indexOf(id) >= 0;
          b.setAttribute('aria-pressed', b.disabled ? 'true' : 'false');
        });
      });
    }
    function renderCloud(key, list2) {
      var g = groups[key]; if (!g || !list2 || !list2.length) return;
      g.cloud.textContent = '';
      list2.slice(0, cfg.cloudLimit || 12).forEach(function (t) {
        var b = el('button', 'bh-tags__pick', t.name); b.type = 'button'; b.setAttribute('data-id', String(t.id));
        if (typeof t.count === 'number') { b.appendChild(el('small', '', ' ' + t.count)); b.title = t.count === 1 ? 'Used on 1 post' : 'Used on ' + t.count + ' posts'; }
        b.addEventListener('click', function () { add({ id: t.id, name: t.name }); });
        g.cloud.appendChild(b);
      });
      g.box.hidden = false;
    }
    if (ajax && Object.keys(groups).length) {
      tagOverview(ajax).then(function (d) { if (!d) return; renderCloud('most_used', d.most_used); renderCloud('recent', d.recent); syncClouds(); });
    }

    renderState(cfg.tags, cfg.pending);

    var api = {
      el: host,
      focusTarget: function () { return input; },
      /** {ids: [existing tag ids], names: [new names]} in chip order. */
      value: function () {
        var ids = [], n = [];
        chipsEl.querySelectorAll('.bh-tag').forEach(function (c) {
          var id = parseInt(c.getAttribute('data-id'), 10);
          if (id) ids.push(id); else n.push(c.getAttribute('data-name') || c.querySelector('.bh-tag__name').textContent);
        });
        return { ids: ids, names: n };
      },
      /** The server's view after a save (Save draft, Quick Edit). */
      set: function (t, pending) { renderState(t, pending); },
      setPostId: function (id) { postId = id || null; host.setAttribute('data-post-id', postId ? String(postId) : ''); },
      onChange: changed.on
    };
    api.setPostId(postId);
    return api;
  }

  // ===========================================================================
  // Publish
  // ===========================================================================
  /**
   * cfg: PostPublishing::panel() — status, can_publish, tz, tz_label, now,
   *      published_at, date, time, mode, min_date, max_date, type_label, summary.
   * opts: targetStatus() → the status the save will ask for (default: cfg.status),
   *       names (true: fields are named publish_mode/date/time for a form post),
   *       idPrefix, compact, showSummary (true), skin ('editor' | 'admin').
   *
   * Modes: a post that is not live — Now | Schedule (a future time) | Set date (any time; 1.2.88);
   * a scheduled post — the same, starting on Schedule; a live post — Keep
   * date | Change date (a past time re-dates it, a future one schedules it).
   */
  function publish(host, cfg, opts) {
    cfg = cfg || {}; opts = opts || {};
    var tz = cfg.tz || 'UTC';
    var stored = cfg.status || 'draft';
    var canPublish = !!cfg.can_publish;
    var noun = cfg.type_label || 'post';
    var prefix = opts.idPrefix || ('bh-publish-' + (++uid));
    var skew = (parseInt(cfg.now, 10) || Math.floor(Date.now() / 1000)) * 1000 - Date.now();
    var MAX = Date.UTC(2038, 0, 19, 3, 14, 7);
    var live = stored === 'published';
    var changed = emitter();
    function serverNow() { return Date.now() + skew; }

    host.textContent = '';
    host.classList.add('bh-publish');
    if (opts.compact) host.classList.add('bh-publish--compact');
    host.setAttribute('data-bh-publish', '');

    var state = el('p', 'bh-publish__state bh-publish__state--' + stored); state.setAttribute('data-bh-publish-state', '');
    state.appendChild(el('span', 'bh-publish__dot')).setAttribute('aria-hidden', 'true');
    state.appendChild(el('span', '', cfg.summary || ''));
    if (opts.showSummary !== false) host.appendChild(state);

    var radios = [], when, dateIn, timeIn, errorEl, preview, modesBox;
    if (canPublish) {
      modesBox = el('fieldset', 'bh-publish__modes'); modesBox.setAttribute('data-bh-publish-modes', '');
      modesBox.appendChild(el('legend', skinCls(opts.skin, 'label'), 'When'));
      var seg = el('div', skinCls(opts.skin, 'seg') + 'bh-publish__seg');
      // "Set date" (1.2.88): give a post that is not live yet any date. A
      // past one publishes it dated then, so it sorts where it belongs; a
      // future one schedules it. "Schedule" stays future-only.
      var choices = live ? [['keep', 'Keep date'], ['date', 'Change date']] : [['now', 'Now'], ['schedule', 'Schedule'], ['date', 'Set date']];
      var initial = cfg.mode && choices.some(function (c) { return c[0] === cfg.mode; }) ? cfg.mode : choices[0][0];
      choices.forEach(function (c) {
        var lab = el('label', 'bh-publish__mode');
        var r = el('input'); r.type = 'radio'; r.value = c[0]; r.name = opts.names === false ? prefix + '-mode' : 'publish_mode';
        r.checked = c[0] === initial;
        lab.appendChild(r); lab.appendChild(el('span', '', c[1]));
        seg.appendChild(lab); radios.push(r);
        r.addEventListener('change', function () { showError(null); render(); changed.fire(api.values()); });
      });
      modesBox.appendChild(seg);
      host.appendChild(modesBox);

      when = el('div', 'bh-publish__when'); when.setAttribute('data-bh-publish-when', '');
      var grid = el('div', 'bh-publish__grid');
      function dt(type, labelText, value) {
        var f = el('div', (opts.skin === 'editor' ? 'bhe-field ' : '') + 'bh-publish__field');
        var input = el('input', skinCls(opts.skin, 'input')); input.type = type; input.id = prefix + '-' + type;
        input.name = opts.names === false ? '' : 'publish_' + type;
        if (opts.names === false) input.removeAttribute('name');
        input.value = value || '';
        if (type === 'time') input.step = 60;
        if (type === 'date') { input.setAttribute('max', cfg.max_date || '2038-01-18'); if (!live && cfg.min_date) input.setAttribute('min', cfg.min_date); }
        input.setAttribute('aria-describedby', prefix + '-preview ' + prefix + '-error');
        var l = el('label', skinCls(opts.skin, 'label'), labelText); l.setAttribute('for', input.id); l.htmlFor = input.id;
        f.appendChild(l); f.appendChild(input); grid.appendChild(f);
        input.addEventListener('input', function () { render(); changed.fire(api.values()); });
        input.addEventListener('change', function () { render(); changed.fire(api.values()); });
        return input;
      }
      dateIn = dt('date', 'Date', cfg.date);
      timeIn = dt('time', 'Time', cfg.time);
      when.appendChild(grid);
      var tzp = el('p', 'bh-publish__tz');
      tzp.appendChild(el('span', '', 'Site time: '));
      tzp.appendChild(el('strong', '', tz));
      if (cfg.tz_label) tzp.appendChild(el('span', '', ' (' + cfg.tz_label + ')'));
      when.appendChild(tzp);
      errorEl = el('p', 'bh-publish__error'); errorEl.id = prefix + '-error'; errorEl.setAttribute('role', 'alert'); errorEl.hidden = true;
      errorEl.setAttribute('data-bh-publish-error', '');
      when.appendChild(errorEl);
      host.appendChild(when);
      preview = el('p', 'bh-publish__preview'); preview.id = prefix + '-preview'; preview.setAttribute('aria-live', 'polite'); preview.hidden = true;
      preview.setAttribute('data-bh-publish-preview', '');
      host.appendChild(preview);
    } else if (stored !== 'published' && stored !== 'scheduled' && stored !== 'private') {
      host.appendChild(el('p', skinCls(opts.skin, 'help') + ' bh-publish__note', 'Your role submits ' + noun + 's for review. An editor chooses when it goes live.'));
    }

    // -- time zone arithmetic (Intl) --
    var partsFmt;
    try { partsFmt = new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
    catch (e) { partsFmt = null; }
    function wall(ms) {
      if (!partsFmt) { var d = new Date(ms); return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), h: d.getUTCHours(), mi: d.getUTCMinutes(), s: d.getUTCSeconds() }; }
      var o = {}; partsFmt.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = parseInt(p.value, 10); });
      return { y: o.year, m: o.month, d: o.day, h: o.hour === 24 ? 0 : o.hour, mi: o.minute, s: o.second };
    }
    function offsetAt(ms) { var w = wall(ms); return Date.UTC(w.y, w.m - 1, w.d, w.h, w.mi, w.s) - Math.floor(ms / 1000) * 1000; }
    /** Site-local date + time → instant (ms), or NaN. DST gaps move forward, as PHP does. */
    function toInstant(date, time) {
      var dm = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || ''), tm = /^(\d{2}):(\d{2})/.exec(time || '');
      if (!dm || !tm) return NaN;
      var y = +dm[1], mo = +dm[2], d = +dm[3], h = +tm[1], mi = +tm[2];
      var probe = new Date(Date.UTC(y, mo - 1, d));
      if (probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d || h > 23 || mi > 59) return NaN;
      var guess = Date.UTC(y, mo - 1, d, h, mi);
      var t = guess - offsetAt(guess), o2 = offsetAt(t);
      if (guess - o2 !== t) t = guess - o2;
      var w = wall(t);
      if (w.h !== h || w.mi !== mi) t = guess - Math.min(offsetAt(guess - 864e5), offsetAt(guess + 864e5));
      return t;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function localDate(ms) { var w = wall(ms); return w.y + '-' + pad(w.m) + '-' + pad(w.d); }
    function localTime(ms) { var w = wall(ms); return pad(w.h) + ':' + pad(w.mi); }
    function describe(ms) {
      try { return new Intl.DateTimeFormat(undefined, { timeZone: tz, weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(new Date(ms)); }
      catch (e) { return localDate(ms) + ' ' + localTime(ms) + ' (' + tz + ')'; }
    }
    function relative(ms) {
      var min = Math.round((ms - serverNow()) / 60000), unit = 'minute', n = min;
      if (Math.abs(min) >= 2880) { unit = 'day'; n = Math.round(min / 1440); } else if (Math.abs(min) >= 120) { unit = 'hour'; n = Math.round(min / 60); }
      try { return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(n, unit); } catch (e) { return ''; }
    }

    // -- state --
    function mode() { for (var i = 0; i < radios.length; i++) if (radios[i].checked) return radios[i].value; return live ? 'keep' : 'now'; }
    function target() { return typeof opts.targetStatus === 'function' ? opts.targetStatus() : stored; }
    /** Does timing apply to this save (not when going to draft, pending or private)? */
    function applies() { var t = target(); return canPublish && (t === 'published' || t === 'scheduled'); }
    function usesTime() { var m = mode(); return applies() && (m === 'schedule' || m === 'date'); }
    function chosen() { return dateIn ? toInstant(dateIn.value, timeIn.value) : NaN; }

    function validate() {
      if (!usesTime()) return null;
      if (!dateIn.value || !timeIn.value) return 'Choose a date and a time.';
      var t = chosen();
      if (isNaN(t)) return 'Choose a valid date and time.';
      if (mode() === 'schedule' && t <= serverNow()) return 'Pick a time in the future, or choose Now or Set date.';
      if (t > MAX) return 'Choose a date before 19 January 2038.';
      return null;
    }
    /** What the save will do, in a button's words. */
    function label() {
      var t = target(), m = mode();
      if (!canPublish) return (stored === 'published' || stored === 'scheduled' || stored === 'private') ? 'Update' : 'Submit for review';
      if (stored !== 'published' && stored !== 'scheduled' && stored !== 'private') {
        if (m === 'schedule') return 'Schedule';
        if (m === 'date') { var cd = chosen(); return !isNaN(cd) && cd > serverNow() ? 'Schedule' : 'Publish'; }
        return 'Publish';
      }
      if (t !== 'published' && t !== 'scheduled') return 'Update';
      if (stored === 'scheduled') {
        if (m === 'schedule') return 'Update';
        if (m === 'date') { var cs = chosen(); return !isNaN(cs) && cs > serverNow() ? 'Update' : 'Publish'; }
        return 'Publish now';
      }
      if (stored === 'published') { var c = chosen(); return m === 'date' && !isNaN(c) && c > serverNow() ? 'Schedule' : 'Update'; }
      return 'Update';
    }
    /** A sentence to confirm before saving, or null. */
    function confirmMessage() {
      if (!(live && applies() && mode() === 'date')) return null;
      var c = chosen();
      if (isNaN(c) || c <= serverNow()) return null;
      return 'This ' + noun + ' is live. Scheduling it takes it off the site until ' + describe(c) + '.';
    }
    function showError(msg) {
      if (!errorEl) return;
      errorEl.textContent = msg || ''; errorEl.hidden = !msg;
      [dateIn, timeIn].forEach(function (x) { if (!x) return; if (msg) x.setAttribute('aria-invalid', 'true'); else x.removeAttribute('aria-invalid'); });
      if (msg && when) when.hidden = false;
    }
    function render(o) {
      o = o || {};
      if (!canPublish) return;
      var on = applies();
      if (modesBox) modesBox.hidden = !on;
      var timed = usesTime();
      if (when) when.hidden = !timed;
      // Only Schedule needs a future day; Set date may go back in time (1.2.88).
      if (!live && dateIn) { if (mode() === 'schedule' && cfg.min_date) dateIn.setAttribute('min', cfg.min_date); else dateIn.removeAttribute('min'); }
      // First switch to Set date on a new post: start from now, ready to move back.
      if (timed && !live && mode() === 'date' && !dateIn.value && !timeIn.value) {
        dateIn.value = localDate(serverNow()); timeIn.value = localTime(serverNow());
      }
      // First switch to Schedule: the next whole hour, an hour ahead.
      if (timed && mode() === 'schedule' && !dateIn.value && !timeIn.value) {
        var next = serverNow() + 3600000, w = wall(next);
        next = toInstant(localDate(next), pad(w.h) + ':00') + (w.mi > 0 ? 3600000 : 0);
        dateIn.value = localDate(next); timeIn.value = localTime(next);
      }
      var text = '';
      if (on) {
        var c = chosen(), m = mode();
        if (timed && !isNaN(c)) {
          var rel = relative(c);
          if (m === 'date' && c <= serverNow()) text = live ? 'The publication date becomes ' + describe(c) + '. It stays live.' : 'Publishes now, dated ' + describe(c) + '.';
          else {
            text = 'Goes live ' + describe(c) + (rel ? ' — ' + rel : '') + '.';
            if (live) text += ' Until then it is taken off the site.';
          }
        } else if (!timed && (stored !== 'published' && stored !== 'private')) {
          text = 'Goes live as soon as you press ' + label() + '.';
        }
      }
      if (preview) { preview.textContent = text; preview.hidden = text === ''; }
      var showing = o.showErrors || (errorEl && !errorEl.hidden);
      showError(showing ? validate() : null);
    }

    var api = {
      el: host,
      mode: mode,
      applies: applies,
      validate: validate,
      label: label,
      confirmMessage: confirmMessage,
      describe: describe,
      toInstant: toInstant,
      focusTarget: function () { return radios[0] || null; },
      /** The fields the server reads (PostPublishing::resolve), for this save. */
      values: function () {
        if (!canPublish) return {};
        var v = { publish_mode: mode() };
        if (usesTime()) { v.publish_date = dateIn.value; v.publish_time = timeIn.value; }
        return v;
      },
      /** Show validation; returns the error or null. */
      check: function () { var e = validate(); render({ showErrors: !!e }); if (e) { var bad = (!dateIn.value || isNaN(chosen())) ? dateIn : timeIn; if (bad) bad.focus(); } return e; },
      showError: showError,
      render: render,
      onChange: changed.on,
      fields: { date: dateIn || null, time: timeIn || null, radios: radios }
    };
    render();
    return api;
  }

  window.BasehimPostFields = { categories: categories, tags: tags, publish: publish };
})();
