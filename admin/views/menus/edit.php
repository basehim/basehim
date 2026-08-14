<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <div class="flex items-center gap-3 min-w-0">
        <a href="<?= $base ?>/admin/menus" class="text-slate-400 hover:text-blue-600 shrink-0" title="All menus"><?= icon('arrow-left', 'w-5 h-5') ?></a>
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-slate-900 truncate"><?= htmlspecialchars($menu['name']) ?></h2>
            <p class="text-xs text-slate-500">
                <?php $loc = (string) ($menu['location'] ?? ''); ?>
                <?= $loc !== '' ? 'Shown in the <strong>' . htmlspecialchars(ucfirst($loc)) . '</strong> location' : 'Not assigned to a location yet' ?>
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <span id="mb-dirty" class="hidden text-xs text-amber-600 font-medium">Unsaved changes</span>
        <button type="button" id="mb-save" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-lg text-sm font-medium shadow-sm">
            <?= icon('check', 'w-4 h-4') ?> <span id="mb-save-txt">Save menu</span>
        </button>
    </div>
</div>

<div id="mb-flash" class="hidden mb-4 rounded-lg px-3 py-2.5 text-sm border"></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

    <!-- ─────────────── Sources ─────────────── -->
    <div class="space-y-3">
        <?php
        // Each source is a checkbox list plus one "Add to menu" action — the
        // pattern people already know from other CMSs.
        $panels = [];
        if (!empty($sources['pages'])) {
            $panels[] = ['label' => 'Pages', 'icon' => 'document', 'type' => 'page', 'items' => $sources['pages']];
        }
        if (!empty($sources['posts'])) {
            $panels[] = ['label' => 'Posts', 'icon' => 'newspaper', 'type' => 'post', 'items' => $sources['posts']];
        }
        foreach (($sources['taxonomies'] ?? []) as $t) {
            $panels[] = [
                'label' => $t['label'],
                'icon'  => ($t['slug'] ?? '') === 'tag' ? 'tag' : 'folder',
                'type'  => 'taxonomy',
                'items' => $t['terms'],
            ];
        }
        ?>

        <?php foreach ($panels as $p): ?>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-panel>
            <button type="button" class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left hover:bg-slate-50" data-acc-toggle>
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                    <?= icon($p['icon'], 'w-4 h-4 text-slate-400') ?>
                    <?= htmlspecialchars($p['label']) ?>
                    <span class="text-xs font-normal text-slate-400">(<?= count($p['items']) ?>)</span>
                </span>
                <span data-chev class="transition-transform"><?= icon('chevron-down', 'w-4 h-4 text-slate-400') ?></span>
            </button>
            <div class="hidden border-t border-slate-100" data-acc-body>
                <?php if (count($p['items']) > 8): ?>
                <div class="p-2 border-b border-slate-100">
                    <input type="search" placeholder="Filter&hellip;" data-filter
                           class="w-full px-2.5 py-1.5 text-xs border border-slate-200 rounded-lg outline-none focus:border-blue-400">
                </div>
                <?php endif; ?>
                <div class="max-h-56 overflow-y-auto p-2 space-y-0.5">
                    <?php foreach ($p['items'] as $it): ?>
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer" data-row>
                        <input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 shrink-0"
                               data-pick
                               data-type="<?= htmlspecialchars($p['type']) ?>"
                               data-object-id="<?= (int) $it['id'] ?>"
                               data-title="<?= htmlspecialchars($it['title'], ENT_QUOTES) ?>"
                               data-url="<?= htmlspecialchars($it['url'], ENT_QUOTES) ?>">
                        <span class="text-sm text-slate-700 truncate" data-label><?= htmlspecialchars($it['title']) ?></span>
                        <?php if (isset($it['count'])): ?>
                        <span class="ml-auto text-[10px] text-slate-400 shrink-0"><?= (int) $it['count'] ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="flex items-center justify-between gap-2 px-3 py-2 bg-slate-50 border-t border-slate-100">
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-700" data-select-all>Select all</button>
                    <button type="button" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 disabled:opacity-40 text-white rounded-lg text-xs font-medium" data-add disabled>
                        Add to menu
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Custom link -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-panel>
            <button type="button" class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left hover:bg-slate-50" data-acc-toggle>
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                    <?= icon('link', 'w-4 h-4 text-slate-400') ?> Custom link
                </span>
                <span data-chev class="transition-transform"><?= icon('chevron-down', 'w-4 h-4 text-slate-400') ?></span>
            </button>
            <div class="hidden border-t border-slate-100 p-3 space-y-2" data-acc-body>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Label</label>
                    <input type="text" id="mb-custom-title" placeholder="About us"
                           class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">URL</label>
                    <input type="text" id="mb-custom-url" placeholder="/about or https://example.com"
                           class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400">
                </div>
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" id="mb-custom-blank" class="rounded border-slate-300 text-blue-600"> Open in a new tab
                </label>
                <button type="button" id="mb-custom-add" class="w-full px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-medium">
                    Add to menu
                </button>
            </div>
        </div>

        <!-- Settings -->
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Menu settings</h3>
            <form method="POST" action="<?= $base ?>/admin/menus/<?= (int) $menu['id'] ?>" class="space-y-3 text-sm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($menu['name']) ?>"
                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Location</label>
                    <select name="location" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php foreach (['' => '— None —', 'primary' => 'Primary', 'footer' => 'Footer', 'sidebar' => 'Sidebar'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($menu['location'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium">Save settings</button>
            </form>
        </div>

        <!-- Danger zone -->
        <div class="bg-white rounded-xl border border-red-200 p-4">
            <h3 class="text-sm font-semibold text-red-700 mb-1">Delete menu</h3>
            <p class="text-xs text-slate-500 mb-3">Removes this menu and all of its items. This cannot be undone.</p>
            <form method="POST" action="<?= $base ?>/admin/menus/<?= (int) $menu['id'] ?>/delete"
                  onsubmit="return confirm('Delete the menu &quot;<?= htmlspecialchars(addslashes($menu['name']), ENT_QUOTES) ?>&quot;?\n\nAll of its items will be removed. This cannot be undone.')">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="w-full px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-sm font-medium flex items-center justify-center gap-1.5">
                    <?= icon('trash', 'w-4 h-4') ?> Delete this menu
                </button>
            </form>
        </div>
    </div>

    <!-- ─────────────── Structure ─────────────── -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-slate-100">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-slate-900">Menu structure</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Drag a row to move it. Drop near the top or bottom edge to reorder, or on the middle of a row to nest it underneath &mdash; up to three levels.</p>
                </div>
                <span class="text-xs text-slate-400 shrink-0" id="mb-count"></span>
            </div>

            <div id="mb-tree" class="p-3 min-h-[12rem]"></div>

            <div id="mb-empty" class="hidden px-5 py-16 text-center">
                <span class="inline-block text-slate-200 mb-3"><?= icon('bars-3', 'w-12 h-12') ?></span>
                <p class="text-sm font-medium text-slate-600">This menu is empty</p>
                <p class="text-xs text-slate-400 mt-1">Pick pages, categories or tags on the left, then choose <strong>Add to menu</strong>.</p>
            </div>
        </div>
    </div>
</div>

<style>
    .mb-item { border:1px solid #e2e8f0; border-radius:.625rem; background:#fff; margin-bottom:.4rem; }
    .mb-item__row { display:flex; align-items:center; gap:.5rem; padding:.6rem .75rem; position:relative; }
    .mb-grip { color:#cbd5e1; cursor:grab; flex-shrink:0; display:inline-flex; }
    .mb-grip:active { cursor:grabbing; }
    .mb-item:hover > .mb-item__row > .mb-grip { color:#64748b; }
    .mb-item.is-dragging { opacity:.35; }
    .mb-item.is-dragging .mb-item__row { cursor:grabbing; }

    /* The whole row is a grab target, not just the six-pixel handle. */
    .mb-item__row { cursor:grab; border-radius:.625rem; transition:background .12s ease; }
    .mb-item__row:hover { background:#f8fafc; }

    /* Reordering: a solid rule where the item will land. Drawn on the row so it
       sits at the boundary the pointer is actually near, rather than at the edge
       of a subtree that may be hundreds of pixels tall. */
    .mb-item__row.drop-before,
    .mb-item__row.drop-after { position:relative; }
    .mb-item__row.drop-before::after,
    .mb-item__row.drop-after::after {
        content:''; position:absolute; left:0; right:0; height:3px;
        background:#2563eb; border-radius:2px;
    }
    .mb-item__row.drop-before::after { top:-2px; }
    .mb-item__row.drop-after::after  { bottom:-2px; }
    /* A dot at the line's start, so the target reads as a caret rather than a
       stray border. */
    .mb-item__row.drop-before::before,
    .mb-item__row.drop-after::before {
        content:''; position:absolute; left:-3px; width:9px; height:9px;
        background:#2563eb; border-radius:50%; z-index:1;
    }
    .mb-item__row.drop-before::before { top:-5px; }
    .mb-item__row.drop-after::before  { bottom:-5px; }

    /* Nesting: the row itself is highlighted and indented, previewing where the
       item is about to sit. */
    .mb-item__row.drop-into {
        background:#ecfdf5;
        box-shadow: inset 0 0 0 2px #10b981;
        padding-left:1.75rem;
    }
    .mb-item__row.drop-into::before {
        content:'↳'; position:absolute; left:.6rem;
        color:#059669; font-size:.9rem; font-weight:700; line-height:1;
    }

    /* While a drag is in progress, show where nesting is possible at all. A
       cue that only appears once you have found the zone teaches nobody. */
    body.mb-dragging .mb-item__row {
        outline:1px dashed transparent; outline-offset:-2px;
    }
    body.mb-dragging .mb-item__row:not(.drop-into):not(.drop-before):not(.drop-after) {
        outline-color:#cbd5e1;
    }

    /* The row copy that follows the pointer. Fixed, so it is positioned in
       viewport coordinates and unaffected by the page scrolling underneath. */
    .mb-ghost {
        position:fixed; z-index:9999; pointer-events:none;
        background:#fff; border:1px solid #cbd5e1; border-radius:.625rem;
        box-shadow:0 12px 28px rgba(15,23,42,.22);
        opacity:.95; transform:rotate(-1deg);
    }

    /* touch-action:none tells the browser this element's gestures are ours, so
       a drag does not also scroll the page. It is set on the row rather than
       the tree, or the list itself could never be scrolled on a phone. */
    .mb-item__row { touch-action:none; -webkit-user-select:none; user-select:none; }

    /* A larger grip on touch: the pointer is a fingertip, not a cursor. */
    @media (pointer:coarse) {
        .mb-grip { padding:.35rem; margin:-.35rem; }
        .mb-item__row { padding-top:.75rem; padding-bottom:.75rem; }
    }
    .mb-kids { margin-left:1.75rem; padding-left:.75rem; border-left:2px dashed #e2e8f0; }
    .mb-edit { border-top:1px solid #f1f5f9; padding:.75rem; background:#f8fafc; }
</style>

<script>
(function () {
    var BASE = <?= json_encode($base) ?>;
    var CSRF = <?= json_encode($csrf) ?>;
    var MENU_ID = <?= (int) $menu['id'] ?>;
    var items = <?= json_encode(array_values($items), JSON_UNESCAPED_SLASHES) ?>;   // nested tree

    var tree = document.getElementById('mb-tree');
    var empty = document.getElementById('mb-empty');
    var saveBtn = document.getElementById('mb-save');
    var dirtyEl = document.getElementById('mb-dirty');
    var dirty = false;

    function esc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    function flash(kind, msg) {
        var b = document.getElementById('mb-flash');
        b.className = 'mb-4 rounded-lg px-3 py-2.5 text-sm border ' +
            (kind === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                           : 'bg-red-50 border-red-200 text-red-700');
        b.textContent = msg;
        b.classList.remove('hidden');
        if (kind === 'ok') setTimeout(function () { b.classList.add('hidden'); }, 2500);
    }
    function markDirty(on) { dirty = on; dirtyEl.classList.toggle('hidden', !on); }
    function post(path, body) {
        return fetch(BASE + path, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ _csrf: CSRF }, body || {}))
        }).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) { throw new Error('Unexpected server response (HTTP ' + r.status + ').'); }
            });
        });
    }

    /* ---------------- render ---------------- */
    var BADGE = { page:'Page', post:'Post', taxonomy:'Term', custom:'Link', archive:'Archive' };

    function itemHtml(it) {
        var kids = (it.children || []).map(itemHtml).join('');
        return '<div class="mb-item" data-id="' + esc(it.id) + '">'
            + '<div class="mb-item__row">'
            +   '<span class="mb-grip" title="Drag to move">' + BasehimIcon('bars-2','w-4 h-4') + '</span>'
            +   '<span class="text-sm font-medium text-slate-800 truncate">' + esc(it.title) + '</span>'
            +   '<span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 shrink-0">' + (BADGE[it.type] || 'Link') + '</span>'
            +   (it.target === '_blank' ? '<span class="text-[10px] text-slate-400 shrink-0" title="Opens in a new tab">&#8599;</span>' : '')
            +   '<span class="ml-auto flex items-center gap-1 shrink-0">'
            +     '<button type="button" class="p-1.5 rounded hover:bg-slate-100 text-slate-400 hover:text-slate-600" data-edit title="Edit">' + BasehimIcon('pencil','w-3.5 h-3.5') + '</button>'
            +     '<button type="button" class="p-1.5 rounded hover:bg-red-50 text-slate-400 hover:text-red-600" data-remove title="Remove">' + BasehimIcon('trash','w-3.5 h-3.5') + '</button>'
            +   '</span>'
            + '</div>'
            + '<div class="mb-edit hidden" data-editor>'
            +   '<div class="grid gap-2 sm:grid-cols-2">'
            +     '<div><label class="block text-[11px] text-slate-500 mb-1">Label</label>'
            +       '<input type="text" data-f="title" value="' + esc(it.title) + '" class="w-full px-2.5 py-1.5 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400"></div>'
            +     '<div><label class="block text-[11px] text-slate-500 mb-1">URL</label>'
            +       '<input type="text" data-f="url" value="' + esc(it.url || '') + '" class="w-full px-2.5 py-1.5 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400"></div>'
            +     '<div><label class="block text-[11px] text-slate-500 mb-1">CSS classes</label>'
            +       '<input type="text" data-f="classes" value="' + esc(it.classes || '') + '" class="w-full px-2.5 py-1.5 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400"></div>'
            +     '<div><label class="block text-[11px] text-slate-500 mb-1">Opens in</label>'
            +       '<select data-f="target" class="w-full px-2.5 py-1.5 text-sm border border-slate-200 rounded-lg outline-none focus:border-blue-400">'
            +         '<option value="_self"' + (it.target !== '_blank' ? ' selected' : '') + '>Same tab</option>'
            +         '<option value="_blank"' + (it.target === '_blank' ? ' selected' : '') + '>New tab</option>'
            +       '</select></div>'
            +   '</div>'
            +   '<div class="flex justify-end gap-2 mt-2">'
            +     '<button type="button" class="px-2.5 py-1.5 text-xs text-slate-500 hover:text-slate-700" data-cancel>Close</button>'
            +     '<button type="button" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium" data-apply>Apply</button>'
            +   '</div>'
            + '</div>'
            + (kids ? '<div class="mb-kids">' + kids + '</div>' : '')
            + '</div>';
    }

    function render() {
        tree.innerHTML = items.map(itemHtml).join('');
        var n = 0;
        walk(items, function () { n++; });
        document.getElementById('mb-count').textContent = n + (n === 1 ? ' item' : ' items');
        empty.classList.toggle('hidden', n > 0);
        tree.classList.toggle('hidden', n === 0);
        wire();
    }

    /* ---------------- tree helpers ---------------- */
    function walk(list, fn, parent) {
        (list || []).forEach(function (i) { fn(i, parent || null); walk(i.children, fn, i); });
    }
    function find(id) {
        var hit = null;
        walk(items, function (i) { if (String(i.id) === String(id)) hit = i; });
        return hit;
    }
    function detach(id) {
        var found = null;
        (function rec(list) {
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].id) === String(id)) { found = list.splice(i, 1)[0]; return true; }
                if (list[i].children && rec(list[i].children)) return true;
            }
            return false;
        })(items);
        return found;
    }
    function insertNear(node, targetId, mode) {
        var placed = (function rec(list) {
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].id) === String(targetId)) {
                    if (mode === 'into') {
                        list[i].children = list[i].children || [];
                        list[i].children.push(node);
                    } else {
                        list.splice(mode === 'before' ? i : i + 1, 0, node);
                    }
                    return true;
                }
                if (list[i].children && rec(list[i].children)) return true;
            }
            return false;
        })(items);
        if (!placed) items.push(node);
    }
    function isDescendant(node, id) {
        var hit = false;
        walk(node.children, function (c) { if (String(c.id) === String(id)) hit = true; });
        return hit;
    }
    /** Flatten to {id, parent_id} in display order — exactly what the server stores. */
    function flatten() {
        var out = [];
        walk(items, function (i, parent) { out.push({ id: i.id, parent_id: parent ? parent.id : null }); });
        return out;
    }

    /* ---------------- interactions ---------------- */
    function wire() {
        tree.querySelectorAll('.mb-item').forEach(function (el) {
            var id = el.getAttribute('data-id');
            var row = el.querySelector(':scope > .mb-item__row');
            var editor = el.querySelector(':scope > [data-editor]');
            if (!row || !editor) return;

            row.querySelector('[data-edit]').addEventListener('click', function () {
                editor.classList.toggle('hidden');
            });
            editor.querySelector('[data-cancel]').addEventListener('click', function () {
                editor.classList.add('hidden');
            });
            editor.querySelector('[data-apply]').addEventListener('click', function () {
                var vals = {};
                editor.querySelectorAll('[data-f]').forEach(function (f) { vals[f.getAttribute('data-f')] = f.value; });
                if (!vals.title || !vals.url) { flash('err', 'A menu item needs both a label and a URL.'); return; }
                post('/admin/menus/' + MENU_ID + '/items/' + id, vals).then(function (d) {
                    if (!d.ok) { flash('err', d.error || d.detail || 'Could not save that item.'); return; }
                    var node = find(id);
                    if (node) {
                        node.title = vals.title; node.url = vals.url;
                        node.target = vals.target; node.classes = vals.classes;
                    }
                    render();
                    flash('ok', 'Item updated.');
                }).catch(function (e) { flash('err', e.message); });
            });

            row.querySelector('[data-remove]').addEventListener('click', function () {
                if (!confirm('Remove this item from the menu?\n\nIts sub-items will be removed too.')) return;
                post('/admin/menus/' + MENU_ID + '/items/' + id + '/delete', {}).then(function (d) {
                    if (!d.ok) { flash('err', d.error || d.detail || 'Could not remove that item.'); return; }
                    items = d.items || [];
                    render();
                    flash('ok', 'Item removed.');
                }).catch(function (e) { flash('err', e.message); });
            });

            /* Dragging is handled once, at tree level, by installDrag() below.
             * Nothing is bound per item here — the previous version attached
             * five listeners to every row on every render, and re-rendered on
             * every change. */
            row.setAttribute('data-drag-row', '1');
        });
    }

    /* ---------------- dragging ----------------
     *
     * Pointer events rather than HTML5 drag-and-drop.
     *
     * The native API does not fire on touch at all, so the builder simply could
     * not reorder a menu on a tablet or a phone — the drag never started. There
     * is no polyfill worth having; the fix is to stop using it. Pointer events
     * give one code path for a mouse, a stylus and a finger.
     *
     * Bound once to the tree rather than per row, so a re-render does not have
     * to rewire anything.
     */
    var drag = null;

    function installDrag() {
        tree.addEventListener('pointerdown', onDown);
        // passive:false because a touch-drag must be able to preventDefault to
        // stop the page scrolling underneath it.
        tree.addEventListener('pointermove', onMove, { passive: false });
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);
    }

    function rowAt(node) {
        while (node && node !== tree) {
            if (node.classList && node.classList.contains('mb-item__row')) return node;
            node = node.parentElement;
        }
        return null;
    }

    function onDown(ev) {
        if (ev.button != null && ev.button !== 0) return;          // left button only
        // Buttons and inputs inside the row must keep working.
        if (ev.target.closest('button, input, select, textarea, a')) return;

        var row = rowAt(ev.target);
        if (!row) return;
        var el = row.parentElement;
        var id = el.getAttribute('data-id');
        if (!id) return;

        drag = {
            id: id, el: el, row: row,
            startX: ev.clientX, startY: ev.clientY,
            pointerId: ev.pointerId,
            active: false,
            fromGrip: !!ev.target.closest('.mb-grip'),
            timer: null
        };

        /* On touch, a drag must not start immediately: the same gesture is how
         * you scroll the list. Holding still for a moment says "I mean to move
         * this", which is the convention every mobile list uses. Dragging from
         * the grip is unambiguous, so that starts at once. */
        if (ev.pointerType === 'touch' && !drag.fromGrip) {
            drag.timer = setTimeout(function () {
                if (drag) begin(ev);
            }, 220);
        }
    }

    function begin(ev) {
        if (!drag || drag.active) return;
        drag.active = true;
        drag.el.classList.add('is-dragging');
        document.body.classList.add('mb-dragging');
        try { drag.row.setPointerCapture(drag.pointerId); } catch (e) {}

        // A floating copy of the row follows the pointer, so there is something
        // to aim with. The original stays in place, dimmed.
        var r = drag.row.getBoundingClientRect();
        var ghost = drag.row.cloneNode(true);
        ghost.className = 'mb-item__row mb-ghost';
        ghost.style.width = r.width + 'px';
        ghost.style.left = r.left + 'px';
        ghost.style.top = r.top + 'px';
        drag.offsetX = drag.startX - r.left;
        drag.offsetY = drag.startY - r.top;
        document.body.appendChild(ghost);
        drag.ghost = ghost;
    }

    function onMove(ev) {
        if (!drag) return;

        if (!drag.active) {
            var moved = Math.abs(ev.clientX - drag.startX) + Math.abs(ev.clientY - drag.startY);
            if (ev.pointerType === 'touch' && !drag.fromGrip) {
                // Moving before the hold elapsed means a scroll, not a drag.
                if (moved > 10) { clearTimeout(drag.timer); drag = null; }
                return;
            }
            if (moved < 5) return;      // a click, not a drag
            begin(ev);
        }

        ev.preventDefault();            // stop the page scrolling under a touch drag

        drag.ghost.style.left = (ev.clientX - drag.offsetX) + 'px';
        drag.ghost.style.top  = (ev.clientY - drag.offsetY) + 'px';

        autoScroll(ev.clientY);

        /* The ghost sits under the pointer, so elementFromPoint would return it
         * rather than the row beneath. Hide it for the measurement. */
        drag.ghost.style.display = 'none';
        var under = document.elementFromPoint(ev.clientX, ev.clientY);
        drag.ghost.style.display = '';

        var row = rowAt(under);
        clearMarks();
        drag.target = null;

        if (!row) return;
        var el = row.parentElement;
        var id = el.getAttribute('data-id');
        if (!id || id === drag.id) return;

        var node = find(drag.id);
        if (node && isDescendant(node, id)) return;   // never into its own subtree

        var mode = dropMode(ev, row.getBoundingClientRect());
        if (mode === 'into' && !canNest(drag.id, id)) mode = 'after';

        drag.target = { id: id, mode: mode };
        el.classList.add('drop-' + mode);
        row.classList.add('drop-' + mode);
    }

    /* Dragging to the edge of the viewport scrolls the page. Without this a long
     * menu cannot be reordered on a phone at all — there is nowhere to drop to. */
    function autoScroll(y) {
        var margin = 70;
        if (y < margin) window.scrollBy(0, -Math.ceil((margin - y) / 5));
        else if (y > window.innerHeight - margin) window.scrollBy(0, Math.ceil((y - (window.innerHeight - margin)) / 5));
    }

    function onUp() {
        if (!drag) return;
        clearTimeout(drag.timer);

        if (drag.active) {
            if (drag.ghost) drag.ghost.remove();
            try { drag.row.releasePointerCapture(drag.pointerId); } catch (e) {}
            drag.el.classList.remove('is-dragging');
            document.body.classList.remove('mb-dragging');
            if (drag.target) move(drag.id, drag.target.id, drag.target.mode);
            clearMarks();
        }
        drag = null;
    }
    /**
     * Where does this pointer position mean the item should go?
     *
     * The vertical edges reorder, the middle band nests. Reading vertically
     * first matches what the eye expects: near a boundary you are moving
     * between items, in the middle of a row you are targeting that row.
     *
     * The old rule was horizontal — past 60% of the width meant "nest" — which
     * is invisible, has no equivalent on a narrow screen, and gave no way to
     * reorder while the pointer happened to be on the right-hand side.
     */
    function dropMode(ev, r) {
        var y = ev.clientY - r.top;
        var edge = Math.max(6, Math.min(14, r.height * 0.3));
        if (y < edge) return 'before';
        if (y > r.height - edge) return 'after';
        return 'into';
    }

    /** Would nesting this item under that one exceed the three-level limit? */
    function canNest(fromId, toId) {
        var node = find(fromId);
        if (!node) return false;
        if (isDescendant(node, toId) || String(fromId) === String(toId)) return false;
        return depthOf(toId) + subtreeHeight(node) <= MAX_DEPTH;
    }
    function clearMarks() {
        tree.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(function (e) {
            e.classList.remove('drop-before', 'drop-after', 'drop-into');
        });
    }
    /* Three levels is what the front end renders as a dropdown. Anything deeper
       would be lifted back up when the page is drawn, so the item would sit in
       one place in this builder and appear somewhere else on the site. Better to
       refuse the drop and say why. */
    var MAX_DEPTH = 3;

    /** How many levels does this node's own subtree add below it? */
    function subtreeHeight(node) {
        var kids = node.children || [];
        if (!kids.length) return 1;
        var deepest = 1;
        kids.forEach(function (k) { deepest = Math.max(deepest, 1 + subtreeHeight(k)); });
        return deepest;
    }

    /** Depth of an item in the current tree, 1 for a top-level item. */
    function depthOf(id, nodes, level) {
        nodes = nodes || items; level = level || 1;
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].id === id) return level;
            var found = depthOf(id, nodes[i].children || [], level + 1);
            if (found) return found;
        }
        return 0;
    }

    function move(fromId, toId, mode) {
        var node = find(fromId);
        if (!node) return;
        // Dropping a parent into its own subtree would detach the branch entirely.
        if (isDescendant(node, toId)) { flash('err', "You can't move an item inside one of its own sub-items."); return; }

        // Nesting deeper than the front end can show.
        if (mode === 'into') {
            var targetDepth = depthOf(toId);
            var resulting = targetDepth + subtreeHeight(node);
            if (resulting > MAX_DEPTH) {
                flash('err', 'Menus go three levels deep. Moving this here would make it '
                    + resulting + ' levels, and the extra levels would not show on the site.');
                return;
            }
        }

        node = detach(fromId);
        if (!node) return;
        insertNear(node, toId, mode);
        render();
        markDirty(true);
    }

    /* ---------------- add from pickers ---------------- */
    function addItems(payload) {
        if (!payload.length) return;
        post('/admin/menus/' + MENU_ID + '/items/bulk', { items: payload }).then(function (d) {
            if (!d.ok) { flash('err', d.error || d.detail || 'Could not add those items.'); return; }
            items = d.items || [];
            render();
            flash('ok', d.added + (d.added === 1 ? ' item added.' : ' items added.'));
        }).catch(function (e) { flash('err', e.message); });
    }

    document.querySelectorAll('[data-panel]').forEach(function (panel) {
        var toggle = panel.querySelector('[data-acc-toggle]');
        var body = panel.querySelector('[data-acc-body]');
        var chev = panel.querySelector('[data-chev]');
        toggle.addEventListener('click', function () {
            var open = !body.classList.contains('hidden');
            body.classList.toggle('hidden', open);
            if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
        });

        var addBtn = panel.querySelector('[data-add]');
        function sync() {
            if (addBtn) addBtn.disabled = !panel.querySelectorAll('[data-pick]:checked').length;
        }
        panel.querySelectorAll('[data-pick]').forEach(function (c) { c.addEventListener('change', sync); });

        var all = panel.querySelector('[data-select-all]');
        if (all) all.addEventListener('click', function () {
            var rows = Array.prototype.filter.call(panel.querySelectorAll('[data-row]'), function (r) {
                return r.style.display !== 'none';
            });
            var every = rows.length > 0 && rows.every(function (r) { return r.querySelector('[data-pick]').checked; });
            rows.forEach(function (r) { r.querySelector('[data-pick]').checked = !every; });
            sync();
        });

        var filter = panel.querySelector('[data-filter]');
        if (filter) filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            panel.querySelectorAll('[data-row]').forEach(function (r) {
                var t = r.querySelector('[data-label]').textContent.toLowerCase();
                r.style.display = (!q || t.indexOf(q) >= 0) ? '' : 'none';
            });
        });

        if (addBtn) addBtn.addEventListener('click', function () {
            var payload = [];
            panel.querySelectorAll('[data-pick]:checked').forEach(function (c) {
                payload.push({
                    type: c.getAttribute('data-type'),
                    object_id: c.getAttribute('data-object-id'),
                    title: c.getAttribute('data-title'),
                    url: c.getAttribute('data-url')
                });
                c.checked = false;
            });
            sync();
            addItems(payload);
        });
    });

    var customAdd = document.getElementById('mb-custom-add');
    if (customAdd) customAdd.addEventListener('click', function () {
        var t = document.getElementById('mb-custom-title').value.trim();
        var u = document.getElementById('mb-custom-url').value.trim();
        if (!t || !u) { flash('err', 'A custom link needs both a label and a URL.'); return; }
        addItems([{ type: 'custom', title: t, url: u,
                    target: document.getElementById('mb-custom-blank').checked ? '_blank' : '_self' }]);
        document.getElementById('mb-custom-title').value = '';
        document.getElementById('mb-custom-url').value = '';
        document.getElementById('mb-custom-blank').checked = false;
    });

    /* ---------------- save ---------------- */
    saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true;
        document.getElementById('mb-save-txt').textContent = 'Saving…';
        post('/admin/menus/' + MENU_ID + '/items/reorder', { tree: flatten() }).then(function (d) {
            if (!d.ok) { flash('err', d.error || d.detail || 'Could not save the menu.'); return; }
            markDirty(false);
            flash('ok', 'Menu saved.');
        }).catch(function (e) {
            flash('err', e.message);
        }).finally(function () {
            saveBtn.disabled = false;
            document.getElementById('mb-save-txt').textContent = 'Save menu';
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault(); e.returnValue = '';
    });

    // Open the first source so the page doesn't look inert.
    var firstPanel = document.querySelector('[data-panel]');
    if (firstPanel) {
        firstPanel.querySelector('[data-acc-body]').classList.remove('hidden');
        var c = firstPanel.querySelector('[data-chev]');
        if (c) c.style.transform = 'rotate(180deg)';
    }
    render();
    installDrag();
})();
</script>

<?php $this->endSection(); ?>
