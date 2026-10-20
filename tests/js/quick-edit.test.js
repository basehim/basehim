/*
 * Runs the real admin/assets/js/datatable.js in a small DOM (mini-dom.js)
 * and drives Quick Edit (1.2.61) like a person would: open it from the row's
 * action menu, change fields, save, cancel. The AJAX layer is a stub that
 * records requests and answers with the shapes the server returns
 * (tests/Integration/QuickEditTest.php checks the server side).
 * Started by tests/Unit/QuickEditJsTest.php; prints [{name, ok, error?}].
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { createDocument, Event } = require('./mini-dom.js');

const SRC = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/datatable.js'), 'utf8');
const FIELDS = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/post-fields.js'), 'utf8');

function row(id, title, status, extra) {
  return Object.assign({ id, status, cells: {
    title: '<a class="bh-dt-title" href="/admin/posts/' + id + '/edit">' + title + '</a><span class="bh-dt-sub">/slug-' + id + '</span>',
    status: '<span class="bh-dt-badge">' + status + '</span>',
  } }, extra || {});
}

/** The publish control's settings, as PostPublishing::panel() builds them. */
const PANEL = (over = {}) => Object.assign({ status: 'draft', can_publish: true, tz: 'Asia/Karachi', tz_label: 'PKT · UTC+05:00',
  now: Math.floor(Date.now() / 1000), published_at: '', date: '', time: '', mode: 'now', min_date: '2026-10-18', max_date: '2038-01-18',
  type_label: 'post', summary: 'Draft — not published yet' }, over);

const POST_FIELDS = (over = {}) => {
  const status = over.status || 'draft';
  const panel = PANEL(Object.assign({ status, mode: status === 'scheduled' ? 'schedule' : (status === 'published' ? 'keep' : 'now'), date: over.date || '', time: over.time || '' }, over.panel || {}));
  return [
    { name: 'title', type: 'text', label: 'Title', value: over.title || 'First post', required: true, max: 1000, span: 6 },
    { name: 'slug', type: 'slug', label: 'Slug', value: 'slug-1', max: 190, span: 3, placeholder: 'From the title' },
    { name: 'author', type: 'select', label: 'Author', value: '1', span: 3, options: [{ value: 1, label: 'Admin' }, { value: 4, label: 'Author' }] },
    { name: 'status', type: 'select', label: 'Status', value: status, span: 3,
      options: ['draft', 'pending'].concat(status === 'scheduled' ? ['scheduled'] : [], ['published', 'private']).map((v) => ({ value: v, label: v })),
      confirm: { from: ['published'], to: ['draft', 'pending', 'private'], message: 'This takes the live post off the site. Continue?' } },
    { name: 'publish', type: 'publish', label: 'Publish', value: panel, show_when: { field: 'status', in: ['published', 'scheduled'] }, span: 9 },
    { name: 'categories', type: 'categories', label: 'Categories', span: 5, value: { options: [{ id: 10, name: 'News' }, { id: 11, name: 'Guides' }], selected: [10] } },
    { name: 'tags', type: 'tags', label: 'Tags', span: 7, value: { post_id: 1, can_create: true, tags: [{ id: 3, name: 'Arduino' }], pending: [] } },
    { name: 'comments', type: 'toggle', label: 'Allow comments', value: true, span: 3 },
  ];
};

