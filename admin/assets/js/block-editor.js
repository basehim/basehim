/*! Basehim Block Editor 2.0 — https://www.basehim.com — Heroicons (MIT) by Tailwind Labs */
(function () {
'use strict';
/*
   Basehim Block Editor 2.0

   A Gutenberg-style editor: a full-screen writing canvas, a floating block
   toolbar, the block inserter and List View on the left, Post / Block
   settings on the right. Blocks nest (Columns, Group, Cover, Media & Text,
   Details, Buttons), and every block can carry colours, a font size, an HTML
   anchor and extra CSS classes.

   Content is still saved as JSON in the form's content field:

       {"version":1,"blocks":[{"id","type","data","innerBlocks"?}]}

   innerBlocks only appears on container blocks, so documents written by the
   1.x editor load unchanged, and the server renders both
   (App\Services\BlockRenderer).

   Public API (unchanged from 1.x, extended):
     BasehimEditor.registerBlock(type, def)   add a block type
     BasehimEditor.addToolbarButton(def)      button in the top bar
     BasehimEditor.addBlockAction(def)        item in every block's ⋮ menu
     BasehimEditor.addSidebarPanel(def)       panel in the Post sidebar
     BasehimEditor.on(event, cb)              init | change | select | save |
                                              block:add | block:remove
     BasehimEditor.addFilter(name, cb)        save.data | load.data
     BasehimEditor.getBlocks() / setBlocks() / insertBlock() / insertBlocks()
     BasehimEditor.updateBlock() / removeBlock() / getSelected() / deselect()
     BasehimEditor.serialize() / refresh() / htmlToBlocks()

   A block definition is {title, icon, category, defaults, keywords, edit,
   inspector, save} exactly as before; 2.0 adds optional description,
   supports, toolbar, transforms, parent, allowedBlocks and template.

   Icons: Heroicons v2 (MIT, https://heroicons.com) plus a handful drawn for
   block types Heroicons has no symbol for.
   */

var VERSION = '2.0.0';

var mount = document.getElementById('bh-block-editor');
if (!mount) { defineApiStub(); return; }

var CONFIG = window.BasehimEditorConfig || {};
var contentField = document.querySelector('textarea[name="content"], input[name="content"]');
var form = contentField ? contentField.closest('form') : null;
var formatField = form ? form.querySelector('[name="content_format"]') : null;

// -- small utilities
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function uid() { return 'b_' + Math.random().toString(36).slice(2, 10); }
function clone(v) { return v == null ? v : JSON.parse(JSON.stringify(v)); }
function h(tag, attrs, children) {
  var el = document.createElement(tag);
  if (attrs) Object.keys(attrs).forEach(function (k) {
    var v = attrs[k];
    if (v == null || v === false) return;
    if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'text') el.textContent = v;
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v === true ? '' : v);
  });
  (children || []).forEach(function (c) {
    if (c == null || c === false) return;
    el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  });
  return el;
}
function stripTags(html) { var d = document.createElement('div'); d.innerHTML = String(html || ''); return d.textContent || ''; }
function isEmptyHtml(html) {
  var s = String(html || '');
  if (/<(img|iframe|video|audio)\b/i.test(s)) return false;
  return stripTags(s).replace(/\xa0/g, ' ').replace(ZW_RE, ' ').trim() === '';
}
// A zero-width space: what browsers leave in emptied inline elements.
var ZW = String.fromCharCode(0x200b);
var ZW_RE = new RegExp(ZW, 'g');
function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }
function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }
var IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');
function kbd(s) { return IS_MAC ? s.replace(/Ctrl/g, '⌘').replace(/Alt/g, '⌥').replace(/Shift/g, '⇧') : s; }

// -- icons
var HERO = {"plus":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 4.5v15m7.5-7.5h-15\"/>","x-mark":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M6 18 18 6M6 6l12 12\"/>","bold":"<path stroke-linejoin=\"round\" d=\"M6.75 3.744h-.753v8.25h7.125a4.125 4.125 0 0 0 0-8.25H6.75Zm0 0v.38m0 16.122h6.747a4.5 4.5 0 0 0 0-9.001h-7.5v9h.753Zm0 0v-.37m0-15.751h6a3.75 3.75 0 1 1 0 7.5h-6m0-7.5v7.5m0 0v8.25m0-8.25h6.375a4.125 4.125 0 0 1 0 8.25H6.75m.747-15.38h4.875a3.375 3.375 0 0 1 0 6.75H7.497v-6.75Zm0 7.5h5.25a3.75 3.75 0 0 1 0 7.5h-5.25v-7.5Z\"/>","italic":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M5.248 20.246H9.05m0 0h3.696m-3.696 0 5.893-16.502m0 0h-3.697m3.697 0h3.803\"/>","underline":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M17.995 3.744v7.5a6 6 0 1 1-12 0v-7.5m-2.25 16.502h16.5\"/>","strikethrough":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 12a8.912 8.912 0 0 1-.318-.079c-1.585-.424-2.904-1.247-3.76-2.236-.873-1.009-1.265-2.19-.968-3.301.59-2.2 3.663-3.29 6.863-2.432A8.186 8.186 0 0 1 16.5 5.21M6.42 17.81c.857.99 2.176 1.812 3.761 2.237 3.2.858 6.274-.23 6.863-2.431.233-.868.044-1.779-.465-2.617M3.75 12h16.5\"/>","link":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244\"/>","link-slash":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M13.181 8.68a4.503 4.503 0 0 1 1.903 6.405m-9.768-2.782L3.56 14.06a4.5 4.5 0 0 0 6.364 6.365l3.129-3.129m5.614-5.615 1.757-1.757a4.5 4.5 0 0 0-6.364-6.365l-4.5 4.5c-.258.26-.479.541-.661.84m1.903 6.405a4.495 4.495 0 0 1-1.242-.88 4.483 4.483 0 0 1-1.062-1.683m6.587 2.345 5.907 5.907m-5.907-5.907L8.898 8.898M2.991 2.99 8.898 8.9\"/>","code-bracket":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5\"/>","code-bracket-square":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M14.25 9.75 16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z\"/>","chevron-down":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m19.5 8.25-7.5 7.5-7.5-7.5\"/>","chevron-up":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m4.5 15.75 7.5-7.5 7.5 7.5\"/>","chevron-right":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m8.25 4.5 7.5 7.5-7.5 7.5\"/>","chevron-left":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15.75 19.5 8.25 12l7.5-7.5\"/>","ellipsis-vertical":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z\"/>","trash":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0\"/>","square-2-stack":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M16.5 8.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v8.25A2.25 2.25 0 0 0 6 16.5h2.25m8.25-8.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-7.5A2.25 2.25 0 0 1 8.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 0 0-2.25 2.25v6\"/>","arrow-uturn-left":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3\"/>","arrow-uturn-right":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m15 15 6-6m0 0-6-6m6 6H9a6 6 0 0 0 0 12h3\"/>","queue-list":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z\"/>","list-bullet":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z\"/>","numbered-list":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M8.242 5.992h12m-12 6.003H20.24m-12 5.999h12M4.117 7.495v-3.75H2.99m1.125 3.75H2.99m1.125 0H5.24m-1.92 2.577a1.125 1.125 0 1 1 1.591 1.59l-1.83 1.83h2.16M2.99 15.745h1.125a1.125 1.125 0 0 1 0 2.25H3.74m0-.002h.375a1.125 1.125 0 0 1 0 2.25H2.99\"/>","h1":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M2.243 4.493v7.5m0 0v7.502m0-7.501h10.5m0-7.5v7.5m0 0v7.501m4.501-8.627 2.25-1.5v10.126m0 0h-2.25m2.25 0h2.25\"/>","h2":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M21.75 19.5H16.5v-1.609a2.25 2.25 0 0 1 1.244-2.012l2.89-1.445c.651-.326 1.116-.955 1.116-1.683 0-.498-.04-.987-.118-1.463-.135-.825-.835-1.422-1.668-1.489a15.202 15.202 0 0 0-3.464.12M2.243 4.492v7.5m0 0v7.502m0-7.501h10.5m0-7.5v7.5m0 0v7.501\"/>","h3":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M20.905 14.626a4.52 4.52 0 0 1 .738 3.603c-.154.695-.794 1.143-1.504 1.208a15.194 15.194 0 0 1-3.639-.104m4.405-4.707a4.52 4.52 0 0 0 .738-3.603c-.154-.696-.794-1.144-1.504-1.209a15.19 15.19 0 0 0-3.639.104m4.405 4.708H18M2.243 4.493v7.5m0 0v7.502m0-7.501h10.5m0-7.5v7.5m0 0v7.501\"/>","photo":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z\"/>","film":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h1.5C5.496 19.5 6 18.996 6 18.375m-3.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-1.5A1.125 1.125 0 0 1 18 18.375M20.625 4.5H3.375m17.25 0c.621 0 1.125.504 1.125 1.125M20.625 4.5h-1.5C18.504 4.5 18 5.004 18 5.625m3.75 0v1.5c0 .621-.504 1.125-1.125 1.125M3.375 4.5c-.621 0-1.125.504-1.125 1.125M3.375 4.5h1.5C5.496 4.5 6 5.004 6 5.625m-3.75 0v1.5c0 .621.504 1.125 1.125 1.125m0 0h1.5m-1.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m1.5-3.75C5.496 8.25 6 7.746 6 7.125v-1.5M4.875 8.25C5.496 8.25 6 8.754 6 9.375v1.5m0-5.25v5.25m0-5.25C6 5.004 6.504 4.5 7.125 4.5h9.75c.621 0 1.125.504 1.125 1.125m1.125 2.625h1.5m-1.5 0A1.125 1.125 0 0 1 18 7.125v-1.5m1.125 2.625c-.621 0-1.125.504-1.125 1.125v1.5m2.625-2.625c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125M18 5.625v5.25M7.125 12h9.75m-9.75 0A1.125 1.125 0 0 1 6 10.875M7.125 12C6.504 12 6 12.504 6 13.125m0-2.25C6 11.496 5.496 12 4.875 12M18 10.875c0 .621-.504 1.125-1.125 1.125M18 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m-12 5.25v-5.25m0 5.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125m-12 0v-1.5c0-.621-.504-1.125-1.125-1.125M18 18.375v-5.25m0 5.25v-1.5c0-.621.504-1.125 1.125-1.125M18 13.125v1.5c0 .621.504 1.125 1.125 1.125M18 13.125c0-.621.504-1.125 1.125-1.125M6 13.125v1.5c0 .621-.504 1.125-1.125 1.125M6 13.125C6 12.504 5.496 12 4.875 12m-1.5 0h1.5m-1.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M19.125 12h1.5m0 0c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h1.5m14.25 0h1.5\"/>","musical-note":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m9 9 10.5-3m0 6.553v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 1 1-.99-3.467l2.31-.66a2.25 2.25 0 0 0 1.632-2.163Zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 0 1-.99-3.467l2.31-.66A2.25 2.25 0 0 0 9 15.553Z\"/>","document":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z\"/>","document-arrow-down":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z\"/>","document-text":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z\"/>","table-cells":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5\"/>","view-columns":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z\"/>","rectangle-group":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z\"/>","rectangle-stack":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122\"/>","globe-alt":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418\"/>","puzzle-piece":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 0 1-.657.643 48.39 48.39 0 0 1-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 0 1-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 0 0-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 0 1-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 0 0 .657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 0 1-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 0 0 5.427-.63 48.05 48.05 0 0 0 .582-4.717.532.532 0 0 0-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 0 0 .658-.663 48.422 48.422 0 0 0-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 0 1-.61-.58v0Z\"/>","arrows-up-down":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5\"/>","minus":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M5 12h14\"/>","swatch":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z\"/>","cog-6-tooth":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z\"/><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z\"/>","magnifying-glass":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z\"/>","arrow-left":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18\"/>","eye":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z\"/><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z\"/>","arrow-top-right-on-square":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25\"/>","squares-plus":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z\"/>","squares-2x2":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z\"/>","check":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m4.5 12.75 6 6 9-13.5\"/>","arrow-up":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18\"/>","arrow-down":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3\"/>","arrow-path":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99\"/>","arrow-up-tray":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5\"/>","paint-brush":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42\"/>","language":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802\"/>","sparkles":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z\"/>","play-circle":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z\"/><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15.91 11.672a.375.375 0 0 1 0 .656l-5.603 3.113a.375.375 0 0 1-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112Z\"/>","variable":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M4.745 3A23.933 23.933 0 0 0 3 12c0 3.183.62 6.22 1.745 9M19.5 3c.967 2.78 1.5 5.817 1.5 9s-.533 6.22-1.5 9M8.25 8.885l1.444-.89a.75.75 0 0 1 1.105.402l2.402 7.206a.75.75 0 0 0 1.104.401l1.445-.889m-8.25.75.213.09a1.687 1.687 0 0 0 2.062-.617l4.45-6.676a1.688 1.688 0 0 1 2.062-.618l.213.09\"/>","cursor-arrow-rays":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15.042 21.672 13.684 16.6m0 0-2.51 2.225.569-9.47 5.227 7.917-3.286-.672ZM12 2.25V4.5m5.834.166-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243-1.59-1.59\"/>","adjustments-horizontal":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75\"/>","information-circle":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z\"/>","pencil":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125\"/>","scissors":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m7.848 8.25 1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3Zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 1 1-5.196 3 3 3 0 0 1 5.196-3Zm1.536-.887a2.165 2.165 0 0 0 1.083-1.838c.005-.352.054-.695.14-1.025m-1.223 2.863 2.077-1.199m0-3.328a4.323 4.323 0 0 1 2.068-1.379l5.325-1.628a4.5 4.5 0 0 1 2.48-.044l.803.215-7.794 4.5m-2.882-1.664A4.33 4.33 0 0 0 10.607 12m3.736 0 7.794 4.5-.802.215a4.5 4.5 0 0 1-2.48-.043l-5.326-1.629a4.324 4.324 0 0 1-2.068-1.379M14.343 12l-2.882 1.664\"/>","clipboard-document":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M8.25 7.5V6.108c0-1.135.845-2.098 1.976-2.192.373-.03.748-.057 1.123-.08M15.75 18H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08M15.75 18.75v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.375 7.5H5.25m11.9-3.664A2.251 2.251 0 0 0 15 2.25h-1.5a2.251 2.251 0 0 0-2.15 1.586m5.8 0c.065.21.1.433.1.664v.75h-6V4.5c0-.231.035-.454.1-.664M6.75 7.5H4.875c-.621 0-1.125.504-1.125 1.125v12c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V16.5a9 9 0 0 0-9-9Z\"/>","command-line":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z\"/>","bars-3":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5\"/>","exclamation-triangle":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z\"/>","hashtag":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5\"/>","map-pin":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z\"/><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z\"/>","arrows-pointing-out":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15\"/>","arrows-pointing-in":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25\"/>","document-duplicate":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75\"/>","clock":"<path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z\"/>"};
var CUSTOM_ICONS = {
  paragraph: '<path d="M13 4v16M17 4v16M19 4H10a4 4 0 0 0 0 8h3"/>',
  heading: '<path d="M6 4v16M18 4v16M6 12h12"/>',
  quote: '<path d="M10 11H6.5A1.5 1.5 0 0 1 5 9.5v-2A1.5 1.5 0 0 1 6.5 6h2A1.5 1.5 0 0 1 10 7.5V12a6 6 0 0 1-5 5.9M19 11h-3.5A1.5 1.5 0 0 1 14 9.5v-2A1.5 1.5 0 0 1 15.5 6h2A1.5 1.5 0 0 1 19 7.5V12a6 6 0 0 1-5 5.9"/>',
  pullquote: '<path d="M3 4.5h18M3 19.5h18M11 13H8.5A1.5 1.5 0 0 1 7 11.5v-1A1.5 1.5 0 0 1 8.5 9h1A1.5 1.5 0 0 1 11 10.5v2.2a3.5 3.5 0 0 1-2.6 3.3M17 13h-2.5a1.5 1.5 0 0 1-1.5-1.5v-1A1.5 1.5 0 0 1 14.5 9h1a1.5 1.5 0 0 1 1.5 1.5v2.2a3.5 3.5 0 0 1-2.6 3.3"/>',
  preformatted: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M7.5 9h9M7.5 12h5M7.5 15h7"/>',
  details: '<path d="M5 6.5l3 3-3 3M11 9.5h8M5 17.5h14"/>',
  gallery: '<rect x="3" y="3.5" width="8" height="8" rx="1.5"/><rect x="13" y="3.5" width="8" height="8" rx="1.5"/><rect x="3" y="13.5" width="8" height="7" rx="1.5"/><rect x="13" y="13.5" width="8" height="7" rx="1.5"/>',
  cover: '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M7.5 10.5h9M9.5 13.5h5"/>',
  'media-text': '<rect x="3" y="5.5" width="8" height="13" rx="1.5"/><path d="M14 9h7M14 12h7M14 15h4.5"/>',
  buttons: '<rect x="3.5" y="7" width="17" height="10" rx="3"/><path d="M8 12h8"/>',
  column: '<rect x="7" y="3.5" width="10" height="17" rx="1.5"/>',
  group: '<rect x="3.5" y="3.5" width="17" height="17" rx="2" stroke-dasharray="3 2.5"/><rect x="7.5" y="7.5" width="9" height="9" rx="1"/>',
  separator: '<path d="M3 12h18"/><path d="M8 7.5h8M8 16.5h8" stroke-opacity=".35"/>',
  spacer: '<path d="M12 3.5v17M8.5 7 12 3.5 15.5 7M8.5 17l3.5 3.5 3.5-3.5"/><path d="M4 12h3M17 12h3"/>',
  embed: '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="m10 9.5-2.5 2.5 2.5 2.5M14 9.5l2.5 2.5-2.5 2.5"/>',
  'align-left': '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>',
  'align-center': '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>',
  'align-right': '<path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>',
  'align-justify': '<path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
  'align-none': '<rect x="7" y="7" width="10" height="10" rx="1.5"/><path d="M4 4h16M4 20h16"/>',
  'align-wide': '<rect x="4.5" y="7" width="15" height="10" rx="1.5"/><path d="M8 4h8M8 20h8"/>',
  'align-full': '<rect x="2" y="7" width="20" height="10" rx="1.5"/><path d="M8 4h8M8 20h8"/>',
  'block-left': '<rect x="4" y="5" width="8" height="8" rx="1.5"/><path d="M15 6h5M15 10h5M4 16h16M4 20h12"/>',
  'block-center': '<rect x="8" y="4" width="8" height="8" rx="1.5"/><path d="M4 16h16M6 20h12"/>',
  'block-right': '<rect x="12" y="5" width="8" height="8" rx="1.5"/><path d="M4 6h5M4 10h5M4 16h16M8 20h12"/>',
  'valign-top': '<path d="M4 4h16"/><rect x="8" y="7" width="8" height="8" rx="1"/>',
  'valign-center': '<path d="M4 12h3M17 12h3"/><rect x="8" y="8" width="8" height="8" rx="1"/>',
  'valign-bottom': '<path d="M4 20h16"/><rect x="8" y="9" width="8" height="8" rx="1"/>',
  drag: '<circle cx="9" cy="6.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="6.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="9" cy="17.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="17.5" r="1.2" fill="currentColor" stroke="none"/>',
  sidebar: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
  'list-view': '<path d="M4 6h3M10 6h10M4 12h3M10 12h10M4 18h3M10 18h10"/>',
  typography: '<path d="M4 19 9 5l5 14M5.8 14h6.4M15 19l3-8 3 8M15.9 16.6h4.2"/>',
  'parent': '<path d="M9 5h10v10M19 5 5 19"/>',
  more: '<circle cx="5" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.3" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.3" fill="currentColor" stroke="none"/>',
  highlight: '<path d="m9 15 6.5-6.5a2.1 2.1 0 0 0-3-3L6 12l-1 4 4-1ZM4 20h16"/>',
  superscript: '<path d="m4 8 8 10M12 8l-8 10M15 6.5a1.75 1.75 0 1 1 3.1 1.1L15 11h4"/>',
  subscript: '<path d="m4 6 8 10M12 6l-8 10M15 15.5a1.75 1.75 0 1 1 3.1 1.1L15 20h4"/>',
  'inline-code': '<path d="m8 8-4 4 4 4M16 8l4 4-4 4"/>',
  'row-before': '<rect x="3.5" y="11" width="17" height="9" rx="1.5"/><path d="M12 3.5v5M9.5 6h5"/>',
  'row-after': '<rect x="3.5" y="4" width="17" height="9" rx="1.5"/><path d="M12 15.5v5M9.5 18h5"/>',
  'col-before': '<rect x="11" y="3.5" width="9" height="17" rx="1.5"/><path d="M3.5 12h5M6 9.5v5"/>',
  'col-after': '<rect x="4" y="3.5" width="9" height="17" rx="1.5"/><path d="M15.5 12h5M18 9.5v5"/>',
  'row-delete': '<rect x="3.5" y="8" width="17" height="8" rx="1.5"/><path d="m9.5 10 5 4M14.5 10l-5 4"/>',
  'col-delete': '<rect x="8" y="3.5" width="8" height="17" rx="1.5"/><path d="m10 9.5 4 5M14 9.5l-4 5"/>',
  'insert-before': '<path d="M12 4v6M9 7h6"/><rect x="4" y="13" width="16" height="7" rx="1.5"/>',
  'insert-after': '<rect x="4" y="4" width="16" height="7" rx="1.5"/><path d="M12 14v6M9 17h6"/>',
  ungroup: '<rect x="3.5" y="3.5" width="10" height="10" rx="1.5" stroke-dasharray="3 2.5"/><rect x="10.5" y="10.5" width="10" height="10" rx="1.5"/>',
  'text-color': '<path d="M6 16 11 4h2l5 12M8 11h8"/><path d="M4 20h16" stroke-width="3"/>',
  'background-color': '<rect x="4" y="4" width="16" height="16" rx="3" fill="currentColor" fill-opacity=".18"/><path d="M8 15l4-8 4 8M9.4 12.5h5.2"/>'
};
// Legacy Font Awesome names used by 1.x app blocks and toolbar buttons.
var FA_MAP = {
  'fa-paragraph': 'paragraph', 'fa-heading': 'heading', 'fa-image': 'photo', 'fa-list': 'list-bullet',
  'fa-quote-left': 'quote', 'fa-code': 'code-bracket', 'fa-file-code': 'code-bracket-square',
  'fa-minus': 'separator', 'fa-arrows-up-down': 'spacer', 'fa-hand-pointer': 'buttons',
  'fa-square-share-nodes': 'embed', 'fa-puzzle-piece': 'puzzle-piece', 'fa-cube': 'squares-2x2',
  'fa-bolt': 'sparkles', 'fa-table': 'table-cells', 'fa-video': 'film', 'fa-music': 'musical-note'
};
function icon(name, cls) {
  name = String(name || 'squares-2x2');
  if (FA_MAP[name]) name = FA_MAP[name];
  var body = CUSTOM_ICONS[name] || HERO[name];
  if (!body && window.BasehimIcon) return window.BasehimIcon(name, cls || 'bhe-icon');
  if (!body) body = HERO['squares-2x2'];
  var stroke = CUSTOM_ICONS[name] ? ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"' : ' stroke-width="1.5"';
  return '<svg class="' + (cls || 'bhe-icon') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor"' + stroke
    + ' aria-hidden="true" focusable="false">' + body + '</svg>';
}

// -- events + filters (app surface)
var listeners = {};
var filters = {};
function on(ev, cb) { (listeners[ev] = listeners[ev] || []).push(cb); }
function emit(ev) {
  var args = Array.prototype.slice.call(arguments, 1);
  (listeners[ev] || []).forEach(function (cb) {
    try { cb.apply(null, args); } catch (e) { console.error('[BasehimEditor] listener error', e); }
  });
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

// -- block registry
var registry = {};
var toolbarButtons = [];
var blockActions = [];
var sidebarPanels = [];

var CATEGORIES = [
  ['text', 'Text'], ['media', 'Media'], ['design', 'Design'], ['widgets', 'Widgets'],
  ['embed', 'Embeds'], ['custom', 'Custom'], ['layout', 'Design']
];
function categoryTitle(slug) {
  for (var i = 0; i < CATEGORIES.length; i++) if (CATEGORIES[i][0] === slug) return CATEGORIES[i][1];
  return slug ? slug.charAt(0).toUpperCase() + slug.slice(1) : 'Custom';
}

function registerBlock(type, def) {
  def = def || {};
  var supports = Object.assign({ anchor: true, className: true, align: false, color: false, fontSize: false }, def.supports || {});
  registry[type] = {
    type: type,
    title: def.title || type,
    description: def.description || '',
    icon: def.icon || 'squares-2x2',
    category: def.category === 'layout' ? 'design' : (def.category || 'custom'),
    defaults: def.defaults || {},
    keywords: def.keywords || [],
    supports: supports,
    edit: def.edit || null,
    inspector: def.inspector || null,
    toolbar: def.toolbar || null,
    save: def.save || null,
    transforms: def.transforms || null,      // {to: {type: fn(block) -> {data, innerBlocks}}}
    richField: def.richField || null,        // field split/merged by Enter / Backspace
    parent: def.parent || null,              // allowed parent types (child-only blocks)
    allowedBlocks: def.allowedBlocks || null,
    container: !!def.container,
    template: def.template || null,          // fn(data) -> [[type, data, inner?], …]
    styles: def.styles || null,
    inserter: def.inserter !== false,
    core: !!def.__core
  };
  if (state.booted) { renderInserterPanel(); renderAll(); }
}
function defOf(type) { return registry[type] || null; }

// -- document model
var state = {
  blocks: [],
  selectedId: null,
  booted: false,
  leftPanel: null,         // null | 'inserter' | 'listview'
  sidebarOpen: true,
  sideTab: 'post',
  typing: false,
  insertionPoint: null     // {parentId, index} the inserter adds at
};

function makeBlock(type, data, inner) {
  var def = defOf(type) || {};
  var d = clone(def.defaults || {}) || {};
  Object.keys(data || {}).forEach(function (k) { d[k] = data[k]; });
  var b = { id: uid(), type: type, data: d };
  if (def.container) {
    if (inner && inner.length) b.innerBlocks = inner;
    else if (typeof def.template === 'function') b.innerBlocks = fromTemplate(def.template(d));
    else b.innerBlocks = [];
  } else if (inner && inner.length) {
    b.innerBlocks = inner;
  }
  return b;
}
function fromTemplate(list) {
  return (list || []).map(function (t) {
    if (!Array.isArray(t)) return normalizeBlock(t);
    return makeBlock(t[0], t[1] || {}, t[2] ? fromTemplate(t[2]) : null);
  });
}
function normalizeBlock(raw, fresh) {
  raw = raw || {};
  var b = {
    id: (!fresh && raw.id) ? String(raw.id) : uid(),
    type: raw.type || 'paragraph',
    data: (raw.data && typeof raw.data === 'object' && !Array.isArray(raw.data)) ? raw.data : {}
  };
  var def = defOf(b.type);
  if (Array.isArray(raw.innerBlocks)) b.innerBlocks = raw.innerBlocks.map(function (c) { return normalizeBlock(c, fresh); });
  else if (def && def.container) b.innerBlocks = [];
  return b;
}
function normalizeList(list, fresh) { return (list || []).filter(Boolean).map(function (b) { return normalizeBlock(b, fresh); }); }

// Depth-first walk. cb(block, list, index, parent) — return false to stop.
function walk(list, cb, parent) {
  for (var i = 0; i < list.length; i++) {
    if (cb(list[i], list, i, parent || null) === false) return false;
    if (list[i].innerBlocks && walk(list[i].innerBlocks, cb, list[i]) === false) return false;
  }
  return true;
}
function locate(id) {
  var hit = null;
  if (!id) return null;
  walk(state.blocks, function (b, list, i, parent) {
    if (b.id === id) { hit = { block: b, list: list, index: i, parent: parent }; return false; }
  });
  return hit;
}
function blockById(id) { var l = locate(id); return l ? l.block : null; }
function listOf(parentId) {
  if (!parentId) return state.blocks;
  var p = blockById(parentId);
  if (!p) return state.blocks;
  if (!p.innerBlocks) p.innerBlocks = [];
  return p.innerBlocks;
}
function parentsOf(id) {
  var out = [], l = locate(id);
  while (l && l.parent) { out.unshift(l.parent); l = locate(l.parent.id); }
  return out;
}
// Flat reading order (for Up/Down arrow navigation and tab order).
function flatOrder() { var out = []; walk(state.blocks, function (b) { out.push(b); }); return out; }
function cloneBlockFresh(b) { return normalizeBlock(clone(b), true); }

// Can `type` live inside `parent` (null = top level)?
function canInsert(type, parent) {
  var def = defOf(type);
  if (!def) return false;
  if (def.parent && (!parent || def.parent.indexOf(parent.type) < 0)) return false;
  if (parent) {
    var pdef = defOf(parent.type);
    if (pdef && pdef.allowedBlocks && pdef.allowedBlocks.indexOf(type) < 0) return false;
  }
  return true;
}

// -- serialization
function serializeBlock(b) {
  var def = defOf(b.type);
  var data = b.data;
  if (def && typeof def.save === 'function') {
    try {
      var html = def.save(clone(b));
      if (typeof html === 'string') data = Object.assign({}, data, { html: html });
    } catch (e) { console.error('[BasehimEditor] save() failed for ' + b.type, e); }
  }
  // Editor-only state (`_preview`, `_editing`, an unfinished upload) is not saved.
  var clean = {};
  Object.keys(data || {}).forEach(function (k) { if (k.charAt(0) !== '_' && k !== 'uploading') clean[k] = data[k]; });
  var out = { id: b.id, type: b.type, data: clean };
  if (b.innerBlocks && (b.innerBlocks.length || (def && def.container))) out.innerBlocks = b.innerBlocks.map(serializeBlock);
  return out;
}
function serialize() {
  var doc = { version: 1, blocks: state.blocks.map(serializeBlock) };
  doc = applyFilters('save.data', doc);
  return JSON.stringify(doc);
}
function isBlocksMode() { return !formatField || formatField.value === 'blocks'; }
function syncField() { if (contentField && isBlocksMode()) contentField.value = serialize(); }

function load(json) {
  var doc = null;
  try { doc = JSON.parse(json); } catch (e) { doc = null; }
  if (doc && Array.isArray(doc)) doc = { version: 1, blocks: doc };
  if (doc && Array.isArray(doc.blocks)) {
    doc = applyFilters('load.data', doc);
    state.blocks = normalizeList(doc.blocks);
  } else if (json && String(json).trim() !== '') {
    var converted = [];
    try { converted = htmlToBlocks(json); } catch (err) { console.error('[BasehimEditor] HTML conversion failed', err); }
    state.blocks = converted.length ? normalizeList(converted, true) : [makeBlock('html', { html: json })];
  } else {
    state.blocks = [];
  }
  if (!state.blocks.length) state.blocks = [makeBlock('paragraph', {})];
}

// -- change tracking + history
/*
 * Undo and redo over the whole document.
 *
 * Snapshots are the serialized block tree plus which block was selected.
 * Typing is debounced into one step per pause, so undo goes back a phrase at
 * a time rather than a letter at a time; structural edits (insert, move,
 * delete, transform) commit immediately so each is its own step.
 */
var history = { past: [], future: [], current: null, timer: null, muted: false, limit: 100 };

function snapshotNow() { return JSON.stringify({ b: state.blocks, s: state.selectedId }); }
function historyInit() { history.current = snapshotNow(); history.past = []; history.future = []; updateHistoryButtons(); }
function historyCommit() {
  if (history.muted) return;
  clearTimeout(history.timer);
  var snap = snapshotNow();
  if (snap === history.current) return;
  // A snapshot that differs only in selection is not an undo step.
  try {
    if (history.current && JSON.stringify(JSON.parse(snap).b) === JSON.stringify(JSON.parse(history.current).b)) {
      history.current = snap; return;
    }
  } catch (e) {}
  if (history.current) history.past.push(history.current);
  if (history.past.length > history.limit) history.past.shift();
  history.current = snap;
  history.future = [];
  updateHistoryButtons();
}
var historyLater = debounce(historyCommit, 600);
function historyRestore(snap) {
  history.muted = true;
  try {
    var s = JSON.parse(snap);
    state.blocks = normalizeList(s.b);
    state.selectedId = s.s && locate(s.s) ? s.s : null;
    renderAll();
    if (state.selectedId) focusBlock(state.selectedId, 'end');
    afterChange(true);
  } finally { history.muted = false; }
  history.current = snap;
  updateHistoryButtons();
}
function undo() {
  historyCommit();
  if (!history.past.length) return;
  history.future.push(history.current);
  historyRestore(history.past.pop());
}
function redo() {
  if (!history.future.length) return;
  history.past.push(history.current);
  historyRestore(history.future.pop());
}
function updateHistoryButtons() {
  var u = document.getElementById('bhe-undo'), r = document.getElementById('bhe-redo');
  if (u) u.disabled = !history.past.length && history.current === snapshotNow();
  if (r) r.disabled = !history.future.length;
}

// Called after every model change. `structural` commits a history step now.
function afterChange(structural) {
  syncField();
  try { window.BasehimEditorDirty = true; } catch (e) {}
  if (!history.muted) { if (structural) historyCommit(); else historyLater(); }
  updateHistoryButtons();
  scheduleChrome();
  emit('change', getBlocks());
}

// -- public operations
function getBlocks() { return clone(state.blocks); }
function setBlocks(list) {
  state.blocks = normalizeList(list, false);
  if (!state.blocks.length) state.blocks = [makeBlock('paragraph', {})];
  state.selectedId = null;
  renderAll();
  afterChange(true);
}
// insertBlock(type, data, index) — 1.x signature; `parentId` added in 2.0.
function insertBlock(type, data, atIndex, parentId, opts) {
  if (!registry[type]) { console.warn('[BasehimEditor] unknown block type', type); return null; }
  var b = makeBlock(type, data || {}, opts && opts.innerBlocks ? normalizeList(opts.innerBlocks, true) : null);
  insertBlockObjects([b], atIndex, parentId);
  if (!opts || opts.select !== false) selectBlock(b.id, { focus: 'start' });
  emit('block:add', clone(b));
  return b.id;
}
function insertBlockObjects(list, atIndex, parentId) {
  var target = listOf(parentId);
  if (atIndex == null || atIndex < 0 || atIndex > target.length) atIndex = target.length;
  Array.prototype.splice.apply(target, [atIndex, 0].concat(list));
  renderAll();
  afterChange(true);
}
function insertBlocks(list, atIndex, parentId) {
  if (!Array.isArray(list) || !list.length) return;
  var made = normalizeList(clone(list), true).filter(function (b) { return registry[b.type]; });
  if (!made.length) { console.warn('[BasehimEditor] nothing insertable'); return; }
  insertBlockObjects(made, atIndex, parentId);
  selectBlock(made[made.length - 1].id, { focus: 'end' });
  emit('template:insert', clone(made));
}
function updateBlock(id, data, opts) {
  var b = blockById(id); if (!b) return;
  Object.keys(data || {}).forEach(function (k) {
    if (data[k] === undefined) delete b.data[k]; else b.data[k] = data[k];
  });
  if (!opts || opts.rerender !== false) rerenderBlock(id);
  if (state.selectedId === id && (!opts || opts.inspector !== false)) renderInspectorSoon();
  afterChange(!!(opts && opts.structural));
}
function removeBlock(id, opts) {
  var l = locate(id); if (!l) return;
  var removed = l.list.splice(l.index, 1)[0];
  if (!state.blocks.length) state.blocks.push(makeBlock('paragraph', {}));
  var next = null;
  if (!opts || opts.focusPrevious !== false) {
    next = l.list[l.index - 1] || l.list[l.index] || l.parent || null;
  }
  if (state.selectedId === id) state.selectedId = null;
  renderAll();
  if (next) selectBlock(next.id, { focus: (l.list[l.index - 1] === next) ? 'end' : 'start' });
  else renderSidebar();
  afterChange(true);
  emit('block:remove', clone(removed));
}
function replaceBlock(id, newBlocks, focusWhere) {
  var l = locate(id); if (!l) return;
  newBlocks = Array.isArray(newBlocks) ? newBlocks : [newBlocks];
  Array.prototype.splice.apply(l.list, [l.index, 1].concat(newBlocks));
  renderAll();
  if (newBlocks[0]) selectBlock(newBlocks[0].id, { focus: focusWhere || 'end' });
  afterChange(true);
}
function moveBlock(id, toParentId, toIndex) {
  var l = locate(id); if (!l) return false;
  var parent = toParentId ? blockById(toParentId) : null;
  if (!canInsert(l.block.type, parent)) return false;
  // A block cannot be moved into itself or its own descendants.
  if (toParentId) {
    var p = locate(toParentId), inside = false;
    while (p) { if (p.block.id === id) { inside = true; break; } p = p.parent ? locate(p.parent.id) : null; }
    if (inside) return false;
  }
  var target = listOf(toParentId);
  var moved = l.list.splice(l.index, 1)[0];
  if (target === l.list && toIndex > l.index) toIndex--;
  target.splice(clamp(toIndex, 0, target.length), 0, moved);
  renderAll();
  selectBlock(moved.id, { focus: false });
  afterChange(true);
  return true;
}
function moveUpDown(id, dir) {
  var l = locate(id); if (!l) return;
  var to = l.index + dir;
  if (to < 0 || to >= l.list.length) return;
  l.list.splice(to, 0, l.list.splice(l.index, 1)[0]);
  renderAll();
  selectBlock(id, { focus: false });
  afterChange(true);
}
function duplicateBlock(id) {
  var l = locate(id); if (!l) return;
  var copy = cloneBlockFresh(l.block);
  l.list.splice(l.index + 1, 0, copy);
  renderAll();
  selectBlock(copy.id, { focus: 'end' });
  afterChange(true);
}
function insertDefaultAt(parentId, index, select) {
  var parent = parentId ? blockById(parentId) : null;
  var type = 'paragraph';
  if (parent) {
    var pdef = defOf(parent.type);
    if (pdef && pdef.allowedBlocks && pdef.allowedBlocks.indexOf('paragraph') < 0) type = pdef.allowedBlocks[0];
  }
  return insertBlock(type, {}, index, parentId, { select: select !== false });
}

var api = {
  registerBlock: registerBlock,
  addToolbarButton: function (d) { toolbarButtons.push(d || {}); renderHeaderPlugins(); },
  addBlockAction: function (d) { blockActions.push(d || {}); },
  addSidebarPanel: function (d) { sidebarPanels.push(d || {}); renderAppPanels(); },
  on: on, addFilter: addFilter,
  getBlocks: getBlocks, setBlocks: setBlocks,
  insertBlock: insertBlock, insertBlocks: insertBlocks, updateBlock: updateBlock, removeBlock: removeBlock,
  moveBlock: moveBlock, duplicateBlock: duplicateBlock,
  getSelected: function () { var b = blockById(state.selectedId); return b ? clone(b) : null; },
  select: function (id) { selectBlock(id, { focus: 'start' }); },
  deselect: function () { selectBlock(null); },
  serialize: serialize,
  refresh: function () { renderAll(); },
  htmlToBlocks: function (html) { return htmlToBlocks(html); },
  undo: function () { undo(); }, redo: function () { redo(); },
  icon: icon,
  config: CONFIG, version: VERSION
};

//  Rendering
var shell = {};          // element references, filled at boot
var listEl = null;       // root block list
var canvasEl = null;     // scrolling canvas
var innerEl = null;      // positioned content inside the canvas (toolbar lives here)

function renderAll() {
  if (!listEl) return;
  var scroll = canvasEl ? canvasEl.scrollTop : 0;
  listEl.textContent = '';
  renderList(listEl, state.blocks, null);
  renderRootAppender();
  if (canvasEl) canvasEl.scrollTop = scroll;
  markSelection();
  renderListView();
  renderBreadcrumb();
  scheduleChrome();
}

function renderList(container, list, parent) {
  container.classList.add('bhe-list');
  container.setAttribute('data-parent', parent ? parent.id : '');
  list.forEach(function (b) { container.appendChild(renderBlock(b, parent)); });
}

function blockTitle(b) {
  var def = defOf(b.type);
  if (b.type === 'heading') return 'Heading ' + (b.data.level || 2);
  return def ? def.title : b.type;
}

function renderBlock(b, parent) {
  var def = defOf(b.type);
  var wrap = h('div', {
    class: 'bhe-block nbe-block bhe-block--' + b.type,
    'data-id': b.id, 'data-type': b.type, tabindex: '-1',
    role: 'group', 'aria-label': 'Block: ' + blockTitle(b)
  });
  var body = h('div', { class: 'bhe-block__body nbe-block__body' });
  wrap.appendChild(body);
  applyPreviewStyles(wrap, b);

  if (def && typeof def.edit === 'function') {
    try { def.edit(body, b, api, makeCtx(b, body, wrap)); }
    catch (e) {
      console.error(e);
      body.innerHTML = '<div class="bhe-notice bhe-notice--error">This block could not be displayed: ' + esc(e.message) + '</div>';
    }
  } else {
    body.innerHTML = '<div class="bhe-notice">' + icon('exclamation-triangle', 'bhe-icon') + '<div><strong>Unknown block “' + esc(b.type)
      + '”.</strong> Its app may be switched off. The content is kept and will be saved unchanged.</div></div>';
  }
  if (b.id === state.selectedId) wrap.classList.add('is-selected');
  return wrap;
}

function rerenderBlock(id) {
  var old = wrapOf(id);
  var l = locate(id);
  if (!old || !l) return;
  var fresh = renderBlock(l.block, l.parent);
  old.parentNode.replaceChild(fresh, old);
  markSelection();
  scheduleChrome();
  renderListView();
}

function wrapOf(id) { return id && listEl ? listEl.querySelector('.bhe-block[data-id="' + id + '"]') : null; }

// Colours, font size and alignment are previewed on the wrapper so the canvas
// looks like the published page.
var FONT_SIZES = [
  { slug: 'small', name: 'S', label: 'Small', size: '0.875rem' },
  { slug: 'medium', name: 'M', label: 'Medium', size: '1.125rem' },
  { slug: 'large', name: 'L', label: 'Large', size: '1.5rem' },
  { slug: 'x-large', name: 'XL', label: 'Extra large', size: '2.25rem' }
];
function fontSizeValue(slug) { for (var i = 0; i < FONT_SIZES.length; i++) if (FONT_SIZES[i].slug === slug) return FONT_SIZES[i].size; return ''; }
function applyPreviewStyles(wrap, b) {
  var d = b.data || {}, def = defOf(b.type), sup = def ? def.supports : {};
  var body = wrap.firstChild;
  if (sup.color && sup.color !== 'custom') {
    body.style.color = isColor(d.textColor) ? d.textColor : '';
    body.style.backgroundColor = isColor(d.backgroundColor) ? d.backgroundColor : '';
    body.classList.toggle('has-background', isColor(d.backgroundColor));
  }
  if (sup.fontSize) body.style.fontSize = fontSizeValue(d.fontSize);
  if (sup.align === 'text' && d.align) body.style.textAlign = d.align;
  if (sup.align && sup.align !== 'text' && d.align) wrap.classList.add('is-align-' + d.align);
}
function isColor(v) { return typeof v === 'string' && /^#[0-9a-f]{3,8}$/i.test(v); }

// -- block context: what a core block's edit() gets besides (el, block, api)
function makeCtx(b, body, wrap) {
  return {
    wrap: wrap,
    update: function (data, opts) { updateBlock(b.id, data, opts); },
    rich: function (opts) { return RichText(b, opts); },
    inner: function (el, opts) { renderInner(b, el, opts || {}); },
    selected: function () { return state.selectedId === b.id; },
    placeholder: function (opts) { return Placeholder(opts); },
    pickMedia: pickMedia
  };
}

// A container's inner blocks, plus the "+" that adds one.
function renderInner(b, el, opts) {
  el.classList.add('bhe-inner');
  if (opts.layout) el.classList.add('bhe-inner--' + opts.layout);
  if (!b.innerBlocks) b.innerBlocks = [];
  renderList(el, b.innerBlocks, b);
  if (opts.appender !== false) {
    var def = defOf(b.type);
    var only = def && def.allowedBlocks && def.allowedBlocks.length === 1 ? def.allowedBlocks[0] : null;
    var add = h('button', {
      type: 'button', class: 'bhe-inner-appender' + (b.innerBlocks.length ? '' : ' is-empty'),
      'aria-label': only ? 'Add ' + (defOf(only) ? defOf(only).title : only) : 'Add block',
      title: only ? 'Add ' + (defOf(only) ? defOf(only).title : only) : 'Add block',
      html: icon('plus', 'bhe-icon')
    });
    add.addEventListener('mousedown', function (e) { e.preventDefault(); });
    add.addEventListener('click', function (e) {
      e.stopPropagation();
      if (only) { insertBlock(only, {}, b.innerBlocks.length, b.id); return; }
      openQuickInserter(add, { parentId: b.id, index: b.innerBlocks.length });
    });
    el.appendChild(add);
  }
}

// The click-to-write line after the last block, like Gutenberg's default
// block appender.
function renderRootAppender() {
  var last = state.blocks[state.blocks.length - 1];
  var lastIsEmptyText = last && last.type === 'paragraph' && isEmptyHtml(last.data.text);
  var ap = h('div', { class: 'bhe-appender' + (lastIsEmptyText ? ' is-hidden' : '') });
  var line = h('div', { class: 'bhe-appender__line', role: 'button', tabindex: '0', text: 'Type / to choose a block' });
  var plus = h('button', { type: 'button', class: 'bhe-appender__plus', 'aria-label': 'Add block', title: 'Add block', html: icon('plus', 'bhe-icon') });
  line.addEventListener('click', function () { insertDefaultAt(null, state.blocks.length); });
  line.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); insertDefaultAt(null, state.blocks.length); } });
  plus.addEventListener('click', function (e) { e.stopPropagation(); openQuickInserter(plus, { parentId: null, index: state.blocks.length }); });
  ap.appendChild(line); ap.appendChild(plus);
  listEl.appendChild(ap);
}

