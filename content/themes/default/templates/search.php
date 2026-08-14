<?php $partial('header'); ?>

<div class="max-w-3xl mx-auto px-4 lg:px-6 py-12">
    <header class="mb-8">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-600 mb-2">
            <?= icon('magnifying-glass', 'w-4 h-4 mr-1') ?> Search
        </p>
        <?php if ($query !== ''): ?>
            <h1 class="text-3xl font-bold text-slate-900 mb-2">Results for "<?= htmlspecialchars($query) ?>"</h1>
            <p class="text-slate-600"><?= $meta['total'] ?> result<?= $meta['total'] === 1 ? '' : 's' ?> found</p>
        <?php else: ?>
            <h1 class="text-3xl font-bold text-slate-900">Search</h1>
        <?php endif; ?>
    </header>

    <form action="<?= $base ?>/search" method="GET" class="mb-10">
        <div class="flex bg-white border border-slate-300 rounded-xl overflow-hidden focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100">
            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search the site..." autofocus
                class="flex-1 px-4 py-3 outline-none">
            <button type="submit" class="px-6 bg-brand-600 hover:bg-brand-700 text-white">
                <?= icon('magnifying-glass', 'w-4 h-4') ?>
            </button>
        </div>
    </form>

    <?php if ($query !== '' && empty($posts)): ?>
        <div class="text-center py-12 text-slate-500">
            <?= icon('magnifying-glass-minus', 'w-12 h-12 text-slate-200 mb-3') ?>
            <p>No results found for "<?= htmlspecialchars($query) ?>".</p>
            <p class="text-sm mt-2">Try a different search term.</p>
        </div>
    <?php elseif (!empty($posts)): ?>
    <div class="space-y-5">
        <?php foreach ($posts as $p): ?>
        <article class="bg-white border border-slate-200 rounded-xl p-5 hover:border-brand-300">
            <h2 class="font-semibold text-slate-900 mb-1">
                <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="hover:text-brand-600">
                    <?= htmlspecialchars($p['title']) ?>
                </a>
            </h2>
            <div class="text-xs text-slate-500 mb-2"><?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?></div>
            <?php if (!empty($p['excerpt'])): ?>
                <p class="text-sm text-slate-600"><?= htmlspecialchars(mb_substr($p['excerpt'], 0, 200)) ?></p>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php $partial('footer'); ?>