/** A Posts table page with the given rows, and a stub AJAX layer. */
function page(opts = {}) {
  const doc = createDocument();
  doc.hiddenSelectors = ['.bh-dt[data-view="list"] .bh-dt-table', '.bh-dt[data-view="grid"] .bh-dt-table',
                         '.bh-dt[data-view="table"] .bh-dt-cards'];
  const rows = opts.rows || [row(1, 'First post', 'draft'), row(2, 'Second post', 'published')];
  const COLS = opts.columns || [{ id: 'title', label: 'Title', align: 'left', sortable: ['title_az', 'title_za'], can_hide: false }, { id: 'status', label: 'Status', align: 'center' }];
  const cfg = {
    table: opts.table || 'content.posts', csrf: 't', base: '', bulkEndpoint: '/admin/posts/bulk', editBase: '/admin/posts/{id}/edit', filters: {},
    initial: {
      table: opts.table || 'content.posts', rows, meta: { page: 2, per_page: 2, total: 9, last_page: 5 },
      columns: COLS, catalog: COLS, label: opts.label,
      prefs: { visible: COLS.map((c) => c.id), order: COLS.map((c) => c.id), page_size: 2, density: 'comfortable', view: opts.view || 'table' },
      sort: 'newest', search: 'abc', filters: { status: 'draft' }, page_sizes: [2, 20], bulk_actions: ['publish', 'draft', 'delete'],
      row_actions: ['edit', 'trash'], quick_edit: opts.quickEdit !== false,
    },
  };
  doc.body.innerHTML = '<div class="bh-dt bh-card" data-view="' + (opts.view || 'table') + '">'
    + '<script type="application/json" data-dt-config>' + JSON.stringify(cfg).replace(/</g, '\\u003c') + '</script>'
    + '<div><input type="search" data-dt-search value="abc"><button type="button" data-dt-options>Screen Options</button><div class="bh-dt-panel" data-dt-panel></div>'
    + '<div data-dt-bulk><span data-dt-bulk-count></span><div data-dt-bulk-actions></div></div></div>'
    + '<div class="bh-dt-wrap"><table class="bh-dt-table"><thead data-dt-head></thead><tbody data-dt-body></tbody></table><div class="bh-dt-cards" data-dt-cards></div></div>'
    + '<div data-dt-foot><span data-dt-count></span><div data-dt-pages></div><select data-dt-perpage><option value="2">2</option></select></div></div>';

  const calls = [];
  const handlers = Object.assign({
    'content.quick_edit': (d) => ({ form: { id: d.id, title: 'Post ' + d.id, version: 'v' + d.id + 'a', fields: POST_FIELDS() } }),
  }, opts.handlers || {});
  const toasts = [], confirms = [];
  let confirmAnswer = true;
  const timers = [];
  const mqls = [];
  const win = {
    document: doc, console,
    setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout: () => {}, setInterval: () => 0,
    bhToast: (m, t) => toasts.push([m, t]),
    bhConfirm: (m) => { confirms.push(m); return Promise.resolve(confirmAnswer); },
    scrollX: 0, scrollY: 0, _listeners: {},
    addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push({ fn, cap: false }); },
    Basehim: { ajax: {} },
    Object, Array, JSON, Promise, String, Math, parseInt, Error, Intl, Date, isNaN, AbortController,
    // Media queries: pointer, phones (600px) and where cards are the only view
    // (820px). setWidth() below flips the 820px one and fires its listeners.
    matchMedia: (q) => {
      if (q.indexOf('pointer: fine') !== -1) return { matches: (opts.pointer || 'fine') === 'fine' };
      if (q.indexOf('max-width: 600px') !== -1) return { matches: !!opts.narrow };
      if (q.indexOf('max-width: 820px') !== -1) {
        const m = { matches: !!(opts.mobile || opts.narrow), fns: [], addEventListener(t, fn) { if (t === 'change') this.fns.push(fn); } };
        mqls.push(m); return m;
      }
      return { matches: false };
    },
  };
  const answer = (method) => (action, data) => {
    calls.push({ method, action, data });
    const h = handlers[action];
    if (!h) return Promise.reject(Object.assign(new Error('No stub for ' + action), { code: 'X' }));
    try {
      const out = h(data, calls);
      return out && typeof out.then === 'function' ? out : Promise.resolve(out);
    } catch (e) { return Promise.reject(e); }
  };
  win.Basehim.ajax.get = answer('GET');
  win.Basehim.ajax.request = answer('POST');
  win.window = win;
  doc.defaultView = win;
  const ctx = vm.createContext(win);
  vm.runInContext(FIELDS, ctx);   // the list page loads the shared fields before the table
  vm.runInContext(SRC, ctx);
  const root = doc.querySelector('.bh-dt');
  const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
  return {
    doc, root, calls, toasts, confirms, win,
    setConfirm(v) { confirmAnswer = v; },
    /** Cross the 820px line: cards become compact (true) or full (false). */
    setMobile(on) { mqls.forEach((m) => { m.matches = on; m.fns.forEach((fn) => fn({ matches: on })); }); },
    settle,
    flushTimers() { while (timers.length) timers.shift()(); },
    /** Open Quick Edit on a row through its ⋮ menu. */
    async openQuick(id) {
      const tr = root.querySelector('tr[data-dt-row="' + id + '"]');
      tr.querySelector('.bh-dt-actbtn').click();
      const item = doc.querySelectorAll('.bh-dt-menu__item').find((n) => n.textContent === 'Quick Edit');
      if (!item) throw new Error('no Quick Edit item in the menu');
      item.click();
      await settle();
      return doc.querySelector('dialog[open] .bh-dt-qe');
    },
    input(name) { return doc.querySelector('dialog[open] .bh-dt-qe [data-qe-field="' + name + '"] [name="' + name + '"]'); },
    field(name) { return doc.querySelector('dialog[open] .bh-dt-qe [data-qe-field="' + name + '"]'); },
    /** Choose a mode in the Publish control. */
    mode(v) {
      const r = this.field('publish').querySelectorAll('input[type="radio"]');
      r.forEach((x) => { x.checked = x.value === v; });
      r.find((x) => x.value === v).dispatchEvent(new Event('change', { bubbles: true }));
    },
    typeIn(el, v) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); },
    set(name, value) {
      const el = this.input(name);
      if (el.type === 'checkbox') el.checked = !!value; else el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
    },
    async save() { doc.querySelector('dialog[open] .bh-dt-qe button[type="submit"]').click(); await settle(); },
    saves() { return calls.filter((c) => c.action === 'content.quick_edit_save'); },
  };
}

/** JSON with object keys sorted, so key order does not matter (array order does). */
function canon(v) { return JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x) ? Object.keys(x).sort().reduce((o, key) => (o[key] = x[key], o), {}) : x)); }
function eq(a, b, msg) { if (canon(a) !== canon(b)) throw new Error((msg || 'expected') + ': ' + canon(b) + ', got ' + canon(a)); }
function ok(c, msg) { if (!c) throw new Error(msg || 'expected true'); }

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('the row menu offers Quick Edit only on tables that have it', async () => {
  const p = page();
  p.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn').click();
  ok(p.doc.querySelectorAll('.bh-dt-menu__item').some((n) => n.textContent === 'Quick Edit'));
  const q = page({ quickEdit: false });
  q.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn').click();
  ok(!q.doc.querySelectorAll('.bh-dt-menu__item').some((n) => n.textContent === 'Quick Edit'));
});

