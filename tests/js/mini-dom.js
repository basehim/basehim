/*
 * A small DOM for running admin scripts in Node tests (no dependencies, in
 * keeping with the test suite). It covers what datatable.js uses: parsing the
 * HTML it writes, simple CSS selectors (tag, #id, .class, [attr], [attr="v"],
 * :not(), descendant combinator, comma lists), events with bubbling and
 * preventDefault, form controls, and closest(). Layout is not computed:
 * offsetParent is null for an element inside something hidden (the `hidden`
 * flag, style.display = 'none', or a selector the test declares hidden).
 */
'use strict';

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
const RAW = new Set(['script', 'style', 'textarea']);
const decode = (s) => s.replace(/&(amp|lt|gt|quot|#39|nbsp|times);/g, (m, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'", nbsp: ' ', times: '×' })[e]);
const encode = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

class Node {
  constructor(doc) { this.ownerDocument = doc; this.parentNode = null; this.childNodes = []; this._listeners = {}; }
  get firstChild() { return this.childNodes[0] || null; }
  get lastChild() { return this.childNodes[this.childNodes.length - 1] || null; }
  get nextSibling() { const p = this.parentNode; if (!p) return null; const i = p.childNodes.indexOf(this); return p.childNodes[i + 1] || null; }
  appendChild(n) { return this.insertBefore(n, null); }
  insertBefore(n, ref) {
    if (n.nodeType === 11) { [...n.childNodes].forEach((c) => this.insertBefore(c, ref)); return n; }
    if (n.parentNode) n.parentNode.removeChild(n);
    const i = ref ? this.childNodes.indexOf(ref) : -1;
    if (i < 0) this.childNodes.push(n); else this.childNodes.splice(i, 0, n);
    n.parentNode = this; return n;
  }
  removeChild(n) {
    const i = this.childNodes.indexOf(n); if (i >= 0) this.childNodes.splice(i, 1); n.parentNode = null;
    // As in browsers: taking the focused element out of the page drops focus to <body>.
    const doc = this.ownerDocument || this;
    if (doc && doc.activeElement && doc.activeElement !== doc.body && (doc.activeElement === n || n.contains(doc.activeElement))) doc.activeElement = doc.body;
    return n;
  }
  replaceChild(n, old) { this.insertBefore(n, old); this.removeChild(old); return old; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  contains(n) { for (let x = n; x; x = x.parentNode) if (x === this) return true; return false; }
  addEventListener(t, fn, opts) { const cap = opts === true || (opts && opts.capture); (this._listeners[t] = this._listeners[t] || []).push({ fn, cap }); }
  removeEventListener(t, fn) { this._listeners[t] = (this._listeners[t] || []).filter((l) => l.fn !== fn); }
  dispatchEvent(ev) {
    const path = [];
    for (let x = this.parentNode; x; x = x.parentNode) path.push(x);          // ancestors, nearest first
    const doc = this.ownerDocument;
    if (doc && doc.defaultView && path[path.length - 1] === doc) path.push(doc.defaultView);
    ev.target = this;
    const run = (node, phase) => {
      for (const l of [...((node._listeners && node._listeners[ev.type]) || [])]) {
        if (phase === 'capture' && !l.cap) continue;
        if (phase === 'bubble' && l.cap) continue;
        ev.currentTarget = node;
        l.fn.call(node, ev);
        if (ev._stopImm) return;
      }
    };
    for (let i = path.length - 1; i >= 0 && !ev._stop; i--) run(path[i], 'capture');
    if (!ev._stop) run(this, 'target');
    if (ev.bubbles) for (let i = 0; i < path.length && !ev._stop; i++) run(path[i], 'bubble');
    return !ev.defaultPrevented;
  }
}

class Text extends Node {
  constructor(doc, t) { super(doc); this.nodeType = 3; this.data = t; }
  get textContent() { return this.data; } set textContent(v) { this.data = String(v); }
}

class Event {
  constructor(type, init = {}) { this.type = type; this.bubbles = !!init.bubbles; this.cancelable = init.cancelable !== false; this.defaultPrevented = false; this.detail = init.detail; Object.assign(this, init.props || {}); }
  preventDefault() { if (this.cancelable) this.defaultPrevented = true; }
  stopPropagation() { this._stop = true; }
  stopImmediatePropagation() { this._stop = true; this._stopImm = true; }
}

class Element extends Node {
  constructor(doc, tag) {
    super(doc); this.nodeType = 1; this.tagName = tag.toUpperCase(); this.localName = tag.toLowerCase();
    this.attributes = {}; this.style = {}; this._value = undefined; this.checked = false; this.selected = false; this.disabled = false;
    const self = this;
    this.classList = {
      add: (...c) => { const s = self._classes(); c.forEach((x) => s.add(x)); self._setClasses(s); },
      remove: (...c) => { const s = self._classes(); c.forEach((x) => s.delete(x)); self._setClasses(s); },
      contains: (c) => self._classes().has(c),
      toggle: (c, force) => { const s = self._classes(); const on = force === undefined ? !s.has(c) : !!force; on ? s.add(c) : s.delete(c); self._setClasses(s); return on; },
    };
  }
  _classes() { return new Set((this.attributes.class || '').split(/\s+/).filter(Boolean)); }
  _setClasses(s) { this.attributes.class = [...s].join(' '); }
  get className() { return this.attributes.class || ''; } set className(v) { this.attributes.class = String(v); }
  get id() { return this.attributes.id || ''; } set id(v) { this.attributes.id = String(v); }
  get name() { return this.attributes.name || ''; } set name(v) { this.attributes.name = String(v); }
  get type() { return (this.attributes.type || (this.localName === 'button' ? 'submit' : 'text')).toLowerCase(); } set type(v) { this.attributes.type = String(v); }
  get hidden() { return 'hidden' in this.attributes; } set hidden(v) { if (v) this.attributes.hidden = ''; else delete this.attributes.hidden; }
  get htmlFor() { return this.attributes.for || ''; } set htmlFor(v) { this.attributes.for = String(v); }
  get href() { return this.attributes.href || ''; } set href(v) { this.attributes.href = String(v); }
  get placeholder() { return this.attributes.placeholder || ''; } set placeholder(v) { this.attributes.placeholder = String(v); }
  get children() { return this.childNodes.filter((n) => n.nodeType === 1); }
  get firstElementChild() { return this.children[0] || null; }
  get value() {
    if (this.localName === 'select') { const o = this.querySelectorAll('option'); const s = o.find((x) => x.selected) || o[0]; return s ? s.value : ''; }
    if (this.localName === 'option') return this._value !== undefined ? this._value : (this.attributes.value !== undefined ? this.attributes.value : this.textContent);
    if (this._value !== undefined) return this._value;
    return this.localName === 'textarea' ? this.textContent : (this.attributes.value || (this.type === 'checkbox' ? 'on' : ''));
  }
  set value(v) {
    v = String(v);
    if (this.localName === 'select') { this.querySelectorAll('option').forEach((o) => { o.selected = o.value === v; }); return; }
    this._value = v;
  }
  get selectedOptions() { return this.querySelectorAll('option').filter((o) => o.selected); }
  get options() { return this.querySelectorAll('option'); }
  setAttribute(k, v) { this.attributes[k] = String(v); if (k === 'checked') this.checked = true; if (k === 'selected') this.selected = true; if (k === 'disabled') this.disabled = true; }
  getAttribute(k) { return k in this.attributes ? this.attributes[k] : null; }
  hasAttribute(k) { return k in this.attributes; }
  removeAttribute(k) { delete this.attributes[k]; }
  get textContent() { return this.childNodes.map((n) => n.textContent).join(''); }
  set textContent(v) { this.childNodes.forEach((n) => { n.parentNode = null; }); this.childNodes = []; if (v !== '' && v != null) this.appendChild(new Text(this.ownerDocument, String(v))); }
  get innerHTML() { return this.childNodes.map(serialize).join(''); }
  set innerHTML(html) { this.textContent = ''; parse(String(html), this, this.ownerDocument); }
  get outerHTML() { return serialize(this); }
  matches(sel) { return matches(this, sel); }
  closest(sel) { for (let x = this; x && x.nodeType === 1; x = x.parentNode) if (matches(x, sel)) return x; return null; }
  querySelectorAll(sel) { const out = []; walk(this, (n) => { if (n !== this && matches(n, sel, this)) out.push(n); }); return out; }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  get offsetParent() { return isRendered(this) ? (this.parentNode || null) : null; }
  get offsetWidth() { return isRendered(this) ? 100 : 0; }
  getBoundingClientRect() { return { top: 0, left: 0, right: 100, bottom: 20, width: 100, height: 20 }; }
  focus() { this.ownerDocument.activeElement = this; }
  blur() { if (this.ownerDocument.activeElement === this) this.ownerDocument.activeElement = this.ownerDocument.body; }
  click() {
    if (this.disabled) return;
    if (this.localName === 'input' && (this.type === 'checkbox' || this.type === 'radio')) this.checked = this.type === 'radio' ? true : !this.checked;
    const ok = this.dispatchEvent(new Event('click', { bubbles: true }));
    if (this.localName === 'input' && (this.type === 'checkbox' || this.type === 'radio')) this.dispatchEvent(new Event('change', { bubbles: true }));
    if (ok && (this.localName === 'button' && this.type === 'submit')) { const f = this.closest('form'); if (f) f.requestSubmit(this); }
  }
  requestSubmit() { if (this.dispatchEvent(new Event('submit', { bubbles: true }))) this.submitted = (this.submitted || 0) + 1; }
  submit() { this.submitted = (this.submitted || 0) + 1; }
  get form() { return this.closest('form'); }
  // <dialog>: open state, showModal() and close(). Escape is a 'cancel' event.
  get open() { return 'open' in this.attributes; }
  showModal() { if (!this.isConnected) throw new Error('showModal on a detached dialog'); this.attributes.open = ''; this.ownerDocument.modal = this; }
  close() { if (!this.open) return; delete this.attributes.open; if (this.ownerDocument.modal === this) this.ownerDocument.modal = null; this.dispatchEvent(new Event('close')); }
  get isConnected() { for (let x = this; x; x = x.parentNode) if (x === this.ownerDocument) return true; return false; }
  get colSpan() { return +(this.attributes.colspan || 1); } set colSpan(v) { this.attributes.colspan = String(v); }
}
['step', 'min', 'max', 'maxLength', 'required', 'noValidate', 'readOnly'].forEach((p) => {
  Object.defineProperty(Element.prototype, p, { get() { return this['_' + p]; }, set(v) { this['_' + p] = v; }, configurable: true });
});

class Fragment extends Node { constructor(d) { super(d); this.nodeType = 11; } }

function walk(n, fn) { for (const c of n.childNodes) if (c.nodeType === 1) { fn(c); walk(c, fn); } }

function isRendered(el) {
  const doc = el.ownerDocument;
  for (let x = el; x && x.nodeType === 1; x = x.parentNode) {
    if (x.hidden || x.style.display === 'none') return false;
    for (const sel of doc.hiddenSelectors || []) if (matches(x, sel)) return false;
  }
  for (let x = el; x; x = x.parentNode) if (x === doc) return true;
  return false;
}

function serialize(n) {
  if (n.nodeType === 3) return encode(n.data);
  const attrs = Object.entries(n.attributes).map(([k, v]) => (v === '' ? ` ${k}` : ` ${k}="${encode(v)}"`)).join('');
  if (VOID.has(n.localName)) return `<${n.localName}${attrs}>`;
  return `<${n.localName}${attrs}>${n.childNodes.map(serialize).join('')}</${n.localName}>`;
}

function parse(html, parent, doc) {
  const re = /<!--[\s\S]*?-->|<\/([a-zA-Z0-9-]+)\s*>|<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s=>/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*(\/?)>|([^<]+|<)/g;
  const stack = [parent]; let m;
  while ((m = re.exec(html))) {
    const top = stack[stack.length - 1];
    if (m[0].startsWith('<!--')) continue;
    if (m[1]) { const t = m[1].toLowerCase(); for (let i = stack.length - 1; i > 0; i--) if (stack[i].localName === t) { stack.length = i; break; } continue; }
    if (m[2]) {
      const el = doc.createElement(m[2]);
      const ar = /([^\s=]+)(?:\s*=\s*("[^"]*"|'[^']*'|[^\s>]+))?/g; let a;
      while ((a = ar.exec(m[3] || ''))) { let v = a[2] === undefined ? '' : a[2]; if (/^["']/.test(v)) v = v.slice(1, -1); el.setAttribute(a[1].toLowerCase(), decode(v)); }
      top.appendChild(el);
      if (RAW.has(el.localName)) {
        const close = html.toLowerCase().indexOf('</' + el.localName, re.lastIndex);
        const end = close < 0 ? html.length : close;
        el.textContent = el.localName === 'textarea' ? decode(html.slice(re.lastIndex, end)) : html.slice(re.lastIndex, end);
        re.lastIndex = close < 0 ? html.length : html.indexOf('>', close) + 1;
        continue;
      }
      if (!VOID.has(el.localName) && !m[4]) stack.push(el);
      continue;
    }
    if (m[5]) top.appendChild(new Text(doc, decode(m[5])));
  }
}

// --- selectors ---------------------------------------------------------------
function splitTop(s, ch) { const out = []; let depth = 0, q = null, cur = ''; for (const c of s) { if (q) { if (c === q) q = null; } else if (c === '"' || c === "'") q = c; else if (c === '(' || c === '[') depth++; else if (c === ')' || c === ']') depth--; else if (c === ch && depth === 0) { out.push(cur); cur = ''; continue; } cur += c; } out.push(cur); return out.map((x) => x.trim()).filter(Boolean); }
function compound(el, s) {
  // One compound selector: tag? then any of #id .class [attr] [attr="v"] :not(x).
  const re = /([a-zA-Z*][a-zA-Z0-9-]*)|#([\w-]+)|\.([\w-]+)|\[([^\]=]+?)(?:=("[^"]*"|'[^']*'|[^\]]*))?\]|:not\(((?:[^()]|\([^()]*\))*)\)/y;
  let pos = 0;
  while (pos < s.length) {
    re.lastIndex = pos;
    const m = re.exec(s);
    if (!m) throw new Error('mini-dom: unsupported selector "' + s + '"');
    if (m[1] && m[1] !== '*' && el.localName !== m[1].toLowerCase()) return false;
    if (m[2] && el.id !== m[2]) return false;
    if (m[3] && !el.classList.contains(m[3])) return false;
    if (m[4]) {
      const k = m[4].trim();
      if (!el.hasAttribute(k)) return false;
      if (m[5] !== undefined) { let v = m[5]; if (/^["']/.test(v)) v = v.slice(1, -1); if (el.getAttribute(k) !== v) return false; }
    }
    if (m[6] !== undefined && matches(el, m[6])) return false;
    pos = re.lastIndex;
  }
  return true;
}
function matches(el, sel, scope) {
  return splitTop(sel, ',').some((one) => {
    const parts = one.split(/\s+(?![^\[]*\])(?![^(]*\))/).filter(Boolean);
    if (!compound(el, parts[parts.length - 1])) return false;
    let node = el.parentNode;
    for (let i = parts.length - 2; i >= 0; i--) {
      while (node && node.nodeType === 1 && !compound(node, parts[i])) { if (node === scope) return false; node = node.parentNode; }
      if (!node || node.nodeType !== 1) return false;
      node = node.parentNode;
    }
    return true;
  });
}

function createDocument() {
  const doc = new Node(null);
  doc.nodeType = 9; doc.ownerDocument = doc; doc.readyState = 'complete'; doc.hiddenSelectors = [];
  doc.createElement = (t) => new Element(doc, t);
  doc.createTextNode = (t) => new Text(doc, t);
  doc.createDocumentFragment = () => new Fragment(doc);
  const html = doc.createElement('html'); doc.appendChild(html);
  doc.documentElement = html;
  doc.body = doc.createElement('body'); html.appendChild(doc.body);
  doc.querySelectorAll = (s) => Element.prototype.querySelectorAll.call(doc, s);
  doc.querySelector = (s) => doc.querySelectorAll(s)[0] || null;
  doc.getElementById = (id) => doc.querySelector('#' + id);
  doc.activeElement = doc.body;
  return doc;
}

module.exports = { createDocument, Event };
