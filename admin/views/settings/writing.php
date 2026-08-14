<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <div class="mt-0">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-5">Writing Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/writing" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default Content Format</label>
                    <select name="default_format" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php $df = $values['default_format'] ?? 'html'; ?>
                        <option value="html" <?= $df === 'html' ? 'selected' : '' ?>>HTML</option>
                        <option value="markdown" <?= $df === 'markdown' ? 'selected' : '' ?>>Markdown</option>
                        <option value="blocks" <?= $df === 'blocks' ? 'selected' : '' ?>>Blocks</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default Post Status</label>
                    <select name="default_status" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php $ds = $values['default_status'] ?? 'draft'; ?>
                        <option value="draft" <?= $ds === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="published" <?= $ds === 'published' ? 'selected' : '' ?>>Published</option>
                        <option value="pending" <?= $ds === 'pending' ? 'selected' : '' ?>>Pending Review</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Auto-save Interval (seconds)</label>
                    <input type="number" name="autosave_interval" min="0" value="<?= htmlspecialchars($values['autosave_interval'] ?? 60) ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <p class="text-xs text-slate-500 mt-1">Set to 0 to disable.</p>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
