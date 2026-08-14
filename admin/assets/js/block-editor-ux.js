/* ─────────────────────────────────────────────────────────────────────────────
   Basehim Block Editor — writing experience
   Added in 1.42.13.

   Everything here is built on the editor's existing public API rather than by
   editing block-editor.js. That file is nearly a thousand lines and already
   works; adding to it in place would risk the parts that are fine. This loads
   after it, finds window.BasehimEditor, and adds the affordances a writer expects:

     /            insert a block without leaving the keyboard
     selection    a formatting toolbar where the text is
     Ctrl+Z/Y     undo and redo
     Ctrl+D       duplicate a block
     paste        keep the structure of what was pasted
     Ctrl+S       save

   If the editor is not on the page this does nothing at all.
   ───────────────────────────────────────────────────────────────────────────── */
(function (global) {
'use strict';

var E = null;
var root = null;

// ── boot ────────────────────────────────────────────────────────────────────
function boot() {
  E = global.BasehimEditor;
  if (!E || typeof E.getBlocks !== 'function') return;

  root = document.querySelector('.nbe');
  if (!root) return;

  installHistory();
  installSlashMenu();
  installInlineToolbar();
  installPaste();
  installShortcuts();
  installDuplicate();
  installAlignment();

  // Announce the shortcuts once, quietly — a writer should not have to guess
  // that "/" does anything.
  var hint = document.createElement('div');
  hint.className = 'nbe-hint';
  hint.innerHTML = 'Type <kbd>/</kbd> for blocks · select text to format · '
                 + '<kbd>Ctrl</kbd>+<kbd>Z</kbd> undo · <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>E</kbd> centre';
  var canvas = root.querySelector('.nbe-canvas');
  if (canvas) canvas.appendChild(hint);
}

// ── history ─────────────────────────────────────────────────────────────────
/**
 * Undo and redo over the block model.
 *
 * The editor has no history of its own. Snapshots are taken of the serialised
 * block list, which is small — text and settings, never image data — so a
 * generous depth costs almost nothing.
 *
 * Snapshots are debounced. Without that, typing a sentence would record a step
 * per keystroke and undo would crawl backwards one letter at a time.
 */
var history = { stack: [], index: -1, limit: 60, muted: false, timer: null };

function snapshot() {
  if (history.muted) return;
  clearTimeout(history.timer);
  history.timer = setTimeout(function () {
    var json = JSON.stringify(E.getBlocks());
    if (history.stack[history.index] === json) return;   // nothing actually changed
    history.stack = history.stack.slice(0, history.index + 1);
    history.stack.push(json);
    if (history.stack.length > history.limit) history.stack.shift();
    history.index = history.stack.length - 1;
    updateHistoryButtons();
  }, 400);
}

function applyHistory(i) {
  if (i < 0 || i >= history.stack.length) return;
  history.index = i;
  history.muted = true;
  try {
    E.setBlocks(JSON.parse(history.stack[i]));
  } finally {
    // Released on the next tick so the re-render's own change event does not
    // record the undo as a new step, which would make redo impossible.
    setTimeout(function () { history.muted = false; updateHistoryButtons(); }, 0);
  }
}

function undo() { if (history.index > 0) applyHistory(history.index - 1); }
function redo() { if (history.index < history.stack.length - 1) applyHistory(history.index + 1); }

function installHistory() {
  history.stack = [JSON.stringify(E.getBlocks())];
  history.index = 0;
  E.on('change', snapshot);

  E.addToolbarButton({
    label: 'Undo', icon: 'arrow-uturn-left', id: 'nbe-undo',
    onClick: undo
  });
  E.addToolbarButton({
    label: 'Redo', icon: 'arrow-path', id: 'nbe-redo',
    onClick: redo
  });
  setTimeout(updateHistoryButtons, 50);
}

function updateHistoryButtons() {
  var u = document.getElementById('nbe-undo');
  var r = document.getElementById('nbe-redo');
  if (u) u.disabled = history.index <= 0;
  if (r) r.disabled = history.index >= history.stack.length - 1;
}

// ── slash menu ──────────────────────────────────────────────────────────────
/**
 * Typing "/" at the start of an empty paragraph opens the block list.
 *
 * The single most useful thing a modern editor does: it means a writer never
 * has to move to the mouse to add a heading, an image or a list.
 */
var slash = { el: null, items: [], active: 0, query: '', anchorBlock: null };

function installSlashMenu() {
  root.addEventListener('keydown', function (e) {
    if (slash.el) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveSlash(1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); moveSlash(-1); return; }
      if (e.key === 'Enter') { e.preventDefault(); chooseSlash(); return; }
      if (e.key === 'Escape') { e.preventDefault(); closeSlash(); return; }
      if (e.key === 'Backspace' && slash.query === '') { closeSlash(); return; }
      return;
    }
    if (e.key !== '/') return;

    var ed = e.target.closest ? e.target.closest('[contenteditable="true"]') : null;
    if (!ed) return;
    // Only on an empty line, so "/" inside a sentence stays a slash.
    if (ed.textContent.trim() !== '') return;

    var sel = E.getSelected();
    if (!sel) return;
    setTimeout(function () { openSlash(ed, sel); }, 0);
  });

  root.addEventListener('input', function () {
    if (!slash.el) return;
    var ed = slash.anchorEl;
    if (!ed) return;
    var text = ed.textContent || '';
    var i = text.indexOf('/');
    if (i < 0) { closeSlash(); return; }
    slash.query = text.slice(i + 1).toLowerCase();
    renderSlash();
  });

  document.addEventListener('click', function (e) {
    if (slash.el && !slash.el.contains(e.target)) closeSlash();
  });
}

