<?php $partial('header'); ?>

<article class="dn-narrow dn-article">
    <header style="margin-bottom:2rem;">
        <?php if (!empty($terms)): ?>
        <div class="dn-terms">
            <?php foreach ($terms as $term): ?>
                <?php $termUrl = ($term['taxonomy_slug'] === 'category' ? "/category/{$term['slug']}" : "/tag/{$term['slug']}"); ?>
                <a href="<?= htmlspecialchars(link_to($termUrl)) ?>" class="dn-term"><?= htmlspecialchars($term['name']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <h1 class="dn-title"><?= htmlspecialchars($post['title']) ?></h1>
        <div class="dn-byline">
            <?php if (!empty($post['author_name'])): ?>
            <span style="display:inline-flex;align-items:center;gap:.55rem;">
                <span class="dn-avatar"><?= strtoupper(substr($post['author_name'], 0, 1)) ?></span>
                <?php $authorUrl = function_exists('bh_author_url') ? bh_author_url($post) : ''; ?>
                <?php if ($authorUrl !== ''): ?>
                <b><a href="<?= htmlspecialchars(($base ?? '') . $authorUrl) ?>" rel="author" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($post['author_name']) ?></a></b>
                <?php else: ?>
                <b><?= htmlspecialchars($post['author_name']) ?></b>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <time><i class="fa-regular fa-calendar" style="margin-right:.35rem;"></i><?= date('F j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?></time>
            <?php if (!empty($post['view_count'])): ?>
            <span><i class="fa-regular fa-eye" style="margin-right:.35rem;"></i><?= number_format($post['view_count']) ?> views</span>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($post['featured_url'])): ?>
    <figure class="dn-figure">
        <img src="<?= htmlspecialchars($post['featured_url']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
    </figure>
    <?php endif; ?>

    <!-- Content -->
    <div class="dn-prose">
        <?php
        $format = $post['content_format'] ?? 'html';
        if ($format === 'markdown') {
            echo nl2br(htmlspecialchars($post['content']));
        } else {
            // 'blocks' arrives server-rendered to safe HTML by the core
            // post.content filter; 'html' is raw by design.
            echo $post['content'];
        }
        ?>
    </div>

    <!-- Author box: core's, in this theme's classes. Controlled by
         Settings → Reading → Author box on posts. -->
    <?php if (function_exists('bh_author_box')): ?>
    <?= bh_author_box($post, [
        'class'        => 'dn-bio',
        'avatar_class' => 'dn-avatar',
        'avatar_size'  => 52,
    ]) ?>
    <?php endif; ?>

    <!-- Comments -->
    <section id="comments" class="dn-comments">
        <h2><i class="fa-regular fa-comments"></i>Comments <span data-bh-comment-count="<?= (int) $post['id'] ?>"><?= (int) $comments_count ?></span></h2>

        <?php if (empty($comments)): ?>
            <p style="color:var(--dn-text-dim);margin:0 0 1.8rem;">The night is quiet — be the first to comment.</p>
        <?php else: ?>
            <div style="margin-bottom:2rem;">
                <?php foreach ($comments as $c): ?>
                <div class="dn-comment" id="comment-<?= $c['id'] ?>">
                    <span class="dn-avatar" style="flex-shrink:0;background:linear-gradient(135deg,#3a4763,#242e47);">
                        <?= strtoupper(substr($c['author_name'] ?? 'A', 0, 1)) ?>
                    </span>
                    <div class="dn-comment-bubble">
                        <div class="dn-comment-head">
                            <b><?= htmlspecialchars($c['author_name']) ?></b>
                            <time><?= date('M j, Y g:i a', strtotime($c['created_at'])) ?></time>
                        </div>
                        <p><?= htmlspecialchars($c['content']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php // Core's standard form, in this theme's classes. Signed-in members
              // are not asked for a name or email. ?>
        <?php if ($comments_open): ?>
        <?= bh_comment_form($post, [
            'class'        => 'dn-form-card',
            'field_class'  => 'dn-field',
            'button_class' => 'dn-btn dn-btn-gold',
            'label_submit' => 'Post Comment',
            'placeholder'  => 'Share your thoughts...',
            'show_url'     => false,
            'closed_text'  => '',
        ]) ?>
        <?php else: ?>
        <p style="color:var(--dn-text-dim);font-size:.9rem;"><i class="fa-solid fa-lock" style="margin-right:.4rem;"></i>Comments are closed on this post.</p>
        <?php endif; ?>
    </section>
</article>

<?php $partial('footer'); ?>
