<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : ''; ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Widgets</h2>
    <p class="text-sm text-slate-500">Widgets are registered by your active apps and theme. They can appear in the block editor, on the public site, and on this dashboard.</p>
</div>

<!-- Sub-nav -->
<div class="flex items-center gap-1 mb-5 text-sm">
    <a href="<?= $base ?>/admin/widgets" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-medium">Registered widgets</a>
    <a href="<?= $base ?>/admin/widgets/areas" class="px-3 py-1.5 rounded-lg text-slate-500 hover:bg-slate-100">Widget areas</a>
</div>

<?php if (empty($widgets)): ?>
<div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
    <?= icon('squares-2x2', 'w-10 h-10 text-slate-300 mb-3 block mx-auto') ?>
    <h3 class="text-slate-700 font-medium mb-1">No widgets registered yet</h3>
    <p class="text-sm text-slate-500 max-w-md mx-auto">Activate a app or theme that provides widgets, and they'll appear here. Developers: call <code class="px-1 py-0.5 bg-slate-100 rounded">$this->registerWidget()</code> from a app, or return an array from a theme's <code class="px-1 py-0.5 bg-slate-100 rounded">widgets.php</code>.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($widgets as $w): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <div class="flex items-start gap-3 mb-2">
            <div class="w-10 h-10 rounded-lg bg-slate-50 text-slate-500 grid place-items-center shrink-0">
                <?= icon(htmlspecialchars($w['icon']), 'w-4 h-4') ?>
            </div>
            <div class="min-w-0 flex-1">
                <h4 class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($w['title']) ?></h4>
                <div class="text-[11px] text-slate-400 font-mono truncate"><?= htmlspecialchars($w['key']) ?></div>
            </div>
        </div>
        <?php if (!empty($w['description'])): ?>
        <p class="text-xs text-slate-500 mb-3"><?= htmlspecialchars($w['description']) ?></p>
        <?php endif; ?>
        <div class="flex flex-wrap gap-1.5 mb-2">
            <?php foreach ($w['surfaces'] as $s): ?>
                <?php
                $badge = match ($s) {
                    'editor'    => ['Editor', 'bg-blue-50 text-blue-700'],
                    'frontend'  => ['Frontend', 'bg-emerald-50 text-emerald-700'],
                    'dashboard' => ['Dashboard', 'bg-violet-50 text-violet-700'],
                    default     => [$s, 'bg-slate-100 text-slate-600'],
                };
                ?>
                <span class="text-[10px] px-2 py-0.5 rounded-full font-medium <?= $badge[1] ?>"><?= htmlspecialchars($badge[0]) ?></span>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($w['source'])): ?>
        <div class="text-[11px] text-slate-400"><?= icon(str_starts_with($w['source'], 'theme:') ? 'palette' : 'plug', 'w-3.5 h-3.5 mr-1 inline-block align-text-bottom') ?><?= htmlspecialchars($w['source']) ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $this->endSection(); ?>
