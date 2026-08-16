<?php
/**
 * Post card — shared by index.php and archive.php via $partial('post-card', ['p' => $p]).
 * Expects: $p (post row), $base.
 */
$pUrl = $base . \App\Core\Helpers::postUrl($p);
?>
<article class="dn-card">
    <?php if (!empty($p['featured_url'])): ?>
    <a href="<?= htmlspecialchars($pUrl) ?>" class="dn-card-media">
        <img src="<?= htmlspecialchars($p['featured_url']) ?>" alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy">
    </a>
    <?php else: ?>
    <a href="<?= htmlspecialchars($pUrl) ?>" class="dn-card-media is-empty">
        <i class="fa-solid fa-moon"></i>
    </a>
    <?php endif; ?>
    <div class="dn-card-body">
        <div class="dn-meta">
            <i class="fa-regular fa-calendar"></i>
            <time><?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?></time>
            <?php if (!empty($p['author_name'])): ?>
                <span class="dot">·</span>
                <i class="fa-regular fa-user"></i>
                <span><?= htmlspecialchars($p['author_name']) ?></span>
            <?php endif; ?>
        </div>
        <h2><a href="<?= htmlspecialchars($pUrl) ?>"><?= htmlspecialchars($p['title']) ?></a></h2>
        <?php if (!empty($p['excerpt'])): ?>
            <p><?= htmlspecialchars($p['excerpt']) ?></p>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($pUrl) ?>" class="dn-more">Read under the stars <i class="fa-solid fa-arrow-right"></i></a>
    </div>
</article>