function blockCatalogue() {
  // Read the registry through the inserter the editor already builds, so this
  // list cannot drift from the blocks actually registered — including any a
  // plugin has added.
  var out = [];
  var reg = E.config && E.config.blocks;
  if (Array.isArray(reg)) return reg;

  // Fall back to the known core set with sensible search terms.
  [
    ['paragraph', 'Paragraph', 'text body'],
    ['heading', 'Heading', 'title h2 h3'],
    ['list', 'List', 'bullet numbered ul ol'],
    ['image', 'Image', 'photo picture media'],
    ['quote', 'Quote', 'blockquote citation'],
    ['code', 'Code', 'snippet pre'],
    ['button', 'Button', 'cta link'],
    ['divider', 'Divider', 'hr separator rule'],
    ['spacer', 'Spacer', 'gap space'],
    ['embed', 'Embed', 'video youtube iframe'],
    ['html', 'Custom HTML', 'raw markup'],
    ['widget', 'Widget', 'plugin']
  ].forEach(function (b) { out.push({ type: b[0], title: b[1], keywords: b[2] }); });
  return out;
}

function openSlash(ed, block) {
  slash.anchorEl = ed;
  slash.anchorBlock = block;
  slash.query = '';
  slash.active = 0;

  slash.el = document.createElement('div');
  slash.el.className = 'nbe-slash';
  document.body.appendChild(slash.el);

  var r = ed.getBoundingClientRect();
  slash.el.style.left = Math.min(r.left, window.innerWidth - 300) + 'px';
  slash.el.style.top = (r.bottom + window.scrollY + 6) + 'px';

  renderSlash();
}

function renderSlash() {
  if (!slash.el) return;
  var q = slash.query;
  slash.items = blockCatalogue().filter(function (b) {
    if (!q) return true;
    return (b.title + ' ' + (b.keywords || '')).toLowerCase().indexOf(q) >= 0;
  });
  if (slash.active >= slash.items.length) slash.active = Math.max(0, slash.items.length - 1);

  slash.el.textContent = '';
  if (!slash.items.length) {
    var none = document.createElement('div');
    none.className = 'nbe-slash__none';
    none.textContent = 'No block matches "' + q + '"';
    slash.el.appendChild(none);
    return;
  }

  slash.items.forEach(function (b, i) {
    var row = document.createElement('button');
    row.type = 'button';
    row.className = 'nbe-slash__item' + (i === slash.active ? ' is-on' : '');
    row.textContent = b.title;
    row.addEventListener('mouseenter', function () { slash.active = i; renderSlash(); });
    row.addEventListener('click', function () { slash.active = i; chooseSlash(); });
    slash.el.appendChild(row);
  });
}