// A reusable empty-state card (image, gallery, table, embed…).
function Placeholder(opts) {
  var box = h('div', { class: 'bhe-placeholder' + (opts.className ? ' ' + opts.className : '') });
  box.appendChild(h('div', { class: 'bhe-placeholder__label', html: icon(opts.icon || 'squares-2x2', 'bhe-icon') + '<span>' + esc(opts.label || '') + '</span>' }));
  if (opts.instructions) box.appendChild(h('div', { class: 'bhe-placeholder__help', text: opts.instructions }));
  var row = h('div', { class: 'bhe-placeholder__fields' });
  box.appendChild(row);
  box.row = row;
  return box;
}

// -- media picking (Media Library with a URL fallback)
function pickMedia(onPick, opts) {
  opts = opts || {};
  if (window.BasehimMedia && BasehimMedia.openPicker) {
    BasehimMedia.openPicker({ onSelect: function (m) { if (m) onPick(m); } });
  } else {
    promptDialog(opts.urlLabel || 'File URL', '', function (url) { if (url) onPick({ url: url }); });
  }
}

//  Selection
function markSelection() {
  if (!listEl) return;
  Array.prototype.forEach.call(listEl.querySelectorAll('.bhe-block.is-selected, .bhe-block.has-child-selected'), function (el) {
    el.classList.remove('is-selected', 'has-child-selected');
  });
  var w = wrapOf(state.selectedId);
  if (!w) return;
  w.classList.add('is-selected');
  var p = w.parentElement ? w.parentElement.closest('.bhe-block') : null;
  while (p) { p.classList.add('has-child-selected'); p = p.parentElement ? p.parentElement.closest('.bhe-block') : null; }
}

function selectBlock(id, opts) {
  opts = opts || {};
  if (id && !locate(id)) id = null;
  var changed = state.selectedId !== id;
  state.selectedId = id;
  markSelection();
  if (changed) {
    if (state.sidebarOpen) state.sideTab = id ? 'block' : 'post';
    renderSidebar();
    emit('select', id ? clone(blockById(id)) : null);
  }
  renderToolbar();
  renderBreadcrumb();
  highlightListView();
  if (opts.focus) focusBlock(id, opts.focus);
  if (opts.scroll) { var w = wrapOf(id); if (w) w.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
}

function richEditables(wrap) {
  if (!wrap) return [];
  // Only this block's own editables, not those of blocks nested inside it.
  return Array.prototype.filter.call(wrap.querySelectorAll('[contenteditable="true"], textarea, input'), function (el) {
    return el.closest('.bhe-block') === wrap && !el.closest('.bhe-toolbar');
  });
}

function focusBlock(id, where) {
  var w = wrapOf(id);
  if (!w) return;
  var eds = richEditables(w);
  if (!eds.length) {
    // A container with nothing of its own to type into (Buttons, Columns
    // with content…): put the caret in its first / last child instead.
    var bb = blockById(id);
    if (where && bb && bb.innerBlocks && bb.innerBlocks.length) {
      var child = where === 'end' ? bb.innerBlocks[bb.innerBlocks.length - 1] : bb.innerBlocks[0];
      selectBlock(child.id, { focus: where });
      return;
    }
    w.focus({ preventScroll: false });
    return;
  }
  var ed = where === 'end' ? eds[eds.length - 1] : eds[0];
  if (typeof where === 'number') { ed = eds[0]; }
  ed.focus({ preventScroll: true });
  if (ed.isContentEditable) {
    if (typeof where === 'number') setCaretOffset(ed, where);
    else if (where === 'end') placeCaret(ed, false);
    else placeCaret(ed, true);
  } else if (ed.setSelectionRange && (ed.type === 'text' || ed.tagName === 'TEXTAREA')) {
    var n = where === 'end' ? ed.value.length : 0;
    try { ed.setSelectionRange(n, n); } catch (e) {}
  }
  var r = ed.getBoundingClientRect(), c = canvasEl.getBoundingClientRect();
  if (r.top < c.top + 60 || r.bottom > c.bottom - 40) w.scrollIntoView({ block: 'nearest' });
}

//  Floating block toolbar
var toolbarEl = null;

function tbButton(opts) {
  var b = h('button', {
    type: 'button', class: 'bhe-tb' + (opts.active ? ' is-active' : '') + (opts.text ? ' bhe-tb--text' : '') + (opts.className ? ' ' + opts.className : ''),
    'aria-label': opts.label, title: opts.label + (opts.shortcut ? ' (' + kbd(opts.shortcut) + ')' : ''),
    'aria-pressed': opts.toggle ? String(!!opts.active) : null,
    disabled: opts.disabled ? true : null,
    html: opts.text ? '<span>' + esc(opts.text) + '</span>' : icon(opts.icon, 'bhe-icon')
  });
  // mousedown: keep the text selection inside the block while clicking.
  b.addEventListener('mousedown', function (e) { if (!opts.allowFocus) e.preventDefault(); });
  if (opts.onClick) b.addEventListener('click', function (e) { e.stopPropagation(); opts.onClick(b, e); });
  return b;
}
function tbGroup(cls) { return h('div', { class: 'bhe-tb-group' + (cls ? ' ' + cls : ''), role: 'group' }); }

// The builder handed to block definitions' toolbar(tb, block, api).
function toolbarBuilder(root) {
  return {
    group: function () {
      var g = tbGroup();
      root.appendChild(g);
      return {
        el: g,
        button: function (opts) { var b = tbButton(opts); g.appendChild(b); return b; },
        dropdown: function (opts) { var b = tbButton(Object.assign({}, opts, { onClick: function (btn) { openMenu(btn, opts.items, opts); } })); b.classList.add('has-caret'); g.appendChild(b); return b; }
      };
    },
    button: function (opts) { return this.group().button(opts); },
    dropdown: function (opts) { return this.group().dropdown(opts); }
  };
}

function renderToolbar() {
  if (!toolbarEl) return;
  toolbarEl.textContent = '';
  var b = blockById(state.selectedId);
  if (!b || !isBlocksMode()) { toolbarEl.hidden = true; return; }
  toolbarEl.hidden = false;
  var def = defOf(b.type) || { title: b.type, icon: 'squares-2x2', supports: {} };
  var parents = parentsOf(b.id);

  // Parent selector.
  if (parents.length) {
    var pg = tbGroup('bhe-tb-group--parent');
    var par = parents[parents.length - 1], pdef = defOf(par.type);
    pg.appendChild(tbButton({ icon: pdef ? pdef.icon : 'parent', label: 'Select parent block: ' + blockTitle(par), onClick: function () { selectBlock(par.id, { focus: false }); var w = wrapOf(par.id); if (w) w.focus({ preventScroll: true }); } }));
    toolbarEl.appendChild(pg);
  }

  // Block switcher + mover.
  var g1 = tbGroup('bhe-tb-group--switcher');
  var sw = tbButton({ icon: def.icon, label: blockTitle(b) + ' — change block type or style', onClick: function (btn) { openSwitcher(btn, b); } });
  sw.classList.add('bhe-tb--switcher');
  g1.appendChild(sw);
  var drag = tbButton({ icon: 'drag', label: 'Drag', allowFocus: true });
  drag.classList.add('bhe-tb--drag');
  drag.setAttribute('draggable', 'true');
  drag.addEventListener('dragstart', function (e) { startBlockDrag(e, b.id); });
  drag.addEventListener('dragend', endBlockDrag);
  g1.appendChild(drag);
  var l = locate(b.id);
  var mover = h('div', { class: 'bhe-mover' });
  mover.appendChild(tbButton({ icon: 'chevron-up', label: 'Move up', shortcut: 'Ctrl+Shift+Alt+T', disabled: l.index === 0, onClick: function () { moveUpDown(b.id, -1); } }));
  mover.appendChild(tbButton({ icon: 'chevron-down', label: 'Move down', shortcut: 'Ctrl+Shift+Alt+Y', disabled: l.index === l.list.length - 1, onClick: function () { moveUpDown(b.id, 1); } }));
  g1.appendChild(mover);
  toolbarEl.appendChild(g1);

  // Block-specific controls.
  if (typeof def.toolbar === 'function') {
    try { def.toolbar(toolbarBuilder(toolbarEl), b, api); } catch (e) { console.error(e); }
  } else if (def.supports && def.supports.align === 'text') {
    alignToolbar(toolbarBuilder(toolbarEl), b);
  }

  // Inline formatting, for blocks that hold rich text.
  // Inline formatting: for text blocks, or while the caret is in a caption.
  var bw = wrapOf(b.id), ae = document.activeElement;
  var focusedRich = ae && ae.isContentEditable && ae.getAttribute('data-formats') === 'all' && bw && bw.contains(ae) && ae.closest('.bhe-block') === bw;
  if (bw && ((def.richField && richEditables(bw).some(function (e) { return e.getAttribute('data-formats') === 'all'; })) || focusedRich)) formatToolbar(toolbarEl);

  // Options.
  var g3 = tbGroup('bhe-tb-group--options');
  g3.appendChild(tbButton({ icon: 'ellipsis-vertical', label: 'Options', onClick: function (btn) { openBlockOptions(btn, b); } }));
  toolbarEl.appendChild(g3);

  positionToolbar();
}

function alignToolbar(tb, b, opts) {
  opts = opts || {};
  var cur = b.data.align || '';
  var items = (opts.values || ['left', 'center', 'right']).map(function (v) {
    return { icon: 'align-' + v, label: 'Align text ' + v, active: cur === v, onClick: function () { updateBlock(b.id, { align: cur === v ? '' : v }, { structural: true }); renderToolbar(); } };
  });
  tb.dropdown({ icon: cur ? 'align-' + cur : 'align-left', label: 'Align text', items: items });
}

function blockAlignToolbar(tb, b, values) {
  var cur = b.data.align || '';
  var names = { '': 'None', left: 'Align left', center: 'Align center', right: 'Align right', wide: 'Wide width', full: 'Full width' };
  var icons = { '': 'align-none', left: 'block-left', center: 'block-center', right: 'block-right', wide: 'align-wide', full: 'align-full' };
  var items = [''].concat(values || ['left', 'center', 'right', 'wide', 'full']).map(function (v) {
    return { icon: icons[v], label: names[v], active: cur === v, onClick: function () { updateBlock(b.id, { align: v }, { structural: true }); renderToolbar(); } };
  });
  tb.dropdown({ icon: icons[cur], label: 'Align', items: items });
}

function positionToolbar() {
  if (!toolbarEl || toolbarEl.hidden) return;
  var w = wrapOf(state.selectedId);
  if (!w) { toolbarEl.hidden = true; return; }
  var wr = w.getBoundingClientRect();
  var ir = innerEl.getBoundingClientRect();
  var cr = canvasEl.getBoundingClientRect();
  var th = toolbarEl.offsetHeight || 48;
  var top = wr.top - ir.top - th - 8;
  // Stick under the top of the canvas while the block scrolls past.
  var minTop = cr.top - ir.top + 8;
  if (top < minTop) top = Math.min(minTop, wr.bottom - ir.top - th);
  var left = wr.left - ir.left;
  var maxLeft = innerEl.clientWidth - toolbarEl.offsetWidth - 8;
  toolbarEl.style.top = Math.round(top) + 'px';
  toolbarEl.style.left = Math.round(clamp(left, 8, Math.max(8, maxLeft))) + 'px';
}

var chromeFrame = 0;
function scheduleChrome() {
  if (chromeFrame) return;
  chromeFrame = requestAnimationFrame(function () {
    chromeFrame = 0;
    positionToolbar();
    updateEmptyStates();
  });
}
function updateEmptyStates() {
  if (!listEl) return;
  var ap = listEl.querySelector(':scope > .bhe-appender');
  if (ap) {
    var last = state.blocks[state.blocks.length - 1];
    ap.classList.toggle('is-hidden', !!(last && last.type === 'paragraph' && isEmptyHtml(last.data.text)));
  }
}

// -- options (⋮) menu
function openBlockOptions(btn, b) {
  var l = locate(b.id);
  var items = [
    { icon: 'document-duplicate', label: 'Duplicate', shortcut: 'Ctrl+Shift+D', onClick: function () { duplicateBlock(b.id); } },
    { icon: 'insert-before', label: 'Add before', shortcut: 'Ctrl+Alt+T', onClick: function () { insertDefaultAt(l.parent ? l.parent.id : null, l.index); } },
    { icon: 'insert-after', label: 'Add after', shortcut: 'Ctrl+Alt+Y', onClick: function () { insertDefaultAt(l.parent ? l.parent.id : null, l.index + 1); } },
    { icon: 'clipboard-document', label: 'Copy block', onClick: function () { copyBlocksToClipboard([b]); } },
    'sep'
  ];
  if (canInsert('group', l.parent) && b.type !== 'group') items.push({ icon: 'group', label: 'Group', onClick: function () { wrapInGroup(b.id); } });
  if (b.type === 'group' && b.innerBlocks && b.innerBlocks.length) items.push({ icon: 'ungroup', label: 'Ungroup', onClick: function () { unwrap(b.id); } });
  blockActions.forEach(function (a) {
    try { if (a.when && !a.when(clone(b))) return; } catch (e) { return; }
    items.push({ icon: a.icon || 'sparkles', label: a.title || 'Action', onClick: function () { try { a.onClick(clone(b), api); } catch (e) { console.error(e); } } });
  });
  items.push('sep');
  items.push({ icon: 'trash', label: 'Delete', shortcut: 'Shift+Alt+Z', danger: true, onClick: function () { removeBlock(b.id); } });
  openMenu(btn, items, { label: 'Block options' });
}

function wrapInGroup(id) {
  var l = locate(id); if (!l) return;
  var g = makeBlock('group', {}, [l.block]);
  l.list.splice(l.index, 1, g);
  renderAll(); selectBlock(g.id, { focus: false }); afterChange(true);
}
function unwrap(id) {
  var l = locate(id); if (!l || !l.block.innerBlocks) return;
  var kids = l.block.innerBlocks;
  Array.prototype.splice.apply(l.list, [l.index, 1].concat(kids));
  renderAll(); if (kids[0]) selectBlock(kids[0].id, { focus: 'start' }); afterChange(true);
}

// Copies the block as Basehim JSON for pasting elsewhere in an editor, with an
// HTML rendering alongside for other apps.
function copyBlocksToClipboard(list) {
  var json = JSON.stringify({ basehimBlocks: list.map(serializeBlock) });
  var text = list.map(function (b) { return stripTags(b.data.text || b.data.code || b.data.html || ''); }).join('\n\n');
  try {
    if (navigator.clipboard && window.ClipboardItem) {
      navigator.clipboard.write([new ClipboardItem({
        'text/plain': new Blob([text], { type: 'text/plain' }),
        'text/html': new Blob(['<meta name="basehim-blocks" content="' + esc(json) + '">' + esc(text)], { type: 'text/html' })
      })]).then(function () { toast('Copied'); });
      return;
    }
  } catch (e) {}
  clipboardFallback = json;
  toast('Copied');
}
var clipboardFallback = null;

//  Menus, popovers, dialogs
var openPopover = null;
function closePopover() {
  if (openPopover) {
    var p = openPopover; openPopover = null;
    if (p.onClose) try { p.onClose(); } catch (e) {}
    p.el.remove();
    if (p.anchor) p.anchor.setAttribute('aria-expanded', 'false');
  }
}
function popover(anchor, content, opts) {
  opts = opts || {};
  // Pressing the button that opened this popover closes it. The outside-press
  // handler above deliberately ignores presses on the anchor, so before 1.2.44
  // the click that followed closed the menu and reopened it straight away:
  // the ⋮ button could open its menu but never close it (most noticeable on
  // phones, where tapping elsewhere is awkward).
  if (!opts.noToggle && openPopover && openPopover.anchor && openPopover.anchor === anchor) {
    closePopover();
    return null;
  }
  closePopover();
  var el = h('div', { class: 'bhe-popover' + (opts.className ? ' ' + opts.className : ''), role: opts.role || 'dialog', 'aria-label': opts.label || null });
  el.appendChild(content);
  document.body.appendChild(el);
  var r = anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : anchor;
  var w = el.offsetWidth, ht = el.offsetHeight;
  var left = opts.alignRight ? r.right - w : r.left;
  var top = r.bottom + 6;
  if (top + ht > window.innerHeight - 8 && r.top - ht - 6 > 8) top = r.top - ht - 6;
  el.style.left = clamp(left, 8, window.innerWidth - w - 8) + 'px';
  el.style.top = clamp(top, 8, Math.max(8, window.innerHeight - ht - 8)) + 'px';
  if (anchor.setAttribute) anchor.setAttribute('aria-expanded', 'true');
  openPopover = { el: el, anchor: anchor.setAttribute ? anchor : null, onClose: opts.onClose };
  return el;
}
document.addEventListener('mousedown', function (e) {
  if (openPopover && !openPopover.el.contains(e.target) && !(openPopover.anchor && openPopover.anchor.contains(e.target))) closePopover();
}, true);
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openPopover) { e.stopPropagation(); closePopover(); } }, true);

// items: [{icon, label, onClick, active, danger, shortcut}] | 'sep' | {heading}
function openMenu(anchor, items, opts) {
  opts = opts || {};
  var menu = h('div', { class: 'bhe-menu', role: 'menu' });
  var buttons = [];
  items.forEach(function (it) {
    if (it === 'sep') { menu.appendChild(h('div', { class: 'bhe-menu__sep', role: 'separator' })); return; }
    if (it.heading) { menu.appendChild(h('div', { class: 'bhe-menu__heading', text: it.heading })); return; }
    var btn = h('button', {
      type: 'button', role: it.active != null ? 'menuitemradio' : 'menuitem',
      class: 'bhe-menu__item' + (it.active ? ' is-active' : '') + (it.danger ? ' is-danger' : ''),
      'aria-checked': it.active != null ? String(!!it.active) : null,
      disabled: it.disabled ? true : null,
      html: (it.icon ? icon(it.icon, 'bhe-icon') : (it.textIcon ? '<span class="bhe-menu__texticon">' + esc(it.textIcon) + '</span>' : '<span class="bhe-icon"></span>'))
        + '<span class="bhe-menu__label">' + esc(it.label) + '</span>'
        + (it.shortcut ? '<kbd>' + esc(kbd(it.shortcut)) + '</kbd>' : (it.active ? icon('check', 'bhe-icon bhe-menu__check') : ''))
    });
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function () { closePopover(); if (it.onClick) it.onClick(); });
    menu.appendChild(btn);
    buttons.push(btn);
  });
  menu.addEventListener('keydown', function (e) {
    var i = buttons.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); (buttons[i + 1] || buttons[0]).focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (buttons[i - 1] || buttons[buttons.length - 1]).focus(); }
  });
  var pop = popover(anchor, menu, { label: opts.label, role: 'presentation', alignRight: opts.alignRight });
  if (!pop) return null;   // a second press on the same button closed it
  if (opts.focusFirst !== false && buttons[0] && !opts.keepFocus) {
    var act = menu.querySelector('.is-active') || buttons[0];
    if (document.activeElement && document.activeElement.isContentEditable) {/* keep caret for format menus */}
    else act.focus({ preventScroll: true });
  }
  return pop;
}

function promptDialog(label, value, cb, opts) {
  opts = opts || {};
  var box = h('form', { class: 'bhe-prompt' });
  var input = h('input', { type: opts.type || 'url', class: 'bhe-input', value: value || '', placeholder: opts.placeholder || '', 'aria-label': label });
  box.appendChild(h('label', { class: 'bhe-label', text: label }));
  box.appendChild(input);
  box.appendChild(h('div', { class: 'bhe-prompt__actions' }, [
    h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary', text: 'Cancel', onclick: function () { closePopover(); } }),
    h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary', text: opts.okLabel || 'Apply' })
  ]));
  box.addEventListener('submit', function (e) { e.preventDefault(); closePopover(); cb(input.value.trim()); });
  var anchor = opts.anchor || toolbarEl || shell.header;
  popover(anchor, box, { label: label });
  setTimeout(function () { input.focus(); input.select(); }, 0);
}

function toast(msg, type) {
  if (window.bhToast) { window.bhToast(msg, type || 'success'); return; }
  var t = h('div', { class: 'bhe-snackbar', role: 'status', text: msg });
  document.body.appendChild(t);
  setTimeout(function () { t.classList.add('is-leaving'); setTimeout(function () { t.remove(); }, 300); }, 2600);
}

//  Drag and drop
var drag = { id: null, target: null };
var dropLine = null;

function startBlockDrag(e, id) {
  drag.id = id;
  document.body.classList.add('bhe-is-dragging');
  try {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'basehim-block:' + id);
    var w = wrapOf(id);
    var ghost = h('div', { class: 'bhe-drag-chip', html: icon(defOf(blockById(id).type) ? defOf(blockById(id).type).icon : 'squares-2x2', 'bhe-icon') + '<span>' + esc(blockTitle(blockById(id))) + '</span>' });
    document.body.appendChild(ghost);
    e.dataTransfer.setDragImage(ghost, 16, 16);
    setTimeout(function () { ghost.remove(); }, 0);
    if (w) w.classList.add('is-dragging');
  } catch (err) {}
}
function endBlockDrag() {
  document.body.classList.remove('bhe-is-dragging');
  var w = wrapOf(drag.id); if (w) w.classList.remove('is-dragging');
  drag.id = null; drag.target = null;
  hideDropLine();
}
function hideDropLine() { if (dropLine) dropLine.hidden = true; }

// Where would a drop at (x, y) land? → {parentId, index, rect, before}
function dropTargetAt(target, x, y) {
  var blockEl = target && target.closest ? target.closest('.bhe-block') : null;
  // Over an empty container: drop inside it.
  var innerList = target && target.closest ? target.closest('.bhe-inner') : null;
  if (innerList && (!blockEl || !innerList.contains(blockEl))) {
    var pid = innerList.getAttribute('data-parent');
    var kids = Array.prototype.filter.call(innerList.children, function (c) { return c.classList.contains('bhe-block'); });
    var idx = kids.length;
    for (var k = 0; k < kids.length; k++) { var kr = kids[k].getBoundingClientRect(); if (y < kr.top + kr.height / 2) { idx = k; break; } }
    var rr = (kids[idx] || kids[kids.length - 1] || innerList).getBoundingClientRect();
    return { parentId: pid, index: idx, rect: rr, before: !!kids[idx] };
  }
  if (!blockEl) {
    var all = Array.prototype.filter.call(listEl.children, function (c) { return c.classList.contains('bhe-block'); });
    if (!all.length) return { parentId: null, index: 0, rect: listEl.getBoundingClientRect(), before: true };
    var last = all[all.length - 1].getBoundingClientRect();
    if (y > last.bottom) return { parentId: null, index: state.blocks.length, rect: last, before: false };
    return null;
  }
  var id = blockEl.getAttribute('data-id');
  var l = locate(id); if (!l) return null;
  var r = blockEl.getBoundingClientRect();
  var before = y < r.top + r.height / 2;
  return { parentId: l.parent ? l.parent.id : null, index: before ? l.index : l.index + 1, rect: r, before: before };
}
function showDropLine(t) {
  if (!dropLine) { dropLine = h('div', { class: 'bhe-dropline' }); innerEl.appendChild(dropLine); }
  var ir = innerEl.getBoundingClientRect();
  dropLine.hidden = false;
  dropLine.style.top = Math.round((t.before ? t.rect.top : t.rect.bottom) - ir.top - 1) + 'px';
  dropLine.style.left = Math.round(t.rect.left - ir.left) + 'px';
  dropLine.style.width = Math.round(t.rect.width) + 'px';
}

function installDragAndDrop() {
  canvasEl.addEventListener('dragover', function (e) {
    var files = e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0;
    if (!drag.id && !files) return;
    e.preventDefault();
    var t = dropTargetAt(e.target, e.clientX, e.clientY);
    if (t && drag.id) {
      var moving = blockById(drag.id);
      var parent = t.parentId ? blockById(t.parentId) : null;
      if (!canInsert(moving.type, parent) || (t.parentId && (t.parentId === drag.id || parentsOf(t.parentId).some(function (p) { return p.id === drag.id; })))) t = null;
    }
    drag.target = t;
    try { e.dataTransfer.dropEffect = t ? (drag.id ? 'move' : 'copy') : 'none'; } catch (err) {}
    if (t) showDropLine(t); else hideDropLine();
  });
  canvasEl.addEventListener('dragleave', function (e) { if (!canvasEl.contains(e.relatedTarget)) hideDropLine(); });
  canvasEl.addEventListener('drop', function (e) {
    var t = drag.target || dropTargetAt(e.target, e.clientX, e.clientY);
    hideDropLine();
    if (drag.id) {
      e.preventDefault();
      if (t) moveBlock(drag.id, t.parentId || null, t.index);
      endBlockDrag();
      return;
    }
    var files = e.dataTransfer && e.dataTransfer.files;
    if (files && files.length && t) { e.preventDefault(); dropFiles(files, t.parentId || null, t.index); }
  });
}

