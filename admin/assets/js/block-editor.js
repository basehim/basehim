/* Basehim Block Editor — WordPress-style block editing with a public app
 * API. Apps extend it via window.BasehimEditor:
 *
 *   BasehimEditor.registerBlock(type, def)   — add a custom block type
 *   BasehimEditor.addToolbarButton(def)      — button in the editor top bar
 *   BasehimEditor.addBlockAction(def)        — action in every block's toolbar
 *   BasehimEditor.addSidebarPanel(def)       — panel in the inspector sidebar
 *   BasehimEditor.on(event, cb)              — init | change | select |
 *                                           block:add | block:remove | save
 *   BasehimEditor.addFilter(name, cb)        — save.data | load.data
 *   BasehimEditor.getBlocks()/setBlocks()/insertBlock()/updateBlock()/
 *   BasehimEditor.removeBlock()/getSelected()/serialize()/refresh()
 *
 * Content serializes to {"version":1,"blocks":[{id,type,data}...]} in the
 * form's content field with content_format=blocks. Server-side rendering is
 * App\Services\BlockRenderer (+ `blocks.render.{type}` PHP filter).
 */
(function () {
  'use strict';

  var mount = document.getElementById('bh-block-editor');
  if (!mount) { defineApiStub(); return; }

  var CONFIG = window.BasehimEditorConfig || {};
  var contentField = document.querySelector('textarea[name="content"], input[name="content"]');
  var form = contentField ? contentField.closest('form') : null;

  // ---------------- state ----------------
  var blocks = [];
  var selectedId = null;
  var uid = function () { return 'b_' + Math.random().toString(36).slice(2, 10); };

  // ---------------- events + filters (app surface) ----------------
  var listeners = {};   // event -> [cb]
  var filters = {};     // name -> [cb]
  function on(ev, cb) { (listeners[ev] = listeners[ev] || []).push(cb); }
  function emit(ev) {
    var args = Array.prototype.slice.call(arguments, 1);
    (listeners[ev] || []).forEach(function (cb) { try { cb.apply(null, args); } catch (e) { console.error('[BasehimEditor] listener error', e); } });
  }
  function addFilter(name, cb) { (filters[name] = filters[name] || []).push(cb); }
  function applyFilters(name, value) {
    var args = Array.prototype.slice.call(arguments, 2);
    (filters[name] || []).forEach(function (cb) {
      try { var r = cb.apply(null, [value].concat(args)); if (r !== undefined) value = r; }
      catch (e) { console.error('[BasehimEditor] filter error', e); }
    });
    return value;
  }

  // ---------------- block registry ----------------
  var registry = {};      // type -> definition
  var toolbarButtons = [];
  var blockActions = [];
  var sidebarPanels = [];

  function registerBlock(type, def) {
    def = def || {};
    registry[type] = {
      type: type,
      title: def.title || type,
      icon: def.icon || 'fa-cube',
      category: def.category || 'custom',
      defaults: def.defaults || {},
      // edit(el, block, api): build the block's editing UI inside el.
      edit: def.edit || null,
      // inspector(el, block, api): optional per-block sidebar settings UI.
      inspector: def.inspector || null,
      // For app blocks with no PHP renderer: save(block) -> html string
      // stored in data.html so the server fallback can output it.
      save: def.save || null,
      keywords: def.keywords || []
    };
    renderInserterIndex();
  }

  // ---------------- helpers ----------------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function blockById(id) { for (var i = 0; i < blocks.length; i++) if (blocks[i].id === id) return blocks[i]; return null; }
  function indexOfId(id) { for (var i = 0; i < blocks.length; i++) if (blocks[i].id === id) return i; return -1; }
  function makeBlock(type, data) {
    var def = registry[type] || {};
    var d = {};
    var src = def.defaults || {};
    Object.keys(src).forEach(function (k) { d[k] = src[k]; });
    Object.keys(data || {}).forEach(function (k) { d[k] = data[k]; });
    return { id: uid(), type: type, data: d };
  }

  function changed() {
    syncField();
    // The editor writes into a hidden field, which fires no input event — flag
    // the change so the page's unsaved-work guard can see it.
    try { window.BasehimEditorDirty = true; } catch (e) {}
    emit('change', getBlocks());
  }

  // ---------------- serialization ----------------
  function serialize() {
    var out = blocks.map(function (b) {
      var data = b.data;
      var def = registry[b.type];
      if (def && typeof def.save === 'function') {
        try { var html = def.save(JSON.parse(JSON.stringify(b))); if (typeof html === 'string') { data = Object.assign({}, data, { html: html }); } }
        catch (e) { console.error('[BasehimEditor] save() failed for ' + b.type, e); }
      }
      return { id: b.id, type: b.type, data: data };
    });
    var doc = { version: 1, blocks: out };
    doc = applyFilters('save.data', doc);
    return JSON.stringify(doc);
  }
  function syncField() { if (contentField) contentField.value = serialize(); }

  function load(json) {
    var doc = null;
    try { doc = JSON.parse(json); } catch (e) { doc = null; }
    if (doc && Array.isArray(doc.blocks)) {
      doc = applyFilters('load.data', doc);
      blocks = doc.blocks.map(function (b) { return { id: b.id || uid(), type: b.type || 'paragraph', data: b.data || {} }; });
    } else if (json && json.trim() !== '') {
      // Legacy HTML/markdown content: wrap in a single html block so nothing is lost.
      blocks = [makeBlock('html', { html: json })];
    } else {
      blocks = [makeBlock('paragraph', {})];
    }
  }

  // ---------------- rendering ----------------
  var listEl, sidebarEl, inserterEl;

  /* ---------------- drag to reorder ----------------
     Only the handle is draggable, so selecting text inside a block still works.
     Reordering happens in the `blocks` model and then re-renders — the DOM is
     never shuffled directly, so the model stays the source of truth. */
  var dragId = null;

  function attachDrag(wrap, b) {
    var handle = wrap.querySelector('.nbe-drag');
    if (!handle) return;

    handle.addEventListener('dragstart', function (ev) {
      dragId = b.id;
      wrap.classList.add('is-dragging');
      try {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', b.id);
        ev.dataTransfer.setDragImage(wrap, 24, 18);
      } catch (e) {}
    });
    handle.addEventListener('dragend', function () {
      wrap.classList.remove('is-dragging');
      clearDropMarks();
      dragId = null;
    });

    wrap.addEventListener('dragover', function (ev) {
      if (!dragId || dragId === b.id) return;
      ev.preventDefault();
      try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
      var r = wrap.getBoundingClientRect();
      var before = (ev.clientY - r.top) < r.height / 2;
      wrap.classList.toggle('nbe-drop-before', before);
      wrap.classList.toggle('nbe-drop-after', !before);
    });
    wrap.addEventListener('dragleave', function () {
      wrap.classList.remove('nbe-drop-before', 'nbe-drop-after');
    });
    wrap.addEventListener('drop', function (ev) {
      if (!dragId || dragId === b.id) return;
      ev.preventDefault();
      var r = wrap.getBoundingClientRect();
      var before = (ev.clientY - r.top) < r.height / 2;
      moveBlock(dragId, b.id, before);
      clearDropMarks();
      dragId = null;
    });
  }

  function clearDropMarks() {
    if (!listEl) return;
    Array.prototype.forEach.call(listEl.querySelectorAll('.nbe-drop-before, .nbe-drop-after'), function (el) {
      el.classList.remove('nbe-drop-before', 'nbe-drop-after');
    });
  }

  function moveBlock(fromId, toId, before) {
    var from = -1, i;
    for (i = 0; i < blocks.length; i++) if (blocks[i].id === fromId) { from = i; break; }
    if (from < 0) return;
    var moved = blocks.splice(from, 1)[0];
    var to = -1;
    for (i = 0; i < blocks.length; i++) if (blocks[i].id === toId) { to = i; break; }
    if (to < 0) { blocks.splice(from, 0, moved); return; }   // target vanished — put it back
    blocks.splice(before ? to : to + 1, 0, moved);
    selectedId = moved.id;
    renderAll();
    changed();
  }

  function renderAll() {
    listEl.innerHTML = '';
    blocks.forEach(function (b) { listEl.appendChild(renderBlockShell(b)); });
    renderSidebar();
  }

  function renderBlockShell(b) {
    var def = registry[b.type];
    var wrap = document.createElement('div');
    wrap.className = 'nbe-block' + (b.id === selectedId ? ' is-selected' : '');
    wrap.setAttribute('data-id', b.id);
    wrap.setAttribute('data-type', b.type);

    // per-block toolbar
    var bar = document.createElement('div');
    bar.className = 'nbe-block__bar';
    bar.innerHTML =
      '<span class="nbe-drag" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">'
      + BasehimIcon('bars-2', 'w-3.5 h-3.5') + '</span>'
      + '<span class="nbe-block__type">' + BasehimIcon(def ? def.icon : 'fa-cube', 'w-4 h-4 inline-block align-text-bottom') + ' ' + esc(def ? def.title : b.type) + '</span>'
      + '<span class="nbe-block__spacer"></span>'
      + btn('up', 'fa-arrow-up', 'Move up') + btn('down', 'fa-arrow-down', 'Move down')
      + btn('dup', 'fa-clone', 'Duplicate')
      + blockActions.map(function (a, i) {
          try { if (a.when && !a.when(b)) return ''; } catch (e) { return ''; }
          return btn('act-' + i, a.icon || 'fa-bolt', a.title || 'Action');
        }).join('')
      + btn('del', 'fa-trash', 'Delete');
    function btn(act, icon, title) { return '<button type="button" class="nbe-bbtn" data-act="' + act + '" title="' + esc(title) + '">' + BasehimIcon(icon) + '</button>'; }
    wrap.appendChild(bar);
    attachDrag(wrap, b);

    // block body — hand off to the block's edit()
    var body = document.createElement('div');
    body.className = 'nbe-block__body';
    wrap.appendChild(body);
    if (def && typeof def.edit === 'function') {
      try { def.edit(body, b, api); }
      catch (e) { body.innerHTML = '<div class="nbe-error">Block failed to render: ' + esc(e.message) + '</div>'; }
    } else {
      body.innerHTML = '<div class="nbe-error">Unknown block type "' + esc(b.type) + '" — its app may be deactivated. Content is preserved.</div>';
    }

    // add-below affordance
    var adder = document.createElement('button');
    adder.type = 'button';
    adder.className = 'nbe-addbelow';
    adder.innerHTML = ''+BasehimIcon('plus','w-4 h-4')+'';
    adder.title = 'Add block below';
    adder.addEventListener('click', function (ev) { ev.stopPropagation(); openInserter(indexOfId(b.id) + 1); });
    wrap.appendChild(adder);

    wrap.addEventListener('click', function () { select(b.id); });
    bar.addEventListener('click', function (ev) {
      var t = ev.target.closest('.nbe-bbtn'); if (!t) return;
      ev.stopPropagation();
      var act = t.getAttribute('data-act');
      var idx = indexOfId(b.id);
      if (act === 'up' && idx > 0) { blocks.splice(idx - 1, 0, blocks.splice(idx, 1)[0]); renderAll(); changed(); }
      else if (act === 'down' && idx < blocks.length - 1) { blocks.splice(idx + 1, 0, blocks.splice(idx, 1)[0]); renderAll(); changed(); }
      else if (act === 'dup') { var copy = JSON.parse(JSON.stringify(b)); copy.id = uid(); blocks.splice(idx + 1, 0, copy); renderAll(); changed(); }
      else if (act === 'del') { removeBlock(b.id); }
      else if (act && act.indexOf('act-') === 0) {
        var a = blockActions[parseInt(act.slice(4), 10)];
        if (a && a.onClick) { try { a.onClick(b, api); } catch (e) { console.error(e); } }
      }
    });
    return wrap;
  }

  function rerenderBlock(id) {
    var b = blockById(id); if (!b) return;
    var old = listEl.querySelector('[data-id="' + id + '"]');
    if (old) listEl.replaceChild(renderBlockShell(b), old);
  }

  // ---------------- selection + sidebar ----------------
  var sideTab = 'post';   // 'post' | 'block'
  var adoptedSettings = null;  // persistent holder for real post-settings cards
  function select(id) {
    if (selectedId === id) { return; }
    selectedId = id;
    listEl.querySelectorAll('.nbe-block').forEach(function (el) {
      el.classList.toggle('is-selected', el.getAttribute('data-id') === id);
    });
    // Auto-switch: selecting a block shows Block tab; deselecting shows Post.
    sideTab = id ? 'block' : 'post';
    renderSidebar();
    emit('select', blockById(id));
  }

  function renderSidebar() {
    sidebarEl.innerHTML = '';

    // --- tab bar (Post | Block) ---
    var tabs = document.createElement('div');
    tabs.className = 'nbe-side__tabs';
    tabs.innerHTML =
      '<button type="button" class="nbe-side__tab' + (sideTab === 'post' ? ' is-active' : '') + '" data-tab="post">'+BasehimIcon('document-text','w-4 h-4')+' Post</button>'
      + '<button type="button" class="nbe-side__tab' + (sideTab === 'block' ? ' is-active' : '') + '" data-tab="block">'+BasehimIcon('cube','w-4 h-4')+' Block</button>';
    sidebarEl.appendChild(tabs);
    tabs.addEventListener('click', function (ev) {
      var t = ev.target.closest('.nbe-side__tab'); if (!t) return;
      sideTab = t.getAttribute('data-tab');
      renderSidebar();
    });

    var body = document.createElement('div');
    body.className = 'nbe-side__body';
    sidebarEl.appendChild(body);

    if (sideTab === 'block') { renderBlockTab(body); }
    else { renderPostTab(body); }
  }

  function renderBlockTab(body) {
    var b = blockById(selectedId);
    if (b) {
      var def = registry[b.type];
      var head = document.createElement('div');
      head.className = 'nbe-side__head';
      head.innerHTML = BasehimIcon(def ? def.icon : 'fa-cube', 'w-4 h-4 inline-block align-text-bottom') + ' ' + esc(def ? def.title : b.type);
      body.appendChild(head);
      if (def && typeof def.inspector === 'function') {
        var box = document.createElement('div');
        box.className = 'nbe-side__panel';
        body.appendChild(box);
        try { def.inspector(box, b, api); } catch (e) { box.textContent = 'Inspector error: ' + e.message; }
      } else {
        var none = document.createElement('div');
        none.className = 'nbe-side__empty';
        none.textContent = 'This block has no settings.';
        body.appendChild(none);
      }
      // Quick block actions.
      var act = document.createElement('div');
      act.className = 'nbe-side__panel';
      var dup = document.createElement('button');
      dup.type = 'button'; dup.className = 'nbe-side__btn';
      dup.innerHTML = ''+BasehimIcon('square-2-stack','w-4 h-4')+' Duplicate block';
      dup.addEventListener('click', function () {
        var copy = JSON.parse(JSON.stringify(b.data || {}));
        insertBlock(b.type, copy, indexOfId(b.id) + 1);
      });
      var del = document.createElement('button');
      del.type = 'button'; del.className = 'nbe-side__btn nbe-side__btn--danger';
      del.innerHTML = ''+BasehimIcon('trash','w-4 h-4')+' Delete block';
      del.addEventListener('click', function () { removeBlock(b.id); });
      act.appendChild(dup); act.appendChild(del);
      body.appendChild(act);
    } else {
      body.innerHTML = '<div class="nbe-side__empty">Select a block to edit its settings.</div>';
    }
  }

  function renderPostTab(body) {
    // Templates (reusable patterns) — insert whole block groups.
    var tpl = document.createElement('div');
    tpl.className = 'nbe-side__panel';
    tpl.innerHTML = '<div class="nbe-side__ptitle">'+BasehimIcon('squares-plus','w-4 h-4')+' Templates</div>'
      + '<div class="nbe-tpl" id="nbe-tpl-list"><div class="nbe-side__empty">Loading templates…</div></div>';
    body.appendChild(tpl);
    loadTemplatesInto(tpl.querySelector('#nbe-tpl-list'));

    // Post controls. If the page provides a container of real post-settings
    // cards (#bh-post-settings), adopt them ONCE into a persistent holder so
    // they survive tab switches (renderSidebar clears sidebarEl each time).
    // They are the single source of truth — no mirroring.
    if (!adoptedSettings) {
      var realSettings = document.getElementById('bh-post-settings');
      if (realSettings && realSettings.children.length) {
        adoptedSettings = document.createElement('div');
        adoptedSettings.className = 'nbe-side__realhost';
        while (realSettings.firstChild) adoptedSettings.appendChild(realSettings.firstChild);
      }
    }
    if (adoptedSettings) {
      body.appendChild(adoptedSettings);
    } else {
      var box = document.createElement('div');
      box.className = 'nbe-side__panel';
      box.innerHTML = '<div class="nbe-side__ptitle">'+BasehimIcon('adjustments-horizontal','w-4 h-4')+' Post</div>';
      body.appendChild(box);
      mirrorControl(box, 'Status', 'select[name="status"]');
      mirrorTaxonomy(box, 'Categories', 'category');
      mirrorTaxonomy(box, 'Tags', 'tag');
    }

    // Save proxy.
    var save = document.createElement('button');
    save.type = 'button';
    save.className = 'nbe-side__save';
    save.innerHTML = ''+BasehimIcon('document-check','w-4 h-4')+' Save';
    save.addEventListener('click', function () {
      if (form) { if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
    });
    body.appendChild(save);

    // App panels (kept — shown under Post controls).
    sidebarPanels.forEach(function (p) {
      var pbox = document.createElement('div');
      pbox.className = 'nbe-side__panel nbe-side__panel--app';
      pbox.innerHTML = '<div class="nbe-side__ptitle">' + esc(p.title || p.id) + '</div>';
      var inner = document.createElement('div');
      pbox.appendChild(inner);
      body.appendChild(pbox);
      try { p.render(inner, api); } catch (e) { inner.textContent = 'Panel error: ' + e.message; }
    });
  }

  // Mirror a single form control (select) into the sidebar; two-way synced.
  function mirrorControl(container, label, selector) {
    var src = document.querySelector(selector);
    if (!src) return;
    var wrap = document.createElement('label');
    wrap.className = 'nbe-side__field';
    wrap.innerHTML = '<span>' + esc(label) + '</span>';
    var sel = document.createElement('select');
    sel.className = 'nbe-side__input';
    Array.prototype.forEach.call(src.options, function (o) {
      var opt = document.createElement('option');
      opt.value = o.value; opt.textContent = o.textContent;
      if (o.selected) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', function () { src.value = sel.value; });
    wrap.appendChild(sel);
    container.appendChild(wrap);
  }

  // Mirror category/tag checkboxes: the form uses term_ids[] checkboxes grouped
  // under headings; we clone them as a compact checklist that drives the originals.
  function mirrorTaxonomy(container, label, kind) {
    // Find the form card whose heading matches the taxonomy label.
    var cards = document.querySelectorAll('form .bg-white');
    var target = null;
    cards.forEach(function (c) {
      var h = c.querySelector('h3');
      if (h && h.textContent.trim().toLowerCase() === label.toLowerCase()) target = c;
    });
    if (!target) return;
    var boxes = target.querySelectorAll('input[name="term_ids[]"]');
    if (!boxes.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'nbe-side__field';
    wrap.innerHTML = '<span>' + esc(label) + '</span>';
    var list = document.createElement('div');
    list.className = 'nbe-side__checks';
    boxes.forEach(function (orig) {
      var name = orig.parentNode.textContent.trim();
      var row = document.createElement('label');
      row.className = 'nbe-side__check';
      var cb = document.createElement('input');
      cb.type = 'checkbox'; cb.checked = orig.checked;
      cb.addEventListener('change', function () { orig.checked = cb.checked; });
      orig.addEventListener('change', function () { cb.checked = orig.checked; });
      row.appendChild(cb);
      row.appendChild(document.createTextNode(' ' + name));
      list.appendChild(row);
    });
    wrap.appendChild(list);
    container.appendChild(wrap);
  }

  // Fetch reusable templates and render as insertable buttons.
  var templatesCache = null;
  function loadTemplatesInto(el) {
    function render(list) {
      if (!list || !list.length) { el.innerHTML = '<div class="nbe-side__empty">No templates yet. Create reusable patterns under Posts → Templates.</div>'; return; }
      el.innerHTML = '';
      list.forEach(function (t) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nbe-tpl__item';
        btn.innerHTML = ''+BasehimIcon('squares-plus','w-4 h-4')+' <span>' + esc(t.title) + '</span><em>' + (t.blocks.length) + ' block' + (t.blocks.length === 1 ? '' : 's') + '</em>';
        btn.addEventListener('click', function () { insertBlocks(t.blocks); });
        el.appendChild(btn);
      });
    }
    if (templatesCache) { render(templatesCache); return; }
    var url = (CONFIG.templatesUrl || ((CONFIG.base || '') + '/admin/posts/editor/templates'));
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { templatesCache = (d && d.templates) || []; render(templatesCache); })
      .catch(function () { el.innerHTML = '<div class="nbe-side__empty">Could not load templates.</div>'; });
  }

  // ---------------- inserter ----------------
  function renderInserterIndex() { /* lazily built on open; hook for future */ }
  function openInserter(atIndex) {
    closeInserter();
    var ov = document.createElement('div');
    ov.className = 'nbe-inserter';
    ov.id = 'nbe-inserter';
    var cats = {};
    Object.keys(registry).forEach(function (t) {
      var d = registry[t];
      (cats[d.category] = cats[d.category] || []).push(d);
    });
    var listHtml = Object.keys(cats).map(function (c) {
      return '<div class="nbe-ins__cat">' + esc(c) + '</div>'
        + cats[c].map(function (d) {
            return '<button type="button" class="nbe-ins__item" data-type="' + esc(d.type) + '" data-kw="' + esc((d.title + ' ' + d.keywords.join(' ')).toLowerCase()) + '">'
              + BasehimIcon(d.icon) + '<span>' + esc(d.title) + '</span></button>';
          }).join('');
    }).join('');
    ov.innerHTML = '<div class="nbe-ins__box">'
      + '<input type="text" class="nbe-ins__search" placeholder="Search blocks…">'
      + '<div class="nbe-ins__list">' + listHtml + '</div></div>';
    document.body.appendChild(ov);
    var search = ov.querySelector('.nbe-ins__search');
    search.focus();
    search.addEventListener('input', function () {
      var q = search.value.toLowerCase();
      ov.querySelectorAll('.nbe-ins__item').forEach(function (it) {
        it.style.display = !q || it.getAttribute('data-kw').indexOf(q) >= 0 ? '' : 'none';
      });
    });
    ov.addEventListener('click', function (ev) {
      var it = ev.target.closest('.nbe-ins__item');
      if (it) { insertBlock(it.getAttribute('data-type'), {}, atIndex); closeInserter(); return; }
      if (ev.target === ov) closeInserter();
    });
    document.addEventListener('keydown', insEsc);
  }
  function insEsc(e) { if (e.key === 'Escape') closeInserter(); }
  function closeInserter() {
    var ov = document.getElementById('nbe-inserter');
    if (ov) ov.remove();
    document.removeEventListener('keydown', insEsc);
  }

  // ---------------- public API ops ----------------
  function getBlocks() { return JSON.parse(JSON.stringify(blocks)); }
  function setBlocks(list) {
    blocks = (list || []).map(function (b) { return { id: b.id || uid(), type: b.type, data: b.data || {} }; });
    renderAll(); changed();
  }
  function insertBlock(type, data, atIndex) {
    if (!registry[type]) { console.warn('[BasehimEditor] unknown block type', type); return null; }
    var b = makeBlock(type, data);
    if (atIndex == null || atIndex < 0 || atIndex > blocks.length) atIndex = blocks.length;
    blocks.splice(atIndex, 0, b);
    renderAll(); select(b.id); changed();
    emit('block:add', b);
    return b.id;
  }
  // Insert a group of blocks (a template/pattern) at the end, preserving order.
  function insertBlocks(list, atIndex) {
    if (!Array.isArray(list) || !list.length) return;
    if (atIndex == null || atIndex < 0 || atIndex > blocks.length) atIndex = blocks.length;
    var made = list.map(function (b) { return makeBlock(b.type, JSON.parse(JSON.stringify(b.data || {}))); });
    // Drop known-unregistered types so a bad template can't break the editor.
    made = made.filter(function (b) { return registry[b.type]; });
    if (!made.length) { console.warn('[BasehimEditor] template had no known block types'); return; }
    Array.prototype.splice.apply(blocks, [atIndex, 0].concat(made));
    renderAll(); select(made[made.length - 1].id); changed();
    emit('template:insert', made);
  }
  function updateBlock(id, data, opts) {
    var b = blockById(id); if (!b) return;
    Object.keys(data || {}).forEach(function (k) { b.data[k] = data[k]; });
    if (!opts || opts.rerender !== false) rerenderBlock(id);
    changed();
  }
  function removeBlock(id) {
    var idx = indexOfId(id); if (idx < 0) return;
    var b = blocks.splice(idx, 1)[0];
    if (!blocks.length) blocks.push(makeBlock('paragraph', {}));
    if (selectedId === id) selectedId = null;
    renderAll(); changed();
    emit('block:remove', b);
  }

  var api = {
    registerBlock: registerBlock,
    addToolbarButton: function (d) { toolbarButtons.push(d); renderTopbar(); },
    addBlockAction: function (d) { blockActions.push(d); renderAll(); },
    addSidebarPanel: function (d) { sidebarPanels.push(d); renderSidebar(); },
    on: on, addFilter: addFilter,
    getBlocks: getBlocks, setBlocks: setBlocks,
    insertBlock: insertBlock, insertBlocks: insertBlocks, updateBlock: updateBlock, removeBlock: removeBlock,
    getSelected: function () { return blockById(selectedId); },
    deselect: function () { select(null); },
    serialize: serialize, refresh: renderAll,
    config: CONFIG, version: '1.1.0'
  };

  // ---------------- core blocks ----------------
  function editableText(el, b, field, tag, placeholder) {
    var ed = document.createElement(tag || 'div');
    ed.className = 'nbe-text';
    ed.contentEditable = 'true';
    ed.setAttribute('data-placeholder', placeholder || 'Type something…');
    ed.innerHTML = b.data[field] || '';
    if (b.data.align) ed.style.textAlign = b.data.align;
    ed.addEventListener('input', function () { updateBlock(b.id, (function (o) { o[field] = ed.innerHTML; return o; })({}), { rerender: false }); });
    ed.addEventListener('keydown', function (e) {
      // Enter at end of a paragraph -> new paragraph below (WordPress feel).
      if (e.key === 'Enter' && !e.shiftKey && b.type === 'paragraph') {
        e.preventDefault();
        insertBlock('paragraph', {}, indexOfId(b.id) + 1);
        var nb = listEl.querySelector('.nbe-block.is-selected .nbe-text');
        if (nb) nb.focus();
      }
    });
    el.appendChild(ed);
    return ed;
  }

  registerBlock('paragraph', {
    title: 'Paragraph', icon: 'fa-paragraph', category: 'text', keywords: ['text', 'p'],
    defaults: { text: '', align: '' },
    edit: function (el, b) { editableText(el, b, 'text', 'p', 'Start writing…'); },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Alignment</label>';
      var sel = document.createElement('select'); sel.className = 'nbe-in';
      ['', 'left', 'center', 'right'].forEach(function (v) {
        var o = document.createElement('option'); o.value = v; o.textContent = v || 'default'; if (b.data.align === v) o.selected = true; sel.appendChild(o);
      });
      sel.addEventListener('change', function () { api.updateBlock(b.id, { align: sel.value }); });
      el.appendChild(sel);
    }
  });

  registerBlock('heading', {
    title: 'Heading', icon: 'fa-heading', category: 'text', keywords: ['h2', 'h3', 'title'],
    defaults: { text: '', level: 2, align: '' },
    edit: function (el, b) {
      var ed = editableText(el, b, 'text', 'h' + (b.data.level || 2), 'Heading…');
      ed.classList.add('nbe-h');
    },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Level</label>';
      var row = document.createElement('div'); row.className = 'nbe-btnrow';
      [2, 3, 4].forEach(function (l) {
        var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'nbe-mini' + (b.data.level === l ? ' is-on' : ''); bt.textContent = 'H' + l;
        bt.addEventListener('click', function () { api.updateBlock(b.id, { level: l }); api.refresh(); });
        row.appendChild(bt);
      });
      el.appendChild(row);
    }
  });

  registerBlock('image', {
    title: 'Image', icon: 'fa-image', category: 'media', keywords: ['photo', 'picture', 'media'],
    defaults: { url: '', alt: '', caption: '', align: '' },
    edit: function (el, b, api) {
      if (!b.data.url) {
        var pick = document.createElement('div');
        pick.className = 'nbe-imgpick';
        pick.innerHTML = ''+BasehimIcon('photo','w-4 h-4')+'<span>Choose from Media Library</span>';
        pick.addEventListener('click', function () {
          if (window.BasehimMedia && BasehimMedia.openPicker) {
            BasehimMedia.openPicker({ onSelect: function (m) {
              api.updateBlock(b.id, { url: m.url || '', alt: m.alt_text || '', caption: b.data.caption || m.caption || '' });
            }});
          } else {
            var url = window.prompt('Image URL:', '');
            if (url) api.updateBlock(b.id, { url: url });
          }
        });
        el.appendChild(pick);
        return;
      }
      var fig = document.createElement('figure');
      fig.className = 'nbe-fig';
      fig.innerHTML = '<img src="' + esc(b.data.url) + '" alt="">';
      el.appendChild(fig);
      var cap = document.createElement('div');
      cap.className = 'nbe-cap'; cap.contentEditable = 'true';
      cap.setAttribute('data-placeholder', 'Caption (optional)');
      cap.innerHTML = b.data.caption || '';
      cap.addEventListener('input', function () { api.updateBlock(b.id, { caption: cap.innerHTML }, { rerender: false }); });
      el.appendChild(cap);
    },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Image URL</label>';
      var url = document.createElement('input'); url.className = 'nbe-in'; url.value = b.data.url || '';
      url.addEventListener('change', function () { api.updateBlock(b.id, { url: url.value }); });
      el.appendChild(url);
      var l2 = document.createElement('label'); l2.className = 'nbe-lbl'; l2.textContent = 'Alt text'; el.appendChild(l2);
      var alt = document.createElement('input'); alt.className = 'nbe-in'; alt.value = b.data.alt || '';
      alt.addEventListener('change', function () { api.updateBlock(b.id, { alt: alt.value }, { rerender: false }); });
      el.appendChild(alt);
    }
  });

  registerBlock('list', {
    title: 'List', icon: 'fa-list', category: 'text', keywords: ['bullet', 'ol', 'ul'],
    defaults: { style: 'ul', items: [''] },
    edit: function (el, b, api) {
      var tag = b.data.style === 'ol' ? 'ol' : 'ul';
      var list = document.createElement(tag);
      list.className = 'nbe-list'; list.contentEditable = 'true';
      list.innerHTML = (b.data.items && b.data.items.length ? b.data.items : ['']).map(function (i) { return '<li>' + i + '</li>'; }).join('');
      list.addEventListener('input', function () {
        var items = Array.prototype.map.call(list.querySelectorAll('li'), function (li) { return li.innerHTML; });
        api.updateBlock(b.id, { items: items }, { rerender: false });
      });
      el.appendChild(list);
    },
    inspector: function (el, b, api) {
      var row = document.createElement('div'); row.className = 'nbe-btnrow';
      [['ul', 'Bulleted'], ['ol', 'Numbered']].forEach(function (p) {
        var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'nbe-mini' + (b.data.style === p[0] ? ' is-on' : ''); bt.textContent = p[1];
        bt.addEventListener('click', function () { api.updateBlock(b.id, { style: p[0] }); });
        row.appendChild(bt);
      });
      el.appendChild(row);
    }
  });

  registerBlock('quote', {
    title: 'Quote', icon: 'fa-quote-left', category: 'text', keywords: ['blockquote', 'cite'],
    defaults: { text: '', cite: '' },
    edit: function (el, b, api) {
      var q = document.createElement('blockquote'); q.className = 'nbe-quote';
      var t = editableText(q, b, 'text', 'p', 'Quote…');
      var c = document.createElement('cite'); c.contentEditable = 'true';
      c.setAttribute('data-placeholder', '— citation'); c.innerHTML = b.data.cite || '';
      c.addEventListener('input', function () { api.updateBlock(b.id, { cite: c.innerHTML }, { rerender: false }); });
      q.appendChild(c); el.appendChild(q);
    }
  });

  registerBlock('code', {
    title: 'Code', icon: 'fa-code', category: 'text', keywords: ['pre', 'snippet'],
    defaults: { code: '', language: '' },
    edit: function (el, b, api) {
      var ta = document.createElement('textarea');
      ta.className = 'nbe-codearea'; ta.rows = 6; ta.value = b.data.code || '';
      ta.placeholder = '// code';
      ta.addEventListener('input', function () { api.updateBlock(b.id, { code: ta.value }, { rerender: false }); });
      el.appendChild(ta);
    },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Language</label>';
      var i = document.createElement('input'); i.className = 'nbe-in'; i.value = b.data.language || ''; i.placeholder = 'php, js…';
      i.addEventListener('change', function () { api.updateBlock(b.id, { language: i.value }, { rerender: false }); });
      el.appendChild(i);
    }
  });

  registerBlock('html', {
    title: 'Custom HTML', icon: 'fa-file-code', category: 'text', keywords: ['raw', 'embed'],
    defaults: { html: '' },
    edit: function (el, b, api) {
      var ta = document.createElement('textarea');
      ta.className = 'nbe-codearea'; ta.rows = 6; ta.value = b.data.html || '';
      ta.placeholder = '<div>raw html…</div>';
      ta.addEventListener('input', function () { api.updateBlock(b.id, { html: ta.value }, { rerender: false }); });
      el.appendChild(ta);
    }
  });

  registerBlock('divider', {
    title: 'Divider', icon: 'fa-minus', category: 'layout', keywords: ['hr', 'separator'],
    defaults: {},
    edit: function (el) { el.innerHTML = '<hr class="nbe-hr">'; }
  });

  registerBlock('spacer', {
    title: 'Spacer', icon: 'fa-arrows-up-down', category: 'layout', keywords: ['gap', 'space'],
    defaults: { height: 40 },
    edit: function (el, b) { el.innerHTML = '<div class="nbe-spacer" style="height:' + (parseInt(b.data.height, 10) || 40) + 'px"><span>' + (b.data.height || 40) + 'px</span></div>'; },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Height (px)</label>';
      var i = document.createElement('input'); i.type = 'number'; i.className = 'nbe-in'; i.value = b.data.height || 40; i.min = 4; i.max = 400;
      i.addEventListener('change', function () { api.updateBlock(b.id, { height: parseInt(i.value, 10) || 40 }); });
      el.appendChild(i);
    }
  });

  registerBlock('button', {
    title: 'Button', icon: 'fa-hand-pointer', category: 'layout', keywords: ['cta', 'link'],
    defaults: { text: 'Click here', url: '', align: '' },
    edit: function (el, b, api) {
      var a = document.createElement('span'); a.className = 'nbe-btnprev'; a.contentEditable = 'true';
      a.innerHTML = b.data.text || 'Click here';
      a.addEventListener('input', function () { api.updateBlock(b.id, { text: a.innerHTML }, { rerender: false }); });
      el.appendChild(a);
    },
    inspector: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Link URL</label>';
      var i = document.createElement('input'); i.className = 'nbe-in'; i.value = b.data.url || ''; i.placeholder = 'https://…';
      i.addEventListener('change', function () { api.updateBlock(b.id, { url: i.value }, { rerender: false }); });
      el.appendChild(i);
    }
  });

  registerBlock('embed', {
    title: 'Embed', icon: 'fa-square-share-nodes', category: 'media', keywords: ['iframe', 'video', 'youtube'],
    defaults: { url: '' },
    edit: function (el, b, api) {
      el.innerHTML = '<label class="nbe-lbl">Embed URL (iframe src)</label>';
      var i = document.createElement('input'); i.className = 'nbe-in'; i.value = b.data.url || ''; i.placeholder = 'https://www.youtube.com/embed/…';
      i.addEventListener('change', function () { api.updateBlock(b.id, { url: i.value }, { rerender: false }); });
      el.appendChild(i);
      if (b.data.url) {
        var p = document.createElement('div'); p.className = 'nbe-embedprev';
        p.innerHTML = '<iframe src="' + esc(b.data.url) + '" frameborder="0"></iframe>';
        el.appendChild(p);
      }
    }
  });

  // ---------------- widget block (app/theme widgets) ----------------
  // Only registered when the server reports available editor widgets, so the
  // block doesn't clutter the inserter on installs with no widgets.
  var WIDGETS = (CONFIG.widgets || []);
  if (WIDGETS.length) {
    var widgetById = {};
    WIDGETS.forEach(function (w) { widgetById[w.key] = w; });

    function previewWidget(el, b) {
      var key = b.data.widget || '';
      var host = el.querySelector('.nbe-widget-preview');
      if (!host) return;
      if (!key) { host.innerHTML = '<div class="nbe-widget-empty">Pick a widget above.</div>'; return; }
      host.innerHTML = '<div class="nbe-widget-empty">'+BasehimIcon('arrow-path','w-4 h-4 animate-spin')+' Loading preview…</div>';
      var fd = new FormData();
      fd.append('_csrf', CONFIG.csrf || '');
      fd.append('widget', key);
      fd.append('surface', 'editor');
      fd.append('settings', JSON.stringify(b.data.settings || {}));
      fetch(CONFIG.widgetRenderUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { host.innerHTML = d.ok ? (d.html || '<div class="nbe-widget-empty">(empty)</div>') : '<div class="nbe-widget-empty">' + esc(d.error || 'Preview failed') + '</div>'; })
        .catch(function () { host.innerHTML = '<div class="nbe-widget-empty">Preview unavailable</div>'; });
    }

    registerBlock('widget', {
      title: 'Widget', icon: 'fa-puzzle-piece', category: 'custom',
      keywords: ['widget', 'app', 'theme', 'dynamic'],
      defaults: { widget: (WIDGETS[0] && WIDGETS[0].key) || '', settings: {} },
      edit: function (el, b, api) {
        var sel = document.createElement('select');
        sel.className = 'nbe-in';
        sel.innerHTML = WIDGETS.map(function (w) {
          return '<option value="' + esc(w.key) + '"' + (w.key === b.data.widget ? ' selected' : '') + '>' + esc(w.title) + '</option>';
        }).join('');
        sel.addEventListener('change', function () {
          api.updateBlock(b.id, { widget: sel.value, settings: {} }, { rerender: false });
          b.data.widget = sel.value; b.data.settings = {};
          previewWidget(el, b);
        });
        el.appendChild(sel);
        var prev = document.createElement('div');
        prev.className = 'nbe-widget-preview';
        el.appendChild(prev);
        previewWidget(el, b);
      },
      inspector: function (el, b, api) {
        var w = widgetById[b.data.widget];
        if (!w || !w.fields || !w.fields.length) {
          el.innerHTML = '<div class="nbe-lbl">This widget has no settings.</div>';
          return;
        }
        b.data.settings = b.data.settings || {};
        w.fields.forEach(function (f) {
          var wrap = document.createElement('div'); wrap.style.marginBottom = '10px';
          wrap.innerHTML = '<label class="nbe-lbl">' + esc(f.label || f.key) + '</label>';
          var input;
          if (f.type === 'select' && Array.isArray(f.options)) {
            input = document.createElement('select'); input.className = 'nbe-in';
            input.innerHTML = f.options.map(function (o) {
              var val = (o && o.value !== undefined) ? o.value : o;
              var lbl = (o && o.label !== undefined) ? o.label : o;
              return '<option value="' + esc(val) + '"' + (String(b.data.settings[f.key]) === String(val) ? ' selected' : '') + '>' + esc(lbl) + '</option>';
            }).join('');
          } else if (f.type === 'checkbox') {
            input = document.createElement('input'); input.type = 'checkbox';
            input.checked = !!b.data.settings[f.key];
          } else {
            input = document.createElement('input');
            input.type = f.type === 'number' ? 'number' : 'text';
            input.className = 'nbe-in';
            input.value = b.data.settings[f.key] != null ? b.data.settings[f.key] : (f.default != null ? f.default : '');
          }
          input.addEventListener('change', function () {
            var s = b.data.settings || {};
            s[f.key] = f.type === 'checkbox' ? input.checked : input.value;
            api.updateBlock(b.id, { settings: s }, { rerender: false });
            b.data.settings = s;
            var host = document.querySelector('[data-block-id="' + b.id + '"] .nbe-widget-preview');
            if (host) { var fake = el.closest('[data-block-id]'); previewWidget(fake || document, b); }
          });
          wrap.appendChild(input);
          el.appendChild(wrap);
        });
      },
      // Server renders the widget from the block data; no client save() needed.
      save: null
    });
  }

  // ---------------- top bar (inline formatting + app buttons) ----------------
  var topbarEl;
  function renderTopbar() {
    if (!topbarEl) return;
    topbarEl.innerHTML = ''
      + fmt('bold', 'fa-bold', 'Bold (Ctrl+B)') + fmt('italic', 'fa-italic', 'Italic (Ctrl+I)')
      + fmt('underline', 'fa-underline', 'Underline') + fmt('link', 'fa-link', 'Link')
      + '<span class="nbe-top__sep"></span>'
      + '<button type="button" class="nbe-tbtn nbe-tbtn--add" data-cmd="insert">'+BasehimIcon('plus','w-4 h-4')+' Add block</button>'
      + toolbarButtons.map(function (t, i) {
          return '<button type="button" class="nbe-tbtn" data-cmd="plug-' + i + '" title="' + esc(t.title || '') + '">' + BasehimIcon(t.icon || 'fa-bolt') + (t.label ? ' ' + esc(t.label) : '') + '</button>';
        }).join('');
    function fmt(cmd, icon, title) { return '<button type="button" class="nbe-tbtn" data-cmd="' + cmd + '" title="' + title + '">' + BasehimIcon(icon) + '</button>'; }
  }

  // ---------------- boot ----------------
  function boot() {
    // The Post/Block settings sidebar can live OUTSIDE the editor (in the page's
    // main sidebar column) when CONFIG.sidebarTarget names an element. That
    // gives the canvas full width. Otherwise it renders inside the editor.
    var externalSide = CONFIG.sidebarTarget ? document.getElementById(CONFIG.sidebarTarget) : null;

    mount.innerHTML =
      '<div class="nbe' + (externalSide ? ' nbe--wide' : '') + '">'
      + '<div class="nbe-top" id="nbe-top"></div>'
      + '<div class="nbe-main">'
      +   '<div class="nbe-canvas"><div class="nbe-list" id="nbe-list"></div>'
      +     '<button type="button" class="nbe-append" id="nbe-append">'+BasehimIcon('plus','w-4 h-4')+' Add block</button></div>'
      +   (externalSide ? '' : '<div class="nbe-side" id="nbe-side"></div>')
      + '</div></div>';
    listEl = document.getElementById('nbe-list');
    if (externalSide) {
      externalSide.classList.add('nbe-side', 'nbe-side--external');
      sidebarEl = externalSide;
    } else {
      sidebarEl = document.getElementById('nbe-side');
    }
    topbarEl = document.getElementById('nbe-top');
    renderTopbar();

    topbarEl.addEventListener('click', function (ev) {
      var t = ev.target.closest('.nbe-tbtn'); if (!t) return;
      var cmd = t.getAttribute('data-cmd');
      if (cmd === 'insert') openInserter(null);
      else if (cmd === 'link') { var url = window.prompt('Link URL:', 'https://'); if (url) document.execCommand('createLink', false, url); }
      else if (cmd && cmd.indexOf('plug-') === 0) { var tb = toolbarButtons[parseInt(cmd.slice(5), 10)]; if (tb && tb.onClick) { try { tb.onClick(api); } catch (e) { console.error(e); } } }
      else document.execCommand(cmd, false, null);
    });
    document.getElementById('nbe-append').addEventListener('click', function () { openInserter(null); });

    // Click on empty canvas (not on a block) deselects -> auto-switch to Post tab.
    var canvasEl = mount.querySelector('.nbe-canvas');
    if (canvasEl) canvasEl.addEventListener('click', function (ev) {
      if (ev.target.closest('.nbe-block') || ev.target.closest('.nbe-append')) return;
      if (selectedId !== null) select(null);
    });

    load(contentField ? contentField.value : '');
    renderAll();
    syncField();
    if (form) form.addEventListener('submit', function () { emit('save', getBlocks()); syncField(); });

    emit('init', api);
    document.dispatchEvent(new CustomEvent('bh-editor:ready', { detail: api }));
  }

  function defineApiStub() {
    // Editor not on this page: expose a queueing stub so app scripts that
    // load everywhere can still call BasehimEditor.* safely.
    if (window.BasehimEditor) return;
    var q = [];
    window.BasehimEditor = new Proxy({ _queue: q }, { get: function (o, k) { if (k in o) return o[k]; return function () { q.push([k, arguments]); }; } });
  }

  // Replay any calls queued by app scripts that loaded before us.
  var pre = window.BasehimEditor && window.BasehimEditor._queue;
  window.BasehimEditor = api;
  function start() {
    boot();
    if (pre && pre.length) pre.forEach(function (c) { try { api[c[0]] && api[c[0]].apply(null, c[1]); } catch (e) { console.error(e); } });
  }
  // Boot only after the document is parsed: the external sidebar target
  // (CONFIG.sidebarTarget, the page's main sidebar column) appears LATER in
  // the DOM than this script tag. Booting immediately made getElementById
  // return null and silently fell back to the internal sidebar — the whole
  // reason the tabs rendered inside the editor instead of the settings column.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