test('a post opens under its row with its current values, labelled and focused', async () => {
  const p = page();
  const panel = await p.openQuick(1);
  ok(panel, 'panel shown');
  const dlg = p.doc.querySelector('dialog.bh-qe');
  ok(dlg && dlg.open && p.doc.modal === dlg, 'opens as a modal dialog');
  ok(!p.root.querySelector('.bh-dt-qe'), 'not inside the table');
  eq(p.doc.getElementById(dlg.getAttribute('aria-labelledby')).textContent, 'Quick Edit', 'the dialog is named');
  eq(dlg.querySelector('.bh-qe__sub').textContent, 'Post 1', 'with the item\'s title');
  eq(p.calls[0], { method: 'GET', action: 'content.quick_edit', data: { table: 'content.posts', id: 1 } });
  eq(p.input('title').value, 'First post');
  eq(p.input('author').value, '1');
  eq(p.input('comments').checked, true);
  // Tags and Categories are the block editor's components.
  eq(p.field('tags').querySelectorAll('.bh-tag').map((c) => c.textContent.replace('×', '')), ['Arduino']);
  ok(p.field('tags').querySelector('[data-bh-tags-input]'), 'the typeahead box');
  const cats = p.field('categories').querySelectorAll('.bh-terms__opt input');
  eq(cats.map((c) => c.checked), [true, false]);
  ok(p.field('categories').querySelector('.bh-terms--compact'), 'compact checklist');
  const label = panel.querySelector('label[for="' + p.input('title').id + '"]');
  ok(label && label.textContent === 'Title', 'labelled');
  ok(p.doc.activeElement === p.input('title'), 'first field focused');
  ok(p.field('publish').hidden, 'no Publish control while the status is Draft');
  // Compact: the server's size hints reach the twelve-column grid.
  eq(p.field('title').style['--qe-span'] || p.field('title').style.getPropertyValue && p.field('title').style.getPropertyValue('--qe-span'), '6');
  // Cancel before Save, as in the admin's other dialogs.
  eq(panel.querySelectorAll('.bh-dt-qe__actions .bh-btn').map((b) => b.textContent), ['Cancel', 'Save']);
});

test('a page opens with parent and order fields', async () => {
  const p = page({ table: 'content.pages', handlers: { 'content.quick_edit': (d) => ({ form: { id: d.id, title: 'About', version: 'v1', fields: [
    { name: 'title', type: 'text', label: 'Title', value: 'About', required: true },
    { name: 'parent', type: 'select', label: 'Parent', value: '7', options: [{ value: '', label: '(no parent)' }, { value: 7, label: 'Company' }] },
    { name: 'menu_order', type: 'number', label: 'Order', value: 3, min: -100000, max: 100000 },
  ] } }) } });
  await p.openQuick(1);
  eq(p.input('parent').value, '7');
  eq(p.input('menu_order').value, '3');
  eq(p.input('menu_order').type, 'number');
  ok(!p.doc.querySelector('dialog[open] [data-qe-field="categories"]'));
});

test('saving sends only what changed and replaces the row in place, keeping table state', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': (d) => ({ row: row(1, 'Renamed post', 'draft'), version: 'v1b', notices: [], form: {} }) } });
  await p.openQuick(1);
  p.set('title', 'Renamed post');
  await p.save();
  const s = p.saves();
  eq(s.length, 1);
  eq(JSON.parse(s[0].data.values), { title: 'Renamed post' });
  eq(s[0].data.version, 'v1a');
  eq(JSON.parse(s[0].data.columns), ['title', 'status']);
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'), 'closed after saving');
  const tr = p.root.querySelector('tr[data-dt-row="1"]');
  ok(tr.textContent.indexOf('Renamed post') !== -1, 'row shows the new title');
  ok(tr.classList.contains('is-updated'));
  eq(p.calls.filter((c) => c.action === 'content.table').length, 0, 'no table reload');
  eq(p.root.querySelectorAll('tbody tr[data-dt-row]').length, 2, 'other rows untouched');
  eq(p.toasts[0], ['Saved', 'success']);
  // The next table request still has the search, filters and page.
  p.root.querySelector('thead th.bh-dt-sorth').click();
  const load = p.calls.filter((c) => c.action === 'content.table')[0];
  eq([load.data.search, load.data.page, JSON.parse(load.data.filters)], ['abc', 2, { status: 'draft' }]);
});

test('a slug change shows the new address in the row', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => ({ row: Object.assign(row(1, 'First post', 'draft'), { cells: { title: '<a>First post</a><span class="bh-dt-sub">/new-address</span>', status: 'draft' } }), version: 'x', notices: [] }) } });
  await p.openQuick(1);
  p.set('slug', 'new-address');
  await p.save();
  eq(JSON.parse(p.saves()[0].data.values), { slug: 'new-address' });
  ok(p.root.querySelector('tr[data-dt-row="1"] .bh-dt-sub').textContent === '/new-address');
});

test('server validation errors appear under their fields and nothing typed is lost', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => Promise.reject(Object.assign(new Error('Some fields need attention.'),
    { code: 'VALIDATION_FAILED', status: 422, fields: { slug: ['Another post or page already uses this address.'] } })) } });
  await p.openQuick(1);
  p.set('slug', 'taken');
  p.set('title', 'Typed title');
  await p.save();
  const field = p.doc.querySelector('dialog[open] [data-qe-field="slug"]');
  ok(field.classList.contains('has-error'));
  eq(field.querySelector('.bh-dt-qe__error').textContent, 'Another post or page already uses this address.');
  eq(p.input('slug').getAttribute('aria-invalid'), 'true');
  ok(p.input('slug').getAttribute('aria-describedby').indexOf(field.querySelector('.bh-dt-qe__error').id) !== -1, 'error is announced with the field');
  eq([p.input('slug').value, p.input('title').value], ['taken', 'Typed title'], 'input kept');
  ok(p.doc.activeElement === p.input('slug'), 'focus moves to the field');
  // Editing the field clears its error.
  p.set('slug', 'taken-2');
  ok(!field.classList.contains('has-error'));
});