// Image, video and audio files dropped on the canvas upload and become blocks.
function dropFiles(files, parentId, index) {
  if (!(window.BasehimMedia && BasehimMedia.uploadFile)) { toast('Uploading needs the media library.', 'error'); return; }
  Array.prototype.forEach.call(files, function (file, i) {
    var type = /^image\//.test(file.type) ? 'image' : /^video\//.test(file.type) ? 'video' : /^audio\//.test(file.type) ? 'audio' : 'file';
    var id = insertBlock(type, { uploading: true }, index + i, parentId, { select: i === 0 });
    BasehimMedia.uploadFile(file, {}).then(function (resp) {
      var m = (resp && (resp.data || resp.media)) || resp || {};
      var url = m.url || m.file_url || '';
      var patch = { uploading: undefined };
      if (type === 'image') { patch.url = url; patch.alt = m.alt_text || ''; patch.id = m.id || null; }
      else if (type === 'file') { patch.href = url; patch.fileName = m.title || file.name; }
      else { patch.src = url; }
      updateBlock(id, patch, { structural: true });
    }).catch(function (err) {
      toast('Upload failed: ' + err.message, 'error');
      removeBlock(id, { focusPrevious: false });
    });
  });
}

//  Caret utilities
function sel() { return window.getSelection ? window.getSelection() : null; }
function rangeIn(el) {
  var s = sel();
  if (!s || !s.rangeCount) return null;
  var r = s.getRangeAt(0);
  return el.contains(r.startContainer) && el.contains(r.endContainer) ? r : null;
}
function placeCaret(el, atStart) {
  var r = document.createRange();
  r.selectNodeContents(el);
  r.collapse(!!atStart);
  var s = sel(); s.removeAllRanges(); s.addRange(r);
}
function textBefore(el, r) {
  var pre = document.createRange();
  pre.selectNodeContents(el);
  pre.setEnd(r.startContainer, r.startOffset);
  return pre;
}
function caretAtStart(el) {
  var r = rangeIn(el); if (!r || !r.collapsed) return false;
  var pre = textBefore(el, r);
  var frag = pre.cloneContents();
  return pre.toString().replace(ZW_RE, '') === '' && !(frag.querySelector && frag.querySelector('img,br'));
}
function caretAtEnd(el) {
  var r = rangeIn(el); if (!r || !r.collapsed) return false;
  var post = document.createRange();
  post.selectNodeContents(el);
  post.setStart(r.endContainer, r.endOffset);
  var frag = post.cloneContents();
  var hasBr = frag.querySelectorAll ? frag.querySelectorAll('br').length : 0;
  // A single trailing <br> is the browser's placeholder, not content.
  return post.toString().replace(ZW_RE, '') === '' && hasBr <= 1 && !(frag.querySelector && frag.querySelector('img'));
}
function getCaretOffset(el) { var r = rangeIn(el); return r ? textBefore(el, r).toString().length : 0; }
function setCaretOffset(el, n) {
  var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
  var node, left = n;
  while ((node = walker.nextNode())) {
    if (left <= node.nodeValue.length) {
      var r = document.createRange(); r.setStart(node, left); r.collapse(true);
      var s = sel(); s.removeAllRanges(); s.addRange(r); return;
    }
    left -= node.nodeValue.length;
  }
  placeCaret(el, false);
}
function caretRect() {
  var s = sel(); if (!s || !s.rangeCount) return null;
  var r = s.getRangeAt(0).cloneRange();
  var rects = r.getClientRects();
  if (rects.length) return rects[0];
  // Collapsed in an empty node: measure with a temporary marker.
  var span = document.createElement('span'); span.textContent = ZW;
  r.insertNode(span);
  var rect = span.getBoundingClientRect();
  var parent = span.parentNode; parent.removeChild(span); parent.normalize();
  return rect;
}
function onFirstLine(el) {
  var c = caretRect(); if (!c || (!c.top && !c.bottom)) return true;
  var lh = parseFloat(getComputedStyle(el).lineHeight) || 24;
  return c.top - el.getBoundingClientRect().top < lh * 0.8;
}
function onLastLine(el) {
  var c = caretRect(); if (!c || (!c.top && !c.bottom)) return true;
  var lh = parseFloat(getComputedStyle(el).lineHeight) || 24;
  return el.getBoundingClientRect().bottom - c.bottom < lh * 0.8;
}
// Splits an editable at the caret: returns [beforeHTML, afterHTML].
function splitHtmlAtCaret(el) {
  var r = rangeIn(el);
  if (!r) return [el.innerHTML, ''];
  if (!r.collapsed) r.deleteContents();
  var after = document.createRange();
  after.selectNodeContents(el);
  after.setStart(r.endContainer, r.endOffset);
  var frag = after.extractContents();
  var box = document.createElement('div'); box.appendChild(frag);
  return [cleanRich(el.innerHTML), cleanRich(box.innerHTML)];
}
// The browser leaves empty formatting tags and placeholder <br>s behind.
function cleanRich(html) {
  var s = String(html || '');
  s = s.replace(ZW_RE, '');
  s = s.replace(/<(b|strong|i|em|u|s|code|mark|sub|sup|a|span)\b[^>]*>(\s|&nbsp;)*<\/\1>/gi, '');
  s = s.replace(/^(<br\s*\/?>)+$/i, '').replace(/<br\s*\/?>$/i, '');
  return isEmptyHtml(s) && !/<br/i.test(s) ? '' : s;
}

//  RichText — a contenteditable bound to a block field
/*
 * opts:
 *   field        data key holding the HTML (default: the block's richField)
 *   tagName      element to create (p, h2, figcaption, li, td…)
 *   placeholder  shown while empty
 *   formats      'all' (bold, italic, link…) or 'none' (plain text)
 *   split        Enter splits the block (default true for the richField)
 *   lineBreaks   Enter inserts a line break instead of splitting
 *   plain        store text, not HTML (code, preformatted keep whitespace)
 *   value/onChange  for values that are not a top-level data key (table
 *                   cells, list items) — the caller owns storage
 */
function RichText(b, opts) {
  opts = opts || {};
  var def = defOf(b.type) || {};
  var field = opts.field || def.richField || 'text';
  var primary = field === def.richField && !opts.onChange;
  var el = document.createElement(opts.tagName || 'div');
  el.className = 'bhe-rich nbe-text' + (opts.className ? ' ' + opts.className : '');
  el.contentEditable = 'true';
  el.spellcheck = true;
  el.setAttribute('role', 'textbox');
  el.setAttribute('aria-multiline', 'true');
  el.setAttribute('aria-label', opts.label || opts.placeholder || 'Text');
  el.setAttribute('data-formats', opts.formats || 'all');
  el.setAttribute('data-placeholder', opts.placeholder || '');
  if (opts.plain) el.classList.add('is-plain');
  var value = opts.onChange ? (opts.value || '') : (b.data[field] || '');
  if (opts.plain) el.textContent = value; else el.innerHTML = value;
  toggleEmpty();

  function read() { return opts.plain ? el.innerText.replace(/\n$/, '') : cleanRich(el.innerHTML); }
  function toggleEmpty() {
    var empty = opts.plain ? el.textContent === '' : isEmptyHtml(el.innerHTML) && !/<br[^>]*>.+/i.test(el.innerHTML);
    el.classList.toggle('is-empty', empty);
    var w = el.closest ? el.closest('.bhe-block') : null;
    if (w && primary) w.classList.toggle('is-empty', empty);
  }
  function commit() {
    var v = read();
    toggleEmpty();
    if (opts.onChange) opts.onChange(v);
    else { var p = {}; p[field] = v; updateBlock(b.id, p, { rerender: false, inspector: false }); }
  }

  el.addEventListener('input', function () {
    commit();
    if (primary && b.type === 'paragraph') { markdownShortcut(b, el); slashCheck(b, el); }
  });
  el.addEventListener('focus', function () {
    lastRich = el;
    if (state.selectedId !== b.id) selectBlock(b.id);
    else if (!(defOf(b.type) || {}).richField) renderToolbar();   // caption focus shows formats
  });

  el.addEventListener('keydown', function (e) {
    if (slash.open && handleSlashKey(e)) return;
    if (e.isComposing) return;
    var mod = e.ctrlKey || e.metaKey;

    if (mod && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k' && opts.formats !== 'none') { e.preventDefault(); openLinkEditor(el); return; }
    if (mod && (e.key.toLowerCase() === 'b' || e.key.toLowerCase() === 'i' || e.key.toLowerCase() === 'u') && opts.formats === 'none') { e.preventDefault(); return; }

    if (e.key === 'Enter' && !mod) {
      if (opts.lineBreaks || e.shiftKey) {
        e.preventDefault();
        if (opts.plain) document.execCommand('insertText', false, '\n');
        else insertLineBreak(el);
        commit();
        return;
      }
      if (opts.onEnter) { e.preventDefault(); opts.onEnter(e, el); return; }
      if (primary && opts.split !== false) { e.preventDefault(); splitBlock(b, el); return; }
      e.preventDefault();
      return;
    }
    if (e.key === 'Backspace' && !mod && caretAtStart(el)) {
      if (opts.onBackspaceStart) { if (opts.onBackspaceStart(e, el) !== false) e.preventDefault(); return; }
      if (primary) { e.preventDefault(); mergeBackward(b, el); return; }
    }
    if (e.key === 'Delete' && !mod && caretAtEnd(el) && primary) {
      e.preventDefault(); mergeForward(b, el); return;
    }
    if (!e.shiftKey && !mod && !e.altKey) {
      if ((e.key === 'ArrowUp' && onFirstLine(el)) || (e.key === 'ArrowLeft' && caretAtStart(el))) {
        if (opts.onArrowOut && opts.onArrowOut(-1, e, el)) { e.preventDefault(); return; }
        if (focusAdjacent(b.id, -1, el)) e.preventDefault();
        return;
      }
      if ((e.key === 'ArrowDown' && onLastLine(el)) || (e.key === 'ArrowRight' && caretAtEnd(el))) {
        if (opts.onArrowOut && opts.onArrowOut(1, e, el)) { e.preventDefault(); return; }
        if (focusAdjacent(b.id, 1, el)) e.preventDefault();
        return;
      }
    }
    if (e.key.length === 1 && !mod) setTyping(true);
  });
  return el;
}
var lastRich = null;

function insertLineBreak(el) {
  var r = rangeIn(el); if (!r) return;
  r.deleteContents();
  var br = document.createElement('br');
  r.insertNode(br);
  // A <br> at the very end needs a second one to show the new line.
  if (!br.nextSibling || (br.nextSibling.nodeType === 3 && br.nextSibling.nodeValue === '' && !br.nextSibling.nextSibling)) {
    br.parentNode.insertBefore(document.createElement('br'), br.nextSibling);
  }
  var nr = document.createRange(); nr.setStartAfter(br); nr.collapse(true);
  var s = sel(); s.removeAllRanges(); s.addRange(nr);
}

// Moves the caret into the previous / next editable block in reading order.
function focusAdjacent(id, dir, fromEl) {
  var wrap = wrapOf(id);
  var eds = richEditables(wrap);
  var i = eds.indexOf(fromEl);
  if (i >= 0 && eds[i + dir]) { eds[i + dir].focus(); placeCaret(eds[i + dir], dir > 0); return true; }
  var order = flatOrder();
  var idx = -1;
  for (var k = 0; k < order.length; k++) if (order[k].id === id) { idx = k; break; }
  for (var j = idx + dir; j >= 0 && j < order.length; j += dir) {
    var w = wrapOf(order[j].id);
    var list = richEditables(w);
    if (list.length) {
      selectBlock(order[j].id);
      var target = dir > 0 ? list[0] : list[list.length - 1];
      target.focus();
      if (target.isContentEditable) placeCaret(target, dir > 0);
      return true;
    }
    // A block with nothing to type into (image, separator): select it whole.
    if (!(order[j].innerBlocks && order[j].innerBlocks.length)) {
      selectBlock(order[j].id); if (w) w.focus({ preventScroll: false }); return true;
    }
  }
  return false;
}

// -- split / merge
function splitBlock(b, el) {
  var def = defOf(b.type);
  var field = def.richField || 'text';
  var text = stripTags(el.innerHTML).trim();
  // Markdown-ish shortcuts that complete on Enter.
  if (b.type === 'paragraph' && (text === '```' || text === '---' || text === '***')) {
    var made = makeBlock(text === '```' ? 'code' : 'divider', {});
    replaceBlock(b.id, made, 'start');
    if (text !== '```') {
      var ml = locate(made.id);
      insertDefaultAt(ml.parent ? ml.parent.id : null, ml.index + 1);
    }
    return;
  }
  var parts = splitHtmlAtCaret(el);
  var l = locate(b.id);
  // Enter on an empty paragraph that is the last block in a container steps
  // out of the container, as in Gutenberg. Containers that only hold one kind
  // of child (Columns → Column) are climbed past.
  if (b.type === 'paragraph' && isEmptyHtml(parts[0]) && isEmptyHtml(parts[1]) && l.parent && l.index === l.list.length - 1 && l.list.length > 1) {
    var host = locate(l.parent.id);
    while (host && (!canInsert('paragraph', host.parent) || (defOf(host.block.type) && defOf(host.block.type).parent))) {
      host = host.parent ? locate(host.parent.id) : null;
    }
    if (host) {
      l.list.splice(l.index, 1);
      host.list.splice(host.index + 1, 0, b);
      renderAll(); selectBlock(b.id, { focus: 'start' }); afterChange(true);
      return;
    }
  }
  b.data[field] = parts[0];
  var nextType = isEmptyHtml(parts[1]) ? 'paragraph' : b.type;
  if (!canInsert(nextType, l.parent)) nextType = b.type;
  var data = {}; data[nextType === b.type ? field : 'text'] = parts[1];
  if (nextType === b.type) {
    ['level', 'align', 'textColor', 'backgroundColor', 'fontSize'].forEach(function (k) { if (b.data[k] != null) data[k] = b.data[k]; });
  }
  var nb = makeBlock(nextType, data);
  l.list.splice(l.index + 1, 0, nb);
  renderAll();
  selectBlock(nb.id, { focus: 'start' });
  afterChange(true);
}

function textLike(blk) { var d = defOf(blk.type); return !!(d && d.richField && blk.type !== 'code' && blk.type !== 'preformatted'); }

function mergeBackward(b, el) {
  var l = locate(b.id);
  var def = defOf(b.type);
  var field = def.richField;
  var prev = l.list[l.index - 1];
  // A heading, quote… with nothing before it turns into a paragraph first.
  if (!prev && b.type !== 'paragraph' && def.richField && canInsert('paragraph', l.parent)) {
    replaceBlock(b.id, makeBlock('paragraph', { text: b.data[field] || '' }), 'start');
    return;
  }
  if (!prev) {
    if (l.parent && isEmptyHtml(b.data[field]) && l.list.length > 1) { removeBlock(b.id); }
    return;
  }
  if (isEmptyHtml(b.data[field]) && b.type === 'paragraph') {
    l.list.splice(l.index, 1);
    renderAll();
    if (textLike(prev)) selectBlock(prev.id, { focus: 'end' });
    else { selectBlock(prev.id); var pw = wrapOf(prev.id); if (pw) pw.focus(); }
    afterChange(true);
    return;
  }
  if (textLike(prev)) {
    var pf = defOf(prev.type).richField;
    var joinAt = stripTags(prev.data[pf] || '').length;
    prev.data[pf] = cleanRich((prev.data[pf] || '') + (b.data[field] || ''));
    l.list.splice(l.index, 1);
    renderAll();
    selectBlock(prev.id);
    var pw2 = wrapOf(prev.id);
    var ed = pw2 ? richEditables(pw2)[0] : null;
    if (ed) { ed.focus(); setCaretOffset(ed, joinAt); }
    afterChange(true);
    return;
  }
  // The previous block holds no text (an image, a separator): select it.
  selectBlock(prev.id); var w = wrapOf(prev.id); if (w) w.focus();
}

function mergeForward(b, el) {
  var l = locate(b.id);
  var next = l.list[l.index + 1];
  if (!next) return;
  var field = defOf(b.type).richField;
  if (textLike(next)) {
    var nf = defOf(next.type).richField;
    var at = stripTags(b.data[field] || '').length;
    b.data[field] = cleanRich((b.data[field] || '') + (next.data[nf] || ''));
    l.list.splice(l.index + 1, 1);
    renderAll(); selectBlock(b.id);
    var ed = richEditables(wrapOf(b.id))[0];
    if (ed) { ed.focus(); setCaretOffset(ed, at); }
    afterChange(true);
  } else if (isEmptyHtml(b.data[field])) {
    removeBlock(b.id, { focusPrevious: false });
    selectBlock(next.id); var w = wrapOf(next.id); if (w) w.focus();
  }
}

// -- markdown-style shortcuts
function markdownShortcut(b, el) {
  var t = (el.textContent || '').replace(/\xa0/g, ' ');
  var m = t.match(/^(#{1,6}|[-*+]|1[.)]|>|\[\s?\]) $/);
  if (!m || getCaretOffset(el) !== t.length) return;
  var k = m[1], nb;
  if (k.charAt(0) === '#') nb = makeBlock('heading', { level: k.length, text: '' });
  else if (k === '>') nb = makeBlock('quote', { text: '' });
  else if (k === '1.' || k === '1)') nb = makeBlock('list', { style: 'ol', items: [''] });
  else nb = makeBlock('list', { style: 'ul', items: [''] });
  replaceBlock(b.id, nb, 'start');
}

//  "/" block search inside an empty paragraph
var slash = { open: false, el: null, items: [], active: 0, block: null };

function slashCheck(b, el) {
  var t = (el.textContent || '').replace(/\xa0/g, ' ');
  if (/^\/[^\s/]*$/.test(t)) { openSlash(b, el, t.slice(1).toLowerCase()); }
  else if (slash.open) closeSlash();
}
function searchBlocks(q, parent) {
  q = String(q || '').toLowerCase().trim();
  var out = [];
  Object.keys(registry).forEach(function (t) {
    var d = registry[t];
    if (!d.inserter || !canInsert(t, parent)) return;
    var hay = (d.title + ' ' + d.keywords.join(' ') + ' ' + d.category + ' ' + t).toLowerCase();
    if (!q) { out.push({ d: d, s: 0 }); return; }
    var title = d.title.toLowerCase();
    var s = title.indexOf(q) === 0 ? 3 : title.indexOf(q) >= 0 ? 2 : hay.indexOf(q) >= 0 ? 1 : 0;
    if (s) out.push({ d: d, s: s });
  });
  out.sort(function (a, c) { return c.s - a.s; });
  return out.map(function (x) { return x.d; });
}
function openSlash(b, el, q) {
  var l = locate(b.id);
  slash.block = b; slash.el = el; slash.open = true;
  var items = searchBlocks(q, l ? l.parent : null).slice(0, 9);
  slash.items = items;
  if (slash.active >= items.length) slash.active = 0;
  var menu = h('div', { class: 'bhe-slash', role: 'listbox', 'aria-label': 'Blocks' });
  if (!items.length) menu.appendChild(h('div', { class: 'bhe-slash__none', text: 'No results found.' }));
  items.forEach(function (d, i) {
    var it = h('button', { type: 'button', role: 'option', class: 'bhe-slash__item' + (i === slash.active ? ' is-active' : ''), 'aria-selected': String(i === slash.active), html: icon(d.icon, 'bhe-icon') + '<span>' + esc(d.title) + '</span>' });
    it.addEventListener('mousedown', function (e) { e.preventDefault(); slash.active = i; chooseSlash(); });
    menu.appendChild(it);
  });
  var r = caretRect() || el.getBoundingClientRect();
  var anchorRect = { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
  popover(anchorRect, menu, { className: 'bhe-popover--slash', role: 'presentation', onClose: function () { slash.open = false; } });
  slash.open = true;
}
function closeSlash() { if (slash.open) { slash.open = false; closePopover(); } }
function handleSlashKey(e) {
  if (!slash.open) return false;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    slash.active = (slash.active + (e.key === 'ArrowDown' ? 1 : -1) + slash.items.length) % Math.max(1, slash.items.length);
    openSlash(slash.block, slash.el, (slash.el.textContent || '').slice(1).toLowerCase());
    return true;
  }
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); chooseSlash(); return true; }
  if (e.key === 'Escape') { e.preventDefault(); closeSlash(); return true; }
  return false;
}
function chooseSlash() {
  var d = slash.items[slash.active];
  var b = slash.block;
  closeSlash();
  if (!d || !b) return;
  slash.active = 0;
  replaceBlock(b.id, makeBlock(d.type, {}), 'start');
}

//  Inline formats: bold, italic, link, highlight, code, strikethrough…
function activeRich() {
  var a = document.activeElement;
  if (a && a.isContentEditable && a.classList.contains('bhe-rich')) return a;
  return lastRich && document.contains(lastRich) ? lastRich : null;
}
function fire(el) { if (el) el.dispatchEvent(new Event('input', { bubbles: true })); }
function closestTag(el, tag) {
  var s = sel(); if (!s || !s.rangeCount) return null;
  var n = s.anchorNode;
  while (n && n !== el) { if (n.nodeType === 1 && n.nodeName === tag) return n; n = n.parentNode; }
  return null;
}
function toggleWrap(tag) {
  var el = activeRich(); if (!el) return;
  var existing = closestTag(el, tag.toUpperCase());
  if (existing) {
    while (existing.firstChild) existing.parentNode.insertBefore(existing.firstChild, existing);
    existing.remove();
  } else {
    var r = rangeIn(el); if (!r || r.collapsed) return;
    var w = document.createElement(tag);
    try { r.surroundContents(w); }
    catch (e) { w.appendChild(r.extractContents()); r.insertNode(w); }
    var s = sel(); s.removeAllRanges(); var nr = document.createRange(); nr.selectNodeContents(w); s.addRange(nr);
  }
  fire(el);
}
function runFormat(cmd) {
  var el = activeRich(); if (!el) return;
  if (document.activeElement !== el) el.focus();
  if (cmd === 'link') { openLinkEditor(el); return; }
  if (cmd === 'code') { toggleWrap('code'); return; }
  if (cmd === 'mark') { toggleWrap('mark'); return; }
  if (cmd === 'clear') { document.execCommand('removeFormat'); document.execCommand('unlink'); fire(el); return; }
  document.execCommand(cmd, false, null);
  fire(el);
  updateFormatStates();
}
var FORMATS = [
  { cmd: 'bold', icon: 'bold', label: 'Bold', shortcut: 'Ctrl+B' },
  { cmd: 'italic', icon: 'italic', label: 'Italic', shortcut: 'Ctrl+I' },
  { cmd: 'link', icon: 'link', label: 'Link', shortcut: 'Ctrl+K' }
];
var MORE_FORMATS = [
  { cmd: 'mark', icon: 'highlight', label: 'Highlight' },
  { cmd: 'code', icon: 'inline-code', label: 'Inline code' },
  { cmd: 'strikeThrough', icon: 'strikethrough', label: 'Strikethrough' },
  { cmd: 'underline', icon: 'underline', label: 'Underline', shortcut: 'Ctrl+U' },
  { cmd: 'subscript', icon: 'subscript', label: 'Subscript' },
  { cmd: 'superscript', icon: 'superscript', label: 'Superscript' },
  { cmd: 'clear', icon: 'x-mark', label: 'Clear formatting' }
];
function formatToolbar(root) {
  var g = tbGroup('bhe-tb-group--format');
  FORMATS.forEach(function (f) {
    var btn = tbButton({ icon: f.icon, label: f.label, shortcut: f.shortcut, toggle: true, onClick: function () { runFormat(f.cmd); } });
    btn.setAttribute('data-format', f.cmd);
    g.appendChild(btn);
  });
  var more = tbButton({
    icon: 'chevron-down', label: 'More', onClick: function (btn) {
      openMenu(btn, MORE_FORMATS.map(function (f) {
        return { icon: f.icon, label: f.label, shortcut: f.shortcut, onClick: function () { runFormat(f.cmd); } };
      }), { keepFocus: true, label: 'More formats' });
    }
  });
  g.appendChild(more);
  root.appendChild(g);
  updateFormatStates();
}
function updateFormatStates() {
  if (!toolbarEl) return;
  var el = activeRich();
  ['bold', 'italic'].forEach(function (c) {
    var b = toolbarEl.querySelector('[data-format="' + c + '"]');
    if (!b) return;
    var on = false; try { on = !!el && document.queryCommandState(c); } catch (e) {}
    b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on));
  });
  var lb = toolbarEl.querySelector('[data-format="link"]');
  if (lb) { var inLink = !!(el && closestTag(el, 'A')); lb.classList.toggle('is-active', inLink); lb.setAttribute('aria-pressed', String(inLink)); }
}
document.addEventListener('selectionchange', function () { if (state.booted) updateFormatStates(); });

