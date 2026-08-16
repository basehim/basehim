<?php $partial('header'); ?>

<!-- Hero -->
<section class="dn-hero">
    <div class="dn-stars"></div>
    <h1><?= htmlspecialchars($site_title) ?></h1>
    <?php if (!empty($tagline)): ?>
        <p><?= htmlspecialchars($tagline) ?></p>
    <?php endif; ?>
</section>

<!-- Posts -->
<div class="dn-container">
    <?php if (empty($posts)): ?>
        <div class="dn-empty">
            <i class="fa-solid fa-cloud-moon"></i>
            <h2 style="color:var(--dn-text-soft);margin:0 0 .4rem;font-size:1.2rem;">Nothing here yet</h2>
            <p style="margin:0;">The night is young — check back soon.</p>
        </div>
    <?php else: ?>
        <div class="dn-grid">
            <?php foreach ($posts as $p): ?>
                <?php $partial('post-card', ['p' => $p]); ?>
            <?php endforeach; ?>
        </div>

        <?php if (($meta['last_page'] ?? 1) > 1): ?>
        <nav class="dn-pager">
            <?php if ($meta['page'] > 1): ?>
                <a href="?page=<?= $meta['page'] - 1 ?>" class="dn-btn dn-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Newer</a>
            <?php endif; ?>
            <span>Page <?= $meta['page'] ?> of <?= $meta['last_page'] ?></span>
            <?php if ($meta['page'] < $meta['last_page']): ?>
                <a href="?page=<?= $meta['page'] + 1 ?>" class="dn-btn dn-btn-ghost">Older <i class="fa-solid fa-arrow-right"></i></a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $partial('footer'); ?>