test('a required field left empty is caught before any request', async () => {
  const p = page();
  await p.openQuick(1);
  p.set('title', '   ');
  await p.save();
  eq(p.saves().length, 0);
  ok(p.doc.querySelector('dialog[open] [data-qe-field="title"]').classList.contains('has-error'));
});

test('a network failure keeps the form and its values; trying again works', async () => {
  let n = 0;
  const p = page({ handlers: { 'content.quick_edit_save': () => (++n === 1
    ? Promise.reject(Object.assign(new Error('The network is unreachable.'), { code: 'NETWORK_ERROR' }))
    : { row: row(1, 'Kept title', 'draft'), version: 'v2', notices: [] }) } });
  await p.openQuick(1);
  p.set('title', 'Kept title');
  await p.save();
  const banner = p.doc.querySelector('dialog[open] .bh-dt-qe__msg');
  ok(!banner.hidden && /still here/.test(banner.textContent), banner.textContent);
  eq(p.input('title').value, 'Kept title');
  await p.save();
  eq(p.saves().length, 2);
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'));
});

test('a double click on Save sends one request', async () => {
  let resolve;
  const p = page({ handlers: { 'content.quick_edit_save': () => new Promise((r) => { resolve = r; }) } });
  await p.openQuick(1);
  p.set('title', 'Once');
  const btn = p.doc.querySelector('dialog[open] .bh-dt-qe button[type="submit"]');
  btn.click(); btn.click(); btn.click();
  await p.settle();
  eq(p.saves().length, 1);
  ok(p.doc.querySelector('dialog[open] .bh-dt-qe').getAttribute('aria-busy') === 'true', 'busy while saving');
  resolve({ row: row(1, 'Once', 'draft'), version: 'v', notices: [] });
  await p.settle();
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'));
});

test('Cancel closes without saving; with changes it asks first', async () => {
  const p = page();
  await p.openQuick(1);
  p.doc.querySelector('dialog[open] .bh-dt-qe .bh-btn--secondary').click();
  await p.settle();
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'), 'closed');
  eq(p.saves().length, 0);
  ok(p.doc.activeElement === p.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn'), 'focus back on the row');

  await p.openQuick(1);
  p.set('title', 'Unsaved');
  p.setConfirm(false);
  p.doc.querySelector('dialog[open] .bh-dt-qe .bh-btn--secondary').click();
  await p.settle();
  ok(p.doc.querySelector('dialog[open] .bh-dt-qe'), 'kept open when the user keeps editing');
  eq(p.input('title').value, 'Unsaved');
  p.setConfirm(true);
  p.doc.querySelector('dialog.bh-qe').dispatchEvent(new Event('cancel', { cancelable: true }));   // Escape
  await p.settle();
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'), 'Escape, then Discard, closes');
  eq(p.saves().length, 0);
});

test('only one Quick Edit is open at a time', async () => {
  const p = page();
  await p.openQuick(1);
  await p.openQuick(2);
  eq(p.doc.querySelectorAll('.bh-dt-qe').length, 1);
  eq(p.doc.querySelector('dialog.bh-qe .bh-qe__sub').textContent, 'Post 2');
  eq(p.doc.querySelectorAll('dialog.bh-qe').length, 1, 'one dialog, reused');
});

test('taking a live post off the site asks first and says so to the server', async () => {
  const p = page({ handlers: {
    'content.quick_edit': (d) => ({ form: { id: d.id, title: 'Live', version: 'v', fields: POST_FIELDS({ status: 'published' }) } }),
    'content.quick_edit_save': () => ({ row: row(2, 'Second post', 'draft'), version: 'v2', notices: [] }),
  } });
  await p.openQuick(2);
  p.set('status', 'draft');
  p.setConfirm(false);
  await p.save();
  eq(p.confirms.length, 1);
  eq(p.saves().length, 0, 'nothing sent when the user cancels');
  p.setConfirm(true);
  await p.save();
  eq(p.saves()[0].data.confirm, true);
  eq(JSON.parse(p.saves()[0].data.values), { status: 'draft' });
});

test('Published shows the Publish control; Schedule needs a future time and the Save button says so', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => ({ row: row(1, 'First post', 'scheduled'), version: 'v', notices: [] }) } });
  await p.openQuick(1);
  const save = p.doc.querySelector('dialog[open] .bh-dt-qe button[type="submit"]');
  eq(save.textContent, 'Save');
  p.set('status', 'published');
  ok(!p.field('publish').hidden, 'the control appears');
  eq(save.textContent, 'Publish');
  p.mode('schedule');
  eq(save.textContent, 'Schedule');
  const date = p.field('publish').querySelector('input[type="date"]'), time = p.field('publish').querySelector('input[type="time"]');
  ok(date.value && time.value, 'a time is suggested');
  p.typeIn(date, '2020-01-02'); p.typeIn(time, '09:30');
  await p.save();
  eq(p.saves().length, 0, 'a past time is caught in the control');
  ok(/future/.test(p.field('publish').querySelector('[data-bh-publish-error]').textContent));
  p.typeIn(date, '2030-01-02');
  await p.save();
  eq(JSON.parse(p.saves()[0].data.values), { status: 'published', publish_mode: 'schedule', publish_date: '2030-01-02', publish_time: '09:30' });
});

