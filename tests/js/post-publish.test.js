/*
 * The block editor's Publish panel (1.2.60; shared component since 1.2.62):
 * the real admin/assets/js/post-fields.js and post-publish.js, run against
 * the editor's markup in tests/js/mini-dom.js. Started by
 * tests/Unit/PublishPanelJsTest.php. Prints [{name, ok, error?}].
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { createDocument, Event } = require('./mini-dom.js');

const FIELDS = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/post-fields.js'), 'utf8');
const GLUE = fs.readFileSync(path.join(__dirname, '../../admin/assets/js/post-publish.js'), 'utf8');

function cfgFor(o) {
  return Object.assign({ status: 'draft', can_publish: true, tz: 'Asia/Karachi', tz_label: 'PKT · UTC+05:00', now: Math.floor(Date.now() / 1000),
    published_at: '', date: '', time: '', mode: 'now', min_date: '2026-10-18', max_date: '2038-01-18', type_label: 'post', summary: 'Draft' }, o);
}

/** The editor page as edit.php renders it: the form, the status select, the button and the mount. */
function page(o = {}) {
  const status = o.status || 'draft';
  const out = ['published', 'scheduled', 'private'].indexOf(status) !== -1;
  const can = o.canPublish !== false;
  const doc = createDocument();
  const label = out ? 'Update' : (can ? 'Publish' : 'Submit for review');
  const dataStatus = out ? '' : ` data-status="${can ? 'published' : 'pending'}"`;
  const opts = ['draft', 'pending', 'published', 'private'].concat(status === 'scheduled' ? ['scheduled'] : [])
    .map((s) => `<option value="${s}"${s === status ? ' selected' : ''}>${s}</option>`).join('');
  const cfg = cfgFor(Object.assign({ status, can_publish: can, mode: status === 'scheduled' ? 'schedule' : (status === 'published' ? 'keep' : 'now') }, o.cfg || {}));
  doc.body.innerHTML = `<form id="bh-post-form"><button type="submit" id="bhe-publish"${dataStatus}>${label}</button>`
    + `<select name="status" id="bhe-status">${opts}</select>`
    + `<div data-bh-publish-mount data-config="${JSON.stringify(cfg).replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"></div></form>`;
  const toasts = [], confirms = [];
  let answer = true;
  const win = { document: doc, console, Intl, Date, Math, JSON, Promise, Object, Array, String, parseInt, isNaN, Error,
    setTimeout: () => 0, clearTimeout: () => {}, setInterval: () => 0,
    bhToast: (m, t) => toasts.push([m, t]), bhConfirm: (m) => { confirms.push(m); return Promise.resolve(answer); },
    Basehim: { ajax: { get: () => Promise.resolve({}), request: () => Promise.resolve({}) } } };
  win.window = win; doc.defaultView = win;
  const ctx = vm.createContext(win);
  vm.runInContext(FIELDS, ctx);
  vm.runInContext(GLUE, ctx);
  const form = doc.getElementById('bh-post-form');
  const button = doc.getElementById('bhe-publish');
  // block-editor.js's own click handler, registered after ours.
  button.addEventListener('click', () => { const to = button.getAttribute('data-status'); if (to) doc.getElementById('bhe-status').value = to; });
  const q = (s) => doc.querySelector('[data-bh-publish-mount] ' + s);
  return {
    doc, form, button, toasts, confirms, setConfirm(v) { answer = v; },
    sel: doc.getElementById('bhe-status'),
    when: q('[data-bh-publish-when]'), date: q('input[type="date"]'), time: q('input[type="time"]'),
    preview: q('[data-bh-publish-preview]'), error: q('[data-bh-publish-error]'), modes: q('[data-bh-publish-modes]'),
    radio(v) { return doc.querySelectorAll('[data-bh-publish-mount] input[name="publish_mode"]').find((r) => r.value === v); },
    choose(v) { const r = this.radio(v); doc.querySelectorAll('input[name="publish_mode"]').forEach((x) => { x.checked = x === r; }); r.dispatchEvent(new Event('change', { bubbles: true })); },
    type(el, v) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); },
    settle: async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); },
  };
}
function local(ms, tz = 'Asia/Karachi') {
  const p = {};
  new Intl.DateTimeFormat('en-CA', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
    .formatToParts(new Date(ms)).forEach((x) => { p[x.type] = x.value; });
  return [`${p.year}-${p.month}-${p.day}`, `${p.hour}:${p.minute}`];
}
function eq(a, b, m) { if (a !== b) throw new Error(`${m || 'expected'}: ${JSON.stringify(b)}, got ${JSON.stringify(a)}`); }
function ok(c, m) { if (!c) throw new Error(m || 'expected true'); }
const tests = [];
const test = (n, f) => tests.push([n, f]);

test('a new post: Publish, then Schedule with a suggested future time in the site timezone', () => {
  const p = page();
  eq(p.button.textContent, 'Publish');
  eq(p.when.hidden, true, 'date and time hidden for Immediately');
  p.choose('schedule');
  eq(p.button.textContent, 'Schedule');
  eq(p.when.hidden, false);
  ok(/^\d{4}-\d{2}-\d{2}$/.test(p.date.value) && /^\d{2}:00$/.test(p.time.value), 'prefilled: ' + p.date.value + ' ' + p.time.value);
  ok(/^Goes live /.test(p.preview.textContent), p.preview.textContent);
  ok(p.doc.querySelector('[data-bh-publish-mount]').textContent.indexOf('Asia/Karachi') !== -1, 'the site timezone is named');
  p.choose('now');
  eq(p.button.textContent, 'Publish');
  eq(p.button.getAttribute('data-status'), 'published');
});

test('the fields post under the names the server reads', () => {
  const p = page();
  eq(p.radio('now').name, 'publish_mode');
  eq(p.date.name, 'publish_date');
  eq(p.time.name, 'publish_time');
});

test('a past time is caught before submitting, with a message and focus', () => {
  const p = page();
  p.choose('schedule');
  const [d, t] = local(Date.now() - 3600e3);
  p.type(p.date, d); p.type(p.time, t);
  p.button.click();
  eq(p.form.submitted, undefined, 'not submitted');
  eq(p.error.hidden, false);
  ok(/future/.test(p.error.textContent), p.error.textContent);
  eq(p.time.getAttribute('aria-invalid'), 'true');
  eq(p.toasts.length, 1);
  eq(p.sel.value, 'draft', 'status untouched');
  const [d2] = local(Date.now() + 2 * 86400e3);
  p.type(p.date, d2);
  eq(p.error.hidden, true, 'cleared as it is corrected');
  p.button.click();
  eq(p.form.submitted, 1);
  eq(p.sel.value, 'published', 'the server turns published + schedule into scheduled');
});

test('the server clock decides what "future" means, not the browser', () => {
  const p = page({ cfg: { now: Math.floor(Date.now() / 1000) + 7200 } });
  p.choose('schedule');
  const [d, t] = local(Date.now() + 3600e3);
  p.type(p.date, d); p.type(p.time, t);
  p.button.click();
  eq(p.form.submitted, undefined);
});

test('a scheduled post: Update keeps the schedule, Immediately becomes Publish now', () => {
  const [d, t] = local(Date.now() + 86400e3);
  const p = page({ status: 'scheduled', cfg: { date: d, time: t } });
  eq(p.button.textContent, 'Update');
  eq(p.date.value, d, 'starts from the stored time');
  p.choose('now');
  eq(p.button.textContent, 'Publish now');
  eq(p.button.getAttribute('data-status'), 'published');
  p.button.click();
  eq(p.sel.value, 'published');
});

test('a live post: Keep date by default; Change date to the past re-dates it and stays Update', () => {
  const p = page({ status: 'published', cfg: { date: '2024-05-01', time: '15:00' } });
  eq(p.button.textContent, 'Update');
  ok(p.radio('keep').checked && p.radio('date'), 'Keep date / Change date');
  eq(p.when.hidden, true);
  p.choose('date');
  eq(p.when.hidden, false);
  eq(p.date.value, '2024-05-01', 'the stored date to edit');
  p.type(p.date, '2023-02-03');
  eq(p.button.textContent, 'Update');
  ok(/stays live/.test(p.preview.textContent), p.preview.textContent);
  p.button.click();
  eq(p.form.submitted, 1, 'no confirmation needed');
  eq(p.confirms.length, 0);
});

test('a live post: a future date says Schedule and asks first; cancelling keeps it live', async () => {
  const p = page({ status: 'published', cfg: { date: '2024-05-01', time: '15:00' } });
  p.choose('date');
  const [d, t] = local(Date.now() + 3 * 86400e3);
  p.type(p.date, d); p.type(p.time, t);
  eq(p.button.textContent, 'Schedule');
  ok(/taken off the site/.test(p.preview.textContent), p.preview.textContent);
  p.setConfirm(false);
  p.button.click();
  await p.settle();
  eq(p.confirms.length, 1);
  ok(/is live/.test(p.confirms[0]));
  eq(p.form.submitted, undefined);
  p.setConfirm(true);
  p.button.click();
  await p.settle();
  eq(p.form.submitted, 1, 'submitted once after confirming');
});

test('a live post switched to Draft in the select: no timing, plain Update', () => {
  const p = page({ status: 'published' });
  p.sel.value = 'draft';
  p.sel.dispatchEvent(new Event('change', { bubbles: true }));
  eq(p.modes.hidden, true);
  eq(p.button.textContent, 'Update');
});

test('a contributor: Submit for review, no timing controls, and the button asks for pending', () => {
  const p = page({ canPublish: false });
  eq(p.button.textContent, 'Submit for review');
  ok(!p.modes && !p.date, 'no controls');
  ok(/submits posts for review/.test(p.doc.querySelector('[data-bh-publish-mount]').textContent));
  p.button.click();
  eq(p.sel.value, 'pending');
});

(async () => {
  const out = [];
  for (const [name, fn] of tests) {
    try { await fn(); out.push({ name, ok: true }); }
    catch (e) { out.push({ name, ok: false, error: String((e && e.stack) || e).split('\n').slice(0, 3).join(' | ') }); }
  }
  process.stdout.write(JSON.stringify(out) + '\n');
})();