// -- link popover
function openLinkEditor(el) {
  var s = sel(); if (!s || !s.rangeCount) return;
  var saved = s.getRangeAt(0).cloneRange();
  var existing = closestTag(el, 'A');
  var box = h('form', { class: 'bhe-link' });
  var input = h('input', { type: 'text', class: 'bhe-input', placeholder: 'Paste URL or type to search', value: existing ? existing.getAttribute('href') || '' : '', 'aria-label': 'URL' });
  var newTab = h('input', { type: 'checkbox', id: 'bhe-link-newtab' });
  if (existing && existing.getAttribute('target') === '_blank') newTab.checked = true;
  box.appendChild(h('div', { class: 'bhe-link__row' }, [input, h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary bhe-btn--small', 'aria-label': 'Apply', html: icon('check', 'bhe-icon') })]));
  box.appendChild(h('label', { class: 'bhe-toggle-row' }, [newTab, h('span', { text: 'Open in new tab' })]));
  if (existing) {
    box.appendChild(h('button', {
      type: 'button', class: 'bhe-btn bhe-btn--tertiary bhe-btn--small bhe-link__remove', html: icon('link-slash', 'bhe-icon') + ' Remove link',
      onclick: function () { restore(); unwrapNode(existing); fire(el); closePopover(); }
    }));
  }
  function restore() { el.focus(); var s2 = sel(); s2.removeAllRanges(); s2.addRange(saved); }
  box.addEventListener('submit', function (e) {
    e.preventDefault();
    var url = normalizeUrl(input.value);
    closePopover();
    restore();
    if (!url) return;
    if (existing) {
      existing.setAttribute('href', url);
    } else if (saved.collapsed) {
      var a = document.createElement('a'); a.href = url; a.textContent = input.value.trim();
      saved.insertNode(a);
      var r = document.createRange(); r.setStartAfter(a); r.collapse(true); var s3 = sel(); s3.removeAllRanges(); s3.addRange(r);
      existing = a;
    } else {
      document.execCommand('createLink', false, url);
      existing = closestTag(el, 'A');
      if (!existing) { var cand = el.querySelectorAll('a[href="' + url.replace(/"/g, '\\"') + '"]'); existing = cand[cand.length - 1] || null; }
    }
    if (existing) {
      if (newTab.checked) { existing.setAttribute('target', '_blank'); existing.setAttribute('rel', 'noopener noreferrer'); }
      else { existing.removeAttribute('target'); existing.removeAttribute('rel'); }
    }
    fire(el);
    updateFormatStates();
  });
  var rect = saved.getBoundingClientRect();
  if (!rect || (!rect.width && !rect.height)) rect = el.getBoundingClientRect();
  popover({ left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom }, box, { className: 'bhe-popover--link', label: 'Link' });
  setTimeout(function () { input.focus(); input.select(); }, 0);
}
function unwrapNode(n) { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); }
function normalizeUrl(u) {
  u = String(u || '').trim();
  if (!u) return '';
  if (/^(javascript|vbscript|data):/i.test(u)) return '';
  if (/^(https?:|mailto:|tel:|\/|#|\?|\.)/i.test(u)) return u;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(u)) return 'mailto:' + u;
  if (/^[\w-]+(\.[\w-]+)+/.test(u)) return 'https://' + u;
  return u;
}

// "Typing" hides the toolbar until the mouse moves, as in Gutenberg.
function setTyping(on) {
  if (state.typing === on) return;
  state.typing = on;
  document.body.classList.toggle('bhe-typing', on);
}

//  Insertion point
// Where the inserter adds a block: after the selected block, or at the end.
function currentInsertionPoint(type) {
  var b = blockById(state.selectedId);
  if (!b) {
    // Nothing selected: add at the end, filling a trailing empty paragraph.
    var last = state.blocks[state.blocks.length - 1];
    return { parentId: null, index: state.blocks.length, replace: isEmptyPara(last) && (!type || canInsert(type, null)) ? last.id : null };
  }
  var l = locate(b.id);
  // Walk up until the block type is allowed.
  while (l) {
    if (!type || canInsert(type, l.parent)) return { parentId: l.parent ? l.parent.id : null, index: l.index + 1, replace: isEmptyPara(l.block) ? l.block.id : null };
    l = l.parent ? locate(l.parent.id) : null;
  }
  return { parentId: null, index: state.blocks.length };
}
function isEmptyPara(b) { return b && b.type === 'paragraph' && isEmptyHtml(b.data.text); }

function insertFromInserter(type, point) {
  point = point || currentInsertionPoint(type);
  var parent = point.parentId ? blockById(point.parentId) : null;
  if (!canInsert(type, parent)) point = { parentId: null, index: state.blocks.length };
  // Choosing a block while an empty paragraph is selected replaces it.
  if (point.replace && blockById(point.replace)) {
    var nb = makeBlock(type, {});
    replaceBlock(point.replace, nb, 'start');
    emit('block:add', clone(nb));
    return nb.id;
  }
  return insertBlock(type, {}, point.index, point.parentId);
}

//  Inserter panel (left) and quick inserter (popover)
var inserterTab = 'blocks';
var inserterPoint = null;

function setLeftPanel(name) {
  state.leftPanel = state.leftPanel === name ? null : name;
  var left = shell.left;
  left.hidden = !state.leftPanel;
  document.getElementById('bhe-inserter-toggle').setAttribute('aria-pressed', String(state.leftPanel === 'inserter'));
  document.getElementById('bhe-inserter-toggle').classList.toggle('is-active', state.leftPanel === 'inserter');
  document.getElementById('bhe-listview-toggle').setAttribute('aria-pressed', String(state.leftPanel === 'listview'));
  document.getElementById('bhe-listview-toggle').classList.toggle('is-active', state.leftPanel === 'listview');
  shell.root.classList.toggle('has-left', !!state.leftPanel);
  if (state.leftPanel === 'inserter') { renderInserterPanel(); var s = left.querySelector('.bhe-search'); if (s) s.focus(); }
  if (state.leftPanel === 'listview') renderListView();
  scheduleChrome();
}

function renderInserterPanel(query) {
  if (!shell.left || state.leftPanel !== 'inserter') return;
  var left = shell.left;
  left.textContent = '';
  var head = h('div', { class: 'bhe-left__head' });
  var tabs = h('div', { class: 'bhe-tabs', role: 'tablist' });
  [['blocks', 'Blocks'], ['patterns', 'Patterns']].forEach(function (t) {
    tabs.appendChild(h('button', { type: 'button', role: 'tab', class: 'bhe-tab' + (inserterTab === t[0] ? ' is-active' : ''), 'aria-selected': String(inserterTab === t[0]), text: t[1], onclick: function () { inserterTab = t[0]; renderInserterPanel(); } }));
  });
  head.appendChild(tabs);
  head.appendChild(h('button', { type: 'button', class: 'bhe-iconbtn', 'aria-label': 'Close block inserter', html: icon('x-mark', 'bhe-icon'), onclick: function () { setLeftPanel(state.leftPanel); } }));
  left.appendChild(head);

  var search = h('input', { type: 'search', class: 'bhe-search', placeholder: inserterTab === 'blocks' ? 'Search' : 'Search patterns', 'aria-label': 'Search', value: query || '' });
  var searchWrap = h('div', { class: 'bhe-searchwrap', html: icon('magnifying-glass', 'bhe-icon') });
  searchWrap.insertBefore(search, searchWrap.firstChild);
  left.appendChild(searchWrap);
  var body = h('div', { class: 'bhe-left__body' });
  left.appendChild(body);

  function draw() {
    body.textContent = '';
    var q = search.value;
    if (inserterTab === 'patterns') { drawPatterns(body, q); return; }
    var point = inserterPoint || currentInsertionPoint();
    var parent = point.parentId ? blockById(point.parentId) : null;
    if (q.trim()) {
      var hits = searchBlocks(q, parent);
      if (!hits.length) { body.appendChild(h('p', { class: 'bhe-muted', text: 'No results found.' })); return; }
      body.appendChild(blockGrid(hits, point));
      return;
    }
    var cats = {};
    Object.keys(registry).forEach(function (t) {
      var d = registry[t];
      if (!d.inserter || !canInsert(t, parent)) return;
      (cats[d.category] = cats[d.category] || []).push(d);
    });
    ['text', 'media', 'design', 'widgets', 'embed'].concat(Object.keys(cats).filter(function (c) { return ['text', 'media', 'design', 'widgets', 'embed'].indexOf(c) < 0; }))
      .forEach(function (c) {
        if (!cats[c]) return;
        body.appendChild(h('h3', { class: 'bhe-left__cat', text: categoryTitle(c) }));
        body.appendChild(blockGrid(cats[c], point));
      });
  }
  search.addEventListener('input', draw);
  draw();
}

function blockGrid(defs, point, onPick) {
  var grid = h('div', { class: 'bhe-blockgrid', role: 'listbox' });
  defs.forEach(function (d) {
    var it = h('button', { type: 'button', role: 'option', class: 'bhe-blockgrid__item', title: d.description || d.title, html: '<span class="bhe-blockgrid__icon">' + icon(d.icon, 'bhe-icon') + '</span><span class="bhe-blockgrid__title">' + esc(d.title) + '</span>' });
    it.addEventListener('click', function () {
      if (onPick) { onPick(d); return; }
      insertFromInserter(d.type, point);
      inserterPoint = null;
      if (window.innerWidth < 782) setLeftPanel(state.leftPanel);
    });
    grid.appendChild(it);
  });
  return grid;
}

var templatesCache = null;
function drawPatterns(body, q) {
  function render(list) {
    body.textContent = '';
    q = String(q || '').toLowerCase();
    list = (list || []).filter(function (t) { return !q || String(t.title).toLowerCase().indexOf(q) >= 0; });
    if (!list.length) {
      body.appendChild(h('p', { class: 'bhe-muted', html: templatesCache && templatesCache.length ? 'No patterns match.' : 'No patterns yet. Save reusable layouts under <strong>Templates</strong> and they appear here.' }));
      return;
    }
    list.forEach(function (t) {
      var card = h('button', { type: 'button', class: 'bhe-pattern' });
      card.appendChild(h('div', { class: 'bhe-pattern__preview', text: patternPreview(t.blocks) }));
      card.appendChild(h('div', { class: 'bhe-pattern__title', html: esc(t.title) + ' <span>' + t.blocks.length + ' block' + (t.blocks.length === 1 ? '' : 's') + '</span>' }));
      card.addEventListener('click', function () {
        var p = inserterPoint || currentInsertionPoint();
        insertBlocks(t.blocks, p.index, p.parentId);
        if (p.replace && blockById(p.replace)) removeBlock(p.replace, { focusPrevious: false });
      });
      body.appendChild(card);
    });
  }
  if (templatesCache) { render(templatesCache); return; }
  body.appendChild(h('p', { class: 'bhe-muted', text: 'Loading patterns…' }));
  var url = CONFIG.templatesUrl || ((CONFIG.base || '') + '/admin/posts/editor/templates');
  fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) { templatesCache = (d && d.templates) || []; render(templatesCache); })
    .catch(function () { body.textContent = ''; body.appendChild(h('p', { class: 'bhe-muted', text: 'Could not load patterns.' })); });
}
function patternPreview(list) {
  var out = [];
  walk(normalizeList(list || [], true), function (b) {
    var t = stripTags(b.data.text || b.data.title || b.data.caption || (b.data.items || []).join(' ') || '').trim();
    if (t) out.push(t);
    return out.length < 4;
  });
  return out.join(' · ').slice(0, 160) || '(layout)';
}

// Quick inserter: a small popover with search and a handful of blocks.
var QUICK = ['paragraph', 'heading', 'image', 'list', 'table', 'quote'];
function openQuickInserter(anchor, point) {
  var parent = point.parentId ? blockById(point.parentId) : null;
  var box = h('div', { class: 'bhe-quick' });
  var search = h('input', { type: 'search', class: 'bhe-search', placeholder: 'Search', 'aria-label': 'Search for a block' });
  var sw = h('div', { class: 'bhe-searchwrap', html: icon('magnifying-glass', 'bhe-icon') });
  sw.insertBefore(search, sw.firstChild);
  box.appendChild(sw);
  var results = h('div', { class: 'bhe-quick__results' });
  box.appendChild(results);
  var browse = h('button', { type: 'button', class: 'bhe-quick__browse', text: 'Browse all' });
  box.appendChild(browse);
  function pick(d) { closePopover(); var id = insertBlock(d.type, {}, point.index, point.parentId); return id; }
  function draw() {
    results.textContent = '';
    var q = search.value.trim();
    var list = q ? searchBlocks(q, parent).slice(0, 9) : QUICK.map(defOf).filter(function (d) { return d && canInsert(d.type, parent); });
    if (!q && list.length < 6) searchBlocks('', parent).forEach(function (d) { if (list.length < 6 && list.indexOf(d) < 0) list.push(d); });
    if (!list.length) { results.appendChild(h('p', { class: 'bhe-muted', text: 'No results found.' })); return; }
    results.appendChild(blockGrid(list, point, pick));
  }
  search.addEventListener('input', draw);
  search.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); var first = results.querySelector('.bhe-blockgrid__item'); if (first) first.click(); }
  });
  browse.addEventListener('click', function () { closePopover(); inserterPoint = point; if (state.leftPanel !== 'inserter') setLeftPanel('inserter'); else renderInserterPanel(); });
  draw();
  popover(anchor, box, { className: 'bhe-popover--quick', label: 'Add a block' });
  setTimeout(function () { search.focus(); }, 0);
}

//  List View
var collapsed = {};
function renderListView() {
  if (!shell.left || state.leftPanel !== 'listview') return;
  var left = shell.left;
  left.textContent = '';
  var head = h('div', { class: 'bhe-left__head' }, [
    h('div', { class: 'bhe-left__title', text: 'List View' }),
    h('button', { type: 'button', class: 'bhe-iconbtn', 'aria-label': 'Close list view', html: icon('x-mark', 'bhe-icon'), onclick: function () { setLeftPanel('listview'); } })
  ]);
  left.appendChild(head);
  var tree = h('div', { class: 'bhe-tree', role: 'tree', 'aria-label': 'Block navigation structure' });
  (function rows(list, depth) {
    list.forEach(function (b) {
      var def = defOf(b.type);
      var kids = b.innerBlocks && b.innerBlocks.length;
      var row = h('div', { class: 'bhe-tree__row' + (b.id === state.selectedId ? ' is-selected' : ''), role: 'treeitem', 'data-id': b.id, 'aria-level': String(depth + 1), style: { paddingLeft: (8 + depth * 16) + 'px' } });
      var exp = h('button', { type: 'button', class: 'bhe-tree__exp' + (kids ? '' : ' is-leaf'), 'aria-label': collapsed[b.id] ? 'Expand' : 'Collapse', html: kids ? icon(collapsed[b.id] ? 'chevron-right' : 'chevron-down', 'bhe-icon') : '' });
      exp.addEventListener('click', function (e) { e.stopPropagation(); collapsed[b.id] = !collapsed[b.id]; renderListView(); });
      var label = treeLabel(b);
      var btn = h('button', { type: 'button', class: 'bhe-tree__btn', html: icon(def ? def.icon : 'squares-2x2', 'bhe-icon') + '<span class="bhe-tree__title">' + esc(blockTitle(b)) + '</span>' + (label ? '<span class="bhe-tree__snip">' + esc(label) + '</span>' : '') });
      btn.addEventListener('click', function () { selectBlock(b.id, { scroll: true, focus: false }); var w = wrapOf(b.id); if (w) w.focus({ preventScroll: true }); });
      btn.addEventListener('mouseenter', function () { var w = wrapOf(b.id); if (w) w.classList.add('is-highlighted'); });
      btn.addEventListener('mouseleave', function () { var w = wrapOf(b.id); if (w) w.classList.remove('is-highlighted'); });
      row.appendChild(exp); row.appendChild(btn);
      tree.appendChild(row);
      if (kids && !collapsed[b.id]) rows(b.innerBlocks, depth + 1);
    });
  })(state.blocks, 0);
  left.appendChild(h('div', { class: 'bhe-left__body' }, [tree]));
}
function treeLabel(b) {
  var d = b.data || {};
  if (d.anchor) return '#' + d.anchor;
  var t = stripTags(d.text || d.caption || d.summary || (Array.isArray(d.items) ? d.items[0] : '') || d.fileName || '').trim();
  return t.slice(0, 40);
}
function highlightListView() {
  if (!shell.left || state.leftPanel !== 'listview') return;
  Array.prototype.forEach.call(shell.left.querySelectorAll('.bhe-tree__row'), function (r) {
    r.classList.toggle('is-selected', r.getAttribute('data-id') === state.selectedId);
  });
}

//  Block switcher: transforms and styles
var TEXT_TYPES = ['paragraph', 'heading', 'list', 'quote', 'pullquote', 'preformatted', 'code', 'details'];

function textOf(b) {
  var d = b.data || {};
  if (b.type === 'list') return (d.items || []).join('<br>');
  if (b.type === 'code' || b.type === 'preformatted') return esc(d.code != null ? d.code : d.text || '').replace(/\n/g, '<br>');
  if (b.type === 'details') return d.summary || '';
  var def = defOf(b.type);
  return def && def.richField ? (d[def.richField] || '') : '';
}
function transformTo(b, type) {
  var def = defOf(b.type);
  if (def && def.transforms && def.transforms.to && def.transforms.to[type]) {
    var res = def.transforms.to[type](clone(b));
    if (res) return Array.isArray(res) ? res : [res];
  }
  if (TEXT_TYPES.indexOf(b.type) < 0 || TEXT_TYPES.indexOf(type) < 0) return null;
  var html = textOf(b);
  var keep = {};
  ['align', 'textColor', 'backgroundColor', 'fontSize', 'anchor', 'className'].forEach(function (k) { if (b.data[k] != null) keep[k] = b.data[k]; });
  switch (type) {
    case 'paragraph': return html.split(/<br\s*\/?>\s*<br\s*\/?>/i).map(function (p) { return makeBlock('paragraph', Object.assign({}, keep, { text: p })); });
    case 'heading': return [makeBlock('heading', Object.assign({}, keep, { text: html.replace(/<br\s*\/?>/gi, ' '), level: 2 }))];
    case 'list': return [makeBlock('list', { style: 'ul', items: html.split(/<br\s*\/?>/i).filter(function (s) { return !isEmptyHtml(s); }) })];
    case 'quote': return [makeBlock('quote', Object.assign({}, keep, { text: html }))];
    case 'pullquote': return [makeBlock('pullquote', { text: html })];
    case 'preformatted': return [makeBlock('preformatted', { text: html })];
    case 'code': return [makeBlock('code', { code: stripTags(html.replace(/<br\s*\/?>/gi, '\n')) })];
    case 'details': return [makeBlock('details', { summary: stripTags(html.replace(/<br\s*\/?>/gi, ' ')) }, [makeBlock('paragraph', {})])];
  }
  return null;
}
function availableTransforms(b) {
  var out = [];
  var l = locate(b.id);
  var def = defOf(b.type);
  var seen = {};
  function add(type) {
    if (seen[type] || type === b.type || !defOf(type) || !canInsert(type, l.parent)) return;
    seen[type] = 1; out.push(defOf(type));
  }
  if (def && def.transforms && def.transforms.to) Object.keys(def.transforms.to).forEach(add);
  if (TEXT_TYPES.indexOf(b.type) >= 0) TEXT_TYPES.forEach(add);
  if (canInsert('group', l.parent) && b.type !== 'group') add('group');
  if (canInsert('columns', l.parent) && b.type !== 'columns' && b.type !== 'column') add('columns');
  return out;
}
function openSwitcher(btn, b) {
  var def = defOf(b.type) || {};
  var items = [];
  if (b.type === 'heading') {
    items.push({ heading: 'Level' });
    [1, 2, 3, 4, 5, 6].forEach(function (lv) {
      items.push({ textIcon: 'H' + lv, label: 'Heading ' + lv, active: (b.data.level || 2) === lv, onClick: function () { updateBlock(b.id, { level: lv }, { structural: true }); selectBlock(b.id, { focus: 'end' }); renderToolbar(); } });
    });
    items.push('sep');
  }
  var trans = availableTransforms(b);
  if (trans.length) {
    items.push({ heading: 'Transform to' });
    trans.forEach(function (d) {
      items.push({ icon: d.icon, label: d.title, onClick: function () { doTransform(b, d.type); } });
    });
  }
  if (def.styles && def.styles.length) {
    items.push('sep');
    items.push({ heading: 'Styles' });
    def.styles.forEach(function (s) {
      var cur = b.data.styleName || def.styles[0].name;
      items.push({ label: s.label, active: cur === s.name, onClick: function () { updateBlock(b.id, { styleName: s.name === def.styles[0].name ? '' : s.name }, { structural: true }); } });
    });
  }
  if (!items.length) items.push({ label: 'No transforms available', disabled: true });
  openMenu(btn, items, { label: 'Change block type or style' });
}
function doTransform(b, type) {
  if (type === 'group' || (type === 'columns' && TEXT_TYPES.indexOf(b.type) < 0 && !(defOf(b.type).transforms && defOf(b.type).transforms.to && defOf(b.type).transforms.to.columns))) {
    var l = locate(b.id);
    var wrapper = type === 'group' ? makeBlock('group', {}, [b]) : makeBlock('columns', {}, [makeBlock('column', {}, [b]), makeBlock('column', {}, [makeBlock('paragraph', {})])]);
    l.list.splice(l.index, 1, wrapper);
    renderAll(); selectBlock(wrapper.id); afterChange(true);
    return;
  }
  var out = transformTo(b, type);
  if (!out || !out.length) { toast('That block cannot be turned into ' + (defOf(type) ? defOf(type).title : type) + '.', 'error'); return; }
  replaceBlock(b.id, out, 'end');
}

//  Settings sidebar
function setSidebar(open) {
  state.sidebarOpen = open;
  shell.root.classList.toggle('has-sidebar', open);
  shell.sidebar.hidden = !open;
  var t = document.getElementById('bhe-settings-toggle');
  if (t) { t.setAttribute('aria-pressed', String(open)); t.classList.toggle('is-active', open); }
  try { localStorage.setItem('bhe-sidebar', open ? '1' : '0'); } catch (e) {}
  scheduleChrome();
}
function renderSidebar() {
  if (!shell.sidebar) return;
  Array.prototype.forEach.call(shell.sidebar.querySelectorAll('.bhe-sidebar__tab'), function (t) {
    var on = t.getAttribute('data-tab') === state.sideTab;
    t.classList.toggle('is-active', on); t.setAttribute('aria-selected', String(on));
  });
  shell.postPanel.hidden = state.sideTab !== 'post';
  shell.blockPanel.hidden = state.sideTab !== 'block';
  if (state.sideTab === 'block') renderInspector();
}
var inspectorTimer = null;
function renderInspectorSoon() { clearTimeout(inspectorTimer); inspectorTimer = setTimeout(function () { if (state.sideTab === 'block') renderInspector(true); }, 60); }

// Panel open/closed state survives re-renders.
var panelOpen = { styles: true, settings: true, color: true, typography: true, advanced: false };
function inspectorPanel(parent, key, title, opened) {
  if (panelOpen[key] == null) panelOpen[key] = opened !== false;
  var sec = h('section', { class: 'bhe-panel' + (panelOpen[key] ? ' is-open' : '') });
  var btn = h('button', { type: 'button', class: 'bhe-panel__head', 'aria-expanded': String(!!panelOpen[key]), html: '<span>' + esc(title) + '</span>' + icon('chevron-up', 'bhe-icon bhe-panel__chev') });
  var body = h('div', { class: 'bhe-panel__body' });
  body.hidden = !panelOpen[key];
  btn.addEventListener('click', function () {
    panelOpen[key] = !panelOpen[key];
    sec.classList.toggle('is-open', panelOpen[key]); body.hidden = !panelOpen[key]; btn.setAttribute('aria-expanded', String(panelOpen[key]));
  });
  sec.appendChild(btn); sec.appendChild(body);
  parent.appendChild(sec);
  return body;
}

// Controls handed to core inspectors as the fourth argument.
function ui(body, b) {
  function field(label, help, control) {
    var row = h('div', { class: 'bhe-field' });
    if (label) row.appendChild(h('label', { class: 'bhe-label', text: label }));
    row.appendChild(control);
    if (help) row.appendChild(h('p', { class: 'bhe-help', text: help }));
    body.appendChild(row);
    return control;
  }
  return {
    el: body,
    text: function (label, value, onChange, o) {
      o = o || {};
      var i = h('input', { type: o.type || 'text', class: 'bhe-input', value: value == null ? '' : value, placeholder: o.placeholder || '' });
      i.addEventListener(o.live ? 'input' : 'change', function () { onChange(i.value); });
      if (o.live) i.addEventListener('change', function () { historyCommit(); });
      return field(label, o.help, i);
    },
    textarea: function (label, value, onChange, o) {
      o = o || {};
      var t = h('textarea', { class: 'bhe-input', rows: o.rows || 3, placeholder: o.placeholder || '' });
      t.value = value || '';
      t.addEventListener('input', function () { onChange(t.value); });
      return field(label, o.help, t);
    },
    number: function (label, value, onChange, o) {
      o = o || {};
      var i = h('input', { type: 'number', class: 'bhe-input', value: value == null ? '' : value, min: o.min, max: o.max, step: o.step || 1 });
      i.addEventListener('change', function () { onChange(i.value === '' ? null : Number(i.value)); });
      return field(label, o.help, i);
    },
    range: function (label, value, onChange, o) {
      o = o || {};
      var wrap = h('div', { class: 'bhe-range' });
      var r = h('input', { type: 'range', min: o.min || 0, max: o.max || 100, step: o.step || 1, value: value == null ? (o.min || 0) : value });
      var n = h('input', { type: 'number', class: 'bhe-input bhe-input--small', min: o.min || 0, max: o.max || 100, step: o.step || 1, value: value == null ? '' : value });
      r.addEventListener('input', function () { n.value = r.value; onChange(Number(r.value)); });
      r.addEventListener('change', function () { historyCommit(); });
      n.addEventListener('change', function () { r.value = n.value; onChange(Number(n.value)); historyCommit(); });
      wrap.appendChild(r); wrap.appendChild(n);
      if (o.unit) wrap.appendChild(h('span', { class: 'bhe-range__unit', text: o.unit }));
      return field(label, o.help, wrap);
    },
    select: function (label, value, options, onChange, o) {
      o = o || {};
      var s = h('select', { class: 'bhe-input' });
      options.forEach(function (op) {
        var v = Array.isArray(op) ? op[0] : op, t = Array.isArray(op) ? op[1] : op;
        var opt = h('option', { value: v, text: t }); if (String(v) === String(value == null ? '' : value)) opt.selected = true; s.appendChild(opt);
      });
      s.addEventListener('change', function () { onChange(s.value); });
      return field(label, o.help, s);
    },
    toggle: function (label, value, onChange, o) {
      o = o || {};
      var id = 'bhe-t-' + Math.random().toString(36).slice(2, 8);
      var c = h('input', { type: 'checkbox', id: id, class: 'bhe-switch' });
      c.checked = !!value;
      c.addEventListener('change', function () { onChange(c.checked); });
      var row = h('div', { class: 'bhe-field bhe-field--toggle' }, [c, h('label', { for: id, text: label })]);
      body.appendChild(row);
      if (o.help) body.appendChild(h('p', { class: 'bhe-help', text: o.help }));
      return c;
    },
    buttons: function (label, value, options, onChange, o) {
      o = o || {};
      var g = h('div', { class: 'bhe-segmented', role: 'radiogroup', 'aria-label': label });
      options.forEach(function (op) {
        var btn = h('button', { type: 'button', role: 'radio', class: 'bhe-segmented__btn' + (String(op[0]) === String(value) ? ' is-active' : ''), 'aria-checked': String(String(op[0]) === String(value)), title: op[2] || op[1], html: op[3] ? icon(op[3], 'bhe-icon') : esc(op[1]) });
        btn.addEventListener('click', function () { onChange(op[0]); });
        g.appendChild(btn);
      });
      return field(label, o.help, g);
    },
    media: function (label, url, onPick, onClear) {
      var box = h('div', { class: 'bhe-mediafield' });
      if (url) box.appendChild(h('img', { src: url, alt: '' }));
      box.appendChild(h('div', { class: 'bhe-mediafield__actions' }, [
        h('button', { type: 'button', class: 'bhe-btn bhe-btn--secondary bhe-btn--small', text: url ? 'Replace' : 'Choose', onclick: function () { pickMedia(onPick); } }),
        url && onClear ? h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary bhe-btn--small is-danger', text: 'Remove', onclick: onClear }) : null
      ]));
      return field(label, null, box);
    },
    help: function (text) { body.appendChild(h('p', { class: 'bhe-help', text: text })); },
    panel: function (key, title, opened) { return ui(inspectorPanel(body.closest('.bhe-inspector') || body, key, title, opened), b); }
  };
}

var PALETTE = [
  ['#000000', 'Black'], ['#475569', 'Slate'], ['#94a3b8', 'Grey'], ['#ffffff', 'White'],
  ['#ef4444', 'Red'], ['#f97316', 'Orange'], ['#eab308', 'Yellow'], ['#22c55e', 'Green'],
  ['#14b8a6', 'Teal'], ['#0ea5e9', 'Sky'], ['#2563eb', 'Blue'], ['#7c3aed', 'Violet'],
  ['#db2777', 'Pink'], ['#f1f5f9', 'Light grey'], ['#eff6ff', 'Pale blue'], ['#fef9c3', 'Pale yellow']
];
function colorControl(body, label, value, onChange) {
  var row = h('div', { class: 'bhe-color' });
  var head = h('div', { class: 'bhe-color__head' }, [
    h('span', { class: 'bhe-color__swatch' + (value ? '' : ' is-none'), style: { background: value || '' } }),
    h('span', { class: 'bhe-color__label', text: label }),
    value ? h('button', { type: 'button', class: 'bhe-linkbtn', text: 'Clear', onclick: function () { onChange(undefined); } }) : null
  ]);
  row.appendChild(head);
  var sw = h('div', { class: 'bhe-swatches', role: 'radiogroup', 'aria-label': label });
  PALETTE.forEach(function (p) {
    var b = h('button', { type: 'button', role: 'radio', class: 'bhe-swatches__item' + (String(value).toLowerCase() === p[0] ? ' is-active' : ''), 'aria-checked': String(String(value).toLowerCase() === p[0]), title: p[1], 'aria-label': p[1], style: { background: p[0] } });
    b.addEventListener('click', function () { onChange(p[0]); });
    sw.appendChild(b);
  });
  var custom = h('input', { type: 'color', class: 'bhe-swatches__custom', title: 'Custom color', 'aria-label': 'Custom color', value: isColor(value) && value.length === 7 ? value : '#000000' });
  custom.addEventListener('change', function () { onChange(custom.value); });
  sw.appendChild(custom);
  row.appendChild(sw);
  body.appendChild(row);
}

function renderInspector(keepScroll) {
  var panel = shell.blockPanel;
  var scroll = panel.scrollTop;
  panel.textContent = '';
  var b = blockById(state.selectedId);
  if (!b) { panel.appendChild(h('p', { class: 'bhe-muted bhe-inspector__empty', text: 'No block selected.' })); return; }
  var def = defOf(b.type) || { title: b.type, icon: 'squares-2x2', supports: {} };
  var wrap = h('div', { class: 'bhe-inspector' });
  panel.appendChild(wrap);
  wrap.appendChild(h('div', { class: 'bhe-blockcard' }, [
    h('span', { class: 'bhe-blockcard__icon', html: icon(def.icon, 'bhe-icon') }),
    h('div', {}, [h('h2', { class: 'bhe-blockcard__title', text: blockTitle(b) }), def.description ? h('p', { class: 'bhe-blockcard__desc', text: def.description }) : null])
  ]));

  if (def.styles && def.styles.length) {
    var sb = inspectorPanel(wrap, 'styles', 'Styles');
    var cur = b.data.styleName || def.styles[0].name;
    var grid = h('div', { class: 'bhe-styles' });
    def.styles.forEach(function (s) {
      grid.appendChild(h('button', { type: 'button', class: 'bhe-styles__item' + (cur === s.name ? ' is-active' : ''), text: s.label, onclick: function () { updateBlock(b.id, { styleName: s.name === def.styles[0].name ? '' : s.name }, { structural: true }); } }));
    });
    sb.appendChild(grid);
  }
  if (typeof def.inspector === 'function') {
    var body = inspectorPanel(wrap, 'settings', 'Settings');
    try { def.inspector(body, b, api, ui(body, b)); }
    catch (e) { console.error(e); body.textContent = 'Settings could not be shown: ' + e.message; }
    if (!body.childNodes.length) body.parentNode.remove();
  }
  if (def.supports.color) {
    var cb = inspectorPanel(wrap, 'color', 'Color');
    colorControl(cb, 'Text', b.data.textColor, function (v) { updateBlock(b.id, { textColor: v }, { structural: true }); });
    colorControl(cb, 'Background', b.data.backgroundColor, function (v) { updateBlock(b.id, { backgroundColor: v }, { structural: true }); });
    if (isColor(b.data.textColor) && isColor(b.data.backgroundColor) && contrast(b.data.textColor, b.data.backgroundColor) < 4.5) {
      cb.appendChild(h('p', { class: 'bhe-warning', text: 'This color combination may be hard for people to read. Try a brighter background and a darker text color, or the other way round.' }));
    }
  }
  if (def.supports.fontSize) {
    var tb = inspectorPanel(wrap, 'typography', 'Typography');
    var u = ui(tb, b);
    u.buttons('Font size', b.data.fontSize || '', [['', 'Default', 'Default']].concat(FONT_SIZES.map(function (f) { return [f.slug, f.name, f.label]; })), function (v) { updateBlock(b.id, { fontSize: v || undefined }, { structural: true }); });
    if (b.type === 'paragraph') u.toggle('Drop cap', b.data.dropCap, function (v) { updateBlock(b.id, { dropCap: v || undefined }, { structural: true }); }, { help: 'Show a large initial letter.' });
  }
  if (def.supports.anchor || def.supports.className) {
    var ab = inspectorPanel(wrap, 'advanced', 'Advanced', false);
    var ua = ui(ab, b);
    if (def.supports.anchor) ua.text('HTML anchor', b.data.anchor || '', function (v) { updateBlock(b.id, { anchor: v.trim().replace(/[^A-Za-z0-9_:.-]/g, '-').replace(/^-+/, '') || undefined }, { structural: true }); }, { help: 'Enter a word or two, without spaces, to make a unique web address just for this block, called an “anchor”. Then you can link directly to this section of your page.' });
    if (def.supports.className) ua.text('Additional CSS class(es)', b.data.className || '', function (v) { updateBlock(b.id, { className: v.replace(/[^\w\s-]/g, '').trim() || undefined }, { structural: true }); }, { help: 'Separate multiple classes with spaces.' });
  }
  if (keepScroll) panel.scrollTop = scroll;
}
function luminance(hex) {
  var c = hex.replace('#', ''); if (c.length === 3) c = c.split('').map(function (x) { return x + x; }).join('');
  var rgb = [0, 2, 4].map(function (i) { var v = parseInt(c.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
  return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}
function contrast(a, b) { try { var l1 = luminance(a), l2 = luminance(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); } catch (e) { return 21; } }

// App panels from addSidebarPanel() live at the end of the Post tab.
function renderAppPanels() {
  if (!shell.appPanels) return;
  shell.appPanels.textContent = '';
  sidebarPanels.forEach(function (p) {
    var key = 'app-' + (p.id || p.title);
    var body = inspectorPanel(shell.appPanels, key, p.title || p.id || 'App');
    try { p.render(body, api); } catch (e) { body.textContent = 'Panel error: ' + e.message; }
  });
}

//  Breadcrumb + header plugin buttons
function renderBreadcrumb() {
  var bc = shell.breadcrumb; if (!bc) return;
  bc.textContent = '';
  var docBtn = h('button', { type: 'button', class: 'bhe-crumb', text: CONFIG.typeLabel || 'Document', onclick: function () { selectBlock(null); setSidebarTab('post'); } });
  bc.appendChild(docBtn);
  var b = blockById(state.selectedId);
  if (!b) return;
  parentsOf(b.id).concat([b]).forEach(function (p, i, arr) {
    bc.appendChild(h('span', { class: 'bhe-crumb__sep', html: icon('chevron-right', 'bhe-icon') }));
    var last = i === arr.length - 1;
    bc.appendChild(h(last ? 'span' : 'button', { type: last ? null : 'button', class: 'bhe-crumb' + (last ? ' is-current' : ''), text: blockTitle(p), onclick: last ? null : function () { selectBlock(p.id); var w = wrapOf(p.id); if (w) w.focus(); } }));
  });
}
function setSidebarTab(tab) {
  state.sideTab = tab;
  if (!state.sidebarOpen) setSidebar(true);
  renderSidebar();
}
function renderHeaderPlugins() {
  var host = document.getElementById('bhe-plugin-buttons');
  if (!host) return;
  host.textContent = '';
  toolbarButtons.forEach(function (t) {
    var b = h('button', { type: 'button', class: 'bhe-hbtn' + (t.label ? ' bhe-hbtn--text' : ''), id: t.id || null, title: t.title || t.label || '', 'aria-label': t.title || t.label || 'Action', html: icon(t.icon || 'sparkles', 'bhe-icon') + (t.label ? '<span>' + esc(t.label) + '</span>' : '') });
    b.addEventListener('click', function () { try { t.onClick && t.onClick(api); } catch (e) { console.error(e); } });
    host.appendChild(b);
  });
}

//  Core blocks — text

// Adds a paragraph after `b` (in the same container) and puts the caret there.
function exitAfter(b) {
  var l = locate(b.id); if (!l) return;
  var parent = l.parent;
  var p = makeBlock('paragraph', {});
  if (!canInsert('paragraph', parent)) {
    // e.g. a list inside Buttons: climb until a paragraph is allowed.
    while (l && !canInsert('paragraph', l.parent)) l = l.parent ? locate(l.parent.id) : null;
    if (!l) return;
  }
  l.list.splice(l.index + 1, 0, p);
  renderAll();
  selectBlock(p.id, { focus: 'start' });
  afterChange(true);
}

// Enter inserts a line break; Enter on an empty last line leaves the block
// (quote, pullquote, preformatted, verse-like blocks).
function lineBreakEnter(b, field) {
  return function (e, el) {
    var html = el.innerHTML;
    if (caretAtEnd(el) && /<br\s*\/?>\s*(<br\s*\/?>\s*)?$/i.test(html) && !isEmptyHtml(html)) {
      var clean = html.replace(/(\s|<br\s*\/?>)+$/i, '');
      el.innerHTML = clean;
      var p = {}; p[field] = cleanRich(clean);
      updateBlock(b.id, p, { rerender: false, inspector: false });
      exitAfter(b);
      return;
    }
    insertLineBreak(el);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  };
}

// An auto-growing textarea for code and raw HTML.
function codeArea(b, field, opts) {
  opts = opts || {};
  var ta = h('textarea', {
    class: 'bhe-codearea' + (opts.className ? ' ' + opts.className : ''),
    spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off',
    placeholder: opts.placeholder || '', 'aria-label': opts.label || 'Code', rows: 1
  });
  ta.value = b.data[field] || '';
  function grow() { ta.style.height = 'auto'; ta.style.height = Math.max(ta.scrollHeight, 40) + 'px'; }
  ta.addEventListener('input', function () {
    var p = {}; p[field] = ta.value;
    updateBlock(b.id, p, { rerender: false, inspector: false });
    grow();
  });
  ta.addEventListener('focus', function () { if (state.selectedId !== b.id) selectBlock(b.id); });
  ta.addEventListener('keydown', function (e) {
    var start = ta.selectionStart, end = ta.selectionEnd, v = ta.value;
    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && start === end && start === v.length && /\n\n$/.test(v)) {
      e.preventDefault();
      ta.value = v.replace(/\n+$/, '');
      var p = {}; p[field] = ta.value; updateBlock(b.id, p, { rerender: false, inspector: false });
      exitAfter(b);
      return;
    }
    if (e.key === 'Backspace' && v === '' ) { e.preventDefault(); removeBlock(b.id); return; }
    if (e.key === 'ArrowUp' && start === 0 && end === 0) { if (focusAdjacent(b.id, -1, ta)) e.preventDefault(); return; }
    if (e.key === 'ArrowDown' && start === v.length) { if (focusAdjacent(b.id, 1, ta)) e.preventDefault(); return; }
    if (e.key === 'Tab' && opts.tabIndent && !e.ctrlKey && !e.altKey && !e.metaKey && !e.shiftKey && start !== end) {
      // Indent a selected range; a plain Tab still moves focus (accessibility).
      e.preventDefault();
      var lineStart = v.lastIndexOf('\n', start - 1) + 1;
      var chunk = v.slice(lineStart, end).replace(/^/gm, '  ');
      ta.value = v.slice(0, lineStart) + chunk + v.slice(end);
      ta.setSelectionRange(lineStart, lineStart + chunk.length);
      ta.dispatchEvent(new Event('input'));
    }
  });
  requestAnimationFrame(grow);
  setTimeout(grow, 50);
  return ta;
}

function headingToolbar(tb, b) {
  var lv = b.data.level || 2;
  tb.dropdown({
    text: 'H' + lv, label: 'Change level',
    items: [1, 2, 3, 4, 5, 6].map(function (n) {
      return { textIcon: 'H' + n, label: 'Heading ' + n, active: lv === n, onClick: function () { updateBlock(b.id, { level: n }, { structural: true }); focusBlock(b.id, 'end'); renderToolbar(); } };
    })
  });
  alignToolbar(tb, b);
}

registerBlock('paragraph', {
  __core: true,
  title: 'Paragraph', icon: 'paragraph', category: 'text',
  description: 'Start with the basic building block of all narrative.',
  keywords: ['text', 'p'],
  defaults: { text: '', align: '' },
  richField: 'text',
  supports: { align: 'text', color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var r = ctx.rich({ tagName: 'p', placeholder: b.data.placeholder || 'Type / to choose a block', label: 'Empty block; start writing or type forward slash to choose a block' });
    if (b.data.dropCap) r.classList.add('has-drop-cap');
    el.appendChild(r);
  }
});

registerBlock('heading', {
  __core: true,
  title: 'Heading', icon: 'heading', category: 'text',
  description: 'Introduce new sections and organize content to help visitors (and search engines) understand the structure of your content.',
  keywords: ['title', 'subtitle', 'h1', 'h2', 'h3', 'h4'],
  defaults: { text: '', level: 2, align: '' },
  richField: 'text',
  supports: { align: 'text', color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var lv = clamp(parseInt(b.data.level, 10) || 2, 1, 6);
    el.appendChild(ctx.rich({ tagName: 'h' + lv, className: 'bhe-heading', placeholder: 'Heading', label: 'Heading ' + lv }));
  },
  toolbar: headingToolbar
});

// -- List
function listItems(b) { return Array.isArray(b.data.items) && b.data.items.length ? b.data.items.slice() : ['']; }
function focusListItem(id, i, where) {
  var eds = richEditables(wrapOf(id));
  var ed = eds[clamp(i, 0, eds.length - 1)];
  if (!ed) return;
  ed.focus();
  if (typeof where === 'number') setCaretOffset(ed, where); else placeCaret(ed, where !== 'end');
}

registerBlock('list', {
  __core: true,
  title: 'List', icon: 'list-bullet', category: 'text',
  description: 'An organized collection of items displayed in a specific order.',
  keywords: ['bullet list', 'ordered list', 'numbered list', 'ul', 'ol'],
  defaults: { style: 'ul', items: [''] },
  supports: { color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var ordered = b.data.style === 'ol';
    var list = h(ordered ? 'ol' : 'ul', { class: 'bhe-list-block' });
    if (ordered && b.data.start && b.data.start !== 1) list.setAttribute('start', b.data.start);
    if (ordered && b.data.reversed) list.setAttribute('reversed', '');
    var items = listItems(b);
    items.forEach(function (html, i) {
      var li = ctx.rich({
        tagName: 'li', value: html, placeholder: 'List', split: false, label: 'List text',
        onChange: function (v) { var it = listItems(b); it[i] = v; b.data.items = it; updateBlock(b.id, { items: it }, { rerender: false, inspector: false }); },
        onEnter: function (e, ed) {
          var it = listItems(b);
          var parts = splitHtmlAtCaret(ed);
          if (isEmptyHtml(parts[0]) && isEmptyHtml(parts[1])) {
            // Empty item: leave the list here (splitting it if needed).
            var before = it.slice(0, i), after = it.slice(i + 1);
            var l = locate(b.id);
            var para = makeBlock('paragraph', {});
            var repl = [];
            if (before.length) { b.data.items = before; repl.push(b); }
            repl.push(para);
            if (after.length) repl.push(makeBlock('list', Object.assign({}, b.data, { items: after })));
            Array.prototype.splice.apply(l.list, [l.index, 1].concat(repl));
            renderAll(); selectBlock(para.id, { focus: 'start' }); afterChange(true);
            return;
          }
          it[i] = parts[0];
          it.splice(i + 1, 0, parts[1]);
          updateBlock(b.id, { items: it }, { structural: true });
          focusListItem(b.id, i + 1, 'start');
        },
        onBackspaceStart: function (e, ed) {
          var it = listItems(b);
          if (i > 0) {
            var at = stripTags(it[i - 1]).length;
            it[i - 1] = cleanRich(it[i - 1] + cleanRich(ed.innerHTML));
            it.splice(i, 1);
            updateBlock(b.id, { items: it }, { structural: true });
            focusListItem(b.id, i - 1, at);
            return true;
          }
          // First item: it becomes a paragraph before the list.
          var l = locate(b.id);
          var para = makeBlock('paragraph', { text: cleanRich(ed.innerHTML) });
          if (!canInsert('paragraph', l.parent)) return true;
          it.splice(0, 1);
          if (it.length) { b.data.items = it; l.list.splice(l.index, 0, para); }
          else l.list.splice(l.index, 1, para);
          renderAll(); selectBlock(para.id, { focus: 'start' }); afterChange(true);
          return true;
        }
      });
      list.appendChild(li);
    });
    el.appendChild(list);
  },
  toolbar: function (tb, b) {
    var g = tb.group();
    var ol = b.data.style === 'ol';
    g.button({ icon: 'list-bullet', label: 'Unordered', toggle: true, active: !ol, onClick: function () { updateBlock(b.id, { style: 'ul' }, { structural: true }); focusBlock(b.id, 'end'); renderToolbar(); } });
    g.button({ icon: 'numbered-list', label: 'Ordered', toggle: true, active: ol, onClick: function () { updateBlock(b.id, { style: 'ol' }, { structural: true }); focusBlock(b.id, 'end'); renderToolbar(); } });
  },
  inspector: function (el, b, api, ui) {
    if (b.data.style !== 'ol') return;
    ui.number('Start value', b.data.start || 1, function (v) { updateBlock(b.id, { start: v && v !== 1 ? v : undefined }, { structural: true }); }, { min: 1 });
    ui.toggle('Reverse order', !!b.data.reversed, function (v) { updateBlock(b.id, { reversed: v || undefined }, { structural: true }); });
  }
});

// -- Quote / Pullquote
registerBlock('quote', {
  __core: true,
  title: 'Quote', icon: 'quote', category: 'text',
  description: 'Give quoted text visual emphasis. “In quoting others, we cite ourselves.” — Julio Cortázar',
  keywords: ['blockquote', 'cite'],
  defaults: { text: '', cite: '' },
  richField: 'text',
  supports: { align: 'text', color: true, fontSize: true },
  styles: [{ name: 'default', label: 'Default' }, { name: 'plain', label: 'Plain' }],
  edit: function (el, b, api, ctx) {
    var q = h('blockquote', { class: 'bhe-quote' + (b.data.styleName ? ' is-style-' + b.data.styleName : '') });
    q.appendChild(ctx.rich({ tagName: 'p', placeholder: 'Add quote', label: 'Quote text', onEnter: lineBreakEnter(b, 'text') }));
    var showCite = !isEmptyHtml(b.data.cite) || ctx.selected();
    var cite = ctx.rich({ field: 'cite', tagName: 'cite', className: 'bhe-cite', placeholder: 'Add citation', label: 'Quote citation', split: false, onEnter: function () { exitAfter(b); } });
    if (!showCite) cite.classList.add('is-hidden-unselected');
    q.appendChild(cite);
    el.appendChild(q);
  }
});

registerBlock('pullquote', {
  __core: true,
  title: 'Pullquote', icon: 'pullquote', category: 'text',
  description: 'Give special visual emphasis to a quote from your text.',
  keywords: ['quote', 'callout'],
  defaults: { text: '', cite: '', align: '' },
  richField: 'text',
  supports: { align: ['left', 'right', 'wide', 'full'], color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var fig = h('figure', { class: 'bhe-pullquote' });
    var q = h('blockquote');
    q.appendChild(ctx.rich({ tagName: 'p', placeholder: 'Add quote', label: 'Pullquote text', onEnter: lineBreakEnter(b, 'text') }));
    q.appendChild(ctx.rich({ field: 'cite', tagName: 'cite', className: 'bhe-cite', placeholder: 'Add citation', label: 'Pullquote citation', split: false, onEnter: function () { exitAfter(b); } }));
    fig.appendChild(q);
    el.appendChild(fig);
  },
  toolbar: function (tb, b) { blockAlignToolbar(tb, b, ['left', 'right', 'wide', 'full']); alignToolbar(tb, b); }
});

// -- Code / Preformatted / Custom HTML
var CODE_LANGS = [['', 'Plain text'], ['bash', 'Bash / Shell'], ['css', 'CSS'], ['html', 'HTML'], ['javascript', 'JavaScript'], ['json', 'JSON'], ['markdown', 'Markdown'], ['php', 'PHP'], ['python', 'Python'], ['sql', 'SQL'], ['typescript', 'TypeScript'], ['xml', 'XML'], ['yaml', 'YAML']];

registerBlock('code', {
  __core: true,
  title: 'Code', icon: 'code-bracket', category: 'text',
  description: 'Display code snippets that respect your spacing and tabs.',
  keywords: ['pre', 'snippet', 'source', 'program'],
  defaults: { code: '', language: '' },
  supports: { color: false },
  edit: function (el, b) {
    var box = h('pre', { class: 'bhe-code' });
    box.appendChild(codeArea(b, 'code', { placeholder: 'Write code…', label: 'Code', tabIndent: true }));
    if (b.data.language) box.appendChild(h('span', { class: 'bhe-code__lang', text: b.data.language }));
    el.appendChild(box);
  },
  inspector: function (el, b, api, ui) {
    var known = CODE_LANGS.some(function (l) { return l[0] === (b.data.language || ''); });
    var opts = CODE_LANGS.slice();
    if (!known) opts.push([b.data.language, b.data.language]);
    ui.select('Language', b.data.language || '', opts, function (v) { updateBlock(b.id, { language: v }); });
  }
});

registerBlock('preformatted', {
  __core: true,
  title: 'Preformatted', icon: 'preformatted', category: 'text',
  description: 'Add text that respects your spacing and tabs, and also allows styling.',
  keywords: ['pre', 'monospace', 'poetry', 'verse'],
  defaults: { text: '' },
  richField: 'text',
  supports: { color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    el.appendChild(ctx.rich({ tagName: 'pre', className: 'bhe-pre', placeholder: 'Write preformatted text…', label: 'Preformatted text', onEnter: lineBreakEnter(b, 'text') }));
  }
});

registerBlock('html', {
  __core: true,
  title: 'Custom HTML', icon: 'code-bracket-square', category: 'widgets',
  description: 'Add custom HTML code and preview it as you edit.',
  keywords: ['raw', 'embed', 'script', 'markup'],
  defaults: { html: '' },
  supports: { anchor: false, className: false },
  edit: function (el, b) {
    if (b.data._preview) {
      var frame = h('iframe', { class: 'bhe-html-preview', sandbox: '', title: 'Custom HTML preview' });
      var css = Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"][data-bhe-preview]'), function (l) { return '<link rel="stylesheet" href="' + esc(l.href) + '">'; }).join('');
      frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8">' + css + '<style>body{margin:0;font:16px/1.6 system-ui,sans-serif;color:#1e1e1e}img,video,iframe{max-width:100%}</style></head><body>' + (b.data.html || '') + '</body></html>';
      frame.addEventListener('load', function () { try { frame.style.height = Math.max(60, frame.contentDocument.documentElement.scrollHeight) + 'px'; } catch (e) { frame.style.height = '200px'; } });
      el.appendChild(h('div', { class: 'bhe-html' }, [frame]));
      return;
    }
    var box = h('div', { class: 'bhe-html' });
    box.appendChild(codeArea(b, 'html', { placeholder: 'Write HTML…', label: 'HTML', className: 'bhe-codearea--html' }));
    el.appendChild(box);
  },
  toolbar: function (tb, b) {
    var g = tb.group();
    g.button({ text: 'HTML', label: 'Edit HTML', active: !b.data._preview, onClick: function () { b.data._preview = false; rerenderBlock(b.id); renderToolbar(); focusBlock(b.id, 'end'); } });
    g.button({ text: 'Preview', label: 'Preview', active: !!b.data._preview, onClick: function () { b.data._preview = true; rerenderBlock(b.id); renderToolbar(); } });
  }
});

// -- Details (an accordion: summary + hidden content)
registerBlock('details', {
  __core: true,
  title: 'Details', icon: 'details', category: 'text',
  description: 'Hide and show additional content.',
  keywords: ['accordion', 'summary', 'toggle', 'disclosure', 'faq', 'spoiler'],
  defaults: { summary: '', open: false },
  container: true,
  template: function () { return [['paragraph', {}]]; },
  supports: { color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var box = h('div', { class: 'bhe-details' + (b.data.open ? ' is-open' : '') });
    var head = h('div', { class: 'bhe-details__head', html: icon('chevron-right', 'bhe-icon bhe-details__marker') });
    head.appendChild(ctx.rich({
      field: 'summary', tagName: 'div', className: 'bhe-details__summary', placeholder: 'Type / to add a hidden block', label: 'Details summary', split: false,
      onEnter: function () {
        if (b.innerBlocks && b.innerBlocks.length) { selectBlock(b.innerBlocks[0].id, { focus: 'start' }); }
        else insertBlock('paragraph', {}, 0, b.id);
      }
    }));
    box.appendChild(head);
    var inner = h('div', { class: 'bhe-details__content' });
    ctx.inner(inner);
    box.appendChild(inner);
    el.appendChild(box);
  },
  inspector: function (el, b, api, ui) {
    ui.toggle('Open by default', !!b.data.open, function (v) { updateBlock(b.id, { open: v || undefined }, { structural: true }); });
  }
});

//  Core blocks — media

function mediaFromResp(resp) {
  var m = (resp && (resp.data || resp.media)) || resp || {};
  return { url: m.url || m.file_url || '', id: m.id || null, alt_text: m.alt_text || '', caption: m.caption || '', title: m.title || m.filename || '', mime_type: m.mime_type || m.mime || '' };
}

// Opens the file chooser and uploads what was picked. cb(media) per file.
function chooseAndUpload(accept, multiple, cb, onStart) {
  if (!(window.BasehimMedia && BasehimMedia.uploadFile)) { toast('Uploading needs the media library.', 'error'); return; }
  var input = h('input', { type: 'file', accept: accept || '', multiple: multiple ? true : null, style: { display: 'none' } });
  document.body.appendChild(input);
  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files || []);
    input.remove();
    if (!files.length) return;
    if (onStart) onStart(files.length);
    files.forEach(function (f) {
      BasehimMedia.uploadFile(f, {}).then(function (resp) {
        var m = mediaFromResp(resp);
        if (!m.title) m.title = f.name;
        cb(m, f);
      }).catch(function (err) { toast('Upload failed: ' + (err && err.message ? err.message : 'error'), 'error'); cb(null, f); });
    });
  });
  input.click();
}