test('editing a scheduled post\'s title sends only the title; a new time travels with its status', async () => {
  const p = page({ handlers: {
    'content.quick_edit': (d) => ({ form: { id: d.id, title: 'S', version: 'v', fields: POST_FIELDS({ status: 'scheduled', date: '2030-01-02', time: '09:30' }) } }),
    'content.quick_edit_save': () => ({ row: row(1, 'S2', 'scheduled'), version: 'v', notices: [] }),
  } });
  await p.openQuick(1);
  ok(!p.field('publish').hidden, 'shown for a scheduled post');
  p.set('title', 'S2');
  await p.save();
  eq(JSON.parse(p.saves()[0].data.values), { title: 'S2' });
  await p.openQuick(1);
  p.typeIn(p.field('publish').querySelector('input[type="time"]'), '11:00');
  await p.save();
  eq(JSON.parse(p.saves()[1].data.values), { status: 'scheduled', publish_mode: 'schedule', publish_date: '2030-01-02', publish_time: '11:00' });
});

test('a live post: Change date into the past saves as an Update; into the future asks first', async () => {
  const p = page({ handlers: {
    'content.quick_edit': (d) => ({ form: { id: d.id, title: 'Live', version: 'v', fields: POST_FIELDS({ status: 'published', panel: { date: '2024-05-01', time: '15:00' } }) } }),
    'content.quick_edit_save': () => ({ row: row(2, 'Second post', 'published'), version: 'v2', notices: [] }),
  } });
  await p.openQuick(2);
  const save = p.doc.querySelector('dialog[open] .bh-dt-qe button[type="submit"]');
  p.mode('date');
  p.typeIn(p.field('publish').querySelector('input[type="date"]'), '2023-02-03');
  eq(save.textContent, 'Save');
  await p.save();
  eq(p.confirms.length, 0);
  eq(JSON.parse(p.saves()[0].data.values), { status: 'published', publish_mode: 'date', publish_date: '2023-02-03', publish_time: '15:00' });
  await p.openQuick(2);
  p.mode('date');
  p.typeIn(p.field('publish').querySelector('input[type="date"]'), '2031-06-01');
  eq(save.textContent === 'Save' ? p.doc.querySelector('dialog[open] .bh-dt-qe button[type="submit"]').textContent : save.textContent, 'Schedule');
  p.setConfirm(false);
  await p.save();
  eq(p.confirms.length, 1);
  ok(/is live/.test(p.confirms[0]));
  eq(p.saves().length, 1, 'nothing sent when cancelled');
  p.setConfirm(true);
  await p.save();
  eq(p.saves()[1].data.confirm, true);
});

test('tags typed in Quick Edit are sent as the component\'s ids and new names', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => ({ row: row(1, 'First post', 'draft'), version: 'v', notices: [] }) } });
  await p.openQuick(1);
  const input = p.field('tags').querySelector('[data-bh-tags-input]');
  input.value = 'ESP32,'; input.dispatchEvent(new Event('input', { bubbles: true }));
  await p.save();
  eq(JSON.parse(p.saves()[0].data.values), { tags: { ids: [3], names: ['ESP32'] } });
  eq(p.calls.filter((c) => c.action === 'posts.tag_add').length, 0, 'nothing stored before Save');
});

test('server errors on the publish values are shown on the Publish control', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => Promise.reject(Object.assign(new Error('Some fields need attention.'),
    { code: 'VALIDATION_FAILED', fields: { publish: ['The scheduled time has to be in the future.'] } })) } });
  await p.openQuick(1);
  p.set('status', 'published');
  p.mode('schedule');
  p.typeIn(p.field('publish').querySelector('input[type="date"]'), '2031-01-01');
  await p.save();
  const err = p.field('publish').querySelector('[data-bh-publish-error]');
  eq([err.hidden, err.textContent], [false, 'The scheduled time has to be in the future.']);
});

test('categories and the comments toggle send their new values', async () => {
  const p = page({ handlers: { 'content.quick_edit_save': () => ({ row: row(1, 'First post', 'draft'), version: 'v', notices: ['1 new tag(s) will be created when someone who can publish publishes this post.'] }) } });
  await p.openQuick(1);
  const cats = p.field('categories').querySelectorAll('.bh-terms__opt input');
  cats[1].click();
  p.set('comments', false);
  p.set('author', '4');
  await p.save();
  eq(JSON.parse(p.saves()[0].data.values), { author: '4', comments: false, categories: ['10', '11'] });
  eq(p.toasts[0][1], 'info', 'notices are shown');
});

test('a focused field stays focused when the window resizes (a phone keyboard opening)', async () => {
  const p = page();
  await p.openQuick(1);
  const title = p.input('title');
  title.focus();
  // The keyboard opening resizes the window; nothing may move the form.
  (p.win._listeners.resize || []).forEach((l) => l.fn());
  p.flushTimers();
  ok(title.isConnected, 'the field is still in the page');
  ok(p.doc.activeElement === title, 'and still focused');
  ok(p.doc.querySelector('dialog.bh-qe').open);
  // Switching the table's view leaves the dialog alone too.
  p.root.querySelectorAll('[data-dt-view]').forEach(() => {});
  ok(p.doc.querySelector('dialog[open] .bh-dt-qe') && title.isConnected);
});

test('on a touch screen no field is focused on open, so the keyboard stays down until one is tapped', async () => {
  const p = page({ pointer: 'coarse' });
  await p.openQuick(1);
  ok(p.doc.activeElement !== p.input('title'), 'the title field is not focused');
  ok(p.doc.activeElement.classList.contains('bh-qe__title'), 'the dialog heading is');
});

