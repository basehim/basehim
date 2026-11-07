<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($taxonomy['label']) ?></h2>
    <p class="text-sm text-slate-500">Manage <?= strtolower($taxonomy['label']) ?> for your content.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <!-- New term form -->
    <div class="bg-white rounded-xl border border-slate-200 p-5 self-start">
        <h3 class="text-sm font-semibold text-slate-900 mb-3 flex items-center"><?= icon('plus', 'w-4 h-4 text-blue-500 mr-2') ?>New <?= $taxonomy['singular'] ?></h3>
        <form method="POST" action="<?= $base ?>/admin/taxonomies/<?= $taxonomy['slug'] ?>/terms" class="space-y-3 text-sm">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div>
                <label class="bh-label">Name *</label>
                <input type="text" name="name" required class="bh-input">
            </div>
            <div>
                <label class="bh-label">Slug</label>
                <input type="text" name="slug" placeholder="auto-generated" class="bh-input">
            </div>
            <?php if ($taxonomy['hierarchical']): ?>
            <div>
                <label class="bh-label">Parent</label>
                <select name="parent_id" class="bh-select">
                    <option value="">— None —</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="bh-label">Description</label>
                <textarea name="description" rows="3" class="bh-textarea"></textarea>
            </div>
            <button type="submit" class="bh-btn bh-btn--primary w-full">
                Add <?= $taxonomy['singular'] ?>
            </button>
        </form>
    </div>

    <!-- Terms list -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <?php if (empty($terms)): ?>
            <div class="flex flex-col items-center text-center py-16 text-slate-500">
                <?= icon('tag', 'w-12 h-12 text-slate-300 mb-3') ?>
                <p>No <?= strtolower($taxonomy['label']) ?> yet.</p>
            </div>
        <?php else: ?>
        <table class="bh-table bh-table--stack">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="hidden md:table-cell">Slug</th>
                    <th>Posts</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($terms as $t): ?>
                <tr id="term-row-<?= $t['id'] ?>">
                    <td class="bh-table__primary font-medium text-slate-900">
                        <?= htmlspecialchars($t['name']) ?>
                        <?php if (!empty($t['description'])): ?>
                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars(mb_substr($t['description'], 0, 80)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-slate-500 text-xs font-mono hidden md:table-cell" data-label="Slug"><?= htmlspecialchars($t['slug']) ?></td>
                    <td data-label="Posts"><?= (int)$t['count'] ?></td>
                    <td class="bh-table__actions">
                            <button type="button" class="bh-btn bh-btn--secondary bh-btn--sm"
                                    aria-expanded="false" aria-controls="term-edit-<?= $t['id'] ?>"
                                    onclick="toggleTermEdit(<?= $t['id'] ?>)">
                                <?= icon('pencil', 'w-4 h-4') ?> Edit
                            </button>
                            <form method="POST" action="<?= $base ?>/admin/terms/<?= $t['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this term?')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <button class="bh-btn bh-btn--ghost bh-btn--sm bh-btn--icon" title="Delete" aria-label="Delete <?= htmlspecialchars($t['name'], ENT_QUOTES) ?>">
                                    <?= icon('trash', 'w-4 h-4') ?>
                                </button>
                            </form>
                    </td>
                </tr>
                <!-- Inline edit row -->
                <tr id="term-edit-<?= $t['id'] ?>" class="hidden bg-slate-50">
                    <td colspan="4">
                        <form method="POST" action="<?= $base ?>/admin/terms/<?= $t['id'] ?>" class="space-y-3 text-sm">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="bh-label">Name *</label>
                                    <input type="text" name="name" required value="<?= htmlspecialchars($t['name']) ?>"
                                           class="bh-input">
                                </div>
                                <div>
                                    <label class="bh-label">Slug</label>
                                    <input type="text" name="slug" value="<?= htmlspecialchars($t['slug']) ?>"
                                           class="bh-input font-mono">
                                </div>
                            </div>
                            <?php if ($taxonomy['hierarchical']): ?>
                            <div>
                                <label class="bh-label">Parent</label>
                                <select name="parent_id" class="bh-select">
                                    <option value="">— None —</option>
                                    <?php foreach ($terms as $opt): ?>
                                        <?php if ((int)$opt['id'] === (int)$t['id']) continue; // a term can't be its own parent ?>
                                        <option value="<?= $opt['id'] ?>" <?= (int)($t['parent_id'] ?? 0) === (int)$opt['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="bh-label">Description</label>
                                <textarea name="description" rows="2" class="bh-textarea"><?= htmlspecialchars($t['description'] ?? '') ?></textarea>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="bh-btn bh-btn--primary">
                                    <?= icon('check', 'w-4 h-4') ?> Save changes
                                </button>
                                <button type="button" onclick="toggleTermEdit(<?= $t['id'] ?>)"
                                        class="bh-btn bh-btn--secondary">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleTermEdit(id) {
        var editRow = document.getElementById('term-edit-' + id);
        if (!editRow) return;
        var opening = editRow.classList.contains('hidden');
        // Only one editor open at a time keeps the table tidy.
        document.querySelectorAll('[id^="term-edit-"]').forEach(function (r) { r.classList.add('hidden'); });
        document.querySelectorAll('[aria-controls^="term-edit-"]').forEach(function (b) {
            b.setAttribute('aria-expanded', opening && b.getAttribute('aria-controls') === 'term-edit-' + id ? 'true' : 'false');
        });
        if (opening) {
            editRow.classList.remove('hidden');
            var first = editRow.querySelector('input[name="name"]');
            if (first) { first.focus(); first.select(); }
        }
    }

    // Deep link: /admin/taxonomies/{tax}?edit={id} (e.g. from the posts table's
    // Tags column) opens that term's editor and scrolls it into view.
    (function () {
        var m = /[?&]edit=(\d+)/.exec(window.location.search);
        if (!m) return;
        var id = m[1], row = document.getElementById('term-row-' + id);
        toggleTermEdit(id);
        if (row && row.scrollIntoView) row.scrollIntoView({ block: 'center' });
    })();
</script>

<?php $this->endSection(); ?>