// Upload / Media Library / Insert from URL — the row every media placeholder shows.
function mediaPlaceholderButtons(row, opts) {
  var canUpload = !!(window.BasehimMedia && BasehimMedia.uploadFile);
  if (canUpload) {
    row.appendChild(h('button', { type: 'button', class: 'bhe-btn bhe-btn--primary', text: 'Upload', onclick: function (e) {
      e.stopPropagation();
      chooseAndUpload(opts.accept, opts.multiple, function (m) { if (m) opts.onPick(m); }, opts.onStart);
    } }));
  }
  row.appendChild(h('button', { type: 'button', class: 'bhe-btn bhe-btn--secondary', text: 'Media Library', onclick: function (e) { e.stopPropagation(); pickMedia(opts.onPick, { urlLabel: opts.urlLabel }); } }));
  var urlBtn = h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary', text: 'Insert from URL' });
  urlBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    promptDialog(opts.urlLabel || 'URL', '', function (u) { u = normalizeUrl(u); if (u) opts.onPick({ url: u }); }, { anchor: urlBtn, placeholder: 'Paste or type URL', okLabel: 'Insert' });
  });
  row.appendChild(urlBtn);
}

function replaceMediaMenu(btn, opts) {
  var items = [
    { icon: 'photo', label: 'Open Media Library', onClick: function () { pickMedia(opts.onPick); } }
  ];
  if (window.BasehimMedia && BasehimMedia.uploadFile) items.push({ icon: 'arrow-up-tray', label: 'Upload', onClick: function () { chooseAndUpload(opts.accept, false, function (m) { if (m) opts.onPick(m); }); } });
  items.push({ icon: 'link', label: 'Insert from URL', onClick: function () { promptDialog(opts.urlLabel || 'URL', opts.current || '', function (u) { u = normalizeUrl(u); if (u) opts.onPick({ url: u }); }, { anchor: btn, okLabel: 'Apply' }); } });
  if (opts.onRemove) { items.push('sep'); items.push({ icon: 'trash', label: 'Reset', danger: true, onClick: opts.onRemove }); }
  openMenu(btn, items, { label: 'Replace' });
}

function uploadingBox(label) {
  return h('div', { class: 'bhe-placeholder bhe-placeholder--busy', html: icon('arrow-path', 'bhe-icon bhe-spin') + '<span>' + esc(label || 'Uploading…') + '</span>' });
}

// Caption under a figure. Shown while selected or when there is one.
function captionField(b, ctx, field, placeholder) {
  field = field || 'caption';
  var cap = ctx.rich({ field: field, tagName: 'figcaption', className: 'bhe-caption', placeholder: placeholder || 'Add caption', label: 'Caption text', split: false, onEnter: function () { exitAfter(b); } });
  if (isEmptyHtml(b.data[field]) && !ctx.selected()) cap.classList.add('is-hidden-unselected');
  return cap;
}

// -- Image
function linkControl(btn, b) {
  var box = h('form', { class: 'bhe-link' });
  var input = h('input', { type: 'text', class: 'bhe-input', placeholder: 'Paste URL', value: b.data.href || '', 'aria-label': 'Link URL' });
  var nt = h('input', { type: 'checkbox' }); nt.checked = b.data.linkTarget === '_blank';
  box.appendChild(h('div', { class: 'bhe-link__row' }, [input, h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary bhe-btn--small', 'aria-label': 'Apply', html: icon('check', 'bhe-icon') })]));
  box.appendChild(h('label', { class: 'bhe-toggle-row' }, [nt, h('span', { text: 'Open in new tab' })]));
  var row = h('div', { class: 'bhe-link__presets' });
  if (b.data.url) row.appendChild(h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary bhe-btn--small', text: 'Link to image file', onclick: function () { input.value = b.data.url; } }));
  if (b.data.href) row.appendChild(h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary bhe-btn--small is-danger', html: icon('link-slash', 'bhe-icon') + ' Remove link', onclick: function () { closePopover(); updateBlock(b.id, { href: undefined, linkTarget: undefined }, { structural: true }); renderToolbar(); } }));
  box.appendChild(row);
  box.addEventListener('submit', function (e) {
    e.preventDefault(); closePopover();
    var u = normalizeUrl(input.value);
    updateBlock(b.id, { href: u || undefined, linkTarget: u && nt.checked ? '_blank' : undefined }, { structural: true });
    renderToolbar();
  });
  popover(btn, box, { className: 'bhe-popover--link', label: 'Link' });
  setTimeout(function () { input.focus(); }, 0);
}

function imagePatch(m, b) {
  return { url: m.url || '', id: m.id || undefined, alt: (b && b.data.alt) || m.alt_text || '', caption: (b && b.data.caption) || m.caption || '', uploading: undefined };
}

registerBlock('image', {
  __core: true,
  title: 'Image', icon: 'photo', category: 'media',
  description: 'Insert an image to make a visual statement.',
  keywords: ['photo', 'picture', 'img'],
  defaults: { url: '', alt: '', caption: '', align: '' },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'] },
  styles: [{ name: 'default', label: 'Default' }, { name: 'rounded', label: 'Rounded' }],
  edit: function (el, b, api, ctx) {
    if (b.data.uploading) { el.appendChild(uploadingBox()); return; }
    if (!b.data.url) {
      var ph = Placeholder({ icon: 'photo', label: 'Image', instructions: 'Upload an image file, pick one from your media library, or add one with a URL.' });
      mediaPlaceholderButtons(ph.row, { accept: 'image/*', urlLabel: 'Image URL', onPick: function (m) { updateBlock(b.id, imagePatch(m, b), { structural: true }); } });
      el.appendChild(ph);
      return;
    }
    var fig = h('figure', { class: 'bhe-image' + (b.data.styleName ? ' is-style-' + b.data.styleName : '') });
    var frame = h('div', { class: 'bhe-image__frame' });
    var img = h('img', { src: b.data.url, alt: b.data.alt || '', draggable: 'false' });
    if (b.data.width) frame.style.width = b.data.width + 'px';
    frame.appendChild(img);
    // Drag the corner to resize.
    var handle = h('span', { class: 'bhe-resize bhe-resize--corner', title: 'Drag to resize', 'aria-hidden': 'true' });
    handle.addEventListener('mousedown', function (e) {
      e.preventDefault(); e.stopPropagation();
      var startX = e.clientX, startW = frame.getBoundingClientRect().width;
      var maxW = el.getBoundingClientRect().width || 2000;
      var right = b.data.align === 'center' ? 2 : 1;
      function mv(ev) { frame.style.width = Math.round(clamp(startW + (ev.clientX - startX) * right, 40, maxW)) + 'px'; }
      function up() {
        document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up);
        updateBlock(b.id, { width: Math.round(frame.getBoundingClientRect().width) }, { rerender: false, structural: true });
      }
      document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
    });
    frame.appendChild(handle);
    fig.appendChild(frame);
    fig.appendChild(captionField(b, ctx));
    el.appendChild(fig);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['left', 'center', 'right', 'wide', 'full']);
    if (!b.data.url) return;
    var g = tb.group();
    g.button({ icon: 'link', label: b.data.href ? 'Edit link' : 'Link', active: !!b.data.href, onClick: function (btn) { linkControl(btn, b); } });
    g.button({ text: 'Replace', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { accept: 'image/*', current: b.data.url, urlLabel: 'Image URL', onPick: function (m) { updateBlock(b.id, { url: m.url, id: m.id || undefined }, { structural: true }); } }); } });
  },
  inspector: function (el, b, api, ui) {
    if (!b.data.url) return;
    ui.textarea('Alternative text', b.data.alt || '', function (v) { updateBlock(b.id, { alt: v }, { rerender: false, inspector: false }); var im = wrapOf(b.id) && wrapOf(b.id).querySelector('img'); if (im) im.alt = v; },
      { help: 'Describe the purpose of the image. Leave empty if the image is purely decorative.' });
    ui.number('Width (px)', b.data.width || '', function (v) { updateBlock(b.id, { width: v ? clamp(v, 20, 4000) : undefined }, { structural: true }); }, { min: 20, help: 'Leave empty to fit the content width.' });
    ui.text('Link', b.data.href || '', function (v) { var u = normalizeUrl(v); updateBlock(b.id, { href: u || undefined }, { structural: true }); }, { placeholder: 'https://' });
    if (b.data.href) ui.toggle('Open in new tab', b.data.linkTarget === '_blank', function (v) { updateBlock(b.id, { linkTarget: v ? '_blank' : undefined }, { structural: true }); });
  },
  transforms: {
    to: {
      gallery: function (b) { return makeBlock('gallery', { images: b.data.url ? [{ url: b.data.url, alt: b.data.alt || '', caption: b.data.caption || '', id: b.data.id || null }] : [] }); },
      cover: function (b) { return makeBlock('cover', { url: b.data.url || '', id: b.data.id || null }); },
      'media-text': function (b) { return makeBlock('media-text', { url: b.data.url || '', id: b.data.id || null, alt: b.data.alt || '' }); }
    }
  }
});

// -- Gallery
registerBlock('gallery', {
  __core: true,
  title: 'Gallery', icon: 'gallery', category: 'media',
  description: 'Display multiple images in a rich gallery.',
  keywords: ['images', 'photos', 'grid'],
  defaults: { images: [], columns: 3, crop: true, caption: '', align: '' },
  supports: { align: ['wide', 'full'] },
  edit: function (el, b, api, ctx) {
    var images = Array.isArray(b.data.images) ? b.data.images : [];
    function add(m) {
      if (!m || !m.url) return;
      var list = (blockById(b.id) || b).data.images || [];
      list = list.concat([{ url: m.url, alt: m.alt_text || '', caption: m.caption || '', id: m.id || null }]);
      updateBlock(b.id, { images: list }, { structural: true });
    }
    if (!images.length) {
      var ph = Placeholder({ icon: 'gallery', label: 'Gallery', instructions: 'Drag and drop images, upload, or choose from your library.' });
      mediaPlaceholderButtons(ph.row, { accept: 'image/*', multiple: true, urlLabel: 'Image URL', onPick: add });
      el.appendChild(ph);
      return;
    }
    var cols = clamp(parseInt(b.data.columns, 10) || Math.min(3, images.length), 1, 8);
    var grid = h('figure', { class: 'bhe-gallery' + (b.data.crop !== false ? ' is-cropped' : ''), style: { '--bhe-cols': String(cols) } });
    images.forEach(function (im, i) {
      var item = h('figure', { class: 'bhe-gallery__item' });
      item.appendChild(h('img', { src: im.url, alt: im.alt || '', draggable: 'false' }));
      var tools = h('div', { class: 'bhe-gallery__tools' });
      function mv(dir) { var list = images.slice(); var t = list[i + dir]; if (!t) return; list[i + dir] = list[i]; list[i] = t; updateBlock(b.id, { images: list }, { structural: true }); }
      tools.appendChild(h('button', { type: 'button', class: 'bhe-iconbtn bhe-iconbtn--small', 'aria-label': 'Move image backward', disabled: i === 0 ? true : null, html: icon('chevron-left', 'bhe-icon'), onclick: function (e) { e.stopPropagation(); mv(-1); } }));
      tools.appendChild(h('button', { type: 'button', class: 'bhe-iconbtn bhe-iconbtn--small', 'aria-label': 'Move image forward', disabled: i === images.length - 1 ? true : null, html: icon('chevron-right', 'bhe-icon'), onclick: function (e) { e.stopPropagation(); mv(1); } }));
      tools.appendChild(h('button', { type: 'button', class: 'bhe-iconbtn bhe-iconbtn--small', 'aria-label': 'Remove image', html: icon('x-mark', 'bhe-icon'), onclick: function (e) { e.stopPropagation(); var list = images.slice(); list.splice(i, 1); updateBlock(b.id, { images: list }, { structural: true }); } }));
      item.appendChild(tools);
      var cap = ctx.rich({
        tagName: 'figcaption', className: 'bhe-gallery__caption', value: im.caption || '', placeholder: 'Add caption', label: 'Image caption', split: false,
        onChange: function (v) { var list = (blockById(b.id) || b).data.images.slice(); list[i] = Object.assign({}, list[i], { caption: v }); b.data.images = list; updateBlock(b.id, { images: list }, { rerender: false, inspector: false }); }
      });
      if (isEmptyHtml(im.caption)) cap.classList.add('is-hidden-unselected');
      item.appendChild(cap);
      grid.appendChild(item);
    });
    el.appendChild(grid);
    var addRow = h('div', { class: 'bhe-gallery__add' });
    mediaPlaceholderButtons(addRow, { accept: 'image/*', multiple: true, urlLabel: 'Image URL', onPick: add });
    el.appendChild(addRow);
    el.appendChild(captionField(b, ctx, 'caption', 'Add gallery caption'));
  },
  toolbar: function (tb, b) { blockAlignToolbar(tb, b, ['wide', 'full']); },
  inspector: function (el, b, api, ui) {
    var n = (b.data.images || []).length;
    if (!n) return;
    ui.range('Columns', b.data.columns || Math.min(3, n), function (v) { updateBlock(b.id, { columns: v }, { rerender: true, inspector: false }); }, { min: 1, max: Math.min(8, Math.max(n, 1)) });
    ui.toggle('Crop images to fit', b.data.crop !== false, function (v) { updateBlock(b.id, { crop: v }, { structural: true }); });
    (b.data.images || []).forEach(function (im, i) {
      ui.text('Alt text — image ' + (i + 1), im.alt || '', function (v) { var list = b.data.images.slice(); list[i] = Object.assign({}, list[i], { alt: v }); updateBlock(b.id, { images: list }, { structural: true }); });
    });
  }
});

// -- Video / Audio / File
function mediaUrlIsEmbed(u) { var e = embedInfo(u); return !!(e && e.provider !== 'generic'); }

registerBlock('video', {
  __core: true,
  title: 'Video', icon: 'film', category: 'media',
  description: 'Embed a video from your media library or upload a new one.',
  keywords: ['movie', 'mp4', 'clip'],
  defaults: { src: '', caption: '', controls: true },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'] },
  edit: function (el, b, api, ctx) {
    if (b.data.uploading) { el.appendChild(uploadingBox()); return; }
    if (!b.data.src) {
      var ph = Placeholder({ icon: 'film', label: 'Video', instructions: 'Upload a video file, pick one from your media library, or add one with a URL.' });
      mediaPlaceholderButtons(ph.row, { accept: 'video/*', urlLabel: 'Video URL', onPick: function (m) {
        if (mediaUrlIsEmbed(m.url)) { replaceBlock(b.id, makeBlock('embed', { url: m.url })); return; }
        updateBlock(b.id, { src: m.url, id: m.id || undefined }, { structural: true });
      } });
      el.appendChild(ph);
      return;
    }
    var fig = h('figure', { class: 'bhe-video' });
    var v = h('video', { src: b.data.src, controls: true, poster: b.data.poster || null, preload: 'metadata' });
    fig.appendChild(h('div', { class: 'bhe-media-frame' }, [v, h('div', { class: 'bhe-embed__overlay' })]));
    fig.appendChild(captionField(b, ctx));
    el.appendChild(fig);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['left', 'center', 'right', 'wide', 'full']);
    if (b.data.src) tb.group().button({ text: 'Replace', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { accept: 'video/*', current: b.data.src, urlLabel: 'Video URL', onPick: function (m) { updateBlock(b.id, { src: m.url, id: m.id || undefined }, { structural: true }); } }); } });
  },
  inspector: function (el, b, api, ui) {
    if (!b.data.src) return;
    [['autoplay', 'Autoplay', 'Autoplay may cause usability issues for some users.'], ['loop', 'Loop'], ['muted', 'Muted'], ['controls', 'Playback controls'], ['playsInline', 'Play inline', 'When enabled, videos play directly within the page on mobile browsers instead of a full-screen player.']].forEach(function (o) {
      var val = o[0] === 'controls' ? b.data.controls !== false : !!b.data[o[0]];
      ui.toggle(o[1], val, function (v) { var p = {}; p[o[0]] = o[0] === 'controls' ? v : (v || undefined); updateBlock(b.id, p, { structural: true }); }, { help: o[2] });
    });
    ui.select('Preload', b.data.preload || 'metadata', [['metadata', 'Metadata'], ['auto', 'Auto'], ['none', 'None']], function (v) { updateBlock(b.id, { preload: v === 'metadata' ? undefined : v }, { structural: true }); });
    ui.media('Poster image', b.data.poster || '', function (m) { updateBlock(b.id, { poster: m.url }, { structural: true }); }, function () { updateBlock(b.id, { poster: undefined }, { structural: true }); });
  }
});

registerBlock('audio', {
  __core: true,
  title: 'Audio', icon: 'musical-note', category: 'media',
  description: 'Embed a simple audio player.',
  keywords: ['music', 'sound', 'podcast', 'mp3'],
  defaults: { src: '', caption: '' },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'] },
  edit: function (el, b, api, ctx) {
    if (b.data.uploading) { el.appendChild(uploadingBox()); return; }
    if (!b.data.src) {
      var ph = Placeholder({ icon: 'musical-note', label: 'Audio', instructions: 'Upload an audio file, pick one from your media library, or add one with a URL.' });
      mediaPlaceholderButtons(ph.row, { accept: 'audio/*', urlLabel: 'Audio URL', onPick: function (m) {
        if (mediaUrlIsEmbed(m.url)) { replaceBlock(b.id, makeBlock('embed', { url: m.url })); return; }
        updateBlock(b.id, { src: m.url, id: m.id || undefined }, { structural: true });
      } });
      el.appendChild(ph);
      return;
    }
    var fig = h('figure', { class: 'bhe-audio' });
    fig.appendChild(h('audio', { src: b.data.src, controls: true, preload: 'none' }));
    fig.appendChild(captionField(b, ctx));
    el.appendChild(fig);
  },
  toolbar: function (tb, b) {
    if (b.data.src) tb.group().button({ text: 'Replace', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { accept: 'audio/*', current: b.data.src, urlLabel: 'Audio URL', onPick: function (m) { updateBlock(b.id, { src: m.url, id: m.id || undefined }, { structural: true }); } }); } });
  },
  inspector: function (el, b, api, ui) {
    if (!b.data.src) return;
    ui.toggle('Autoplay', !!b.data.autoplay, function (v) { updateBlock(b.id, { autoplay: v || undefined }, { structural: true }); }, { help: 'Autoplay may cause usability issues for some users.' });
    ui.toggle('Loop', !!b.data.loop, function (v) { updateBlock(b.id, { loop: v || undefined }, { structural: true }); });
  }
});

registerBlock('file', {
  __core: true,
  title: 'File', icon: 'document-arrow-down', category: 'media',
  description: 'Add a link to a downloadable file.',
  keywords: ['document', 'pdf', 'download', 'attachment'],
  defaults: { href: '', fileName: '', showDownloadButton: true, downloadText: 'Download' },
  edit: function (el, b, api, ctx) {
    if (b.data.uploading) { el.appendChild(uploadingBox()); return; }
    if (!b.data.href) {
      var ph = Placeholder({ icon: 'document-arrow-down', label: 'File', instructions: 'Upload a file or pick one from your media library.' });
      mediaPlaceholderButtons(ph.row, { accept: '', urlLabel: 'File URL', onPick: function (m) {
        updateBlock(b.id, { href: m.url, id: m.id || undefined, fileName: b.data.fileName || m.title || decodeURIComponent(String(m.url).split('/').pop().split('?')[0]) }, { structural: true });
      } });
      el.appendChild(ph);
      return;
    }
    var row = h('div', { class: 'bhe-file' });
    if (/\.pdf(\?|$)/i.test(b.data.href) && b.data.showPreview) row.appendChild(h('object', { class: 'bhe-file__pdf', data: b.data.href, type: 'application/pdf', 'aria-label': 'Embed of ' + stripTags(b.data.fileName || 'PDF') }));
    row.appendChild(ctx.rich({ field: 'fileName', tagName: 'span', className: 'bhe-file__name', placeholder: 'Write file name…', label: 'File name', formats: 'none', split: false, onEnter: function () { exitAfter(b); } }));
    if (b.data.showDownloadButton !== false) {
      row.appendChild(ctx.rich({ field: 'downloadText', tagName: 'span', className: 'bhe-file__button', placeholder: 'Download', label: 'Download button text', formats: 'none', split: false, onEnter: function () { exitAfter(b); } }));
    }
    el.appendChild(row);
  },
  toolbar: function (tb, b) {
    if (b.data.href) tb.group().button({ text: 'Replace', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { current: b.data.href, urlLabel: 'File URL', onPick: function (m) { updateBlock(b.id, { href: m.url, id: m.id || undefined }, { structural: true }); } }); } });
  },
  inspector: function (el, b, api, ui) {
    if (!b.data.href) return;
    ui.toggle('Show download button', b.data.showDownloadButton !== false, function (v) { updateBlock(b.id, { showDownloadButton: v }, { structural: true }); });
    ui.toggle('Open in new tab', b.data.linkTarget === '_blank', function (v) { updateBlock(b.id, { linkTarget: v ? '_blank' : undefined }, { structural: true }); });
    if (/\.pdf(\?|$)/i.test(b.data.href)) ui.toggle('Show inline embed', !!b.data.showPreview, function (v) { updateBlock(b.id, { showPreview: v || undefined }, { structural: true }); }, { help: 'Shows the PDF on the page. Some mobile browsers show only the link.' });
  }
});

// -- Embed
/*
 * Turns a page address into what to show: an iframe source and its shape.
 * The same table lives in BlockRenderer::embedInfo() so the editor preview and
 * the published page agree.
 */