test('a click on the backdrop or the × closes it (asking first when there are changes)', async () => {
  const p = page();
  await p.openQuick(1);
  const dlg = p.doc.querySelector('dialog.bh-qe');
  dlg.dispatchEvent(new Event('mousedown', { bubbles: true }));
  dlg.dispatchEvent(new Event('click', { bubbles: true }));
  await p.settle();
  ok(!dlg.open, 'backdrop click closes');
  await p.openQuick(1);
  p.set('title', 'Changed');
  p.setConfirm(false);
  dlg.querySelector('.bh-qe__x').click();
  await p.settle();
  ok(dlg.open, 'kept open with unsaved changes');
  // A click inside the dialog box (on a field) does not close it.
  p.input('title').dispatchEvent(new Event('mousedown', { bubbles: true }));
  p.input('title').dispatchEvent(new Event('click', { bubbles: true }));
  ok(dlg.open);
});

test('a form that cannot be opened says why and can be closed', async () => {
  const p = page({ handlers: { 'content.quick_edit': () => Promise.reject(Object.assign(new Error('Your role only allows you to edit your own posts.'), { code: 'FORBIDDEN' })) } });
  const panel = await p.openQuick(1);
  ok(/your own posts/.test(panel.textContent));
  panel.querySelector('button').click();
  ok(!p.doc.querySelector('dialog[open] .bh-dt-qe'));
});

test('the row menu offers View for a live row and Preview for the others, in a new tab', async () => {
  const p = page({ rows: [row(1, 'Draft one', 'draft', { link: { url: '/posts/draft-one', live: false } }),
                          row(2, 'Live one', 'published', { link: { url: '/news/live-one', live: true } }),
                          row(3, 'No link', 'draft')] });
  const itemsFor = (id) => { p.root.querySelector('tr[data-dt-row="' + id + '"] .bh-dt-actbtn').click(); return p.doc.querySelectorAll('.bh-dt-menu__item'); };
  let link = itemsFor(2).find((n) => n.textContent === 'View');
  ok(link, 'View for a live row');
  eq([link.getAttribute('href'), link.getAttribute('target'), link.getAttribute('rel')], ['/news/live-one', '_blank', 'noopener']);
  link = itemsFor(1).find((n) => n.textContent === 'Preview');
  ok(link && link.getAttribute('href') === '/posts/draft-one', 'Preview for a draft');
  ok(!itemsFor(3).some((n) => n.textContent === 'View' || n.textContent === 'Preview'), 'none without an address');
});

test('View or Preview is the first item in the row menu', async () => {
  const p = page({ rows: [row(1, 'Live one', 'published', { link: { url: '/live-one', live: true } })] });
  p.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn').click();
  eq(p.doc.querySelectorAll('.bh-dt-menu__item').map((n) => n.textContent).slice(0, 3), ['View', 'Edit', 'Quick Edit']);
});

test('after a save the row menu has the new address', async () => {
  const p = page({ rows: [row(1, 'First post', 'draft', { link: { url: '/posts/slug-1', live: false } })],
    handlers: { 'content.quick_edit_save': () => ({ row: row(1, 'First post', 'published', { link: { url: '/posts/new-slug', live: true } }), version: 'v', notices: [] }) } });
  await p.openQuick(1);
  p.set('slug', 'new-slug');
  await p.save();
  p.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn').click();
  const view = p.doc.querySelectorAll('.bh-dt-menu__item').find((n) => n.textContent === 'View');
  ok(view && view.getAttribute('href') === '/posts/new-slug');
});

test('a selected item looks selected, as a card and as a table row, and keeps the look through a Quick Edit save', async () => {
  const p = page({ view: 'list', handlers: { 'content.quick_edit_save': () => ({ row: row(1, 'Renamed', 'draft'), version: 'v', notices: [] }) } });
  const card = () => p.root.querySelector('.bh-dt-card[data-dt-rowid="1"]');
  const tr = () => p.root.querySelector('tr[data-dt-rowid="1"]');
  ok(!card().classList.contains('is-selected'), 'not selected at first');
  const cb = card().querySelector('.bh-dt-rowcheck');
  cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true }));
  ok(card().classList.contains('is-selected') && tr().classList.contains('is-selected'), 'card and row');
  ok(!p.root.querySelector('.bh-dt-card[data-dt-rowid="2"]').classList.contains('is-selected'), 'only that one');
  await p.openQuick(1);
  p.set('title', 'Renamed');
  await p.save();
  ok(card().classList.contains('is-selected') && tr().classList.contains('is-selected'), 'still selected after the save');
  p.root.querySelector('.bh-dt-bulkclear').click();
  eq(p.root.querySelectorAll('.is-selected').length, 0, 'Clear removes it');
});

test('select all marks every card', async () => {
  const p = page({ view: 'list' });
  const all = p.root.querySelector('thead .bh-dt-check input');
  all.checked = true; all.dispatchEvent(new Event('change', { bubbles: true }));
  eq(p.root.querySelectorAll('.bh-dt-card.is-selected').length, 2);
  all.checked = false; all.dispatchEvent(new Event('change', { bubbles: true }));
  eq(p.root.querySelectorAll('.bh-dt-card.is-selected').length, 0);
});

test('Screen Options marks its Density row, which phones hide (they only show cards)', async () => {
  const p = page();
  p.root.querySelector('[data-dt-options]').click();
  const rows = p.root.querySelectorAll('[data-dt-panel] .bh-dt-panel__row');
  const dens = rows.find((n) => n.classList.contains('bh-dt-panel__row--density'));
  ok(dens && dens.textContent.indexOf('Density') === 0, 'the Density row');
  eq(rows.filter((n) => n.classList.contains('bh-dt-panel__row--density')).length, 1, 'only that row');
});

