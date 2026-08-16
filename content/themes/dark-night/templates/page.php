<?php $partial('header'); ?>

<article class="dn-narrow dn-article">
    <header style="margin-bottom:2rem;">
        <h1 class="dn-title"><?= htmlspecialchars($post['title']) ?></h1>
    </header>

    <?php if (!empty($post['featured_url'])): ?>
    <figure class="dn-figure">
        <img src="<?= htmlspecialchars($post['featured_url']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
    </figure>
    <?php endif; ?>

    <div class="dn-prose">
        <?php
        $format = $post['content_format'] ?? 'html';
        if ($format === 'markdown') {
            echo nl2br(htmlspecialchars($post['content']));
        } else {
            echo $post['content'];
        }
        ?>
    </div>
</article>

<?php $partial('footer'); ?>
