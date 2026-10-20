/*
 * The shared Categories and Tags components (admin/assets/js/post-fields.js,
 * 1.2.62) in tests/js/mini-dom.js — as the block editor uses them (tags
 * stored at once on a saved post) and as Quick Edit uses them (changes wait
 * for Save). Started by tests/Unit/PostFieldsJsTest.php.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { createDocument, Event } = require('./mini-dom.js');
const SRC = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/post-fields.js'), 'utf8');

function env(handlers = {}) {
  const doc = createDocument();
  doc.body.innerHTML = '<form id="f"><div id="a"></div><div id="b"></div></form>';
  const calls = [];
  const answer = (method) => (action, data) => {
    calls.push({ method, action, data });
    const h = handlers[action];
    return h ? Promise.resolve(h(data)) : Promise.resolve({});
  };
  const timers = [];
  const win = { document: doc, console, Intl, Date, Math, JSON, Promise, Object, Array, String, parseInt, isNaN, Error,
    setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout: () => {}, AbortController,
    toasts: [], bhToast: function (m, t) { this.toasts.push([m, t]); },
    Basehim: { ajax: { get: answer('GET'), request: answer('POST') } } };
  win.bhToast = win.bhToast.bind(win);
  win.window = win; doc.defaultView = win;
  vm.runInContext(SRC, vm.createContext(win));
  return { doc, win, F: win.BasehimPostFields, calls, run: () => { while (timers.length) timers.shift()(); }, settle: async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); } };
}
const fire = (el, type, props) => el.dispatchEvent(Object.assign(new Event(type, { bubbles: true }), props || {}));
function eq(a, b, m) { const A = JSON.stringify(a), B = JSON.stringify(b); if (A !== B) throw new Error(`${m || 'expected'}: ${B}, got ${A}`); }
function ok(c, m) { if (!c) throw new Error(m || 'expected true'); }
const tests = [];
const test = (n, f) => tests.push([n, f]);

test('categories: ticked boxes, posted under the given name, with a change event', () => {
  const e = env();
  const host = e.doc.getElementById('a');
  let changes = 0;
  const c = e.F.categories(host, { options: [{ id: 1, name: 'News' }, { id: 2, name: 'Guides' }], selected: [2], name: 'term_ids[]' });
  c.onChange(() => changes++);
  const boxes = host.querySelectorAll('input[type="checkbox"]');
  eq(boxes.map((b) => [b.name, b.value, b.checked]), [['term_ids[]', '1', false], ['term_ids[]', '2', true]]);
  boxes[0].click();
  eq(c.value(), ['1', '2']);
  eq(changes, 1);
  c.set(['1']);
  eq(c.value(), ['1']);
});

test('categories: a long list gets a filter; none yet offers to create one', () => {
  const e = env();
  const opts = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, name: (i === 5 ? 'Arduino' : 'Cat ' + i) }));
  const host = e.doc.getElementById('a');
  e.F.categories(host, { options: opts, selected: [] });
  const filter = host.querySelector('.bh-terms__filter');
  ok(filter, 'filter shown');
  filter.value = 'ardu'; fire(filter, 'input');
  eq(host.querySelectorAll('.bh-terms__opt').filter((n) => !n.hidden).map((n) => n.textContent), ['Arduino']);
  const empty = e.doc.getElementById('b');
  e.F.categories(empty, { options: [], selected: [], createUrl: '/admin/taxonomies/category' });
  eq(empty.querySelector('a').getAttribute('href'), '/admin/taxonomies/category');
});

test('tags (Quick Edit): chips from the post, new names marked, nothing stored until Save', () => {
  const e = env();
  const host = e.doc.getElementById('a');
  let changes = 0;
  const t = e.F.tags(host, { post_id: 7, tags: [{ id: 3, name: 'Arduino' }], pending: ['Brand new'], immediate: false, names: null, can_create: true });
  t.onChange(() => changes++);
  eq(host.querySelectorAll('.bh-tag').map((c) => [c.textContent.replace('×', ''), c.classList.contains('is-new')]), [['Arduino', false], ['Brand new', true]]);
  eq(host.querySelectorAll('input[type="hidden"]').length, 0, 'no hidden inputs in Quick Edit');
  const input = host.querySelector('input[data-bh-tags-input]');
  input.value = 'ESP32, sensors,'; fire(input, 'input');
  eq(t.value(), { ids: [3], names: ['Brand new', 'ESP32', 'sensors'] });
  host.querySelector('.bh-tag .bh-tag__x').click();
  eq(t.value(), { ids: [], names: ['Brand new', 'ESP32', 'sensors'] });
  ok(changes >= 3);
  eq(e.calls.filter((c) => c.action === 'posts.tag_add' || c.action === 'posts.tag_remove').length, 0, 'deferred: no AJAX writes');
});

test('tags: typing suggests existing tags; Enter picks the highlighted one and does not submit the form', async () => {
  const e = env({ 'terms.tag_suggest': () => ({ tags: [{ id: 9, name: 'Raspberry Pi', count: 4 }] }) });
  const host = e.doc.getElementById('a');
  const t = e.F.tags(host, { tags: [], pending: [], immediate: false, names: null });
  const input = host.querySelector('input[data-bh-tags-input]');
  input.value = 'rasp'; fire(input, 'input');
  e.run(); await e.settle();
  const list = host.querySelector('[data-bh-tags-suggest]');
  eq(list.hidden, false);
  ok(/Raspberry Pi/.test(list.textContent) && /Add new tag/.test(list.textContent));
  let submitted = false;
  e.doc.getElementById('f').addEventListener('submit', () => { submitted = true; });
  const ev = Object.assign(new Event('keydown', { bubbles: true }), { key: 'Enter' });
  input.dispatchEvent(ev);
  ok(ev.defaultPrevented && !submitted, 'Enter adds, never submits');
  eq(t.value(), { ids: [9], names: [] });
  const s = e.calls.find((c) => c.action === 'terms.tag_suggest');
  eq([s.data.q, s.data.limit], ['rasp', 8]);
});

test('tags (editor): on a saved post adding and removing is stored at once and marked autosaved', async () => {
  const e = env({
    'posts.tag_add': (d) => ({ tags: [{ id: 3, name: 'Arduino' }, { id: 4, name: 'LED' }], pending_tags: [] }),
    'posts.tag_remove': () => ({ tags: [{ id: 4, name: 'LED' }], pending_tags: [] }),
  });
  const host = e.doc.getElementById('a');
  const t = e.F.tags(host, { post_id: 12, tags: [{ id: 3, name: 'Arduino' }], pending: [], immediate: true, idPrefix: 'bh-tags' });
  eq(host.querySelector('input[data-bh-tags-input]').id, 'bh-tags-input', 'the editor keeps its ids');
  host.querySelector('[data-bh-tags-input]').value = '';
  // A "Most used" style pick: add an existing tag.
  t.set([{ id: 3, name: 'Arduino' }], []);
  const hidden = host.querySelector('input[type="hidden"]');
  eq([hidden.name, hidden.value, hidden.hasAttribute('data-bh-autosaved')], ['term_ids[]', '3', true]);
  const input = host.querySelector('[data-bh-tags-input]');
  input.value = 'LED,'; fire(input, 'input');
  await e.settle();
  eq(e.calls.find((c) => c.action === 'posts.tag_add').data, { post_id: 12, name: 'LED' });
  eq(t.value(), { ids: [3, 4], names: [] }, 'shows the server state');
  host.querySelector('.bh-tag[data-id="3"] .bh-tag__x').click();
  await e.settle();
  eq(e.calls.find((c) => c.action === 'posts.tag_remove').data, { post_id: 12, term_id: 3 });
  eq(t.value(), { ids: [4], names: [] });
});

test('tags (editor): before the first save, chips live in the form as term_ids[] / new_tags[]', () => {
  const e = env();
  const host = e.doc.getElementById('a');
  e.F.tags(host, { post_id: null, tags: [], pending: [], immediate: true });
  const input = host.querySelector('[data-bh-tags-input]');
  input.value = 'First,'; fire(input, 'input');
  const h = host.querySelector('input[type="hidden"]');
  eq([h.name, h.value, h.hasAttribute('data-bh-autosaved')], ['new_tags[]', 'First', false]);
  eq(e.calls.filter((c) => c.action === 'posts.tag_add').length, 0);
});

test('tags: "Most used" is one click and loaded once per page', async () => {
  const e = env({ 'terms.tag_overview': () => ({ most_used: [{ id: 5, name: 'Sensors', count: 9 }], recent: [] }) });
  const a = e.F.tags(e.doc.getElementById('a'), { tags: [], pending: [], immediate: false, names: null });
  e.F.tags(e.doc.getElementById('b'), { tags: [], pending: [], immediate: false, names: null });
  await e.settle();
  eq(e.calls.filter((c) => c.action === 'terms.tag_overview').length, 1);
  const pick = e.doc.getElementById('a').querySelector('.bh-tags__pick');
  ok(pick && /Sensors/.test(pick.textContent));
  pick.click();
  eq(a.value(), { ids: [5], names: [] });
  ok(pick.disabled, 'a chosen tag is greyed out');
});

test('each screen gets only its own field styles (editor bhe-*, lists bh-*)', () => {
  const e = env();
  const ed = e.doc.getElementById('a'), qe = e.doc.getElementById('b');
  e.F.tags(ed, { tags: [], pending: [], skin: 'editor' });
  e.F.publish(qe, { status: 'draft', can_publish: true, tz: 'UTC', now: Math.floor(Date.now() / 1000) }, { compact: true });
  const ti = ed.querySelector('[data-bh-tags-input]');
  ok(ti.classList.contains('bhe-input') && !ti.classList.contains('bh-input'), ti.className);
  const di = qe.querySelector('input[type="date"]');
  ok(di.classList.contains('bh-input') && !di.classList.contains('bhe-input'), di.className);
  ok(!qe.querySelector('.bhe-segmented'), 'no editor segmented style on the list');
});

(async () => {
  const out = [];
  for (const [name, fn] of tests) {
    try { await fn(); out.push({ name, ok: true }); }
    catch (err) { out.push({ name, ok: false, error: String((err && err.stack) || err).split('\n').slice(0, 3).join(' | ') }); }
  }
  process.stdout.write(JSON.stringify(out) + '\n');
})();