function embedInfo(url) {
  url = String(url || '').trim();
  if (!/^https?:\/\//i.test(url)) return null;
  var m;
  if ((m = url.match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([\w-]{6,})/i))) {
    return { provider: 'youtube', title: 'YouTube', src: 'https://www.youtube.com/embed/' + m[1], aspect: /\/shorts\//i.test(url) ? '9-16' : '16-9' };
  }
  if ((m = url.match(/vimeo\.com\/(?:video\/|channels\/[\w-]+\/|groups\/[\w-]+\/videos\/)?(\d+)/i))) return { provider: 'vimeo', title: 'Vimeo', src: 'https://player.vimeo.com/video/' + m[1], aspect: '16-9' };
  if ((m = url.match(/(?:dailymotion\.com\/(?:embed\/)?video\/|dai\.ly\/)([a-z0-9]+)/i))) return { provider: 'dailymotion', title: 'Dailymotion', src: 'https://www.dailymotion.com/embed/video/' + m[1], aspect: '16-9' };
  if ((m = url.match(/open\.spotify\.com\/(?:embed\/)?(track|album|playlist|episode|show|artist)\/(\w+)/i))) return { provider: 'spotify', title: 'Spotify', src: 'https://open.spotify.com/embed/' + m[1] + '/' + m[2], height: m[1] === 'track' || m[1] === 'episode' ? 152 : 352 };
  if ((m = url.match(/loom\.com\/(?:share|embed)\/(\w+)/i))) return { provider: 'loom', title: 'Loom', src: 'https://www.loom.com/embed/' + m[1], aspect: '16-9' };
  if ((m = url.match(/codepen\.io\/([\w-]+)\/(?:pen|embed)\/(\w+)/i))) return { provider: 'codepen', title: 'CodePen', src: 'https://codepen.io/' + m[1] + '/embed/' + m[2] + '?default-tab=result', height: 400 };
  if (/soundcloud\.com\//i.test(url) && !/w\.soundcloud\.com/i.test(url)) return { provider: 'soundcloud', title: 'SoundCloud', src: 'https://w.soundcloud.com/player/?url=' + encodeURIComponent(url), height: 166 };
  if (/w\.soundcloud\.com\/player/i.test(url)) return { provider: 'soundcloud', title: 'SoundCloud', src: url, height: 166 };
  if (/(?:twitter|x)\.com\/\w+\/status\/\d+/i.test(url)) return { provider: 'twitter', title: 'X (Twitter)', src: '', card: true };
  if (/google\.[a-z.]+\/maps\/embed/i.test(url)) return { provider: 'google-maps', title: 'Google Maps', src: url, aspect: '4-3' };
  if ((m = url.match(/ted\.com\/talks\/([\w-]+)/i))) return { provider: 'ted', title: 'TED', src: 'https://embed.ted.com/talks/' + m[1], aspect: '16-9' };
  return { provider: 'generic', title: 'Embed', src: url, aspect: '16-9' };
}

registerBlock('embed', {
  __core: true,
  title: 'Embed', icon: 'embed', category: 'embed',
  description: 'Add a video, music or other content from YouTube, Vimeo, Spotify, SoundCloud, CodePen and more.',
  keywords: ['youtube', 'vimeo', 'spotify', 'soundcloud', 'iframe', 'video', 'tweet', 'codepen', 'loom', 'maps'],
  defaults: { url: '', caption: '' },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'] },
  edit: function (el, b, api, ctx) {
    var info = embedInfo(b.data.url);
    if (!b.data.url || b.data._editing || !info) {
      var ph = Placeholder({ icon: 'embed', label: info && info.provider !== 'generic' ? info.title + ' URL' : 'Embed', instructions: 'Paste a link to the content you want to display on your site.' });
      var f = h('form', { class: 'bhe-placeholder__form' });
      var input = h('input', { type: 'url', class: 'bhe-input', placeholder: 'Enter URL to embed here…', value: b.data.url || '', 'aria-label': 'Embed URL' });
      f.appendChild(input);
      f.appendChild(h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary', text: 'Embed' }));
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var u = normalizeUrl(input.value);
        if (!u) return;
        var inf = embedInfo(u);
        updateBlock(b.id, { url: u, provider: inf ? inf.provider : undefined, _editing: undefined }, { structural: true });
      });
      ph.row.appendChild(f);
      ph.appendChild(h('a', { class: 'bhe-placeholder__learn', href: 'https://www.basehim.com/docs', target: '_blank', rel: 'noopener', text: 'Supported: YouTube, Vimeo, Dailymotion, Spotify, SoundCloud, Loom, CodePen, TED, X, Google Maps.' }));
      el.appendChild(ph);
      setTimeout(function () { if (ctx.selected()) input.focus(); }, 0);
      return;
    }
    var fig = h('figure', { class: 'bhe-embed is-provider-' + info.provider });
    if (info.card) {
      fig.appendChild(h('div', { class: 'bhe-embed__card', html: icon('globe-alt', 'bhe-icon') + '<div><strong>' + esc(info.title) + '</strong><span>' + esc(b.data.url) + '</span><small>The post appears on the published page.</small></div>' }));
    } else {
      var wrap = h('div', { class: 'bhe-embed__wrap' + (info.aspect ? ' has-aspect is-' + info.aspect : '') });
      if (info.height) wrap.style.height = info.height + 'px';
      wrap.appendChild(h('iframe', { src: info.src, title: 'Embedded content from ' + info.title, loading: 'lazy', allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture', allowfullscreen: true, referrerpolicy: 'strict-origin-when-cross-origin' }));
      wrap.appendChild(h('div', { class: 'bhe-embed__overlay' }));
      fig.appendChild(wrap);
    }
    fig.appendChild(captionField(b, ctx));
    el.appendChild(fig);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['left', 'center', 'right', 'wide', 'full']);
    if (b.data.url) tb.group().button({ icon: 'pencil', label: 'Edit URL', onClick: function () { updateBlock(b.id, { _editing: true }, { structural: false }); } });
  },
  inspector: function (el, b, api, ui) {
    if (!b.data.url) return;
    ui.text('URL', b.data.url, function (v) { var u = normalizeUrl(v); var inf = embedInfo(u); updateBlock(b.id, { url: u, provider: inf ? inf.provider : undefined }, { structural: true }); });
    var inf = embedInfo(b.data.url);
    if (inf && inf.aspect) ui.toggle('Resize for smaller devices', b.data.responsive !== false, function (v) { updateBlock(b.id, { responsive: v ? undefined : false }, { structural: true }); }, { help: 'Keeps the embed’s aspect ratio as the screen size changes.' });
  }
});

// -- Cover
var COVER_POSITIONS = ['top left', 'top center', 'top right', 'center left', 'center center', 'center right', 'bottom left', 'bottom center', 'bottom right'];
function coverPositionMenu(btn, b) {
  var cur = b.data.contentPosition || 'center center';
  var grid = h('div', { class: 'bhe-posgrid', role: 'radiogroup', 'aria-label': 'Change content position' });
  COVER_POSITIONS.forEach(function (p) {
    grid.appendChild(h('button', { type: 'button', role: 'radio', class: 'bhe-posgrid__cell' + (p === cur ? ' is-active' : ''), 'aria-checked': String(p === cur), 'aria-label': p, title: p, onclick: function () { closePopover(); updateBlock(b.id, { contentPosition: p === 'center center' ? undefined : p }, { structural: true }); renderToolbar(); } }));
  });
  popover(btn, grid, { label: 'Content position' });
}

registerBlock('cover', {
  __core: true,
  title: 'Cover', icon: 'cover', category: 'media',
  description: 'Add an image or video with a text overlay.',
  keywords: ['banner', 'hero', 'background', 'overlay'],
  defaults: { url: '', dimRatio: 50, overlayColor: '#000000', minHeight: 430, align: '' },
  container: true,
  template: function () { return [['paragraph', { align: 'center', fontSize: 'large', placeholder: 'Write title…' }]]; },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'] },
  edit: function (el, b, api, ctx) {
    var d = b.data;
    if (!d.url && !d.colorOnly && !(b.innerBlocks && b.innerBlocks.some(function (c) { return !isEmptyHtml(c.data.text); }))) {
      var ph = Placeholder({ icon: 'cover', label: 'Cover', instructions: 'Drag and drop onto this block, upload, or select existing media from your library.', className: 'bhe-placeholder--cover' });
      mediaPlaceholderButtons(ph.row, { accept: 'image/*,video/*', urlLabel: 'Image or video URL', onPick: function (m) { updateBlock(b.id, { url: m.url, id: m.id || undefined, backgroundType: /\.(mp4|webm|ogv|mov)(\?|$)/i.test(m.url) || /^video\//.test(m.mime_type || '') ? 'video' : undefined }, { structural: true }); } });
      var sw = h('div', { class: 'bhe-swatches bhe-swatches--inline', role: 'radiogroup', 'aria-label': 'Overlay color' });
      PALETTE.slice(0, 12).forEach(function (p) {
        sw.appendChild(h('button', { type: 'button', class: 'bhe-swatches__item', title: p[1], 'aria-label': p[1], style: { background: p[0] }, onclick: function (e) { e.stopPropagation(); updateBlock(b.id, { overlayColor: p[0], dimRatio: 100, colorOnly: true }, { structural: true }); } }));
      });
      ph.appendChild(sw);
      el.appendChild(ph);
      return;
    }
    var pos = (d.contentPosition || 'center center').split(' ');
    var cover = h('div', { class: 'bhe-cover' + (d.hasParallax ? ' has-parallax' : '') + (isLightColor(d.overlayColor) && (d.dimRatio == null ? 50 : d.dimRatio) >= 50 && !d.url ? ' is-light' : ''), style: { minHeight: (parseInt(d.minHeight, 10) || 430) + 'px', justifyContent: { left: 'flex-start', center: 'center', right: 'flex-end' }[pos[1] || 'center'], alignItems: { top: 'flex-start', center: 'center', bottom: 'flex-end' }[pos[0] || 'center'] } });
    if (d.url) {
      if (d.backgroundType === 'video') cover.appendChild(h('video', { class: 'bhe-cover__media', src: d.url, autoplay: true, muted: true, loop: true, playsinline: true }));
      else cover.appendChild(h('img', { class: 'bhe-cover__media', src: d.url, alt: '', style: { objectPosition: d.focalPoint || '' } }));
    }
    cover.appendChild(h('span', { class: 'bhe-cover__overlay', 'aria-hidden': 'true', style: { background: isColor(d.overlayColor) ? d.overlayColor : '#000', opacity: String((d.dimRatio == null ? 50 : d.dimRatio) / 100) } }));
    var inner = h('div', { class: 'bhe-cover__inner' });
    ctx.inner(inner);
    cover.appendChild(inner);
    el.appendChild(cover);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['left', 'center', 'right', 'wide', 'full']);
    var g = tb.group();
    g.button({ icon: 'squares-2x2', label: 'Change content position', onClick: function (btn) { coverPositionMenu(btn, b); } });
    g.button({ icon: 'arrows-pointing-out', label: 'Toggle full height', active: b.data.minHeightUnit === 'vh', onClick: function () { var full = b.data.minHeightUnit === 'vh'; updateBlock(b.id, { minHeightUnit: full ? undefined : 'vh', minHeight: full ? 430 : 100 }, { structural: true }); renderToolbar(); } });
    g.button({ text: b.data.url ? 'Replace' : 'Add media', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { accept: 'image/*,video/*', current: b.data.url, urlLabel: 'Image or video URL', onPick: function (m) { updateBlock(b.id, { url: m.url, id: m.id || undefined, colorOnly: undefined, backgroundType: /\.(mp4|webm|ogv|mov)(\?|$)/i.test(m.url) ? 'video' : undefined }, { structural: true }); }, onRemove: b.data.url ? function () { updateBlock(b.id, { url: undefined, id: undefined, backgroundType: undefined, colorOnly: true }, { structural: true }); } : null }); } });
  },
  inspector: function (el, b, api, ui) {
    var d = b.data;
    if (d.url && d.backgroundType !== 'video') {
      ui.toggle('Fixed background', !!d.hasParallax, function (v) { updateBlock(b.id, { hasParallax: v || undefined }, { structural: true }); });
      ui.select('Focal point', d.focalPoint || '50% 50%', [['50% 50%', 'Center'], ['50% 0%', 'Top'], ['50% 100%', 'Bottom'], ['0% 50%', 'Left'], ['100% 50%', 'Right']], function (v) { updateBlock(b.id, { focalPoint: v === '50% 50%' ? undefined : v }, { structural: true }); });
      ui.text('Alternative text', d.alt || '', function (v) { updateBlock(b.id, { alt: v || undefined }, { structural: true }); }, { help: 'Describe the image for people who cannot see it. Leave empty if decorative.' });
    }
    var full = d.minHeightUnit === 'vh';
    ui.range(full ? 'Minimum height (vh)' : 'Minimum height (px)', d.minHeight || (full ? 100 : 430), function (v) { updateBlock(b.id, { minHeight: v }, { rerender: true, inspector: false }); }, { min: full ? 10 : 50, max: full ? 100 : 1200, step: full ? 1 : 10 });
    var body = ui.panel('overlay', 'Overlay');
    colorControl(body.el, 'Overlay', d.overlayColor || '#000000', function (v) { updateBlock(b.id, { overlayColor: v || '#000000' }, { structural: true }); });
    body.range('Overlay opacity', d.dimRatio == null ? 50 : d.dimRatio, function (v) { updateBlock(b.id, { dimRatio: v }, { rerender: true, inspector: false }); }, { min: 0, max: 100, step: 10 });
  }
});
function isLightColor(hex) { return isColor(hex) && luminance(hex) > 0.5; }

// -- Media & Text
registerBlock('media-text', {
  __core: true,
  title: 'Media & Text', icon: 'media-text', category: 'media',
  description: 'Set media and words side-by-side for a richer layout.',
  keywords: ['image', 'video', 'side by side', 'split'],
  defaults: { url: '', alt: '', mediaPosition: 'left', mediaWidth: 50, verticalAlign: 'center', isStackedOnMobile: true, align: 'wide' },
  container: true,
  template: function () { return [['paragraph', { placeholder: 'Content…' }]]; },
  supports: { align: ['wide', 'full'], color: true },
  edit: function (el, b, api, ctx) {
    var d = b.data;
    var w = clamp(parseInt(d.mediaWidth, 10) || 50, 15, 85);
    var right = d.mediaPosition === 'right';
    var grid = h('div', { class: 'bhe-mediatext' + (right ? ' has-media-on-the-right' : '') + (d.imageFill ? ' is-image-fill' : ''), style: { gridTemplateColumns: right ? (100 - w) + '% ' + w + '%' : w + '% ' + (100 - w) + '%', alignItems: { top: 'start', center: 'center', bottom: 'end' }[d.verticalAlign || 'center'] } });
    var media = h('figure', { class: 'bhe-mediatext__media' });
    if (d.uploading) media.appendChild(uploadingBox());
    else if (!d.url) {
      var ph = Placeholder({ icon: 'photo', label: 'Media area', className: 'bhe-placeholder--compact' });
      mediaPlaceholderButtons(ph.row, { accept: 'image/*,video/*', urlLabel: 'Image or video URL', onPick: function (m) { updateBlock(b.id, { url: m.url, id: m.id || undefined, mediaType: /\.(mp4|webm|ogv|mov)(\?|$)/i.test(m.url) || /^video\//.test(m.mime_type || '') ? 'video' : undefined, alt: d.alt || m.alt_text || '' }, { structural: true }); } });
      media.appendChild(ph);
    } else if (d.mediaType === 'video') media.appendChild(h('video', { src: d.url, controls: true }));
    else media.appendChild(h('img', { src: d.url, alt: d.alt || '', draggable: 'false', style: { objectPosition: d.focalPoint || '' } }));
    var content = h('div', { class: 'bhe-mediatext__content' });
    ctx.inner(content);
    if (right) { grid.appendChild(content); grid.appendChild(media); } else { grid.appendChild(media); grid.appendChild(content); }
    el.appendChild(grid);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['wide', 'full']);
    var g = tb.group();
    g.button({ icon: 'block-left', label: 'Show media on left', active: b.data.mediaPosition !== 'right', onClick: function () { updateBlock(b.id, { mediaPosition: 'left' }, { structural: true }); renderToolbar(); } });
    g.button({ icon: 'block-right', label: 'Show media on right', active: b.data.mediaPosition === 'right', onClick: function () { updateBlock(b.id, { mediaPosition: 'right' }, { structural: true }); renderToolbar(); } });
    verticalAlignToolbar(tb, b);
    if (b.data.url) tb.group().button({ text: 'Replace', label: 'Replace', onClick: function (btn) { replaceMediaMenu(btn, { accept: 'image/*,video/*', current: b.data.url, onPick: function (m) { updateBlock(b.id, { url: m.url, id: m.id || undefined, mediaType: /\.(mp4|webm|ogv|mov)(\?|$)/i.test(m.url) ? 'video' : undefined }, { structural: true }); }, onRemove: function () { updateBlock(b.id, { url: '', id: undefined, mediaType: undefined }, { structural: true }); } }); } });
  },
  inspector: function (el, b, api, ui) {
    var d = b.data;
    ui.range('Media width', d.mediaWidth || 50, function (v) { updateBlock(b.id, { mediaWidth: v }, { rerender: true, inspector: false }); }, { min: 15, max: 85, unit: '%' });
    ui.toggle('Stack on mobile', d.isStackedOnMobile !== false, function (v) { updateBlock(b.id, { isStackedOnMobile: v }, { structural: true }); });
    if (d.url && d.mediaType !== 'video') {
      ui.toggle('Crop image to fill', !!d.imageFill, function (v) { updateBlock(b.id, { imageFill: v || undefined }, { structural: true }); });
      ui.textarea('Alternative text', d.alt || '', function (v) { updateBlock(b.id, { alt: v }, { rerender: false, inspector: false }); }, { help: 'Describe the purpose of the image. Leave empty if decorative.' });
      ui.text('Media link', d.href || '', function (v) { updateBlock(b.id, { href: normalizeUrl(v) || undefined }, { structural: true }); }, { placeholder: 'https://' });
    }
  }
});

// Vertical alignment control shared by Media & Text and Columns.
function verticalAlignToolbar(tb, b) {
  var cur = b.data.verticalAlign || '';
  var names = { top: 'Align top', center: 'Align middle', bottom: 'Align bottom' };
  tb.dropdown({
    icon: 'valign-' + (cur || 'top'), label: 'Change vertical alignment',
    items: ['top', 'center', 'bottom'].map(function (v) {
      return { icon: 'valign-' + v, label: names[v], active: cur === v, onClick: function () { updateBlock(b.id, { verticalAlign: cur === v ? undefined : v }, { structural: true }); renderToolbar(); } };
    })
  });
}

//  Core blocks — design (buttons, columns, group, separator, spacer) + table

// -- Buttons / Button
var JUSTIFY = { left: 'Justify items left', center: 'Justify items center', right: 'Justify items right', 'space-between': 'Space between items' };
var JUSTIFY_ICON = { left: 'block-left', center: 'block-center', right: 'block-right', 'space-between': 'align-justify' };
function justifyToolbar(tb, b, values) {
  var cur = b.data.justify || 'left';
  tb.dropdown({
    icon: JUSTIFY_ICON[cur], label: 'Change items justification',
    items: (values || ['left', 'center', 'right', 'space-between']).map(function (v) {
      return { icon: JUSTIFY_ICON[v], label: JUSTIFY[v], active: cur === v, onClick: function () { updateBlock(b.id, { justify: v === 'left' ? undefined : v }, { structural: true }); renderToolbar(); } };
    })
  });
}

registerBlock('buttons', {
  __core: true,
  title: 'Buttons', icon: 'buttons', category: 'design',
  description: 'Prompt visitors to take action with a group of button-style links.',
  keywords: ['button', 'link', 'cta', 'call to action'],
  defaults: {},
  container: true,
  allowedBlocks: ['button'],
  template: function () { return [['button', {}]]; },
  supports: { fontSize: true },
  edit: function (el, b, api, ctx) {
    var row = h('div', { class: 'bhe-buttons is-justify-' + (b.data.justify || 'left') + (b.data.orientation === 'vertical' ? ' is-vertical' : '') });
    ctx.inner(row, { layout: 'flex' });
    el.appendChild(row);
  },
  toolbar: function (tb, b) { justifyToolbar(tb, b); },
  inspector: function (el, b, api, ui) {
    ui.buttons('Orientation', b.data.orientation || 'horizontal', [['horizontal', 'Horizontal'], ['vertical', 'Vertical']], function (v) { updateBlock(b.id, { orientation: v === 'horizontal' ? undefined : v }, { structural: true }); });
  }
});

function buttonLinkPopover(btn, b) {
  var box = h('form', { class: 'bhe-link' });
  var input = h('input', { type: 'text', class: 'bhe-input', placeholder: 'Paste URL or type', value: b.data.url || '', 'aria-label': 'URL' });
  var nt = h('input', { type: 'checkbox' }); nt.checked = b.data.linkTarget === '_blank' || !!b.data.newTab;
  box.appendChild(h('div', { class: 'bhe-link__row' }, [input, h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary bhe-btn--small', 'aria-label': 'Apply', html: icon('check', 'bhe-icon') })]));
  box.appendChild(h('label', { class: 'bhe-toggle-row' }, [nt, h('span', { text: 'Open in new tab' })]));
  if (b.data.url) box.appendChild(h('button', { type: 'button', class: 'bhe-btn bhe-btn--tertiary bhe-btn--small is-danger bhe-link__remove', html: icon('link-slash', 'bhe-icon') + ' Unlink', onclick: function () { closePopover(); updateBlock(b.id, { url: '', linkTarget: undefined, newTab: undefined }, { structural: true }); renderToolbar(); } }));
  box.addEventListener('submit', function (e) {
    e.preventDefault(); closePopover();
    updateBlock(b.id, { url: normalizeUrl(input.value), linkTarget: nt.checked ? '_blank' : undefined, newTab: undefined }, { structural: true });
    renderToolbar();
  });
  popover(btn, box, { className: 'bhe-popover--link', label: 'Link' });
  setTimeout(function () { input.focus(); input.select(); }, 0);
}

registerBlock('button', {
  __core: true,
  title: 'Button', icon: 'cursor-arrow-rays', category: 'design',
  description: 'Prompt visitors to take action with a button-style link.',
  keywords: ['link', 'cta'],
  defaults: { text: '', url: '' },
  parent: ['buttons'],
  supports: { color: 'custom', fontSize: true },
  styles: [{ name: 'fill', label: 'Fill' }, { name: 'outline', label: 'Outline' }],
  edit: function (el, b, api, ctx) {
    var d = b.data;
    var legacyTop = !locate(b.id) || !locate(b.id).parent;
    var holder = h('div', { class: 'bhe-button' + (legacyTop && d.align ? ' is-align-' + d.align : '') });
    var pill = ctx.rich({
      field: 'text', tagName: 'div', className: 'bhe-button__link' + (d.styleName === 'outline' ? ' is-style-outline' : '') + (d.width ? ' has-width-' + d.width : ''),
      placeholder: 'Add text…', label: 'Button text', split: false,
      onEnter: function () {
        var l = locate(b.id);
        if (l && l.parent && l.parent.type === 'buttons') { var nb = makeBlock('button', { styleName: d.styleName }); l.list.splice(l.index + 1, 0, nb); renderAll(); selectBlock(nb.id, { focus: 'start' }); afterChange(true); }
        else exitAfter(b);
      }
    });
    if (isColor(d.backgroundColor)) pill.style.backgroundColor = d.backgroundColor;
    if (isColor(d.textColor)) pill.style.color = d.textColor;
    if (d.styleName === 'outline' && isColor(d.backgroundColor)) { pill.style.borderColor = d.backgroundColor; pill.style.backgroundColor = 'transparent'; pill.style.color = isColor(d.textColor) ? d.textColor : d.backgroundColor; }
    holder.appendChild(pill);
    if (d.width) holder.classList.add('has-width-' + d.width);
    el.appendChild(holder);
    if (d.width) ctx.wrap.classList.add('has-width-' + d.width);
  },
  toolbar: function (tb, b) {
    var l = locate(b.id);
    if (!l || !l.parent) alignToolbar(tb, b);
    tb.group().button({ icon: 'link', label: b.data.url ? 'Edit link' : 'Link', shortcut: 'Ctrl+K', active: !!b.data.url, onClick: function (btn) { buttonLinkPopover(btn, b); } });
  },
  inspector: function (el, b, api, ui) {
    ui.buttons('Width', String(b.data.width || ''), [['', 'Auto'], ['25', '25%'], ['50', '50%'], ['75', '75%'], ['100', '100%']], function (v) { updateBlock(b.id, { width: v ? Number(v) : undefined }, { structural: true }); });
    ui.text('Link', b.data.url || '', function (v) { updateBlock(b.id, { url: normalizeUrl(v) }, { structural: true }); }, { placeholder: 'https://' });
    ui.toggle('Open in new tab', b.data.linkTarget === '_blank' || !!b.data.newTab, function (v) { updateBlock(b.id, { linkTarget: v ? '_blank' : undefined, newTab: undefined }, { structural: true }); });
    ui.text('Link rel', b.data.rel || '', function (v) { updateBlock(b.id, { rel: v.replace(/[^\w\s-]/g, '').trim() || undefined }, { structural: true }); }, { help: 'For example: nofollow sponsored' });
  },
  transforms: { to: { buttons: function (b) { return makeBlock('buttons', { justify: b.data.align && b.data.align !== 'left' ? b.data.align : undefined }, [makeBlock('button', { text: b.data.text, url: b.data.url, linkTarget: b.data.linkTarget })]); } } }
});

// -- Columns / Column
var COLUMN_LAYOUTS = [
  { label: '100', title: 'One column', widths: [100] },
  { label: '50 / 50', title: 'Two columns; equal split', widths: [50, 50] },
  { label: '33 / 66', title: 'Two columns; one-third, two-thirds split', widths: [33.33, 66.66] },
  { label: '66 / 33', title: 'Two columns; two-thirds, one-third split', widths: [66.66, 33.33] },
  { label: '33 / 33 / 33', title: 'Three columns; equal split', widths: [33.33, 33.33, 33.33] },
  { label: '25 / 50 / 25', title: 'Three columns; wide center column', widths: [25, 50, 25] }
];
function layoutPreview(widths) {
  return '<span class="bhe-colprev">' + widths.map(function (w) { return '<i style="flex:' + w + ' 1 0"></i>'; }).join('') + '</span>';
}

registerBlock('columns', {
  __core: true,
  title: 'Columns', icon: 'view-columns', category: 'design',
  description: 'Display content in multiple columns, with blocks added to each column.',
  keywords: ['layout', 'grid', 'side by side', 'row'],
  defaults: { isStackedOnMobile: true },
  container: true,
  allowedBlocks: ['column'],
  supports: { align: ['wide', 'full'], color: true },
  edit: function (el, b, api, ctx) {
    if (!b.innerBlocks || !b.innerBlocks.length) {
      var ph = Placeholder({ icon: 'view-columns', label: 'Columns', instructions: 'Divide into columns. Select a layout:' });
      var grid = h('div', { class: 'bhe-layoutpick' });
      COLUMN_LAYOUTS.forEach(function (lay) {
        grid.appendChild(h('button', { type: 'button', class: 'bhe-layoutpick__item', title: lay.title, 'aria-label': lay.title, html: layoutPreview(lay.widths) + '<span>' + esc(lay.label) + '</span>', onclick: function (e) {
          e.stopPropagation();
          var bb = blockById(b.id);
          var equal = lay.widths.every(function (w) { return w === lay.widths[0]; });
          bb.innerBlocks = lay.widths.map(function (w) { return makeBlock('column', equal ? {} : { width: w }, [makeBlock('paragraph', {})]); });
          renderAll();
          selectBlock(bb.innerBlocks[0].innerBlocks[0].id, { focus: 'start' });
          afterChange(true);
        } }));
      });
      ph.appendChild(grid);
      el.appendChild(ph);
      return;
    }
    var row = h('div', { class: 'bhe-columns' + (b.data.isStackedOnMobile === false ? ' is-not-stacked' : '') + (b.data.verticalAlign ? ' is-valign-' + b.data.verticalAlign : '') });
    ctx.inner(row, { layout: 'columns', appender: false });
    el.appendChild(row);
  },
  toolbar: function (tb, b) { blockAlignToolbar(tb, b, ['wide', 'full']); verticalAlignToolbar(tb, b); },
  inspector: function (el, b, api, ui) {
    var n = (b.innerBlocks || []).length;
    if (!n) return;
    ui.range('Columns', n, function (v) { setColumnCount(b.id, v); }, { min: 1, max: 6 });
    if (n > 4) ui.help('This column count exceeds the recommended amount and may cause visual breakage.');
    ui.toggle('Stack on mobile', b.data.isStackedOnMobile !== false, function (v) { updateBlock(b.id, { isStackedOnMobile: v }, { structural: true }); });
  }
});

function setColumnCount(id, n) {
  var b = blockById(id); if (!b) return;
  var cols = b.innerBlocks || (b.innerBlocks = []);
  n = clamp(parseInt(n, 10) || 1, 1, 6);
  if (n === cols.length) return;
  while (cols.length < n) cols.push(makeBlock('column', {}, [makeBlock('paragraph', {})]));
  while (cols.length > n) {
    // Content of a removed column moves into the one before it, so nothing is lost.
    var gone = cols.pop();
    var keep = (gone.innerBlocks || []).filter(function (c) { return !(c.type === 'paragraph' && isEmptyHtml(c.data.text)); });
    if (keep.length) cols[cols.length - 1].innerBlocks = (cols[cols.length - 1].innerBlocks || []).concat(keep);
  }
  // Explicit widths no longer add up: go back to equal columns.
  cols.forEach(function (c) { delete c.data.width; });
  renderAll();
  afterChange(true);
}

registerBlock('column', {
  __core: true,
  title: 'Column', icon: 'column', category: 'design',
  description: 'A single column within a columns block.',
  defaults: {},
  container: true,
  parent: ['columns'],
  inserter: false,
  supports: { color: true },
  edit: function (el, b, api, ctx) {
    var w = parseFloat(b.data.width);
    if (w > 0) { ctx.wrap.style.flexBasis = w + '%'; ctx.wrap.style.flexGrow = '0'; }
    if (b.data.verticalAlign) ctx.wrap.classList.add('is-valign-' + b.data.verticalAlign);
    var inner = h('div', { class: 'bhe-column' });
    ctx.inner(inner);
    el.appendChild(inner);
  },
  toolbar: function (tb, b) { verticalAlignToolbar(tb, b); },
  inspector: function (el, b, api, ui) {
    ui.number('Width (%)', b.data.width || '', function (v) { updateBlock(b.id, { width: v ? clamp(v, 5, 100) : undefined }, { structural: true }); }, { min: 5, max: 100, step: 0.01, help: 'Leave empty to share the space equally.' });
  }
});

// -- Group (with Row and Stack layouts)
var GROUP_LAYOUTS = [
  { name: '', label: 'Group', title: 'Gather blocks in a container.', icon: 'group' },
  { name: 'row', label: 'Row', title: 'Arrange blocks horizontally.', icon: 'view-columns' },
  { name: 'stack', label: 'Stack', title: 'Arrange blocks vertically.', icon: 'queue-list' }
];
registerBlock('group', {
  __core: true,
  title: 'Group', icon: 'group', category: 'design',
  description: 'Gather blocks in a layout container.',
  keywords: ['container', 'wrapper', 'row', 'section', 'stack', 'box'],
  defaults: {},
  container: true,
  supports: { align: ['wide', 'full'], color: true, fontSize: true },
  edit: function (el, b, api, ctx) {
    var d = b.data;
    if (!b.innerBlocks || !b.innerBlocks.length) {
      var ph = Placeholder({ icon: 'group', label: 'Group', instructions: 'Select a layout to start with:', className: 'bhe-placeholder--compact' });
      var grid = h('div', { class: 'bhe-layoutpick bhe-layoutpick--group' });
      GROUP_LAYOUTS.forEach(function (lay) {
        grid.appendChild(h('button', { type: 'button', class: 'bhe-layoutpick__item', title: lay.title, html: icon(lay.icon, 'bhe-icon') + '<span>' + esc(lay.label) + '</span>', onclick: function (e) {
          e.stopPropagation();
          var bb = blockById(b.id);
          if (lay.name) bb.data.layout = lay.name; else delete bb.data.layout;
          bb.innerBlocks = [makeBlock('paragraph', {})];
          renderAll(); selectBlock(bb.innerBlocks[0].id, { focus: 'start' }); afterChange(true);
        } }));
      });
      ph.appendChild(grid);
      el.appendChild(ph);
      return;
    }
    var box = h(d.tagName && /^(section|main|article|aside|header|footer)$/.test(d.tagName) ? d.tagName : 'div', { class: 'bhe-group' + (d.layout ? ' is-layout-' + d.layout : '') + (d.justify ? ' is-justify-' + d.justify : '') + (d.layout === 'row' && d.wrap === false ? ' is-nowrap' : '') });
    ctx.inner(box, { layout: d.layout === 'row' ? 'flex' : null });
    el.appendChild(box);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['wide', 'full']);
    if (b.data.layout === 'row' || b.data.layout === 'stack') justifyToolbar(tb, b, b.data.layout === 'row' ? ['left', 'center', 'right', 'space-between'] : ['left', 'center', 'right']);
  },
  inspector: function (el, b, api, ui) {
    ui.buttons('Layout', b.data.layout || '', GROUP_LAYOUTS.map(function (l) { return [l.name, l.label, l.title]; }), function (v) { updateBlock(b.id, { layout: v || undefined }, { structural: true }); });
    if (b.data.layout === 'row') ui.toggle('Allow to wrap to multiple lines', b.data.wrap !== false, function (v) { updateBlock(b.id, { wrap: v ? undefined : false }, { structural: true }); });
    ui.select('HTML element', b.data.tagName || 'div', [['div', 'Default (<div>)'], ['section', '<section>'], ['main', '<main>'], ['article', '<article>'], ['aside', '<aside>'], ['header', '<header>'], ['footer', '<footer>']], function (v) { updateBlock(b.id, { tagName: v === 'div' ? undefined : v }, { structural: true }); });
    ui.select('Padding', b.data.padding || '', [['', 'None'], ['small', 'Small'], ['medium', 'Medium'], ['large', 'Large']], function (v) { updateBlock(b.id, { padding: v || undefined }, { structural: true }); });
  }
});

// -- Separator (stored as "divider" for 1.x compatibility)
registerBlock('divider', {
  __core: true,
  title: 'Separator', icon: 'separator', category: 'design',
  description: 'Create a break between ideas or sections with a horizontal separator.',
  keywords: ['horizontal-line', 'hr', 'divider', 'line'],
  defaults: {},
  supports: { color: 'custom' },
  styles: [{ name: 'default', label: 'Default' }, { name: 'wide', label: 'Wide Line' }, { name: 'dots', label: 'Dots' }],
  edit: function (el, b) {
    var hr = h('hr', { class: 'bhe-separator' + (b.data.styleName ? ' is-style-' + b.data.styleName : '') });
    if (isColor(b.data.backgroundColor)) { hr.style.color = b.data.backgroundColor; hr.style.backgroundColor = b.data.backgroundColor; }
    el.appendChild(hr);
  },
  inspector: function (el, b, api, ui) {
    colorControl(el, 'Color', b.data.backgroundColor, function (v) { updateBlock(b.id, { backgroundColor: v }, { structural: true }); });
  }
});
// The generic Color panel would offer text + background; the separator has one colour.
registry.divider.supports.color = false;

// -- Spacer
registerBlock('spacer', {
  __core: true,
  title: 'Spacer', icon: 'spacer', category: 'design',
  description: 'Add white space between blocks and customize its height.',
  keywords: ['gap', 'space', 'blank'],
  defaults: { height: 100 },
  edit: function (el, b) {
    var hgt = clamp(parseInt(b.data.height, 10) || 100, 1, 2000);
    var box = h('div', { class: 'bhe-spacer', style: { height: hgt + 'px' } });
    var label = h('span', { class: 'bhe-spacer__label', text: hgt + 'px' });
    var handle = h('span', { class: 'bhe-resize bhe-resize--bottom', title: 'Drag to resize', role: 'slider', 'aria-label': 'Spacer height', 'aria-valuenow': String(hgt), tabindex: '0' });
    handle.addEventListener('mousedown', function (e) {
      e.preventDefault(); e.stopPropagation();
      var y0 = e.clientY, h0 = box.getBoundingClientRect().height;
      document.body.classList.add('bhe-is-resizing');
      function mv(ev) { var v = Math.round(clamp(h0 + ev.clientY - y0, 10, 2000)); box.style.height = v + 'px'; label.textContent = v + 'px'; }
      function up() {
        document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up);
        document.body.classList.remove('bhe-is-resizing');
        updateBlock(b.id, { height: Math.round(box.getBoundingClientRect().height) }, { structural: true });
      }
      document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
    });
    handle.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); updateBlock(b.id, { height: clamp(hgt + (e.key === 'ArrowDown' ? 10 : -10), 10, 2000) }, { structural: true }); var w = wrapOf(b.id); if (w) { var hh = w.querySelector('.bhe-resize'); if (hh) hh.focus(); } }
    });
    box.appendChild(label);
    box.appendChild(handle);
    el.appendChild(box);
  },
  inspector: function (el, b, api, ui) {
    ui.range('Height', b.data.height || 100, function (v) { updateBlock(b.id, { height: v }, { rerender: true, inspector: false }); }, { min: 10, max: 600, unit: 'px' });
  }
});

//  Table
/*
 * data: { head: [[html…]], body: [[html…]], foot: [[html…]],
 *         columnAlign: ['', 'center', …], hasFixedLayout, caption, styleName }
 * Every row in all three sections has the same number of cells.
 */
var tableSel = {}; // blockId -> {section, row, col}

function tableCols(d) { var r = (d.body && d.body[0]) || (d.head && d.head[0]) || (d.foot && d.foot[0]) || []; return r.length; }
function emptyRow(n) { var r = []; for (var i = 0; i < n; i++) r.push(''); return r; }
function tableData(b) {
  var d = b.data;
  return { head: clone(d.head || []), body: clone(d.body || []), foot: clone(d.foot || []), columnAlign: clone(d.columnAlign || []) };
}
function tableEdit(b, fn) {
  var t = tableData(b);
  var res = fn(t);
  if (res === false) return;
  var n = tableCols(t);
  if (!t.body.length && !t.head.length && !t.foot.length) { updateBlock(b.id, { head: [], body: [], foot: [], columnAlign: [] }, { structural: true }); return; }
  if (!n) { toast('A table needs at least one column.', 'error'); return; }
  updateBlock(b.id, { head: t.head, body: t.body, foot: t.foot, columnAlign: t.columnAlign.slice(0, n) }, { structural: true });
  if (res && res.focus) focusTableCell(b.id, res.focus);
  renderToolbar();
}
function focusTableCell(id, pos) {
  var w = wrapOf(id); if (!w) return;
  var cell = w.querySelector('[data-cell="' + pos.section + '-' + pos.row + '-' + pos.col + '"]');
  if (!cell) return;
  cell.focus(); placeCaret(cell, pos.atStart !== false ? false : true);
  tableSel[id] = { section: pos.section, row: pos.row, col: pos.col };
}

var TABLE_OPS = {
  rowBefore: function (t, s) { var n = tableCols(t); t[s.section].splice(s.row, 0, emptyRow(n)); return { focus: { section: s.section, row: s.row, col: s.col } }; },
  rowAfter: function (t, s) { var n = tableCols(t); t[s.section].splice(s.row + 1, 0, emptyRow(n)); return { focus: { section: s.section, row: s.row + 1, col: s.col } }; },
  rowDelete: function (t, s) {
    t[s.section].splice(s.row, 1);
    var sec = s.section, row = s.row;
    if (!t[sec][row]) row = t[sec].length - 1;
    if (row < 0) { sec = 'body'; row = 0; if (!t.body.length) return {}; }
    return { focus: { section: sec, row: row, col: s.col } };
  },
  colBefore: function (t, s) { ['head', 'body', 'foot'].forEach(function (k) { t[k].forEach(function (r) { r.splice(s.col, 0, ''); }); }); t.columnAlign.splice(s.col, 0, ''); return { focus: { section: s.section, row: s.row, col: s.col } }; },
  colAfter: function (t, s) { ['head', 'body', 'foot'].forEach(function (k) { t[k].forEach(function (r) { r.splice(s.col + 1, 0, ''); }); }); t.columnAlign.splice(s.col + 1, 0, ''); return { focus: { section: s.section, row: s.row, col: s.col + 1 } }; },
  colDelete: function (t, s) {
    if (tableCols(t) <= 1) { toast('A table needs at least one column.', 'error'); return false; }
    ['head', 'body', 'foot'].forEach(function (k) { t[k].forEach(function (r) { r.splice(s.col, 1); }); });
    t.columnAlign.splice(s.col, 1);
    return { focus: { section: s.section, row: s.row, col: Math.min(s.col, tableCols(t) - 1) } };
  }
};

function tableCreateForm(el, b) {
  var ph = Placeholder({ icon: 'table-cells', label: 'Table', instructions: 'Insert a table for sharing data.' });
  var f = h('form', { class: 'bhe-placeholder__form bhe-tablecreate' });
  var cols = h('input', { type: 'number', class: 'bhe-input', min: '1', max: '20', value: '2', 'aria-label': 'Column count' });
  var rows = h('input', { type: 'number', class: 'bhe-input', min: '1', max: '100', value: '2', 'aria-label': 'Row count' });
  f.appendChild(h('label', { class: 'bhe-tablecreate__field' }, [h('span', { text: 'Column count' }), cols]));
  f.appendChild(h('label', { class: 'bhe-tablecreate__field' }, [h('span', { text: 'Row count' }), rows]));
  f.appendChild(h('button', { type: 'submit', class: 'bhe-btn bhe-btn--primary', text: 'Create Table' }));
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var c = clamp(parseInt(cols.value, 10) || 2, 1, 20), r = clamp(parseInt(rows.value, 10) || 2, 1, 100);
    var body = []; for (var i = 0; i < r; i++) body.push(emptyRow(c));
    updateBlock(b.id, { body: body, head: [], foot: [], columnAlign: [] }, { structural: true });
    focusTableCell(b.id, { section: 'body', row: 0, col: 0 });
  });
  ph.row.appendChild(f);
  el.appendChild(ph);
  setTimeout(function () { if (state.selectedId === b.id && document.activeElement === document.body) cols.focus(); }, 0);
}

registerBlock('table', {
  __core: true,
  title: 'Table', icon: 'table-cells', category: 'text',
  description: 'Create structured content in rows and columns to display information.',
  keywords: ['grid', 'rows', 'columns', 'data', 'spreadsheet'],
  defaults: { head: [], body: [], foot: [], columnAlign: [], hasFixedLayout: true, caption: '' },
  supports: { align: ['left', 'center', 'right', 'wide', 'full'], color: true, fontSize: true },
  styles: [{ name: 'regular', label: 'Default' }, { name: 'stripes', label: 'Stripes' }],
  edit: function (el, b, api, ctx) {
    var d = b.data;
    if (!tableCols(d)) { tableCreateForm(el, b); return; }
    var fig = h('figure', { class: 'bhe-table' + (d.styleName ? ' is-style-' + d.styleName : '') });
    var scroller = h('div', { class: 'bhe-table__scroll' });
    var table = h('table', { class: d.hasFixedLayout !== false ? 'has-fixed-layout' : '' });
    var align = d.columnAlign || [];
    var sel = tableSel[b.id];
    ['head', 'body', 'foot'].forEach(function (sec) {
      var rows = d[sec] || [];
      if (!rows.length) return;
      var tsec = h(sec === 'head' ? 'thead' : sec === 'foot' ? 'tfoot' : 'tbody');
      rows.forEach(function (row, ri) {
        var tr = h('tr');
        row.forEach(function (cell, ci) {
          var td = ctx.rich({
            tagName: sec === 'head' ? 'th' : 'td', value: cell, placeholder: sec === 'head' ? 'Header label' : sec === 'foot' ? 'Footer label' : '', label: (sec === 'head' ? 'Header' : sec === 'foot' ? 'Footer' : 'Body') + ' cell text', lineBreaks: true, split: false,
            className: 'bhe-cell' + (align[ci] ? ' has-text-align-' + align[ci] : '') + (sel && sel.section === sec && sel.row === ri && sel.col === ci ? ' is-selected' : ''),
            onChange: function (v) {
              var cur = blockById(b.id); if (!cur) return;
              if (!cur.data[sec] || !cur.data[sec][ri]) return;
              cur.data[sec][ri][ci] = v;
              updateBlock(b.id, {}, { rerender: false, inspector: false });
            },
            onArrowOut: function (dir, e) {
              if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return false;
              var order = [];
              ['head', 'body', 'foot'].forEach(function (s) { (d[s] || []).forEach(function (_, r) { order.push([s, r]); }); });
              var idx = 0;
              for (var k = 0; k < order.length; k++) if (order[k][0] === sec && order[k][1] === ri) idx = k;
              var nxt = order[idx + dir];
              if (nxt) { focusTableCell(b.id, { section: nxt[0], row: nxt[1], col: ci, atStart: dir > 0 }); return true; }
              var eds = richEditables(wrapOf(b.id));
              focusAdjacent(b.id, dir, dir < 0 ? eds[0] : eds[eds.length - 1]);
              return true;
            }
          });
          td.setAttribute('data-cell', sec + '-' + ri + '-' + ci);
          td.addEventListener('focus', function () {
            tableSel[b.id] = { section: sec, row: ri, col: ci };
            var w = wrapOf(b.id);
            if (w) Array.prototype.forEach.call(w.querySelectorAll('.bhe-cell.is-selected'), function (c) { c.classList.remove('is-selected'); });
            td.classList.add('is-selected');
            if (state.selectedId === b.id) renderToolbar();
          });
          td.addEventListener('keydown', function (e) {
            // Tab in the very last cell adds a row, like a spreadsheet.
            if (e.key === 'Tab' && !e.shiftKey && sec === 'body' && ri === rows.length - 1 && ci === row.length - 1 && !(d.foot && d.foot.length)) {
              e.preventDefault();
              tableEdit(blockById(b.id), function (t) { return TABLE_OPS.rowAfter(t, { section: 'body', row: ri, col: 0 }); });
            }
          });
          tr.appendChild(td);
        });
        tsec.appendChild(tr);
      });
      table.appendChild(tsec);
    });
    scroller.appendChild(table);
    fig.appendChild(scroller);
    fig.appendChild(captionField(b, ctx, 'caption', 'Add caption'));
    el.appendChild(fig);
  },
  toolbar: function (tb, b) {
    blockAlignToolbar(tb, b, ['left', 'center', 'right', 'wide', 'full']);
    if (!tableCols(b.data)) return;
    var s = tableSel[b.id];
    var cur = s ? ((b.data.columnAlign || [])[s.col] || '') : '';
    tb.dropdown({
      icon: cur ? 'align-' + cur : 'align-left', label: 'Change column alignment', disabled: !s,
      items: ['left', 'center', 'right'].map(function (v) {
        return { icon: 'align-' + v, label: 'Align column ' + v, active: cur === v, onClick: function () { var sel = tableSel[b.id]; if (!sel) return; tableEdit(blockById(b.id), function (t) { while (t.columnAlign.length < tableCols(t)) t.columnAlign.push(''); t.columnAlign[sel.col] = t.columnAlign[sel.col] === v ? '' : v; return { focus: sel }; }); } };
      })
    });
    function op(name) { return function () { var sel = tableSel[b.id]; if (!sel) { toast('Click a cell first.', 'error'); return; } tableEdit(blockById(b.id), function (t) { return TABLE_OPS[name](t, sel); }); }; }
    tb.dropdown({
      icon: 'table-cells', label: 'Edit table', disabled: !s,
      items: [
        { icon: 'row-before', label: 'Insert row before', onClick: op('rowBefore') },
        { icon: 'row-after', label: 'Insert row after', onClick: op('rowAfter') },
        { icon: 'row-delete', label: 'Delete row', onClick: op('rowDelete') },
        'sep',
        { icon: 'col-before', label: 'Insert column before', onClick: op('colBefore') },
        { icon: 'col-after', label: 'Insert column after', onClick: op('colAfter') },
        { icon: 'col-delete', label: 'Delete column', onClick: op('colDelete') }
      ]
    });
  },
  inspector: function (el, b, api, ui) {
    if (!tableCols(b.data)) return;
    ui.toggle('Fixed width table cells', b.data.hasFixedLayout !== false, function (v) { updateBlock(b.id, { hasFixedLayout: v }, { structural: true }); });
    ui.toggle('Header section', !!(b.data.head && b.data.head.length), function (v) { tableEdit(blockById(b.id), function (t) { t.head = v ? [emptyRow(tableCols(t))] : []; }); });
    ui.toggle('Footer section', !!(b.data.foot && b.data.foot.length), function (v) { tableEdit(blockById(b.id), function (t) { t.foot = v ? [emptyRow(tableCols(t))] : []; }); });
  }
});

//  Widget (app / theme widgets)
// Registered only when the server reports widgets, so it does not clutter
// the inserter on installs that have none.
var WIDGETS = CONFIG.widgets || [];
var widgetById = {};
WIDGETS.forEach(function (w) { widgetById[w.key] = w; });

function previewWidget(host, b) {
  var key = b.data.widget || '';
  if (!key) { host.innerHTML = '<div class="bhe-muted">Pick a widget.</div>'; return; }
  if (!CONFIG.widgetRenderUrl) { host.innerHTML = '<div class="bhe-muted">Preview unavailable.</div>'; return; }
  host.innerHTML = '<div class="bhe-muted">' + icon('arrow-path', 'bhe-icon bhe-spin') + ' Loading preview…</div>';
  var fd = new FormData();
  fd.append('_csrf', CONFIG.csrf || '');
  fd.append('widget', key);
  fd.append('surface', 'editor');
  fd.append('settings', JSON.stringify(b.data.settings || {}));
  fetch(CONFIG.widgetRenderUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) { host.innerHTML = d.ok ? (d.html || '<div class="bhe-muted">(empty)</div>') : '<div class="bhe-muted">' + esc(d.error || 'Preview failed') + '</div>'; })
    .catch(function () { host.innerHTML = '<div class="bhe-muted">Preview unavailable</div>'; });
}

if (WIDGETS.length) {
  registerBlock('widget', {
    __core: true,
    title: 'Widget', icon: 'puzzle-piece', category: 'widgets',
    description: 'Show a dynamic widget provided by an app or the theme.',
    keywords: ['widget', 'app', 'theme', 'dynamic'],
    defaults: { widget: (WIDGETS[0] && WIDGETS[0].key) || '', settings: {} },
    edit: function (el, b) {
      var w = widgetById[b.data.widget];
      var head = h('div', { class: 'bhe-widget__head', html: icon('puzzle-piece', 'bhe-icon') + '<span>' + esc(w ? w.title : (b.data.widget || 'Widget')) + '</span>' });
      var host = h('div', { class: 'bhe-widget__preview' });
      host.addEventListener('click', function (e) { if (e.target.closest('a')) e.preventDefault(); });
      el.appendChild(h('div', { class: 'bhe-widget' }, [head, host]));
      previewWidget(host, b);
    },
    inspector: function (el, b, api, ui) {
      ui.select('Widget', b.data.widget || '', WIDGETS.map(function (w) { return [w.key, w.title || w.key]; }), function (v) { updateBlock(b.id, { widget: v, settings: {} }, { structural: true }); });
      var w = widgetById[b.data.widget];
      if (!w || !w.fields || !w.fields.length) { ui.help('This widget has no settings.'); return; }
      var settings = b.data.settings || {};
      function set(k, v) { var s = Object.assign({}, blockById(b.id).data.settings || {}); s[k] = v; updateBlock(b.id, { settings: s }, { structural: true, inspector: false }); }
      w.fields.forEach(function (f) {
        var cur = settings[f.key] != null ? settings[f.key] : (f.default != null ? f.default : '');
        if (f.type === 'select' && Array.isArray(f.options)) {
          ui.select(f.label || f.key, cur, f.options.map(function (o) { return [o && o.value !== undefined ? o.value : o, o && o.label !== undefined ? o.label : o]; }), function (v) { set(f.key, v); }, { help: f.help });
        } else if (f.type === 'checkbox') {
          ui.toggle(f.label || f.key, !!cur, function (v) { set(f.key, v); }, { help: f.help });
        } else if (f.type === 'textarea') {
          ui.textarea(f.label || f.key, cur, debounce(function (v) { set(f.key, v); }, 500), { help: f.help });
        } else {
          ui.text(f.label || f.key, cur, function (v) { set(f.key, f.type === 'number' ? (v === '' ? '' : Number(v)) : v); }, { type: f.type === 'number' ? 'number' : 'text', help: f.help });
        }
      });
    },
    save: null
  });
}

//  HTML → blocks (imports, the HTML/Markdown → Blocks switch, and pasting)
/*
 * Rich text in a block holds only inline formatting: b, strong, i, em, u, s,
 * code, a, br, mark, sub, sup. Anything else inside rich text is either
 * harmless to flatten (span, font, small… keep their text) or would be lost —
 * an <img> in a paragraph would vanish. So images are lifted out into image
 * blocks, tables, details, video and audio become their own blocks, and
 * anything a block cannot hold without loss (forms, scripts, nested lists…)
 * is kept as a Custom HTML block of its own.
 */
var BH_KEEP_AS_HTML = /^(FORM|SCRIPT|STYLE|NOSCRIPT|OBJECT|EMBED|SVG|CANVAS|DL|SELECT|TEXTAREA|INPUT|BUTTON|MAP|TEMPLATE|MATH)$/;
var BH_BLOCK_TAGS = /^(P|H[1-6]|UL|OL|BLOCKQUOTE|PRE|HR|FIGURE|TABLE|DIV|SECTION|ARTICLE|MAIN|HEADER|FOOTER|ASIDE|NAV|CENTER|ADDRESS|FIELDSET|IFRAME|VIDEO|AUDIO|FORM|DETAILS|DL|SCRIPT|STYLE|NOSCRIPT|OBJECT|EMBED|SVG|CANVAS|TEMPLATE|MATH|HGROUP)$/;

function bhIsImageUrl(u) { return /\.(jpe?g|png|gif|webp|avif|bmp|svg)(\?.*)?$/i.test(String(u || '')); }
function bhAlignOf(el) {
  if (!el || !el.getAttribute) return '';
  var st = (el.getAttribute('style') || '').match(/text-align\s*:\s*(left|center|right)/i);
  if (st) return st[1].toLowerCase();
  var cls = ' ' + (el.getAttribute('class') || '') + ' ';
  var c = cls.match(/\s(?:has-text-align-|align)(left|center|right)\s/);
  if (c) return c[1];
  if (el.getAttribute('align')) return String(el.getAttribute('align')).toLowerCase().replace(/[^a-z]/g, '');
  if (el.nodeName === 'CENTER') return 'center';
  return '';
}
// Rich text: whitespace collapsed as a browser would, no leading or trailing
// <br>, inline tags only, and nothing left if all that remains is spaces.
function bhInline(html) {
  var s = sanitizeInline(String(html || '').replace(/\s*\n\s*/g, ' ').replace(/[ \t]{2,}/g, ' '));
  s = s.replace(/^(\s|&nbsp;|<br\s*\/?>)+/i, '').replace(/(\s|&nbsp;|<br\s*\/?>)+$/i, '');
  var probe = s.replace(/<[^>]+>/g, '').replace(/&nbsp;|\xa0/g, ' ').trim();
  return probe === '' ? '' : s;
}
function bhImageFrom(img) {
  // Lazy-loading plugins park the real address in data-src and put a
  // placeholder (often a data: URI) in src.
  var src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || img.getAttribute('data-original') || '';
  if (!src) src = img.getAttribute('src') || '';
  return src;
}

/*
 * Keeps only inline formatting. Google Docs and Word mark bold and italic
 * with styles instead of tags, so those become <strong>/<em>; links keep
 * href (and target/rel) only.
 */
var INLINE_OK = { B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'u', S: 's', STRIKE: 's', DEL: 's', CODE: 'code', KBD: 'code', A: 'a', BR: 'br', MARK: 'mark', SUB: 'sub', SUP: 'sup' };
function sanitizeInline(html) {
  var box = document.createElement('div');
  box.innerHTML = String(html || '');
  function clean(node) {
    var out = '';
    Array.prototype.forEach.call(node.childNodes, function (n) {
      if (n.nodeType === 3) { out += esc(n.nodeValue); return; }
      if (n.nodeType !== 1) return;
      var tag = n.nodeName;
      if (/^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT|META|LINK|TITLE|IFRAME|OBJECT)$/.test(tag)) return;
      var inner = clean(n);
      var st = (n.getAttribute('style') || '').toLowerCase();
      var keep = INLINE_OK[tag];
      // <b style="font-weight:normal"> wraps whole Google Docs pastes.
      if (tag === 'B' && /font-weight\s*:\s*(normal|400)/.test(st)) keep = null;
      if (tag === 'BR') { out += '<br>'; return; }
      if (keep === 'a') {
        var href = normalizeUrl(n.getAttribute('href') || '');
        if (!href) { out += inner; return; }
        var t = n.getAttribute('target') === '_blank';
        out += '<a href="' + esc(href) + '"' + (t ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + inner + '</a>';
        return;
      }
      if (keep) { out += inner === '' ? '' : '<' + keep + '>' + inner + '</' + keep + '>'; return; }
      if (inner !== '' && /font-weight\s*:\s*(bold|[6-9]00)/.test(st)) inner = '<strong>' + inner + '</strong>';
      if (inner !== '' && /font-style\s*:\s*italic/.test(st)) inner = '<em>' + inner + '</em>';
      if (inner !== '' && /text-decoration[^;]*line-through/.test(st)) inner = '<s>' + inner + '</s>';
      // A block-level element inside inline content: keep it on its own line.
      if (/^(P|DIV|LI|H[1-6]|TR|BLOCKQUOTE)$/.test(tag) && out !== '' && !/<br>$/.test(out)) out += '<br>';
      out += inner;
    });
    return out;
  }
  return clean(box).replace(/(<br>)+$/, '');
}

function htmlToBlocks(source) {
  var html = String(source || '');
  html = html.replace(/<!--[\s\S]*?-->/g, '');
  // WordPress [caption] shortcode → a real figure with a caption.
  html = html.replace(/\[caption([^\]]*)\]([\s\S]*?)\[\/caption\]/gi, function (_, attrs, inner) {
    var m = inner.match(/^\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*<\/a>)?)([\s\S]*)$/i);
    var al = (attrs.match(/align\s*=\s*["']?align(left|center|right)/i) || [])[1];
    return m ? '<figure' + (al ? ' class="align' + al + '"' : '') + '>' + m[1] + '<figcaption>' + m[2].trim() + '</figcaption></figure>' : inner;
  });
  if (html.trim() === '') return [];
  var doc = new DOMParser().parseFromString('<!doctype html><html><body>' + html + '</body></html>', 'text/html');
  return convertChildren(doc.body);
}

// Converts the children of `container` into a list of block objects.
function convertChildren(container) {
  var out = [], run = [];
  function push(type, data, inner) { var b = { type: type, data: data }; if (inner) b.innerBlocks = inner; out.push(b); }
  function keepHtml(el) { push('html', { html: el.outerHTML }); }

  // A run of inline content becomes paragraphs: a blank line in plain text,
  // or two <br> in a row, starts a new paragraph.
  function flush(align) {
    if (!run.length) return;
    var s = run.map(function (n) { return n.nodeType === 3 ? esc(n.textContent) : n.outerHTML; }).join('');
    run = [];
    s.split(/(?:<br\s*\/?>\s*){2,}|\n[ \t]*\n/i).forEach(function (part) {
      var t = bhInline(part);
      if (t !== '') push('paragraph', align ? { text: t, align: align } : { text: t });
    });
  }

  function image(img, link, align, caption) {
    var url = bhImageFrom(img);
    var data = { url: url || '', alt: img.getAttribute('alt') || '', caption: caption || '' };
    if (align || bhAlignOf(img)) data.align = align || bhAlignOf(img);
    var w = parseInt(img.getAttribute('width'), 10);
    if (w > 0 && w < 4000) data.width = w;
    if (link && link.getAttribute('href')) {
      var href = link.getAttribute('href');
      // WordPress shows a resized copy linked to the full-size file: use the full size.
      if (bhIsImageUrl(href) && url && href.replace(/-\d+x\d+(?=\.\w+$)/, '') === url.replace(/-\d+x\d+(?=\.\w+$)/, '')) data.url = href;
      else data.href = href;
      if (link.getAttribute('target') === '_blank') data.linkTarget = '_blank';
    }
    push('image', data);
  }

  function walkInto(node, align) {
    Array.prototype.forEach.call(node.childNodes, function (n) {
      if (n.nodeType === 3) { run.push(n); return; }
      if (n.nodeType !== 1) return;
      var tag = n.nodeName;
      if (tag === 'BR') { run.push(n); return; }
      if (tag === 'IMG') { flush(align); image(n, null, align); return; }
      if (tag === 'A' && n.querySelector('img') && n.textContent.trim() === '') { flush(align); image(n.querySelector('img'), n, align); return; }
      if (!BH_BLOCK_TAGS.test(tag)) {
        // An inline element that wraps an image or a block is opened up.
        if (n.querySelector('img,iframe,p,div,ul,ol,table,h1,h2,h3,h4,h5,h6,figure,blockquote,pre,video,audio')) { walkInto(n, align); return; }
        run.push(n);
        return;
      }
      flush(align);
      block(n, align);
    });
  }

  function listItems(el) {
    return Array.prototype.filter.call(el.children, function (c) { return c.nodeName === 'LI'; }).map(function (li) {
      return bhInline(li.innerHTML.replace(/<\/p>\s*<p[^>]*>/gi, '<br>').replace(/<\/?p[^>]*>/gi, ''));
    });
  }

  function tableBlock(el) {
    // Merged cells and block content inside cells cannot be represented.
    if (el.querySelector('[colspan]:not([colspan="1"]),[rowspan]:not([rowspan="1"]),table table,td img,th img,td ul,td ol,td p + p,td div div')) return false;
    var rowsOf = function (sec) { return sec ? Array.prototype.map.call(sec.querySelectorAll(':scope > tr'), function (tr) { return Array.prototype.map.call(tr.children, function (c) { return bhInline(c.innerHTML.replace(/<\/?p[^>]*>/gi, ' ')); }); }) : []; };
    var head = rowsOf(el.tHead), foot = rowsOf(el.tFoot), body = [];
    Array.prototype.forEach.call(el.tBodies, function (tb) { body = body.concat(rowsOf(tb)); });
    var direct = Array.prototype.filter.call(el.children, function (c) { return c.nodeName === 'TR'; });
    if (direct.length) body = body.concat(direct.map(function (tr) { return Array.prototype.map.call(tr.children, function (c) { return bhInline(c.innerHTML); }); }));
    // A first body row of <th> only is a header row.
    if (!head.length && body.length > 1) {
      var firstTr = el.querySelector('tr');
      if (firstTr && firstTr.children.length && Array.prototype.every.call(firstTr.children, function (c) { return c.nodeName === 'TH'; })) head = [body.shift()];
    }
    var n = 0;
    [head, body, foot].forEach(function (rows) { rows.forEach(function (r) { n = Math.max(n, r.length); }); });
    if (!n) return false;
    [head, body, foot].forEach(function (rows) { rows.forEach(function (r) { while (r.length < n) r.push(''); }); });
    var cap = el.querySelector('caption');
    var data = { head: head, body: body, foot: foot, columnAlign: [], hasFixedLayout: false, caption: cap ? bhInline(cap.innerHTML) : '' };
    var firstRow = el.querySelector('tr');
    if (firstRow) data.columnAlign = Array.prototype.map.call(firstRow.children, function (c) { return bhAlignOf(c); });
    if (/is-style-stripes/.test(el.className + ' ' + (el.parentNode && el.parentNode.className || ''))) data.styleName = 'stripes';
    push('table', data);
    return true;
  }

  function block(el, parentAlign) {
    var tag = el.nodeName, align = bhAlignOf(el) || parentAlign || '';
    var cl = el.getAttribute('class') || '';
    if (BH_KEEP_AS_HTML.test(tag)) { keepHtml(el); return; }
    if (tag === 'P' || tag === 'ADDRESS') {
      if (el.querySelector('table,form,script,video,audio,object,embed,svg,canvas,select,textarea,input')) { keepHtml(el); return; }
      walkInto(el, align); flush(align); return;
    }
    if (/^H[1-6]$/.test(tag)) {
      var level = parseInt(tag.charAt(1), 10);
      var imgs = el.querySelectorAll('img');
      var hd = { text: bhInline(el.innerHTML.replace(/<img\b[^>]*>/gi, '')), level: level };
      if (align) hd.align = align;
      if (el.id) hd.anchor = el.id;
      push('heading', hd);
      Array.prototype.forEach.call(imgs, function (i) { image(i, null, align); });
      return;
    }
    if (tag === 'UL' || tag === 'OL') {
      var lis = listItems(el);
      // A nested list, or an image inside an item, would be flattened away.
      if (!lis.length || el.querySelector('li ul,li ol,img,table,iframe,pre')) { keepHtml(el); return; }
      var ld = { style: tag === 'OL' ? 'ol' : 'ul', items: lis };
      if (tag === 'OL' && parseInt(el.getAttribute('start'), 10) > 1) ld.start = parseInt(el.getAttribute('start'), 10);
      if (tag === 'OL' && el.hasAttribute('reversed')) ld.reversed = true;
      push('list', ld);
      return;
    }
    if (tag === 'BLOCKQUOTE') {
      if (/twitter-tweet|instagram-media|tiktok-embed/.test(cl) || el.querySelector('img,ul,ol,table,pre,iframe')) { keepHtml(el); return; }
      var citeEl = el.querySelector('cite,footer');
      var cite = citeEl ? bhInline(citeEl.innerHTML) : '';
      if (citeEl) citeEl.parentNode.removeChild(citeEl);
      var paras = el.querySelectorAll('p');
      var text = paras.length ? Array.prototype.map.call(paras, function (p) { return bhInline(p.innerHTML); }).filter(Boolean).join('<br><br>') : bhInline(el.innerHTML);
      push(/wp-block-pullquote/.test(cl) || (el.parentNode && /wp-block-pullquote/.test(el.parentNode.className || '')) ? 'pullquote' : 'quote', { text: text, cite: cite });
      return;
    }
    if (tag === 'PRE') {
      if (/wp-block-preformatted|wp-block-verse/.test(cl) && !el.querySelector('code')) {
        push('preformatted', { text: sanitizeInline(el.innerHTML.replace(/\n/g, '<br>')) });
        return;
      }
      var code = el.querySelector('code') || el;
      var cls = (code.getAttribute('class') || '') + ' ' + cl;
      var lang = (cls.match(/(?:language|lang)-([\w+#-]+)/) || cls.match(/brush:\s*([\w+#-]+)/) || [])[1] || '';
      push('code', { code: code.textContent.replace(/^\n/, '').replace(/\n$/, ''), language: lang });
      return;
    }
    if (tag === 'HR') { push('divider', /is-style-dots/.test(cl) ? { styleName: 'dots' } : /is-style-wide/.test(cl) ? { styleName: 'wide' } : {}); return; }
    if (tag === 'TABLE') { if (!tableBlock(el)) keepHtml(el); return; }
    if (tag === 'DETAILS') {
      var sum = el.querySelector('summary');
      var sumHtml = sum ? bhInline(sum.innerHTML) : '';
      var body = el.cloneNode(true);
      var s2 = body.querySelector('summary'); if (s2) s2.parentNode.removeChild(s2);
      var inner = convertChildren(body);
      push('details', { summary: sumHtml, open: el.hasAttribute('open') || undefined }, inner.length ? inner : [{ type: 'paragraph', data: { text: '' } }]);
      return;
    }
    if (tag === 'VIDEO' || tag === 'AUDIO') {
      var src = el.getAttribute('src') || (el.querySelector('source') && el.querySelector('source').getAttribute('src')) || '';
      if (!src) { keepHtml(el); return; }
      var md = { src: src, caption: '' };
      ['autoplay', 'loop', 'muted', 'playsinline'].forEach(function (a) { if (el.hasAttribute(a)) md[a === 'playsinline' ? 'playsInline' : a] = true; });
      if (tag === 'VIDEO') { if (el.getAttribute('poster')) md.poster = el.getAttribute('poster'); if (!el.hasAttribute('controls')) md.controls = false; }
      push(tag.toLowerCase(), md);
      return;
    }
    if (tag === 'IFRAME') {
      var isrc = el.getAttribute('src') || el.getAttribute('data-src') || '';
      if (isrc) push('embed', align ? { url: isrc, align: align } : { url: isrc }); else keepHtml(el);
      return;
    }
    if (tag === 'FIGURE') { figure(el, align, cl); return; }

    // WordPress layout blocks.
    if (/\bwp-block-buttons\b/.test(cl) && el.querySelector('a')) {
      var btns = Array.prototype.map.call(el.querySelectorAll('a'), function (a) {
        var bd = { text: bhInline(a.innerHTML) || 'Click here', url: a.getAttribute('href') || '' };
        if (a.getAttribute('target') === '_blank') bd.linkTarget = '_blank';
        if (/is-style-outline/.test((a.parentNode && a.parentNode.className) || '')) bd.styleName = 'outline';
        return { type: 'button', data: bd };
      });
      var j = (cl.match(/is-content-justification-(left|center|right|space-between)/) || [])[1];
      push('buttons', j && j !== 'left' ? { justify: j } : {}, btns);
      return;
    }
    if (/\bwp-block-button\b/.test(cl) && el.querySelector('a')) {
      var a1 = el.querySelector('a');
      push('buttons', {}, [{ type: 'button', data: { text: bhInline(a1.innerHTML), url: a1.getAttribute('href') || '' } }]);
      return;
    }
    if (/\bwp-block-columns\b/.test(cl)) {
      var cols = Array.prototype.filter.call(el.children, function (c) { return /\bwp-block-column\b/.test(c.className || ''); });
      if (cols.length) {
        push('columns', {}, cols.map(function (c) {
          var w = ((c.getAttribute('style') || '').match(/flex-basis\s*:\s*([\d.]+)%/) || [])[1];
          var inner2 = convertChildren(c);
          return { type: 'column', data: w ? { width: parseFloat(w) } : {}, innerBlocks: inner2.length ? inner2 : [{ type: 'paragraph', data: {} }] };
        }));
        return;
      }
    }
    if (/\bwp-block-cover\b/.test(cl)) {
      var cimg = el.querySelector('img.wp-block-cover__image-background, img');
      var cinner = el.querySelector('.wp-block-cover__inner-container') || el;
      var kids = convertChildren(cinner).filter(function (b) { return !(b.type === 'image' && cimg && b.data.url === bhImageFrom(cimg)); });
      push('cover', { url: cimg ? bhImageFrom(cimg) : '', dimRatio: 50, overlayColor: '#000000', minHeight: 430 }, kids.length ? kids : [{ type: 'paragraph', data: {} }]);
      return;
    }
    if (/\bwp-block-media-text\b/.test(cl)) {
      var mimg = el.querySelector('.wp-block-media-text__media img');
      var mc = el.querySelector('.wp-block-media-text__content');
      if (mimg && mc) {
        var mk = convertChildren(mc);
        push('media-text', { url: bhImageFrom(mimg), alt: mimg.getAttribute('alt') || '', mediaPosition: /has-media-on-the-right/.test(cl) ? 'right' : 'left' }, mk.length ? mk : [{ type: 'paragraph', data: {} }]);
        return;
      }
    }
    if (/\bwp-block-file\b/.test(cl) && el.querySelector('a[href]')) {
      var fa = el.querySelector('a[href]:not(.wp-block-file__button)') || el.querySelector('a[href]');
      push('file', { href: fa.getAttribute('href'), fileName: bhInline(fa.innerHTML), showDownloadButton: !!el.querySelector('.wp-block-file__button'), downloadText: 'Download' });
      return;
    }
    // A box styled as a box (a background, border or padding) is kept as it
    // is; plain wrappers — groups, sections — are opened up.
    var style = el.getAttribute('style') || '';
    if (/background|border|padding|box-shadow|display\s*:\s*(grid|flex)/i.test(style) || /\b(alert|notice|callout|note|warning|box)\b/i.test(cl)) { keepHtml(el); return; }
    walkInto(el, align); flush(align);
  }

  function figure(el, align, cl) {
    var fimg = el.querySelector('img'), fcap = el.querySelector(':scope > figcaption');
    if (/\bwp-block-gallery\b/.test(cl) || el.querySelectorAll('img').length > 1) {
      var imgs = Array.prototype.map.call(el.querySelectorAll('img'), function (im) {
        var f = im.closest('figure') !== el ? im.closest('figure') : null;
        var c = f ? f.querySelector('figcaption') : null;
        return { url: bhImageFrom(im), alt: im.getAttribute('alt') || '', caption: c ? bhInline(c.innerHTML) : '' };
      }).filter(function (i) { return i.url; });
      var colsN = parseInt(((cl.match(/columns-(\d)/) || [])[1]), 10);
      push('gallery', { images: imgs, columns: colsN || Math.min(3, imgs.length || 1), crop: true, caption: fcap ? bhInline(fcap.innerHTML) : '' });
      return;
    }
    if (el.querySelector('table')) {
      var t = el.querySelector('table');
      var before = out.length;
      if (tableBlock(t)) {
        if (fcap && out[before]) out[before].data.caption = bhInline(fcap.innerHTML);
        if (/is-style-stripes/.test(cl) && out[before]) out[before].data.styleName = 'stripes';
      } else keepHtml(el);
      return;
    }
    var vid = el.querySelector('video'), aud = el.querySelector('audio');
    if (vid || aud) {
      var before2 = out.length;
      block(vid || aud, align);
      if (fcap && out[before2] && out[before2].type !== 'html') out[before2].data.caption = bhInline(fcap.innerHTML);
      return;
    }
    if (fimg && !el.querySelector('iframe')) {
      image(fimg, fimg.closest('a'), align || bhAlignOf(el) || ((cl.match(/\balign(left|center|right|wide|full)\b/) || [])[1] || ''), fcap ? bhInline(fcap.innerHTML) : '');
      if (/is-style-rounded/.test(cl)) out[out.length - 1].data.styleName = 'rounded';
      return;
    }
    var fif = el.querySelector('iframe');
    if (fif) { push('embed', { url: fif.getAttribute('src') || '', caption: fcap ? bhInline(fcap.innerHTML) : '' }); return; }
    if (/wp-block-embed/.test(cl)) {
      var wrapper = el.querySelector('.wp-block-embed__wrapper');
      var u = (wrapper ? wrapper.textContent : el.textContent).trim().split(/\s+/)[0];
      if (/^https?:\/\//.test(u)) { push('embed', { url: u, caption: fcap ? bhInline(fcap.innerHTML) : '' }); return; }
    }
    if (el.querySelector('blockquote')) { walkInto(el, align); flush(align); return; }
    keepHtml(el);
  }

  walkInto(container, '');
  flush('');

  // Neighbouring Custom HTML pieces read better as one box.
  var merged = [];
  out.forEach(function (b) {
    var last = merged[merged.length - 1];
    if (b.type === 'html' && last && last.type === 'html') last.data.html += '\n' + b.data.html;
    else merged.push(b);
  });
  return merged;
}

// -- Markdown (for pasting plain text that is clearly Markdown)
function looksLikeMarkdown(text) {
  return /^(#{1,6} |[-*+] |\d+[.)] |> |```)/m.test(text) || /\*\*[^*]+\*\*|\[[^\]]+\]\([^)]+\)/.test(text);
}
function markdownInline(s) {
  s = esc(s);
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
  s = s.replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, function (_, a, b) { return '<strong>' + (a || b) + '</strong>'; });
  s = s.replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>').replace(/(^|\W)_([^_\s][^_]*)_(?=\W|$)/g, '$1<em>$2</em>');
  s = s.replace(/~~([^~]+)~~/g, '<s>$1</s>');
  s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, '<img alt="$1" src="$2">');
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2">$1</a>');
  return s;
}
function markdownToHtml(md) {
  var lines = String(md || '').replace(/\r\n?/g, '\n').split('\n');
  var out = [], i = 0;
  while (i < lines.length) {
    var line = lines[i];
    var m;
    if (/^```/.test(line)) {
      var lang = line.slice(3).trim(), code = [];
      i++;
      while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]);
      i++;
      out.push('<pre><code' + (lang ? ' class="language-' + esc(lang) + '"' : '') + '>' + esc(code.join('\n')) + '</code></pre>');
      continue;
    }
    if ((m = line.match(/^(#{1,6})\s+(.*)$/))) { out.push('<h' + m[1].length + '>' + markdownInline(m[2]) + '</h' + m[1].length + '>'); i++; continue; }
    if (/^(\*\s*\*\s*\*|-\s*-\s*-|_\s*_\s*_)[\s*_-]*$/.test(line)) { out.push('<hr>'); i++; continue; }
    if (/^\s*[-*+]\s+/.test(line) || /^\s*\d+[.)]\s+/.test(line)) {
      var ordered = /^\s*\d/.test(line), items = [];
      while (i < lines.length && (ordered ? /^\s*\d+[.)]\s+/ : /^\s*[-*+]\s+/).test(lines[i])) { items.push('<li>' + markdownInline(lines[i].replace(/^\s*([-*+]|\d+[.)])\s+/, '')) + '</li>'); i++; }
      out.push((ordered ? '<ol>' : '<ul>') + items.join('') + (ordered ? '</ol>' : '</ul>'));
      continue;
    }
    if (/^>\s?/.test(line)) {
      var q = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) q.push(lines[i++].replace(/^>\s?/, ''));
      out.push('<blockquote><p>' + q.map(markdownInline).join('<br>') + '</p></blockquote>');
      continue;
    }
    if (/^\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\|?\s*:?-{2,}/.test(lines[i + 1])) {
      var cells = function (l) { return l.replace(/^\s*\||\|\s*$/g, '').split('|').map(function (c) { return markdownInline(c.trim()); }); };
      var head = cells(line); i += 2;
      var rows = [];
      while (i < lines.length && /^\|.*\|\s*$/.test(lines[i])) rows.push(cells(lines[i++]));
      out.push('<table><thead><tr>' + head.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>');
      continue;
    }
    if (line.trim() === '') { i++; continue; }
    var para = [];
    while (i < lines.length && lines[i].trim() !== '' && !/^(#{1,6}\s|```|>\s?|\s*[-*+]\s+|\s*\d+[.)]\s+)/.test(lines[i])) para.push(markdownInline(lines[i++]));
    out.push('<p>' + para.join('<br>') + '</p>');
  }
  return out.join('\n');
}
function textToHtml(text) {
  return String(text || '').replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
}

//  Paste
function blocksFromClipboard(dt) {
  var html = dt.getData('text/html') || '';
  var m = html.match(/<meta name="basehim-blocks" content="([^"]*)"/);
  if (m) {
    try { var d = JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')); if (d && Array.isArray(d.basehimBlocks)) return d.basehimBlocks; } catch (e) {}
  }
  return null;
}

function handlePaste(e) {
  var dt = e.clipboardData; if (!dt) return;
  var target = e.target && e.target.closest ? e.target.closest('.bhe-rich, .bhe-block') : null;
  if (!target || !listEl.contains(target)) return;
  var wrap = target.closest('.bhe-block');
  var id = wrap && wrap.getAttribute('data-id');
  var b = blockById(id); if (!b) return;
  var l = locate(id);

  // Files (screenshots, images copied from a file manager) upload into blocks.
  var files = Array.prototype.filter.call(dt.files || [], function (f) { return /^(image|video|audio)\//.test(f.type); });
  if (files.length && !dt.getData('text/html')) {
    e.preventDefault();
    var at = isEmptyPara(b) ? l.index : l.index + 1;
    if (isEmptyPara(b)) l.list.splice(l.index, 1);
    dropFiles(files, l.parent ? l.parent.id : null, at);
    return;
  }

  var copied = blocksFromClipboard(dt);
  var rich = e.target.closest('.bhe-rich');
  var def = defOf(b.type) || {};
  var primary = rich && def.richField && rich === richEditables(wrap)[0] && b.type !== 'list';

  // Whole blocks copied in this editor.
  if (copied && copied.length && (!rich || primary)) {
    e.preventDefault();
    var made = normalizeList(copied, true);
    if (isEmptyPara(b)) { replaceBlock(b.id, made); return; }
    insertBlockObjects(made, l.index + 1, l.parent ? l.parent.id : null);
    selectBlock(made[made.length - 1].id, { focus: 'end' });
    return;
  }
  if (!rich) {
    // A selected non-text block: paste as new blocks after it.
    var t0 = dt.getData('text/plain');
    if (!t0) return;
    e.preventDefault();
    var list0 = normalizeList(htmlToBlocks(dt.getData('text/html') || (looksLikeMarkdown(t0) ? markdownToHtml(t0) : textToHtml(t0))), true);
    if (list0.length) { insertBlockObjects(list0, l.index + 1, l.parent ? l.parent.id : null); selectBlock(list0[list0.length - 1].id, { focus: 'end' }); }
    return;
  }

  var html = dt.getData('text/html');
  var text = dt.getData('text/plain');
  if (rich.classList.contains('is-plain') || rich.getAttribute('data-formats') === 'none') {
    e.preventDefault();
    document.execCommand('insertText', false, text || stripTags(html));
    return;
  }

  // A single link pasted into an empty paragraph: embed or image.
  var trimmed = (text || '').trim();
  if (primary && b.type === 'paragraph' && isEmptyHtml(b.data.text) && /^https?:\/\/\S+$/.test(trimmed) && (!html || stripTags(html).trim() === trimmed)) {
    var info = embedInfo(trimmed);
    if (bhIsImageUrl(trimmed)) { e.preventDefault(); replaceBlock(b.id, makeBlock('image', { url: trimmed })); return; }
    if (info && info.provider !== 'generic') { e.preventDefault(); replaceBlock(b.id, makeBlock('embed', { url: trimmed })); return; }
  }
  // Text selected + a URL pasted: make it a link.
  var r = rangeIn(rich);
  if (r && !r.collapsed && /^https?:\/\/\S+$/.test(trimmed)) {
    e.preventDefault();
    document.execCommand('createLink', false, trimmed);
    fire(rich);
    return;
  }

  var source = html ? html : (looksLikeMarkdown(text) ? markdownToHtml(text) : textToHtml(text));
  var list = htmlToBlocks(source);
  // One paragraph's worth (or not the main text field): insert inline.
  var inlineOnly = !primary || !list.length || (list.length === 1 && list[0].type === 'paragraph');
  if (inlineOnly) {
    e.preventDefault();
    var inline = list.length === 1 && list[0].type === 'paragraph' ? list[0].data.text : sanitizeInline(source);
    if (!primary && list.length > 1) inline = list.map(function (x) { return x.data && (x.data.text || (x.data.items || []).join('<br>')) || ''; }).filter(Boolean).join('<br>');
    document.execCommand('insertHTML', false, inline || esc(text));
    fire(rich);
    return;
  }

  // Several blocks: split the current block at the caret and put them between.
  e.preventDefault();
  var made2 = normalizeList(list, true);
  var parts = splitHtmlAtCaret(rich);
  var field = def.richField;
  var rest = isEmptyHtml(parts[1]) ? null : makeBlock(b.type, Object.assign({}, b.data, (function () { var o = {}; o[field] = parts[1]; return o; })()));
  if (isEmptyHtml(parts[0])) {
    // Nothing before the caret: the pasted blocks replace this one.
    Array.prototype.splice.apply(l.list, [l.index, 1].concat(made2, rest ? [rest] : []));
  } else {
    b.data[field] = parts[0];
    Array.prototype.splice.apply(l.list, [l.index + 1, 0].concat(made2, rest ? [rest] : []));
  }
  renderAll();
  selectBlock(made2[made2.length - 1].id, { focus: 'end' });
  afterChange(true);
}

// Copying or cutting a selected (non-text) block puts the block itself on the clipboard.
function handleCopy(e, cut) {
  var a = document.activeElement;
  if (!a || !a.classList || !a.classList.contains('bhe-block') || !listEl.contains(a)) return;
  var b = blockById(a.getAttribute('data-id')); if (!b) return;
  var json = JSON.stringify({ basehimBlocks: [serializeBlock(b)] });
  e.preventDefault();
  e.clipboardData.setData('text/html', '<meta name="basehim-blocks" content="' + esc(json) + '">' + esc(stripTags(b.data.text || b.data.code || b.data.html || '')));
  e.clipboardData.setData('text/plain', stripTags(b.data.text || b.data.code || b.data.html || b.data.url || ''));
  if (cut) removeBlock(b.id);
}

//  Boot: wire the shell, keyboard, mouse and form
function $id(id) { return document.getElementById(id); }

// The full-screen shell is rendered by admin/views/posts/edit.php. A page that
// only provides #bh-block-editor gets a compact shell built here instead.
function ensureShell() {
  var root = $id('bhe-root');
  if (root) {
    shell = {
      root: root, header: $id('bhe-header'), left: $id('bhe-left'), sidebar: $id('bhe-sidebar'),
      postPanel: $id('bhe-post-panel'), blockPanel: $id('bhe-block-inspector'), appPanels: $id('bhe-app-panels'),
      breadcrumb: $id('bhe-breadcrumb')
    };
    canvasEl = $id('bhe-canvas');
    innerEl = $id('bhe-canvas-inner') || canvasEl;
    return;
  }
  // Compact shell: header strip, canvas and sidebar inside the mount.
  root = h('div', { class: 'bhe-root bhe-root--embedded has-sidebar', id: 'bhe-root' });
  root.innerHTML =
    '<div class="bhe-header" id="bhe-header"><div class="bhe-header__left">'
    + '<button type="button" class="bhe-hbtn bhe-hbtn--primary" id="bhe-inserter-toggle" aria-label="Block inserter" aria-pressed="false">' + icon('plus', 'bhe-icon') + '</button>'
    + '<button type="button" class="bhe-hbtn" id="bhe-undo" aria-label="Undo" disabled>' + icon('arrow-uturn-left', 'bhe-icon') + '</button>'
    + '<button type="button" class="bhe-hbtn" id="bhe-redo" aria-label="Redo" disabled>' + icon('arrow-uturn-right', 'bhe-icon') + '</button>'
    + '<button type="button" class="bhe-hbtn" id="bhe-listview-toggle" aria-label="Document overview" aria-pressed="false">' + icon('list-view', 'bhe-icon') + '</button>'
    + '</div><div class="bhe-header__right"><div id="bhe-plugin-buttons" class="bhe-plugin-buttons"></div>'
    + '<button type="button" class="bhe-hbtn is-active" id="bhe-settings-toggle" aria-label="Settings" aria-pressed="true">' + icon('sidebar', 'bhe-icon') + '</button></div></div>'
    + '<div class="bhe-body"><aside class="bhe-left" id="bhe-left" hidden></aside>'
    + '<div class="bhe-canvas" id="bhe-canvas"><div class="bhe-canvas__inner" id="bhe-canvas-inner"><div class="bhe-doc" id="bhe-doc"></div></div></div>'
    + '<aside class="bhe-sidebar" id="bhe-sidebar"><div class="bhe-sidebar__tabs" role="tablist">'
    + '<button type="button" class="bhe-sidebar__tab" data-tab="post" role="tab">' + esc(CONFIG.typeLabel || 'Document') + '</button>'
    + '<button type="button" class="bhe-sidebar__tab" data-tab="block" role="tab">Block</button></div>'
    + '<div class="bhe-sidebar__panel" id="bhe-post-panel"><div id="bhe-app-panels"></div></div>'
    + '<div class="bhe-sidebar__panel" id="bhe-block-inspector" hidden></div></aside></div>'
    + '<div class="bhe-footer" id="bhe-breadcrumb"></div>';
  mount.parentNode.insertBefore(root, mount);
  $id('bhe-doc').appendChild(mount);
  ensureShell();
}

function bootEditor() {
  ensureShell();
  document.body.classList.add('bhe-active');
  mount.textContent = '';
  mount.classList.add('bhe-editor');
  listEl = h('div', { class: 'bhe-list bhe-list--root', role: 'document', 'aria-label': 'Block editor content' });
  mount.appendChild(listEl);
  toolbarEl = h('div', { class: 'bhe-toolbar', role: 'toolbar', 'aria-label': 'Block tools', hidden: true });
  toolbarEl.addEventListener('mousedown', function (e) { if (!e.target.closest('input,select,textarea,.bhe-tb--drag')) e.preventDefault(); });
  innerEl.appendChild(toolbarEl);

  if (isBlocksMode()) load(contentField ? contentField.value : '');
  else state.blocks = [makeBlock('paragraph', {})];
  renderAll();
  historyInit();
  syncField();
  // Loading normalises the stored JSON; that is not an edit by the user.
  try { window.BasehimEditorDirty = false; } catch (e) {}

  wireHeader();
  wireCanvas();
  wireKeyboard();
  wireForm();
  wireTitle();
  installDragAndDrop();
  renderHeaderPlugins();
  renderAppPanels();

  var sideWanted = true;
  try { sideWanted = localStorage.getItem('bhe-sidebar') !== '0'; } catch (e) {}
  if (window.innerWidth < 782) sideWanted = false;
  setSidebar(sideWanted);
  state.sideTab = 'post';
  renderSidebar();
  updateModeUi();

  state.booted = true;
  if (CONFIG.flash && CONFIG.flash.message) toast(CONFIG.flash.message, CONFIG.flash.type === 'error' ? 'error' : 'success');
}

// -- header
function wireHeader() {
  var ins = $id('bhe-inserter-toggle');
  if (ins) ins.addEventListener('click', function () { inserterPoint = null; setLeftPanel('inserter'); });
  var lv = $id('bhe-listview-toggle');
  if (lv) lv.addEventListener('click', function () { setLeftPanel('listview'); });
  var u = $id('bhe-undo'); if (u) u.addEventListener('click', undo);
  var r = $id('bhe-redo'); if (r) r.addEventListener('click', redo);
  var st = $id('bhe-settings-toggle'); if (st) st.addEventListener('click', function () { setSidebar(!state.sidebarOpen); });
  Array.prototype.forEach.call(shell.sidebar.querySelectorAll('.bhe-sidebar__tab'), function (t) {
    t.addEventListener('click', function () { setSidebarTab(t.getAttribute('data-tab')); });
  });
  var close = $id('bhe-sidebar-close'); if (close) close.addEventListener('click', function () { setSidebar(false); });
  var more = $id('bhe-more'); if (more) more.addEventListener('click', function () { openMoreMenu(more); });
  // Collapsible server-rendered panels in the Post tab.
  Array.prototype.forEach.call(shell.sidebar.querySelectorAll('[data-bhe-panel]'), function (sec) {
    var btn = sec.querySelector('.bhe-panel__head'), body = sec.querySelector('.bhe-panel__body');
    if (!btn || !body) return;
    var key = 'bhe-panel-' + sec.getAttribute('data-bhe-panel');
    try { var saved = localStorage.getItem(key); if (saved != null) { var open = saved === '1'; sec.classList.toggle('is-open', open); body.hidden = !open; btn.setAttribute('aria-expanded', String(open)); } } catch (e) {}
    btn.addEventListener('click', function () {
      var open = !sec.classList.contains('is-open');
      sec.classList.toggle('is-open', open); body.hidden = !open; btn.setAttribute('aria-expanded', String(open));
      try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
    });
  });
}

function openMoreMenu(btn) {
  var items = [{ heading: 'Editor' }];
  if (formatField) {
    var cur = formatField.value;
    [['blocks', 'Visual editor'], ['html', 'Code editor (HTML)'], ['markdown', 'Markdown']].forEach(function (f) {
      items.push({ label: f[1], active: cur === f[0], onClick: function () { if (formatField.value === f[0]) return; formatField.value = f[0]; formatField.dispatchEvent(new Event('change', { bubbles: true })); } });
    });
    items.push('sep');
  }
  items.push({ icon: 'squares-2x2', label: 'Top toolbar', active: document.body.classList.contains('bhe-top-toolbar'), onClick: function () { var on = !document.body.classList.contains('bhe-top-toolbar'); document.body.classList.toggle('bhe-top-toolbar', on); try { localStorage.setItem('bhe-top-toolbar', on ? '1' : '0'); } catch (e) {} scheduleChrome(); } });
  items.push({ icon: 'command-line', label: 'Keyboard shortcuts', shortcut: 'Shift+Alt+H', onClick: showShortcuts });
  items.push({ icon: 'clipboard-document', label: 'Copy all blocks', onClick: function () { copyBlocksToClipboard(state.blocks); } });
  if (CONFIG.listUrl) { items.push('sep'); items.push({ icon: 'arrow-left', label: 'Back to ' + (CONFIG.typePlural || 'list'), onClick: function () { location.href = CONFIG.listUrl; } }); }
  openMenu(btn, items, { label: 'Options', alignRight: true });
}

var SHORTCUTS = [
  ['Global', [['Ctrl+S', 'Save your changes.'], ['Ctrl+Z', 'Undo your last changes.'], ['Ctrl+Shift+Z', 'Redo your last undo.'], ['Ctrl+Shift+,', 'Show or hide the settings sidebar.'], ['Shift+Alt+O', 'Open the List View.'], ['Shift+Alt+H', 'Display these keyboard shortcuts.']]],
  ['Selection', [['Esc', 'Select the block you are typing in (navigation mode).'], ['Ctrl+Shift+D', 'Duplicate the selected block.'], ['Shift+Alt+Z', 'Remove the selected block.'], ['Ctrl+Alt+T', 'Insert a new block before the selected block.'], ['Ctrl+Alt+Y', 'Insert a new block after the selected block.'], ['Ctrl+Shift+Alt+T', 'Move the selected block up.'], ['Ctrl+Shift+Alt+Y', 'Move the selected block down.']]],
  ['Text formatting', [['Ctrl+B', 'Make the selected text bold.'], ['Ctrl+I', 'Make the selected text italic.'], ['Ctrl+U', 'Underline the selected text.'], ['Ctrl+K', 'Convert the selected text into a link.'], ['Shift+Enter', 'Insert a line break.'], ['/', 'Choose a block type (in an empty paragraph).'], ['# ', 'Start a heading (## for level 2…).'], ['- ', 'Start a list.'], ['> ', 'Start a quote.']]]
];
function showShortcuts() {
  var box = h('div', { class: 'bhe-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Keyboard shortcuts' });
  var card = h('div', { class: 'bhe-modal__card' });
  card.appendChild(h('div', { class: 'bhe-modal__head' }, [h('h2', { text: 'Keyboard shortcuts' }), h('button', { type: 'button', class: 'bhe-iconbtn', 'aria-label': 'Close', html: icon('x-mark', 'bhe-icon'), onclick: function () { box.remove(); } })]));
  var body = h('div', { class: 'bhe-modal__body' });
  SHORTCUTS.forEach(function (sec) {
    body.appendChild(h('h3', { class: 'bhe-shortcuts__title', text: sec[0] }));
    var ul = h('ul', { class: 'bhe-shortcuts' });
    sec[1].forEach(function (s) { ul.appendChild(h('li', {}, [h('span', { text: s[1] }), h('kbd', { text: kbd(s[0]) })])); });
    body.appendChild(ul);
  });
  card.appendChild(body);
  box.appendChild(card);
  box.addEventListener('mousedown', function (e) { if (e.target === box) box.remove(); });
  box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); box.remove(); } });
  document.body.appendChild(box);
  card.querySelector('button').focus();
}

// -- canvas: selection by mouse, hover outline, typing mode
function wireCanvas() {
  listEl.addEventListener('mousedown', function (e) {
    if (e.target.closest('.bhe-appender, .bhe-inner-appender')) return;
    var w = e.target.closest('.bhe-block');
    if (!w || !listEl.contains(w)) return;
    var id = w.getAttribute('data-id');
    setTyping(false);
    if (state.selectedId !== id) selectBlock(id);
    // Clicking a part of the block that takes no keyboard input (an image, a
    // separator) focuses the block itself so Delete, Enter and arrows work.
    if (!e.target.closest('[contenteditable="true"], input, textarea, select, button, a, video, audio, iframe, .bhe-resize')) {
      setTimeout(function () { if (!document.activeElement || !w.contains(document.activeElement) || document.activeElement === document.body) w.focus({ preventScroll: true }); }, 0);
    }
  });
  // Clicking the empty canvas around the document deselects.
  canvasEl.addEventListener('mousedown', function (e) {
    if (e.target.closest('.bhe-block, .bhe-toolbar, .bhe-appender, .bhe-title, .bhe-raw, .bhe-popover')) return;
    if (state.selectedId) selectBlock(null);
  });
  var hovered = null;
  listEl.addEventListener('mouseover', function (e) {
    var w = e.target.closest('.bhe-block');
    if (w === hovered) return;
    if (hovered) hovered.classList.remove('is-hovered');
    hovered = w;
    if (w) w.classList.add('is-hovered');
  });
  listEl.addEventListener('mouseleave', function () { if (hovered) hovered.classList.remove('is-hovered'); hovered = null; });
  var lastMouse = null;
  document.addEventListener('mousemove', function (e) {
    var moved = lastMouse && (lastMouse[0] !== e.clientX || lastMouse[1] !== e.clientY);
    lastMouse = [e.clientX, e.clientY];
    if (state.typing && moved) setTyping(false);
  });
  listEl.addEventListener('paste', handlePaste);
  document.addEventListener('copy', function (e) { handleCopy(e, false); });
  document.addEventListener('cut', function (e) { handleCopy(e, true); });
  canvasEl.addEventListener('scroll', function () { scheduleChrome(); closeSlash(); }, { passive: true });
  window.addEventListener('resize', scheduleChrome);
  // Images loading change the layout under the toolbar.
  listEl.addEventListener('load', scheduleChrome, true);
  try { if (localStorage.getItem('bhe-top-toolbar') === '1') document.body.classList.add('bhe-top-toolbar'); } catch (e) {}
}

// -- keyboard
function inEditor(el) { return !!(el && (el === document.body || (shell.root && shell.root.contains(el)))); }
function inCanvasText(el) { return !!(el && listEl.contains(el)); }

function wireKeyboard() {
  document.addEventListener('keydown', function (e) {
    if (!isBlocksMode() && !(e.key === 's' && (e.ctrlKey || e.metaKey))) return;
    var mod = e.ctrlKey || e.metaKey;
    var k = (e.key || '').toLowerCase();
    var a = document.activeElement;
    var b = blockById(state.selectedId);
    var inModal = a && a.closest && a.closest('.bhe-modal, .nm-overlay, .bh-modal');
    if (inModal) return;

    if (mod && !e.altKey && k === 's') { e.preventDefault(); saveDraft(); return; }
    if (!inEditor(a)) return;
    var inField = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.nodeName) && !inCanvasText(a);

    // Undo / redo over the whole document (fields in the sidebar keep their own).
    if (mod && !e.altKey && (k === 'z' || k === 'y') && !inField) {
      e.preventDefault();
      if (k === 'y' || e.shiftKey) redo(); else undo();
      return;
    }
    if (mod && e.shiftKey && (e.key === ',' || e.code === 'Comma')) { e.preventDefault(); setSidebar(!state.sidebarOpen); return; }
    if (e.shiftKey && e.altKey && !mod && e.code === 'KeyO') { e.preventDefault(); setLeftPanel('listview'); return; }
    if (e.shiftKey && e.altKey && !mod && e.code === 'KeyH') { e.preventDefault(); showShortcuts(); return; }
    if (inField) return;
    if (!b) return;
    var l = locate(b.id);
    if (mod && e.shiftKey && !e.altKey && k === 'd') { e.preventDefault(); duplicateBlock(b.id); return; }
    if (e.shiftKey && e.altKey && !mod && e.code === 'KeyZ') { e.preventDefault(); removeBlock(b.id); return; }
    if (mod && e.altKey && !e.shiftKey && e.code === 'KeyT') { e.preventDefault(); insertDefaultAt(l.parent ? l.parent.id : null, l.index); return; }
    if (mod && e.altKey && !e.shiftKey && e.code === 'KeyY') { e.preventDefault(); insertDefaultAt(l.parent ? l.parent.id : null, l.index + 1); return; }
    if (mod && e.altKey && e.shiftKey && e.code === 'KeyT') { e.preventDefault(); moveUpDown(b.id, -1); return; }
    if (mod && e.altKey && e.shiftKey && e.code === 'KeyY') { e.preventDefault(); moveUpDown(b.id, 1); return; }

    // Escape while typing: select the block itself (navigation mode).
    if (e.key === 'Escape' && !openPopover && inCanvasText(a) && a.closest('.bhe-block') !== a) {
      var w = wrapOf(b.id); if (w) { e.preventDefault(); w.focus({ preventScroll: true }); setTyping(false); }
      return;
    }

    // Keys on a selected block that has focus itself (not its text).
    if (a && a.classList && a.classList.contains('bhe-block') && listEl.contains(a)) {
      var id = a.getAttribute('data-id');
      if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); removeBlock(id); return; }
      if (e.key === 'Enter' && !mod) {
        e.preventDefault();
        var eds = richEditables(a);
        if (eds.length) { focusBlock(id, 'start'); return; }
        var la = locate(id);
        insertDefaultAt(la.parent ? la.parent.id : null, la.index + 1);
        return;
      }
      if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); navigateBlock(id, -1); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); navigateBlock(id, 1); return; }
      if (e.key === 'Tab') return;
    }
  });
}
function navigateBlock(id, dir) {
  var order = flatOrder();
  for (var i = 0; i < order.length; i++) if (order[i].id === id) break;
  var n = order[i + dir];
  if (!n) return;
  selectBlock(n.id, { scroll: true });
  var w = wrapOf(n.id); if (w) w.focus({ preventScroll: true });
}

