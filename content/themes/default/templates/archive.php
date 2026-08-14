<?php $partial('header'); ?>

<div class="bg-gradient-to-br from-brand-50 to-white border-b border-slate-100">
    <div class="max-w-6xl mx-auto px-4 lg:px-6 py-12 text-center">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-600 mb-2">
            <?php if ($archive_type === 'category'): ?>
                <?= icon('folder', 'w-4 h-4 mr-1') ?> Category
            <?php elseif ($archive_type === 'tag'): ?>
                <?= icon('tag', 'w-4 h-4 mr-1') ?> Tag
            <?php elseif ($archive_type === 'author'): ?>
                <?= icon('user', 'w-4 h-4 mr-1') ?> Author
            <?php endif; ?>
        </p>
        <h1 class="text-3xl md:text-4xl font-bold text-slate-900 mb-2 tracking-tight">
            <?php if ($archive_type === 'author' && !empty($author)): ?>
                <?= htmlspecialchars($author['display_name'] ?? $author['username']) ?>
            <?php elseif (!empty($term)): ?>
                <?= htmlspecialchars($term['name']) ?>
            <?php else: ?>
                Archive
            <?php endif; ?>
        </h1>
        <?php if ($archive_type === 'author' && !empty($author['bio'])): ?>
            <p class="text-slate-600 max-w-2xl mx-auto"><?= htmlspecialchars($author['bio']) ?></p>
        <?php elseif (!empty($term['description'])): ?>
            <p class="text-slate-600 max-w-2xl mx-auto"><?= htmlspecialchars($term['description']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="max-w-6xl mx-auto px-4 lg:px-6 py-12">
    <?php if (empty($posts)): ?>
        <div class="text-center py-20 text-slate-500">
            <?= icon('folder-open', 'w-16 h-16 text-slate-200 mb-4 mx-auto') ?>
            <p>No posts found in this archive.</p>
        </div>
    <?php else: ?>
    <div class="space-y-6">
        <?php foreach ($posts as $p): ?>
        <article class="bg-white rounded-2xl border border-slate-200 p-6 hover:shadow-lg hover:shadow-brand-100/40 hover:border-brand-200 transition">
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-2">
                <?= icon('calendar', 'w-4 h-4') ?>
                <time><?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?></time>
                <?php if (!empty($p['author_name'])): ?>
                    <span class="text-slate-300">·</span>
                    <span><?= htmlspecialchars($p['author_name']) ?></span>
                <?php endif; ?>
            </div>
            <h2 class="text-xl font-semibold text-slate-900 mb-2">
                <a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>" class="hover:text-brand-600">
                    <?= htmlspecialchars($p['title']) ?>
                </a>
            </h2>
            <?php if (!empty($p['excerpt'])): ?>
                <p class="text-slate-600"><?= htmlspecialchars($p['excerpt']) ?></p>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if (($meta['last_page'] ?? 1) > 1): ?>
    <nav class="mt-10 flex items-center justify-center gap-2">
        <?php if ($meta['page'] > 1): ?>
            <a href="?page=<?= $meta['page'] - 1 ?>" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">Newer</a>
        <?php endif; ?>
        <span class="px-4 py-2 text-sm text-slate-500">Page <?= $meta['page'] ?> of <?= $meta['last_page'] ?></span>
        <?php if ($meta['page'] < $meta['last_page']): ?>
            <a href="?page=<?= $meta['page'] + 1 ?>" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">Older</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php $partial('footer'); ?>
