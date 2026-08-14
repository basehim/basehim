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
            <h3 class="font-semibold text-slate-900 mb-1">Permalinks</h3>
            <p class="text-sm text-slate-500 mb-5">Control the URL structure for your posts.</p>

            <form method="POST" action="<?= $base ?>/admin/settings/permalinks" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <?php $struct = $values['structure'] ?? 'pretty'; ?>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="pretty" <?= $struct === 'pretty' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Default</div>
                            <div class="text-xs text-slate-500 mt-0.5">Posts use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/posts/sample-post</code>, pages use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-page</code>.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="category" <?= $struct === 'category' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Category / Post name</div>
                            <div class="text-xs text-slate-500 mt-0.5">Posts use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/electronics/sample-post</code> (primary category slug + post slug). Pages use <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-page</code>.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/30">
                        <input type="radio" name="structure" value="flat" <?= $struct === 'flat' ? 'checked' : '' ?>
                            class="mt-1 text-blue-600 focus:ring-blue-500">
                        <div class="flex-1">
                            <div class="font-medium text-sm text-slate-900">Flat (post name only)</div>
                            <div class="text-xs text-slate-500 mt-0.5">Both posts and pages live at <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sample-name</code>. Posts no longer use the <code>/posts/</code> prefix.</div>
                        </div>
                    </label>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
                    <?= icon('information-circle', 'w-4 h-4 mr-1') ?>
                    Changing your permalink structure may break existing links from search engines and external sites pointing to your content.
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