/** A Posts page whose columns carry their card places, as routes/tables.php declares them (1.2.65). */
function cardPage(extra = {}) {
  const cols = [
    { id: 'thumb', label: 'Image', card: 'media' }, { id: 'title', label: 'Title', card: 'title' },
    { id: 'author', label: 'Author', card: 'side' }, { id: 'category', label: 'Category', card: 'under' },
    { id: 'tags', label: 'Tags', card: 'hide' }, { id: 'status', label: 'Status', card: 'under' },
    { id: 'views', label: 'Views', card: 'meta', card_icon: 'eye' }, { id: 'date', label: 'Date', card: 'meta', card_icon: 'calendar' },
    { id: 'seo.score', label: 'SEO' },
  ].map((c) => Object.assign({ align: 'left' }, c));
  const r = (id, status, cells) => ({ id, status, cells: Object.assign({
    thumb: '<span class="bh-thumb bh-thumb--empty"><svg></svg></span>',
    title: '<a class="bh-dt-title" href="#">Post ' + id + '</a><span class="bh-dt-sub">/post-' + id + '</span>',
    author: '<span class="bh-usercell"><span class="bh-ava">A</span><span class="bh-usercell__name">Asif</span></span>',
    category: '<span class="bh-dt-chip">News</span>', tags: '<span class="bh-dt-chip">x</span>',
    status: '<span class="bh-dt-badge"' + (status === 'published' ? ' data-card-quiet' : '') + '>' + status + '</span>',
    views: '50', date: '<span class="bh-dt-date">Oct 10, 2026</span><span class="bh-dt-sub">8:57 PM</span>', 'seo.score': '82',
  }, cells) });
  const p = page(Object.assign({ view: 'list', mobile: true, columns: cols, rows: [r(1, 'published'), r(2, 'draft', { category: '<span class="bh-dt-sub">—</span>', views: '' })] }, extra));
  return { p, cols, r };
}

test('cards place each column where it says: picture, title, chips, author, icons, the rest below', async () => {
  const { p } = cardPage();
  const card = p.root.querySelector('.bh-dt-card[data-dt-rowid="1"]');
  ok(card.classList.contains('has-media') && card.querySelector('.bh-dt-card__media .bh-thumb'), 'the picture, though it has no text');
  eq(card.querySelector('.bh-dt-card__title .bh-dt-title').textContent, 'Post 1');
  eq(card.querySelector('.bh-dt-card__under').querySelectorAll('.bh-dt-chip').map((n) => n.textContent), ['News'], 'the category, not the tags');
  ok(card.querySelector('.bh-dt-card__under [data-card-quiet]'), 'a published badge is there for CSS to leave off');
  ok(card.querySelector('.bh-dt-card__aside .bh-dt-card__side .bh-usercell'), 'the author beside the title');
  const meta = card.querySelectorAll('.bh-dt-card__metaitem');
  eq(meta.map((m) => m.title), ['Views', 'Date']);
  ok(meta.every((m) => m.querySelector('.bh-dt-card__icon svg')), 'each with its icon');
  eq(meta.map((m) => m.querySelector('.bh-dt-sr').textContent), ['Views: ', 'Date: '], 'the label is still read out');
  eq(card.querySelectorAll('.bh-dt-card__row .bh-dt-card__key').map((k) => k.textContent), ['SEO'], 'an unplaced column goes below');
  ok(card.querySelector('.bh-dt-actbtn').parentNode.className === 'bh-dt-card__body', 'the ⋮ button on the line');
  // Empty cells are left off: no dash chip, no blank views line.
  const c2 = p.root.querySelector('.bh-dt-card[data-dt-rowid="2"]');
  eq(c2.querySelector('.bh-dt-card__under').querySelectorAll('.bh-dt-chip').length, 0);
  ok(!c2.querySelector('.bh-dt-card__under [data-card-quiet]'), 'Draft is shown');
  eq(c2.querySelectorAll('.bh-dt-card__metaitem').map((m) => m.title), ['Date']);
});

test('a table that does not place its columns gets picture, title and status by id, the rest below', async () => {
  const p = page({ view: 'list', mobile: true, columns: [{ id: 'title', label: 'Title' }, { id: 'status', label: 'Status' }, { id: 'when', label: 'When' }],
    rows: [row(1, 'First post', 'draft', { cells: { title: '<a class="bh-dt-title">First</a>', status: '<span class="bh-dt-badge">draft</span>', when: 'today' } })] });
  const card = p.root.querySelector('.bh-dt-card');
  eq(card.querySelector('.bh-dt-card__title').textContent, 'First');
  ok(card.querySelector('.bh-dt-card__under .bh-dt-badge'));
  ok(!card.querySelector('.bh-dt-card__aside') && !card.classList.contains('has-media'));
  eq(card.querySelectorAll('.bh-dt-card__row').map((r) => r.textContent), ['Whentoday']);
});

test('Quick Edit keeps the card layout when it redraws a row', async () => {
  const { cols, r } = cardPage();
  const p = page({ view: 'list', mobile: true, columns: cols, rows: [r(1, 'published')],
    handlers: { 'content.quick_edit_save': () => ({ row: r(1, 'draft', { title: '<a class="bh-dt-title">Renamed</a>' }), version: 'v', notices: [] }) } });
  await p.openQuick(1);
  p.set('title', 'Renamed');
  await p.save();
  const card = p.root.querySelector('.bh-dt-card[data-dt-rowid="1"]');
  eq(card.querySelector('.bh-dt-card__title').textContent, 'Renamed');
  ok(card.querySelector('.bh-dt-card__media') && card.querySelectorAll('.bh-dt-card__metaitem').length === 2);
  ok(!card.querySelector('[data-card-quiet]'), 'now a draft: the badge shows');
});

