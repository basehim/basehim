<?php $partial('header'); ?>

<!-- Hero -->
<section class="bg-gradient-to-br from-brand-50 via-white to-brand-50 border-b border-slate-100">
    <div class="max-w-6xl mx-auto px-4 lg:px-6 py-16 lg:py-24 text-center">
        <h1 class="text-4xl md:text-5xl font-bold text-slate-900 mb-4 tracking-tight">
            <?= htmlspecialchars($site_title) ?>
        </h1>
        <?php if (!empty($tagline)): ?>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto"><?= htmlspecialchars($tagline) ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- Posts -->
<div class="max-w-6xl mx-auto px-4 lg:px-6 py-12">
    <?php if (empty($posts)): ?>
        <div class="text-center py-20 text-slate-500">
            <?= icon('newspaper', 'w-16 h-16 text-slate-200 mb-4 mx-auto') ?>
            <h2 class="text-xl font-semibold text-slate-700 mb-2">No posts yet</h2>
            <p>Check back soon for new content.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($posts as $p): ?>
            <article class="bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-lg hover:shadow-brand-100/40 hover:border-brand-200 transition group">
                <?php if (!empty($p['featured_url'])): ?>
                <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="block aspect-video overflow-hidden bg-slate-100">
                    <img src="<?= htmlspecialchars($p['featured_url']) ?>" alt="<?= htmlspecialchars($p['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                </a>
                <?php else: ?>
                <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="block aspect-video bg-gradient-to-br from-brand-100 to-brand-200 grid place-items-center">
                    <?= icon('newspaper', 'w-10 h-10 text-brand-400') ?>
                </a>
                <?php endif; ?>
                <div class="p-5">
                    <div class="flex items-center gap-2 text-xs text-slate-500 mb-2">
                        <?= icon('calendar', 'w-4 h-4') ?>
                        <time><?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?></time>
                        <?php if (!empty($p['author_name'])): ?>
                            <span class="text-slate-300">·</span>
                            <?= icon('user', 'w-4 h-4') ?>
                            <span><?= htmlspecialchars($p['author_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-lg font-semibold text-slate-900 mb-2 leading-tight">
                        <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="hover:text-brand-600">
                            <?= htmlspecialchars($p['title']) ?>
                        </a>
                    </h2>
                    <?php if (!empty($p['excerpt'])): ?>
                        <p class="text-sm text-slate-600 line-clamp-3"><?= htmlspecialchars($p['excerpt']) ?></p>
                    <?php endif; ?>
                    <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="inline-flex items-center gap-1 mt-3 text-sm font-medium text-brand-600 hover:text-brand-700">
                        Read more <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if (($meta['last_page'] ?? 1) > 1): ?>
        <nav class="mt-10 flex items-center justify-center gap-2">
            <?php if ($meta['page'] > 1): ?>
                <a href="?page=<?= $meta['page'] - 1 ?>" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
                    <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Newer
                </a>
            <?php endif; ?>
            <span class="px-4 py-2 text-sm text-slate-500">Page <?= $meta['page'] ?> of <?= $meta['last_page'] ?></span>
            <?php if ($meta['page'] < $meta['last_page']): ?>
                <a href="?page=<?= $meta['page'] + 1 ?>" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
                    Older <?= icon('arrow-right', 'w-4 h-4 ml-1') ?>
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $partial('footer'); ?>
