<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="<?= $base ?>/admin/users" class="text-slate-500 hover:text-blue-600"><?= icon('arrow-left', 'w-4 h-4') ?></a>
        <h2 class="text-xl font-semibold text-slate-900">Roles &amp; Capabilities</h2>
    </div>
    <button id="new-role-btn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
        <?= icon('plus', 'w-4 h-4 mr-1') ?> New Custom Role
    </button>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <?php foreach ($roles as $slug => $def): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($def['label']) ?></h3>
                    <?php if ($def['custom']): ?>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">Custom</span>
                    <?php else: ?>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Built-in</span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-slate-400 mt-0.5 font-mono"><?= htmlspecialchars($slug) ?> · level <?= (int)$def['level'] ?></p>
            </div>
            <?php if ($def['custom']): ?>
            <form method="POST" action="<?= $base ?>/admin/roles/<?= urlencode($slug) ?>/delete" onsubmit="return confirm('Delete this custom role?');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button class="text-slate-300 hover:text-red-600 text-sm" title="Delete role"><?= icon('trash', 'w-4 h-4') ?></button>
            </form>
            <?php endif; ?>
        </div>
        <div class="mt-3 flex flex-wrap gap-1">
            <?php if (in_array('*', $def['capabilities'], true)): ?>
                <span class="text-[11px] px-2 py-0.5 rounded bg-blue-100 text-blue-700 font-medium">All capabilities</span>
            <?php elseif (empty($def['capabilities'])): ?>
                <span class="text-[11px] text-slate-400">No capabilities</span>
            <?php else: foreach ($def['capabilities'] as $c): ?>
                <span class="text-[11px] px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-mono"><?= htmlspecialchars($c) ?></span>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- New role modal -->
<div id="role-modal" class="bh-modal">
    <div class="bh-modal-box !max-w-2xl flex flex-col max-h-[85vh]">
        <div class="flex items-center justify-between p-5 border-b border-slate-100">
            <h3 class="font-semibold text-slate-900">New Custom Role</h3>
            <button id="role-modal-close" class="text-slate-400 hover:text-slate-600"><?= icon('x-mark', 'w-4 h-4') ?></button>
        </div>
        <form method="POST" action="<?= $base ?>/admin/roles" class="flex flex-col overflow-hidden">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="p-5 space-y-4 overflow-y-auto">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Role name *</label>
                        <input type="text" name="label" required placeholder="e.g. Shop Manager"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Level (1-79)</label>
                        <input type="number" name="level" value="25" min="1" max="79"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:border-blue-500">
                    </div>
                </div>
                <p class="text-xs text-slate-500">Pick the capabilities this role grants. You can only assign capabilities you hold yourself.</p>
                <?php foreach ($catalog as $group => $caps): ?>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400 mb-1.5"><?= htmlspecialchars($group) ?></p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                            <?php foreach ($caps as $c): $can = in_array('*', $grantableCaps, true) || in_array($c, $grantableCaps, true); ?>
                            <label class="flex items-center gap-2 text-sm <?= $can ? 'text-slate-700' : 'text-slate-300' ?>">
                                <input type="checkbox" name="capabilities[]" value="<?= htmlspecialchars($c) ?>" <?= $can ? '' : 'disabled' ?>
                                    class="rounded border-slate-300">
                                <span class="font-mono text-[12px]"><?= htmlspecialchars($c) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($appItems)): ?>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400 mb-1.5">App Access</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                            <?php foreach ($appItems as $p): ?>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="capabilities[]" value="<?= htmlspecialchars($p['cap']) ?>" class="rounded border-slate-300">
                                <span><?= icon('puzzle-piece', 'w-4 h-4 text-slate-400 mr-1') ?><?= htmlspecialchars($p['name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" id="role-cancel" class="px-4 py-2 text-sm border border-slate-300 rounded-lg text-slate-600 hover:bg-white">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">Create Role</button>
            </div>
        </form>
    </div>
</div>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
(function () {
    var modal = document.getElementById('role-modal');
    function open() { modal.classList.add('is-open'); }
    function close() { modal.classList.remove('is-open'); }
    document.getElementById('new-role-btn').addEventListener('click', open);
    document.getElementById('role-modal-close').addEventListener('click', close);
    document.getElementById('role-cancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php $this->endSection(); ?>
