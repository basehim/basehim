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
                <label class="block text-xs text-slate-500 mb-1">Name *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Slug</label>
                <input type="text" name="slug" placeholder="auto-generated" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <?php if ($taxonomy['hierarchical']): ?>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Parent</label>
                <select name="parent_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <option value="">— None —</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Description</label>
                <textarea name="description" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"></textarea>
            </div>
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm">
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
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-slate-600">Name</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-600 hidden md:table-cell">Slug</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-600">Posts</th>
                    <th class="text-right px-5 py-3 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($terms as $t): ?>
                <tr class="hover:bg-slate-50" id="term-row-<?= $t['id'] ?>">
                    <td class="px-5 py-3 font-medium text-slate-900">
                        <?= htmlspecialchars($t['name']) ?>
                        <?php if (!empty($t['description'])): ?>
                            <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars(mb_substr($t['description'], 0, 80)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-slate-500 text-xs font-mono hidden md:table-cell"><?= htmlspecialchars($t['slug']) ?></td>
                    <td class="px-5 py-3 text-slate-600"><?= (int)$t['count'] ?></td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button"
                                    class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded"
                                    title="Edit"
                                    onclick="toggleTermEdit(<?= $t['id'] ?>)">
                                <?= icon('pencil', 'w-4 h-4') ?>
                            </button>
                            <form method="POST" action="<?= $base ?>/admin/terms/<?= $t['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete this term?')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <button class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Delete">
                                    <?= icon('trash', 'w-4 h-4') ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <!-- Inline edit row -->
                <tr id="term-edit-<?= $t['id'] ?>" class="hidden bg-slate-50">
                    <td colspan="4" class="px-5 py-4">
                        <form method="POST" action="<?= $base ?>/admin/terms/<?= $t['id'] ?>" class="space-y-3 text-sm">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Name *</label>
                                    <input type="text" name="name" required value="<?= htmlspecialchars($t['name']) ?>"
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Slug</label>
                                    <input type="text" name="slug" value="<?= htmlspecialchars($t['slug']) ?>"
                                           class="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                                </div>
                            </div>
                            <?php if ($taxonomy['hierarchical']): ?>
                            <div>
                                <label class="block text-xs text-slate-500 mb-1">Parent</label>
                                <select name="parent_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
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
                                <label class="block text-xs text-slate-500 mb-1">Description</label>
                                <textarea name="description" rows="2"
                                          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars($t['description'] ?? '') ?></textarea>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm flex items-center gap-1">
                                    <?= icon('check', 'w-4 h-4') ?> Save changes
                                </button>
                                <button type="button" onclick="toggleTermEdit(<?= $t['id'] ?>)"
                                        class="px-4 py-2 bg-white border border-slate-300 hover:bg-slate-100 text-slate-700 rounded-lg text-sm">
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
        if (opening) {
            editRow.classList.remove('hidden');
            var first = editRow.querySelector('input[name="name"]');
            if (first) { first.focus(); first.select(); }
        }
    }
</script>

<?php $this->endSection(); ?>
