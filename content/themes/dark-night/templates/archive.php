<?php $partial('header'); ?>

<div class="dn-page-head">
    <p class="dn-kicker">
        <?php if ($archive_type === 'category'): ?>
            <i class="fa-regular fa-folder" style="margin-right:.3rem;"></i> Category
        <?php elseif ($archive_type === 'tag'): ?>
            <i class="fa-solid fa-tag" style="margin-right:.3rem;"></i> Tag
        <?php elseif ($archive_type === 'author'): ?>
            <i class="fa-regular fa-user" style="margin-right:.3rem;"></i> Author
        <?php endif; ?>
    </p>
    <h1>
        <?php if ($archive_type === 'author' && !empty($author)): ?>
            <?= htmlspecialchars($author['display_name'] ?? $author['username']) ?>
        <?php elseif (!empty($term)): ?>
            <?= htmlspecialchars($term['name']) ?>
        <?php else: ?>
            Archive
        <?php endif; ?>
    </h1>
    <?php if ($archive_type === 'author' && !empty($author['bio'])): ?>
        <p><?= htmlspecialchars($author['bio']) ?></p>
    <?php elseif (!empty($term['description'])): ?>
        <p><?= htmlspecialchars($term['description']) ?></p>
    <?php endif; ?>
</div>

<div class="dn-container">
    <?php if (empty($posts)): ?>
        <div class="dn-empty">
            <i class="fa-solid fa-cloud-moon"></i>
            <p style="margin:0;">Nothing published here yet.</p>
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
