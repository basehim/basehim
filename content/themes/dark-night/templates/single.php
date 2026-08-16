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
                <b><?= htmlspecialchars($post['author_name']) ?></b>
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

    <!-- Author bio -->
    <?php if (!empty($post['author_bio'])): ?>
    <div class="dn-bio">
        <span class="dn-avatar"><?= strtoupper(substr($post['author_name'], 0, 1)) ?></span>
        <div>
            <b><?= htmlspecialchars($post['author_name']) ?></b>
            <p><?= htmlspecialchars($post['author_bio']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Comments -->
    <section id="comments" class="dn-comments">
        <h2><i class="fa-regular fa-comments"></i>Comments <span><?= $comments_count ?></span></h2>

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

        <?php if ($comments_open): ?>
        <div id="comment-form" class="dn-form-card">
            <h3>Leave a comment</h3>
            <div id="comment-status" class="dn-status dn-hidden"></div>
            <form id="comment-form-el" method="POST" action="<?= $base ?>/comments">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars(\App\Core\Helpers::postUrl($post)) ?>">
                <div class="dn-form-grid" style="margin-bottom:.95rem;">
                    <div class="dn-field">
                        <label>Name *</label>
                        <input type="text" name="author_name" required>
                    </div>
                    <div class="dn-field">
                        <label>Email *</label>
                        <input type="email" name="author_email" required>
                    </div>
                </div>
                <div class="dn-field">
                    <label>Comment *</label>
                    <textarea name="content" rows="4" required placeholder="Share your thoughts..."></textarea>
                </div>
                <button type="submit" id="comment-submit" class="dn-btn dn-btn-gold">
                    <i class="fa-regular fa-paper-plane"></i>
                    <span class="label">Post Comment</span>
                </button>
            </form>
        </div>

        <script>
        (function () {
            var form = document.getElementById('comment-form-el');
            var statusBox = document.getElementById('comment-status');
            var submitBtn = document.getElementById('comment-submit');
            if (!form) return;

            function showStatus(type, message) {
                statusBox.className = 'dn-status ' + (type === 'success' || type === 'pending' || type === 'error' ? type : 'error');
                var icons = { success: 'fa-circle-check', pending: 'fa-clock', error: 'fa-circle-exclamation' };
                statusBox.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.error) + '" style="margin-right:.45rem;"></i>' + message;
                statusBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitBtn.disabled = true;
                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (d) {
                    submitBtn.disabled = false;
                    if (d.error) {
                        showStatus('error', d.error);
                    } else if (d.success) {
                        showStatus('success', d.message || 'Comment posted.');
                        form.reset();
                    } else {
                        showStatus('pending', d.message || 'Comment submitted and awaiting moderation.');
                        form.reset();
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    showStatus('error', 'Something went wrong — please try again.');
                });
            });
        })();
        </script>
        <?php else: ?>
        <p style="color:var(--dn-text-dim);font-size:.9rem;"><i class="fa-solid fa-lock" style="margin-right:.4rem;"></i>Comments are closed on this post.</p>
        <?php endif; ?>
    </section>
</article>

<?php $partial('footer'); ?>