function moveSlash(d) {
  slash.active = (slash.active + d + slash.items.length) % slash.items.length;
  renderSlash();
}

function chooseSlash() {
  var item = slash.items[slash.active];
  var ed = slash.anchorEl;
  var block = slash.anchorBlock;
  closeSlash();
  if (!item) return;

  // Clear the "/query" the writer typed before swapping the block in.
  if (ed) ed.innerHTML = '';

  var blocks = E.getBlocks();
  var at = blocks.findIndex(function (b) { return b.id === (block && block.id); });

  if (item.type === 'paragraph') return;

  E.insertBlock(item.type, {}, at >= 0 ? at + 1 : undefined);

  // Remove the now-empty paragraph the slash was typed into, so choosing a
  // heading does not leave a blank line above it.
  if (at >= 0 && block && block.type === 'paragraph') {
    var still = E.getBlocks().find(function (b) { return b.id === block.id; });
    if (still && !(still.data.text || '').trim()) E.removeBlock(block.id);
  }
}

function closeSlash() {
  if (slash.el) slash.el.remove();
  slash.el = null;
  slash.query = '';
  slash.anchorEl = null;
}

// ── inline formatting toolbar ───────────────────────────────────────────────
/**
 * A small toolbar over any selected text.
 *
 * document.execCommand is deprecated and has no replacement for this. Every
 * browser still implements it, and the alternative — managing ranges and
 * splicing markup by hand — is a large amount of code that breaks in ways this
 * does not.
 */
var inline = { el: null };

function installInlineToolbar() {
  document.addEventListener('selectionchange', function () {
    var sel = document.getSelection();
    if (!sel || sel.isCollapsed || !sel.rangeCount) { hideInline(); return; }

    var node = sel.anchorNode;
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    if (!el || !el.closest) { hideInline(); return; }
    var editable = el.closest('[contenteditable="true"]');
    if (!editable || !root.contains(editable)) { hideInline(); return; }
    if (String(sel).trim() === '') { hideInline(); return; }

    showInline(sel);
  });

  window.addEventListener('scroll', hideInline, true);
}

var INLINE_ACTIONS = [
  ['bold', 'B', 'Bold  Ctrl+B', 'font-weight:700'],
  ['italic', 'I', 'Italic  Ctrl+I', 'font-style:italic'],
  ['underline', 'U', 'Underline  Ctrl+U', 'text-decoration:underline'],
  ['strikeThrough', 'S', 'Strikethrough', 'text-decoration:line-through'],
  ['code', '<>', 'Inline code', 'font-family:ui-monospace,monospace;font-size:11px'],
  ['createLink', '\u2693', 'Link  Ctrl+K', '']
];

function showInline(sel) {
  if (!inline.el) {
    inline.el = document.createElement('div');
    inline.el.className = 'nbe-inline';
    INLINE_ACTIONS.forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'nbe-inline__btn';
      b.textContent = a[1];
      b.title = a[2];
      if (a[3]) b.style.cssText += a[3];
      // mousedown, not click: click fires after the selection is lost to the
      // button itself, and the command would then apply to nothing.
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();
        runInline(a[0]);
      });
      inline.el.appendChild(b);
    });
    document.body.appendChild(inline.el);
  }

  var r = sel.getRangeAt(0).getBoundingClientRect();
  if (!r.width && !r.height) { hideInline(); return; }
  inline.el.style.display = 'flex';
  var w = inline.el.offsetWidth || 220;
  inline.el.style.left = Math.max(8, Math.min(window.innerWidth - w - 8,
    r.left + r.width / 2 - w / 2)) + 'px';
  inline.el.style.top = (r.top + window.scrollY - inline.el.offsetHeight - 8) + 'px';
}

