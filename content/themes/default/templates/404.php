<?php $partial('header'); ?>

<div class="max-w-2xl mx-auto px-4 lg:px-6 py-20 text-center">
    <div class="inline-flex w-20 h-20 rounded-2xl bg-gradient-to-br from-brand-100 to-brand-200 grid place-items-center text-brand-500 text-3xl mb-6">
        <?= icon('map', 'w-4 h-4') ?>
    </div>
    <h1 class="text-6xl font-bold text-slate-900 mb-2">404</h1>
    <p class="text-xl text-slate-700 mb-2">Page not found</p>
    <p class="text-slate-500 mb-8 max-w-md mx-auto"><?= htmlspecialchars($message ?? "We couldn't find what you were looking for.") ?></p>
    <div class="flex items-center justify-center gap-3">
        <a href="<?= $base ?>/" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium shadow-sm">
            <?= icon('home', 'w-4 h-4 mr-1') ?> Home
        </a>
        <a href="<?= $base ?>/search" class="px-5 py-2.5 border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-lg font-medium">
            <?= icon('magnifying-glass', 'w-4 h-4 mr-1') ?> Search
        </a>
    </div>
</div>

<?php $partial('footer'); ?>
