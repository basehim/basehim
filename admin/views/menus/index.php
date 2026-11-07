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
                <label class="bh-label">Name *</label>
                <input type="text" name="name" required class="bh-input">
            </div>
            <div>
                <label class="bh-label">Slug</label>
                <input type="text" name="slug" placeholder="auto-generated" class="bh-input">
            </div>
            <div>
                <label class="bh-label">Location</label>
                <select name="location" class="bh-select">
                    <option value="">— None —</option>
                    <option value="primary">Primary</option>
                    <option value="footer">Footer</option>
                    <option value="sidebar">Sidebar</option>
                </select>
            </div>
            <button type="submit" class="bh-btn bh-btn--primary w-full">Create Menu</button>
        </form>
    </div>

    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <?php if (empty($menus)): ?>
            <div class="text-center py-16 text-slate-500">
                <?= icon('bars-3', 'w-12 h-12 text-slate-300 mb-3') ?>
                <p>No menus yet.</p>
            </div>
        <?php else: ?>
        <table class="bh-table bh-table--stack">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Location</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($menus as $m): ?>
                <tr>
                    <td class="bh-table__primary">
                        <a href="<?= $base ?>/admin/menus/<?= $m['id'] ?>/edit" class="font-medium text-slate-900 hover:text-blue-600"><?= htmlspecialchars($m['name']) ?></a>
                        <div class="text-xs text-slate-500 font-mono"><?= htmlspecialchars($m['slug']) ?></div>
                    </td>
                    <td data-label="Location">
                        <?php if ($m['location']): ?>
                            <span class="bh-badge bh-badge--blue"><?= htmlspecialchars($m['location']) ?></span>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="bh-table__actions">
                            <a href="<?= $base ?>/admin/menus/<?= $m['id'] ?>/edit" class="bh-btn bh-btn--secondary bh-btn--sm">
                                <?= icon('pencil', 'w-4 h-4') ?> Edit
                            </a>
                            <form method="POST" action="<?= $base ?>/admin/menus/<?= $m['id'] ?>/delete" class="inline"
                                  onsubmit="return confirm('Delete the menu &quot;<?= htmlspecialchars(addslashes($m['name']), ENT_QUOTES) ?>&quot;?\n\nAll of its items will be removed. This cannot be undone.')">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <button type="submit" class="bh-btn bh-btn--ghost bh-btn--sm bh-btn--icon" title="Delete" aria-label="Delete <?= htmlspecialchars($m['name'], ENT_QUOTES) ?>">
                                    <?= icon('trash', 'w-4 h-4') ?>
                                </button>
                            </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php $this->endSection(); ?>