function hideInline() { if (inline.el) inline.el.style.display = 'none'; }

function runInline(cmd) {
  if (cmd === 'createLink') {
    var url = prompt('Link address:', 'https://');
    if (!url) return;
    document.execCommand('createLink', false, url);
  } else if (cmd === 'code') {
    // No execCommand for inline code, so the selection is wrapped by hand.
    var sel = document.getSelection();
    if (!sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    var code = document.createElement('code');
    try {
      range.surroundContents(code);
    } catch (e) {
      // surroundContents refuses a selection that crosses element boundaries.
      code.textContent = String(sel);
      range.deleteContents();
      range.insertNode(code);
    }
  } else {
    document.execCommand(cmd, false, null);
  }

  // The block's own input listener fires on typing, not on execCommand, so the
  // model is told explicitly — otherwise the formatting would vanish on the
  // next re-render.
  var ed = document.activeElement && document.activeElement.closest
    ? document.activeElement.closest('[contenteditable="true"]') : null;
  if (ed) ed.dispatchEvent(new Event('input', { bubbles: true }));
}

// ── paste ───────────────────────────────────────────────────────────────────
/**
 * Paste as structure, not as a wall of markup.
 *
 * Pasting from a document or a web page normally dumps styled HTML into one
 * paragraph. Here the HTML is read, split on its block-level elements, and each
 * becomes the block it should be — headings stay headings, lists stay lists.
 */
function installPaste() {
  root.addEventListener('paste', function (e) {
    var ed = e.target.closest ? e.target.closest('[contenteditable="true"]') : null;
    if (!ed) return;

    var html = e.clipboardData && e.clipboardData.getData('text/html');
    var text = e.clipboardData && e.clipboardData.getData('text/plain');

    if (!html) {
      // Plain text with blank lines becomes separate paragraphs, which is
      // almost always what was meant.
      if (text && text.indexOf('\n\n') >= 0) {
        e.preventDefault();
        pasteBlocks(text.split(/\n{2,}/).map(function (p) {
          return { type: 'paragraph', data: { text: escapeHtml(p.trim()) } };
        }));
      }
      return;
    }

    var parsed = parsePastedHtml(html);
    if (parsed.length <= 1) {
      // A single fragment: let the browser paste it inline, but strip the
      // styling so a pasted sentence does not arrive in Times New Roman.
      e.preventDefault();
      document.execCommand('insertHTML', false, cleanInline(html));
      ed.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }

    e.preventDefault();
    pasteBlocks(parsed);
  });
}

function parsePastedHtml(html) {
  var doc = new DOMParser().parseFromString(html, 'text/html');
  var out = [];

  Array.prototype.forEach.call(doc.body.children, function (node) {
    var tag = node.tagName.toLowerCase();

    if (/^h[1-6]$/.test(tag)) {
      out.push({ type: 'heading', data: { level: Math.min(6, Math.max(2, +tag[1])),
                                          text: cleanInline(node.innerHTML) } });
    } else if (tag === 'ul' || tag === 'ol') {
      var items = Array.prototype.map.call(node.querySelectorAll('li'), function (li) {
        return cleanInline(li.innerHTML);
      });
      if (items.length) out.push({ type: 'list', data: { style: tag, items: items } });
    } else if (tag === 'blockquote') {
      out.push({ type: 'quote', data: { text: cleanInline(node.textContent), cite: '' } });
    } else if (tag === 'pre') {
      out.push({ type: 'code', data: { code: node.textContent, language: '' } });
    } else if (tag === 'hr') {
      out.push({ type: 'divider', data: {} });
    } else if (tag === 'img') {
      out.push({ type: 'image', data: { url: node.getAttribute('src'), alt: node.getAttribute('alt') || '' } });
    } else {
      var t = cleanInline(node.innerHTML);
      if (t.replace(/<[^>]*>/g, '').trim()) out.push({ type: 'paragraph', data: { text: t } });
    }
  });

  return out;
}

/** Keep the meaningful inline tags; discard fonts, colours and classes. */
function cleanInline(html) {
  var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
  var wrap = doc.body.firstChild;
  var keep = { B: 1, STRONG: 1, I: 1, EM: 1, U: 1, S: 1, STRIKE: 1, CODE: 1, A: 1, BR: 1 };

  (function walk(node) {
    Array.prototype.slice.call(node.childNodes).forEach(function (c) {
      if (c.nodeType !== 1) return;
      walk(c);
      if (!keep[c.tagName]) {
        // Unwrap rather than delete, so the words inside survive.
        while (c.firstChild) c.parentNode.insertBefore(c.firstChild, c);
        c.remove();
      } else {
        Array.prototype.slice.call(c.attributes).forEach(function (a) {
          if (c.tagName === 'A' && a.name === 'href') return;
          c.removeAttribute(a.name);
        });
      }
    });
  })(wrap);

  return wrap.innerHTML.replace(/\s+/g, ' ').trim();
}

function pasteBlocks(list) {
  var sel = E.getSelected();
  var blocks = E.getBlocks();
  var at = sel ? blocks.findIndex(function (b) { return b.id === sel.id; }) : -1;

  E.insertBlocks(list, at >= 0 ? at + 1 : undefined);

  // Drop the empty paragraph that was pasted into.
  if (sel && sel.type === 'paragraph' && !(sel.data.text || '').trim()) E.removeBlock(sel.id);
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ── duplicate ───────────────────────────────────────────────────────────────
/**
 * No block action is registered here: the editor already ships a Duplicate
 * button in every block toolbar. Only the keyboard shortcut was missing.
 */
function installDuplicate() { /* Ctrl+D is wired in installShortcuts */ }

function duplicateBlock(block) {
  var b = block || E.getSelected();
  if (!b) return;
  var blocks = E.getBlocks();
  var at = blocks.findIndex(function (x) { return x.id === b.id; });
  // A deep copy, or the two blocks would share one data object and editing
  // either would change both.
  E.insertBlock(b.type, JSON.parse(JSON.stringify(b.data || {})), at + 1);
}

// ── alignment ───────────────────────────────────────────────────────────────
/**
 * Alignment buttons in every block's own toolbar.
 *
 * The data model and the server renderer already understood `align` on
 * paragraphs, headings, images and buttons — it was only reachable through a
 * dropdown buried in the inspector sidebar. Four buttons where the block is
 * take one click. Lists, quotes, code and embeds get alignment here too.
 *
 * The editor's own `when` predicate hides an action on blocks it does not
 * apply to, so a divider never offers four buttons that do nothing.
 */
var ALIGNABLE = {
  paragraph: 'text', heading: 'text', list: 'text', quote: 'text', code: 'text',
  button: 'text', html: 'text', image: 'block', embed: 'block'
};

var ALIGNMENTS = [
  ['left',    'M3 5h18M3 10h11M3 15h18M3 20h11'],
  ['center',  'M3 5h18M6 10h12M3 15h18M6 20h12'],
  ['right',   'M3 5h18M10 10h11M3 15h18M10 20h11'],
  ['justify', 'M3 5h18M3 10h18M3 15h18M3 20h18']
];

function installAlignment() {
  ALIGNMENTS.forEach(function (a) {
    E.addBlockAction({
      title: 'Align ' + a[0],
      // A name the icon set resolves, so no question-mark appears; the real
      // glyph is substituted below because the set has no alignment icons.
      icon: 'bars-3-bottom-left',
      when: function (block) {
        var kind = ALIGNABLE[block.type];
        if (!kind) return false;
        // Justify only means anything for flowing text.
        return !(a[0] === 'justify' && kind !== 'text');
      },
      onClick: function (block) { setAlign(block, a[0]); }
    });
  });

  // Buttons are rebuilt on every render, so the glyphs and the active state
  // have to be reapplied each time.
  E.on('change', function () { schedulePaint(); });
  E.on('select', function () { schedulePaint(); });
  schedulePaint();
}

var paintTimer = null;
function schedulePaint() {
  clearTimeout(paintTimer);
  paintTimer = setTimeout(paintAlignButtons, 30);
}

function setAlign(block, value) {
  var b = block || E.getSelected();
  if (!b || !ALIGNABLE[b.type]) return;
  // Clicking the current alignment clears it — the only way back to the
  // theme's own default, and how a toggle should behave.
  var next = (b.data && b.data.align) === value ? '' : value;
  E.updateBlock(b.id, { align: next });
}

/**
 * Swap in the alignment glyphs, mark the active one, and preview the result.
 *
 * Buttons are matched by their title rather than by index: the editor numbers
 * block actions in registration order, and a plugin registering its own action
 * first would shift every index.
 */
function paintAlignButtons() {
  var blocks = E.getBlocks();

  document.querySelectorAll('.nbe-block').forEach(function (el) {
    var id = el.getAttribute('data-id');
    var b = blocks.find(function (x) { return x.id === id; });
    if (!b) return;

    ALIGNMENTS.forEach(function (a) {
      var btn = el.querySelector('.nbe-bbtn[title="Align ' + a[0] + '"]');
      if (!btn) return;

      if (btn.dataset.aligned !== '1') {
        btn.dataset.aligned = '1';
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" '
          + 'stroke="currentColor" stroke-width="2" stroke-linecap="round">'
          + '<path d="' + a[1] + '"/></svg>';
      }
      btn.classList.toggle('is-on', (b.data && b.data.align) === a[0]);
    });

    previewAlign(el, b);
  });
}

/**
 * Show the alignment in the editor straight away.
 *
 * Only the paragraph block applies it itself, so without this a heading or a
 * list would look unchanged until the page was published.
 */
function previewAlign(el, b) {
  var body = el.querySelector('.nbe-block__body');
  if (!body) return;
  var align = (b.data && b.data.align) || '';

  if (ALIGNABLE[b.type] === 'block') {
    // An image is positioned by margin, not by text-align.
    var fig = body.querySelector('figure, .nbe-fig, .nbe-embedprev, img');
    if (fig) {
      fig.style.display = align ? 'block' : '';
      fig.style.marginLeft = (align === 'right' || align === 'center') ? 'auto' : '';
      fig.style.marginRight = (align === 'left' || align === 'center') ? 'auto' : '';
    }
  } else {
    body.style.textAlign = align;
  }
}

// ── shortcuts ───────────────────────────────────────────────────────────────
function installShortcuts() {
  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey)) return;
    var inEditor = root.contains(document.activeElement) || slash.el;
    if (!inEditor) return;

    var k = e.key.toLowerCase();
    if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
    else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
    else if (k === 'd') { e.preventDefault(); duplicateBlock(); }
    else if (k === 'l' && e.shiftKey) { e.preventDefault(); setAlign(null, 'left'); }
    else if (k === 'e' && e.shiftKey) { e.preventDefault(); setAlign(null, 'center'); }
    else if (k === 'r' && e.shiftKey) { e.preventDefault(); setAlign(null, 'right'); }
    else if (k === 'j' && e.shiftKey) { e.preventDefault(); setAlign(null, 'justify'); }
    else if (k === 'k') {
      e.preventDefault();
      var sel = document.getSelection();
      if (sel && !sel.isCollapsed) runInline('createLink');
    }
    else if (k === 's') {
      // Save, rather than letting the browser offer to save the page.
      e.preventDefault();
      var save = document.querySelector('.nbe-side__save, [data-nbe-save], button[type="submit"]');
      if (save) save.click();
    }
  });
}

// ── start ───────────────────────────────────────────────────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 60); });
} else {
  setTimeout(boot, 60);
}
})(window);
