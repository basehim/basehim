<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="flex items-center justify-between mb-5">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Menus</h2>
        <p class="text-sm text-slate-500">Build navigation menus for your site.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-900 mb-3"><?= icon('plus', 'w-4 h-4 text-blue-500 mr-2') ?>New Menu</h3>
        <form method="POST" action="<?= $base ?>/admin/menus" class="space-y-3 text-sm">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Name *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Slug</label>
                <input type="text" name="slug" placeholder="auto-generated" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Location</label>
                <select name="location" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <option value="">— None —</option>
                    <option value="primary">Primary</option>
                    <option value="footer">Footer</option>
                    <option value="sidebar">Sidebar</option>
                </select>
            </div>
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm">Create Menu</button>
        </form>
    </div>

    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <?php if (empty($menus)): ?>
            <div class="text-center py-16 text-slate-500">
                <?= icon('bars-3', 'w-12 h-12 text-slate-300 mb-3') ?>
                <p>No menus yet.</p>
            </div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 font-medium text-slate-600">Name</th>
                    <th class="text-left px-5 py-3 font-medium text-slate-600">Location</th>
                    <th class="text-right px-5 py-3 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($menus as $m): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <a href="<?= $base ?>/admin/menus/<?= $m['id'] ?>/edit" class="font-medium text-slate-900 hover:text-blue-600"><?= htmlspecialchars($m['name']) ?></a>
                        <div class="text-xs text-slate-500 font-mono"><?= htmlspecialchars($m['slug']) ?></div>
                    </td>
                    <td class="px-5 py-3 text-slate-600">
                        <?php if ($m['location']): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700"><?= htmlspecialchars($m['location']) ?></span>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="<?= $base ?>/admin/menus/<?= $m['id'] ?>/edit" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded" title="Edit">
                                <?= icon('pencil', 'w-4 h-4') ?>
                            </a>
                            <form method="POST" action="<?= $base ?>/admin/menus/<?= $m['id'] ?>/delete" class="inline"
                                  onsubmit="return confirm('Delete the menu &quot;<?= htmlspecialchars(addslashes($m['name']), ENT_QUOTES) ?>&quot;?\n\nAll of its items will be removed. This cannot be undone.')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded" title="Delete">
                                    <?= icon('trash', 'w-4 h-4') ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php $this->endSection(); ?>
