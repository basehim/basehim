<?php $partial('header'); ?>

<div class="dn-narrow dn-article">
    <header>
        <p class="dn-kicker"><i class="fa-solid fa-magnifying-glass" style="margin-right:.3rem;"></i> Search</p>
        <?php if ($query !== ''): ?>
            <h1 class="dn-title" style="font-size:clamp(1.6rem,4vw,2.3rem);">Results for "<?= htmlspecialchars($query) ?>"</h1>
            <p style="color:var(--dn-text-soft);margin:.3rem 0 0;"><?= $meta['total'] ?> result<?= $meta['total'] === 1 ? '' : 's' ?> found</p>
        <?php else: ?>
            <h1 class="dn-title" style="font-size:clamp(1.6rem,4vw,2.3rem);">Search</h1>
        <?php endif; ?>
    </header>

    <form action="<?= $base ?>/search" method="GET">
        <div class="dn-searchbar">
            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search the night..." autofocus>
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>

    <?php if ($query !== '' && empty($posts)): ?>
        <div class="dn-empty">
            <i class="fa-solid fa-star-half-stroke"></i>
            <p style="margin:0;">No results — try different words.</p>
        </div>
    <?php elseif (!empty($posts)): ?>
        <div>
            <?php foreach ($posts as $p): ?>
            <div class="dn-result">
                <div class="dn-meta">
                    <i class="fa-regular fa-calendar"></i>
                    <time><?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?></time>
                    <?php if (!empty($p['author_name'])): ?>
                        <span class="dot">·</span><span><?= htmlspecialchars($p['author_name']) ?></span>
                    <?php endif; ?>
                </div>
                <h3><a href="<?= $base ?><?= htmlspecialchars(\App\Core\Helpers::postUrl($p)) ?>"><?= htmlspecialchars($p['title']) ?></a></h3>
                <?php if (!empty($p['excerpt'])): ?>
                    <p><?= htmlspecialchars($p['excerpt']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (($meta['last_page'] ?? 1) > 1): ?>
        <nav class="dn-pager" style="padding-top:2rem;">
            <?php if ($meta['page'] > 1): ?>
                <a href="?q=<?= urlencode($query) ?>&page=<?= $meta['page'] - 1 ?>" class="dn-btn dn-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Prev</a>
            <?php endif; ?>
            <span>Page <?= $meta['page'] ?> of <?= $meta['last_page'] ?></span>
            <?php if ($meta['page'] < $meta['last_page']): ?>
                <a href="?q=<?= urlencode($query) ?>&page=<?= $meta['page'] + 1 ?>" class="dn-btn dn-btn-ghost">Next <i class="fa-solid fa-arrow-right"></i></a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $partial('footer'); ?>