// -- form: save, publish, mode switch
function submitForm() {
  if (!form) return;
  if (typeof form.requestSubmit === 'function') form.requestSubmit();
  else { var ev = new Event('submit', { cancelable: true }); if (form.dispatchEvent(ev)) form.submit(); }
}
function statusField() { return form ? form.querySelector('[name="status"]') : null; }
// Ctrl+S saves with the current status: a draft stays a draft, a published
// post is updated.
function saveDraft() { submitForm(); }
function wireForm() {
  if (!form) return;
  form.addEventListener('submit', function () {
    if (isBlocksMode()) { emit('save', getBlocks()); syncField(); }
    var t = form.querySelector('[name="title"]');
    if (t && t.value.trim() === '') t.value = 'Untitled';
    shell.root.classList.add('is-saving');
    var st = $id('bhe-save-state'); if (st) st.textContent = 'Saving…';
  });
  var publish = $id('bhe-publish');
  if (publish) publish.addEventListener('click', function (e) {
    var s = statusField();
    var to = publish.getAttribute('data-status');
    if (s && to) {
      if (!Array.prototype.some.call(s.options, function (o) { return o.value === to; })) return;
      s.value = to;
    }
  });
  var draft = $id('bhe-save-draft');
  if (draft) draft.addEventListener('click', function () {
    var s = statusField();
    if (s && draft.getAttribute('data-status')) s.value = draft.getAttribute('data-status');
  });
  if (formatField) formatField.addEventListener('change', function () { setTimeout(updateModeUi, 0); });
}
function updateModeUi() {
  var blocks = isBlocksMode();
  shell.root.classList.toggle('is-code-mode', !blocks);
  if (!blocks) { selectBlock(null); toolbarEl.hidden = true; if (state.leftPanel) setLeftPanel(state.leftPanel); }
  var raw = $id('nbe-raw');
  if (raw) raw.hidden = blocks;
  mount.hidden = !blocks;
  ['bhe-inserter-toggle', 'bhe-listview-toggle', 'bhe-undo', 'bhe-redo'].forEach(function (id) { var el = $id(id); if (el) el.disabled = !blocks || (id === 'bhe-undo' || id === 'bhe-redo' ? el.disabled : false); });
  if (blocks) updateHistoryButtons();
  var label = $id('bhe-mode-label');
  if (label) label.hidden = blocks;
}

