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
            <h3 class="font-semibold text-slate-900 mb-5">SEO Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/seo" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default Meta Title</label>
                    <input type="text" name="default_meta_title" value="<?= htmlspecialchars($values['default_meta_title'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <p class="text-xs text-slate-500 mt-1">Used when a post doesn't define its own. Use %title% and %site% as placeholders.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default Meta Description</label>
                    <textarea name="default_meta_description" rows="3"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars($values['default_meta_description'] ?? '') ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Default OG Image URL</label>
                    <input type="url" name="default_og_image" value="<?= htmlspecialchars($values['default_og_image'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Twitter Handle</label>
                    <input type="text" name="twitter_handle" value="<?= htmlspecialchars($values['twitter_handle'] ?? '') ?>" placeholder="@yourhandle"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

                <label class="flex items-center gap-2">
                    <input type="checkbox" name="generate_sitemap" value="1" <?= !empty($values['generate_sitemap']) ? 'checked' : '' ?>
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-700">Generate XML sitemap at <code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">/sitemap.xml</code></span>
                </label>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
