<?php $partial('header'); ?>

<!-- Hero -->
<section class="bht-hero">
    <div class="max-w-6xl mx-auto px-4 lg:px-6 bht-hero__inner">
        <h1 class="bht-hero__title"><?= htmlspecialchars($site_title) ?></h1>
        <?php if (!empty($tagline)): ?>
            <p class="bht-hero__tagline"><?= htmlspecialchars($tagline) ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- Posts -->
<div class="max-w-6xl mx-auto px-4 lg:px-6 py-12">
    <?php if (empty($posts)): ?>
        <div class="bht-empty">
            <span class="bht-empty__icon"><?= icon('newspaper', 'w-7 h-7') ?></span>
            <h2 class="text-xl font-semibold text-slate-900 mb-2">Nothing here yet</h2>
            <p class="text-slate-600">New posts will appear here. Check back soon.</p>
        </div>
    <?php else: ?>
        <?php
        // The newest post on the first page leads, wide (1.2.98). A new site
        // with one post looks finished instead of showing a lone card.
        $__lead = ((int) ($meta['page'] ?? 1)) <= 1 ? array_shift($posts) : null;
        $__card = function (array $p, bool $lead) use ($base): void {
            $url = $base . \App\Core\Helpers::postUrl($p);
            $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr(trim((string) $p['title']), 0, 1)) : strtoupper(substr(trim((string) $p['title']), 0, 1));
            ?>
            <article class="<?= $lead ? 'bht-lead' : 'bht-card' ?> group">
                <a href="<?= htmlspecialchars($url) ?>" class="bht-media" tabindex="-1" aria-hidden="true">
                    <?php if (!empty($p['featured_url'])): ?>
                        <img src="<?= htmlspecialchars($p['featured_url']) ?>" alt="" loading="<?= $lead ? 'eager' : 'lazy' ?>" class="bht-media__img">
                    <?php else: ?>
                        <span class="bht-media__ph"><span><?= htmlspecialchars($initial !== '' ? $initial : '·') ?></span></span>
                    <?php endif; ?>
                </a>
                <div class="bht-body">
                    <div class="bht-meta">
                        <?= bh_time_tag($p['published_at'] ?? $p['created_at'], 'M j, Y') ?>
                        <?php if (!empty($p['author_name'])): ?>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span><?= htmlspecialchars($p['author_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="bht-title"><a href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($p['title']) ?></a></h2>
                    <?php if (!empty($p['excerpt'])): ?>
                        <p class="bht-excerpt"><?= htmlspecialchars($p['excerpt']) ?></p>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($url) ?>" class="bht-more" aria-hidden="true" tabindex="-1">Read <?= $lead ? 'the post' : 'more' ?> <?= icon('arrow-right', 'w-4 h-4') ?></a>
                </div>
            </article>
            <?php
        };
        ?>
        <?php if ($__lead) $__card($__lead, true); ?>
        <?php if ($posts): ?>
        <?php if ($__lead): ?><h2 class="bht-section-title">More posts</h2><?php endif; ?>
        <div class="bht-grid">
            <?php foreach ($posts as $p) $__card($p, false); ?>
        </div>
        <?php endif; ?>

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
