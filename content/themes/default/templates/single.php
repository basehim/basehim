<?php $partial('header'); ?>

<article class="max-w-3xl mx-auto px-4 lg:px-6 py-12">
    <!-- Post header -->
    <header class="mb-8">
        <?php if (!empty($terms)): ?>
        <div class="flex flex-wrap gap-2 mb-4">
            <?php foreach ($terms as $term): ?>
                <?php $termUrl = ($term['taxonomy_slug'] === 'category' ? "/category/{$term['slug']}" : "/tag/{$term['slug']}"); ?>
                <a href="<?= htmlspecialchars(link_to($termUrl)) ?>" class="text-xs font-semibold px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 hover:bg-brand-100">
                    <?= htmlspecialchars($term['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <h1 class="text-3xl md:text-5xl font-bold text-slate-900 mb-4 tracking-tight font-serif leading-tight">
            <?= htmlspecialchars($post['title']) ?>
        </h1>
        <div class="flex items-center gap-4 text-sm text-slate-500">
            <?php if (!empty($post['author_name'])): ?>
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-brand-400 to-brand-600 grid place-items-center text-white text-xs font-semibold">
                    <?= strtoupper(substr($post['author_name'], 0, 1)) ?>
                </div>
                <span class="font-medium text-slate-700"><?= htmlspecialchars($post['author_name']) ?></span>
            </div>
            <?php endif; ?>
            <span class="text-slate-300">·</span>
            <time><?= icon('calendar', 'w-4 h-4 mr-1 text-slate-400') ?><?= date('F j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?></time>
            <?php if (!empty($post['view_count'])): ?>
            <span class="text-slate-300">·</span>
            <span><?= icon('eye', 'w-4 h-4 mr-1 text-slate-400') ?><?= number_format($post['view_count']) ?> views</span>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($post['featured_url'])): ?>
    <figure class="mb-8 rounded-2xl overflow-hidden border border-slate-200">
        <img src="<?= htmlspecialchars($post['featured_url']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="w-full">
    </figure>
    <?php endif; ?>

    <!-- Content -->
    <div class="prose prose-slate prose-lg max-w-none prose-headings:font-serif prose-headings:tracking-tight prose-a:text-brand-600 prose-a:no-underline hover:prose-a:underline prose-code:bg-slate-100 prose-code:rounded prose-code:px-1.5 prose-code:py-0.5 prose-code:text-sm prose-code:font-mono">
        <?php
        $format = $post['content_format'] ?? 'html';
        if ($format === 'markdown') {
            // Minimal: just escape and nl2br; in production use Parsedown
            echo nl2br(htmlspecialchars($post['content']));
        } elseif ($format === 'blocks') {
            // Blocks are server-rendered to safe HTML by the core
            // `post.content` filter (App\Services\BlockRenderer) before the
            // template runs — output as-is.
            echo $post['content'];
        } else {
            echo $post['content']; // HTML, raw
        }
        ?>
    </div>

    <!-- Author bio -->
    <?php if (!empty($post['author_bio'])): ?>
    <div class="mt-12 p-5 rounded-2xl bg-slate-50 border border-slate-200 flex items-start gap-4">
        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-brand-400 to-brand-600 grid place-items-center text-white text-lg font-semibold flex-shrink-0">
            <?= strtoupper(substr($post['author_name'], 0, 1)) ?>
        </div>
        <div>
            <div class="font-semibold text-slate-900"><?= htmlspecialchars($post['author_name']) ?></div>
            <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars($post['author_bio']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Comments -->
    <section id="comments" class="mt-12 pt-8 border-t border-slate-200">
        <h2 class="text-2xl font-bold text-slate-900 mb-6">
            <?= icon('chat-bubble-left-right', 'w-4 h-4 text-brand-500 mr-2') ?>
            Comments <span class="text-slate-400 font-medium"><?= $comments_count ?></span>
        </h2>

        <?php if (empty($comments)): ?>
            <p class="text-slate-500 mb-8">Be the first to comment.</p>
        <?php else: ?>
            <?php
                // Build a parent → children tree from the flat approved list.
                $byParent = [];
                foreach ($comments as $c) {
                    $pid = (int) ($c['parent_id'] ?? 0);
                    $byParent[$pid][] = $c;
                }
                // Recursive renderer (capped indent so deep threads stay readable).
                $renderComment = function ($c, int $depth) use (&$renderComment, $byParent) {
                    $email = trim((string) ($c['author_email'] ?? ''));
                    $avatar = $email !== ''
                        ? 'https://www.gravatar.com/avatar/' . md5(strtolower($email)) . '?s=80&d=mp'
                        : null;
                    $indent = $depth > 0 ? ' style="margin-left:' . min($depth, 3) * 2 . 'rem"' : '';
                    ?>
                    <div class="flex gap-3" id="comment-<?= $c['id'] ?>"<?= $indent ?>>
                        <?php if ($avatar): ?>
                            <img src="<?= htmlspecialchars($avatar) ?>" alt="" width="40" height="40" loading="lazy"
                                 class="w-10 h-10 rounded-full flex-shrink-0 bg-slate-200 object-cover">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-slate-300 to-slate-400 grid place-items-center text-white font-semibold flex-shrink-0">
                                <?= strtoupper(substr($c['author_name'] ?? 'A', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <div class="bg-slate-50 rounded-2xl px-4 py-3">
                                <div class="flex items-baseline gap-2 mb-1 flex-wrap">
                                    <?php
                                    // Re-check on output as well as on save: rows
                                    // stored before author_url was validated are
                                    // still in the table.
                                    $authorUrl = \App\Services\CommentService::safeAuthorUrl($c['author_url'] ?? null);
                                    ?>
                                    <?php if ($authorUrl): ?>
                                        <a href="<?= htmlspecialchars($authorUrl) ?>" rel="nofollow ugc noopener noreferrer" target="_blank" class="font-semibold text-slate-900 text-sm hover:text-brand-600"><?= htmlspecialchars($c['author_name']) ?></a>
                                    <?php else: ?>
                                        <span class="font-semibold text-slate-900 text-sm"><?= htmlspecialchars($c['author_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="text-xs text-slate-500"><?= date('M j, Y g:i a', strtotime($c['created_at'])) ?></span>
                                </div>
                                <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($c['content']) ?></p>
                            </div>
                            <?php if ($comments_open): ?>
                                <button type="button" class="comment-reply-btn text-xs text-slate-500 hover:text-brand-600 mt-1 ml-1"
                                        data-id="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['author_name'] ?? '') ?>">Reply</button>
                            <?php endif; ?>
                            <?php foreach ($byParent[(int) $c['id']] ?? [] as $child) { echo '<div class="mt-4">'; $renderComment($child, $depth + 1); echo '</div>'; } ?>
                        </div>
                    </div>
                    <?php
                };
            ?>
            <div class="space-y-6 mb-10">
                <?php foreach ($byParent[0] ?? [] as $c) { $renderComment($c, 0); } ?>
            </div>
        <?php endif; ?>

        <!-- Comment form -->
        <?php if ($comments_open): ?>
        <div id="comment-form" class="bg-white border border-slate-200 rounded-2xl p-6">
            <h3 class="font-semibold text-slate-900 mb-4">Leave a comment</h3>

            <!-- Replying-to indicator (shown when replying) -->
            <div id="comment-reply-notice" class="hidden mb-4 text-sm bg-brand-50 border border-brand-200 text-brand-800 rounded-lg px-4 py-2 flex items-center justify-between">
                <span>Replying to <strong id="comment-reply-name"></strong></span>
                <button type="button" id="comment-reply-cancel" class="text-brand-600 hover:text-brand-800 text-xs font-medium">Cancel</button>
            </div>

            <!-- AJAX status box (initially hidden) -->
            <div id="comment-status" class="hidden mb-4"></div>

            <form id="comment-form-el" method="POST" action="<?= $base ?>/comments" class="space-y-4">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars(\App\Core\Helpers::postUrl($post)) ?>">
                <input type="hidden" name="parent_id" id="comment-parent-id" value="">
                <!-- Honeypot: hidden from humans; bots that fill it are silently dropped. -->
                <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
                    <label>Leave this field empty<input type="text" name="hp_comment_field" tabindex="-1" autocomplete="off" value=""></label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Name *</label>
                        <input type="text" name="author_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-200 focus:border-brand-500 outline-none text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Email *</label>
                        <input type="email" name="author_email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-200 focus:border-brand-500 outline-none text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Comment *</label>
                    <textarea name="content" rows="4" required placeholder="Share your thoughts..." class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-200 focus:border-brand-500 outline-none text-sm"></textarea>
                </div>
                <button type="submit" id="comment-submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <?= icon('paper-airplane', 'w-4 h-4 mr-1') ?>
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

            // ── Reply handling ──────────────────────────────────────────────
            var parentField = document.getElementById('comment-parent-id');
            var replyNotice = document.getElementById('comment-reply-notice');
            var replyName   = document.getElementById('comment-reply-name');
            function clearReply() {
                if (parentField) parentField.value = '';
                if (replyNotice) replyNotice.classList.add('hidden');
            }
            document.querySelectorAll('.comment-reply-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (parentField) parentField.value = btn.getAttribute('data-id') || '';
                    if (replyName) replyName.textContent = btn.getAttribute('data-name') || 'comment';
                    if (replyNotice) replyNotice.classList.remove('hidden');
                    document.getElementById('comment-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    var ta = form.querySelector('textarea[name="content"]');
                    if (ta) ta.focus();
                });
            });
            var cancelBtn = document.getElementById('comment-reply-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', clearReply);

            function showStatus(type, message) {
                var classes = {
                    success: 'bg-green-50 border-green-200 text-green-800',
                    pending: 'bg-blue-50 border-blue-200 text-blue-800',
                    error: 'bg-red-50 border-red-200 text-red-800'
                };
                // SVG markup rendered server-side so the theme needs no icon font.
                var icons = {
                    success: <?= json_encode(icon('check-circle', 'w-4 h-4 inline-block align-text-bottom mr-2')) ?>,
                    pending: <?= json_encode(icon('clock', 'w-4 h-4 inline-block align-text-bottom mr-2')) ?>,
                    error: <?= json_encode(icon('exclamation-circle', 'w-4 h-4 inline-block align-text-bottom mr-2')) ?>
                };
                statusBox.className = 'mb-4 px-4 py-3 rounded-lg border text-sm ' + (classes[type] || classes.error);
                statusBox.innerHTML = (icons[type] || icons.error) + message;
                statusBox.classList.remove('hidden');
                statusBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.querySelector('.label').textContent = 'Submitting...';
                statusBox.classList.add('hidden');

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
                }).then(function (res) {
                    if (!res.ok) {
                        showStatus('error', (res.data && (res.data.error || res.data.message)) || 'Submission failed. Please try again.');
                        return;
                    }
                    if (res.data.pending) {
                        showStatus('pending', res.data.message || 'Thanks! Your comment is awaiting moderation.');
                    } else {
                        showStatus('success', res.data.message || 'Comment posted successfully.');
                    }
                    form.reset();
                }).catch(function () {
                    showStatus('error', 'Network error. Please try again.');
                }).finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.querySelector('.label').textContent = 'Post Comment';
                });
            });
        })();
        </script>
        <?php else: ?>
        <p class="text-sm text-slate-500 italic">Comments are closed.</p>
        <?php endif; ?>
    </section>
</article>

<?php $partial('footer'); ?>