// -- title
function wireTitle() {
  var t = form ? form.querySelector('textarea[name="title"].bhe-title') : null;
  if (!t) return;
  function grow() { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
  var doc = $id('bhe-doctitle');
  t.addEventListener('input', function () {
    if (/\n/.test(t.value)) t.value = t.value.replace(/\s*\n+\s*/g, ' ');
    grow();
    if (doc) doc.textContent = t.value.trim() || 'Untitled';
    try { window.BasehimEditorDirty = true; } catch (e) {}
  });
  t.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (!isBlocksMode()) { var raw = $id('nbe-raw'); if (raw) raw.focus(); return; }
      var first = state.blocks[0];
      if (first && isEmptyPara(first)) selectBlock(first.id, { focus: 'start' });
      else insertBlock('paragraph', {}, 0, null);
    } else if (e.key === 'ArrowDown' && t.selectionStart === t.value.length && isBlocksMode() && state.blocks[0]) {
      e.preventDefault(); selectBlock(state.blocks[0].id, { focus: 'start' });
    }
  });
  t.addEventListener('focus', function () { if (state.selectedId) selectBlock(null); });
  grow();
  window.addEventListener('resize', grow);
  // From the first block, ArrowUp at the very top goes to the title.
  listEl.addEventListener('keydown', function (e) {
    if ((e.key !== 'ArrowUp' && e.key !== 'ArrowLeft') || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
    var first = flatOrder()[0];
    var a = document.activeElement;
    if (!first || !a || !a.isContentEditable) return;
    var w = a.closest('.bhe-block');
    if (!w || w.getAttribute('data-id') !== first.id) return;
    if (richEditables(w)[0] !== a) return;
    if ((e.key === 'ArrowUp' && onFirstLine(a)) || (e.key === 'ArrowLeft' && caretAtStart(a))) {
      e.preventDefault(); e.stopPropagation(); selectBlock(null); t.focus(); t.setSelectionRange(t.value.length, t.value.length);
    }
  }, true);
}

//  Global API + start
function defineApiStub() {
  // Editor not on this page: a queueing stub lets app scripts that load on
  // every admin page call BasehimEditor.* safely.
  if (window.BasehimEditor) return;
  var q = [];
  window.BasehimEditor = new Proxy({ _queue: q }, { get: function (o, k) { if (k in o) return o[k]; return function () { q.push([k, arguments]); }; } });
}

// Replay calls queued by app scripts that loaded before us.
var preQueue = window.BasehimEditor && window.BasehimEditor._queue;
window.BasehimEditor = api;
if (preQueue && preQueue.length) preQueue.forEach(function (c) { if (c[0] !== 'on' && c[0] !== 'addFilter' && c[0] !== 'registerBlock' && c[0] !== 'addToolbarButton' && c[0] !== 'addBlockAction' && c[0] !== 'addSidebarPanel') return; try { api[c[0]].apply(null, c[1]); } catch (e) { console.error(e); } });

function start() {
  try { bootEditor(); }
  catch (e) {
    console.error('[BasehimEditor] failed to start', e);
    // Never leave the author without a way to edit: fall back to the textarea.
    var raw = $id('nbe-raw'); if (raw) { raw.hidden = false; raw.style.display = ''; }
    if (mount) mount.innerHTML = '<div class="bhe-notice bhe-notice--error">The block editor could not start: ' + esc(e.message) + '. The content is shown as source below.</div>';
    return;
  }
  // Calls that need a running editor (insertBlock, setBlocks…) replay now.
  if (preQueue && preQueue.length) preQueue.forEach(function (c) { if (c[0] === 'on' || c[0] === 'addFilter' || c[0] === 'registerBlock' || c[0] === 'addToolbarButton' || c[0] === 'addBlockAction' || c[0] === 'addSidebarPanel') return; try { api[c[0]] && api[c[0]].apply(null, c[1]); } catch (e) { console.error(e); } });
  emit('init', api);
  document.dispatchEvent(new CustomEvent('bh-editor:ready', { detail: api }));
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();

})();
