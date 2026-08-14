<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5 flex items-start justify-between flex-wrap gap-3">
    <div>
        <h2 class="text-xl font-semibold text-slate-900">Themes</h2>
        <p class="text-sm text-slate-500">Choose how your site looks.</p>
    </div>
    <a href="<?= $base ?>/admin/themes/marketplace" class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white rounded-lg text-sm font-medium shadow-sm">
        <?= icon('building-storefront', 'w-4 h-4') ?> Browse Marketplace
    </a>
</div>

<div class="bg-white rounded-xl border border-slate-200 p-5 mb-5">
    <h3 class="text-sm font-semibold text-slate-900 mb-1">Install a theme</h3>
    <p class="text-xs text-slate-500 mb-3">Upload a theme zip — it needs a <code class="px-1 py-0.5 bg-slate-100 rounded">theme.json</code> and a <code class="px-1 py-0.5 bg-slate-100 rounded">templates/</code> folder (at its root or inside one wrapper folder). Max 16&nbsp;MB.</p>
    <form method="POST" action="<?= $base ?>/admin/themes/install" enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="file" name="theme_zip" accept=".zip" required
            class="text-sm text-slate-600 file:mr-3 file:px-3 file:py-2 file:border-0 file:rounded-lg file:bg-blue-50 file:text-blue-700 file:text-xs file:font-medium">
        <label class="flex items-center gap-2 text-xs text-slate-600 select-none">
            <input type="checkbox" name="overwrite" value="1"> Replace existing (update a theme in place)
        </label>
        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
            <?= icon('arrow-up-tray', 'w-4 h-4') ?> Install theme
        </button>
    </form>
</div>

<?php if (empty($themes)): ?>
    <div class="bg-white rounded-xl border border-slate-200 text-center py-16 text-slate-500">
        <?= icon('swatch', 'w-12 h-12 text-slate-300 mb-3') ?>
        <p>No themes detected.</p>
        <p class="text-xs text-slate-400 mt-2">Drop a theme folder into <code class="px-1.5 py-0.5 bg-slate-100 rounded">/content/themes/</code>.</p>
    </div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ($themes as $slug => $t): $active = $slug === $activeSlug; ?>
    <div class="bg-white rounded-xl border <?= $active ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200' ?> overflow-hidden">
        <div class="aspect-video bg-gradient-to-br from-blue-100 to-blue-200 grid place-items-center">
            <?php if (!empty($t['preview'])): ?>
                <img src="<?= htmlspecialchars($t['preview']) ?>" alt="" class="w-full h-full object-cover">
            <?php else: ?>
                <?= icon('swatch', 'w-12 h-12 text-blue-400') ?>
            <?php endif; ?>
        </div>
        <div class="p-5">
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars($t['name'] ?? $slug) ?></h3>
                <?php if ($active): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-blue-50 text-blue-700">
                        <?= icon('check', 'w-4 h-4') ?> Active
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-sm text-slate-600 mb-3"><?= htmlspecialchars($t['description'] ?? '') ?></p>
            <div class="text-xs text-slate-500 mb-3">
                v<?= htmlspecialchars($t['version'] ?? '1.0') ?>
                <?php if (!empty($t['author'])): ?> · by <?= htmlspecialchars($t['author']) ?><?php endif; ?>
            </div>
            <?php if (!$active): ?>
                <div class="flex items-center gap-2">
                    <form method="POST" action="<?= $base ?>/admin/themes/<?= urlencode($slug) ?>/activate" class="flex-1">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <button class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                            <?= icon('bolt', 'w-4 h-4 mr-1') ?> Activate
                        </button>
                    </form>
                    <?php if ($slug !== 'default'): ?>
                    <form method="POST" action="<?= $base ?>/admin/themes/<?= urlencode($slug) ?>/delete"
                          onsubmit="return confirm('Delete the theme \'<?= htmlspecialchars($slug) ?>\' and all its files? This cannot be undone.')">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <button class="px-3 py-2 border border-slate-200 rounded-lg text-sm text-red-600 hover:border-red-400" title="Delete theme">
                            <?= icon('trash', 'w-4 h-4') ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $this->endSection(); ?>