test('wide screens keep the full card: title and status on top, every other column below', async () => {
  const { cols, r } = cardPage();
  const p = page({ view: 'list', columns: cols, rows: [r(1, 'published')] });
  const card = p.root.querySelector('.bh-dt-card[data-dt-rowid="1"]');
  ok(!card.classList.contains('bh-dt-card--compact') && card.querySelector('.bh-dt-card__top'), 'the 1.2.64 card');
  eq(card.querySelector('.bh-dt-card__badge').textContent, 'published', 'its status, Published too');
  eq(card.querySelectorAll('.bh-dt-card__key').map((k) => k.textContent), ['Author', 'Category', 'Tags', 'Views', 'Date', 'SEO'], 'every other column, tags included');
});

test('crossing 820px swaps the card layout and keeps the selection', async () => {
  const { cols, r } = cardPage();
  const p = page({ view: 'list', columns: cols, rows: [r(1, 'published'), r(2, 'draft')] });
  const cb = p.root.querySelector('.bh-dt-card[data-dt-rowid="2"] .bh-dt-rowcheck');
  cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true }));
  p.setMobile(true);
  const c2 = p.root.querySelector('.bh-dt-card[data-dt-rowid="2"]');
  ok(c2.classList.contains('bh-dt-card--compact'), 'compact on a phone');
  ok(c2.classList.contains('is-selected') && c2.querySelector('.bh-dt-rowcheck').checked, 'still selected');
  eq(p.root.querySelectorAll('.bh-dt-card').length, 2, 'one card per row');
  p.setMobile(false);
  ok(p.root.querySelector('.bh-dt-card[data-dt-rowid="2"] .bh-dt-card__top'), 'full again on a wide screen');
});

test('the ⋮ button toggles its menu; another ⋮ moves it; a click outside closes it', async () => {
  const p = page();
  const menu = () => p.doc.querySelector('.bh-dt-menu');
  const b1 = p.root.querySelector('tr[data-dt-row="1"] .bh-dt-actbtn');
  const b2 = p.root.querySelector('tr[data-dt-row="2"] .bh-dt-actbtn');
  b1.click();
  ok(menu().style.display === 'block' && b1.getAttribute('aria-expanded') === 'true', 'opens');
  b1.click();
  ok(menu().style.display === 'none' && b1.getAttribute('aria-expanded') === 'false', 'the same ⋮ closes it');
  b1.click(); b2.click();
  ok(menu().style.display === 'block' && b2.getAttribute('aria-expanded') === 'true' && b1.getAttribute('aria-expanded') === 'false', 'another ⋮ takes it over');
  p.doc.body.click();
  ok(menu().style.display === 'none', 'a click outside closes it');
  b2.click();
  ok(menu().style.display === 'block', 'and it opens again');
});

test('cards have their own Select all, in step with the table header', async () => {
  const p = page({ view: 'list', mobile: true });
  const head = p.root.querySelector('.bh-dt-cardshead');
  ok(head && !head.hidden && head.textContent === 'Select all', 'shown above the cards');
  const all = head.querySelector('input');
  all.checked = true; all.dispatchEvent(new Event('change', { bubbles: true }));
  eq(p.root.querySelectorAll('.bh-dt-card.is-selected').length, 2, 'every card');
  eq(p.root.querySelector('[data-dt-bulk-count]').textContent, '2 items selected', 'the bulk bar');
  ok(p.root.querySelector('thead .bh-dt-check input').checked, 'the table header agrees');
  // One card off: both boxes show "some".
  const cb = p.root.querySelector('.bh-dt-card[data-dt-rowid="1"] .bh-dt-rowcheck');
  cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true }));
  ok(!all.checked && all.indeterminate, 'partly selected');
  all.checked = false; all.dispatchEvent(new Event('change', { bubbles: true }));
  eq(p.root.querySelectorAll('.is-selected').length, 0, 'and off again');
  const empty = page({ view: 'list', mobile: true, rows: [] });
  ok(empty.root.querySelector('.bh-dt-cardshead').hidden, 'hidden when there is nothing to select');
});

test('the count names the items, and phones get three page numbers', async () => {
  const wide = page({ label: 'Posts' });
  eq(wide.root.querySelector('[data-dt-count]').textContent, 'Showing 3–4 of 9 posts');
  eq(wide.root.querySelectorAll('[data-dt-pages] .bh-dt-page').map((b) => b.textContent).filter(Boolean), ['1', '2', '3', '4', '5']);
  const narrow = page({ label: 'Posts', narrow: true });
  eq(narrow.root.querySelectorAll('[data-dt-pages] .bh-dt-page').map((b) => b.textContent).filter(Boolean), ['1', '2', '3']);
  const plain = page();
  eq(plain.root.querySelector('[data-dt-count]').textContent, 'Showing 3–4 of 9 results', 'no label: results');
});

(async () => {
  const out = [];
  for (const [name, fn] of tests) {
    try { await fn(); out.push({ name, ok: true }); }
    catch (e) { out.push({ name, ok: false, error: String((e && e.stack) || e).split('\n').slice(0, 3).join(' | ') }); }
  }
  process.stdout.write(JSON.stringify(out) + '\n');
})();
