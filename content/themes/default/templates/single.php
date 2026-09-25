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
                <?php $authorUrl = function_exists('bh_author_url') ? bh_author_url($post) : ''; ?>
                <?php if ($authorUrl !== ''): ?>
                <a href="<?= htmlspecialchars(($base ?? '') . $authorUrl) ?>" rel="author" class="font-medium text-slate-700 hover:text-brand-600"><?= htmlspecialchars($post['author_name']) ?></a>
                <?php else: ?>
                <span class="font-medium text-slate-700"><?= htmlspecialchars($post['author_name']) ?></span>
                <?php endif; ?>
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

    <!-- Author box: core's, in this theme's classes. Controlled by
         Settings → Reading → Author box on posts. -->
    <?php if (function_exists('bh_author_box')): ?>
    <?= bh_author_box($post, [
        'class'        => 'mt-12 p-5 rounded-2xl bg-slate-50 border border-slate-200',
        'avatar_class' => 'bg-gradient-to-br from-brand-400 to-brand-600',
        'name_class'   => 'text-slate-900',
        'bio_class'    => 'text-sm text-slate-600',
        'link_class'   => 'text-sm font-medium text-brand-600 hover:text-brand-700',
        'avatar_size'  => 56,
    ]) ?>
    <?php endif; ?>

    <!-- Comments -->
    <section id="comments" class="mt-12 pt-8 border-t border-slate-200">
        <h2 class="text-2xl font-bold text-slate-900 mb-6">
            <?= icon('chat-bubble-left-right', 'w-4 h-4 text-brand-500 mr-2') ?>
            Comments <span class="text-slate-400 font-medium" data-bh-comment-count="<?= (int) $post['id'] ?>"><?= (int) $comments_count ?></span>
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
                $renderComment = function ($c, int $depth) use (&$renderComment, $byParent, $comments_open) {
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
                                    <?php if (!empty($c['author_url'])): ?>
                                        <a href="<?= htmlspecialchars($c['author_url']) ?>" rel="nofollow ugc" target="_blank" class="font-semibold text-slate-900 text-sm hover:text-brand-600"><?= htmlspecialchars($c['author_name']) ?></a>
                                    <?php else: ?>
                                        <span class="font-semibold text-slate-900 text-sm"><?= htmlspecialchars($c['author_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="text-xs text-slate-500"><?= date('M j, Y g:i a', strtotime($c['created_at'])) ?></span>
                                </div>
                                <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($c['content']) ?></p>
                            </div>
                            <?php if ($comments_open): ?>
                                <button type="button" class="comment-reply-btn text-xs text-slate-500 hover:text-brand-600 mt-1 ml-1"
                                        data-bh-reply data-id="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['author_name'] ?? '') ?>">Reply</button>
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

        <!-- Comment form: core's standard form, styled for this theme. Signed-in
             members are not asked for a name or email. -->
        <?= bh_comment_form($post, [
            'class'          => 'bg-white border border-slate-200 rounded-2xl p-6',
            'title_class'    => 'font-semibold text-slate-900 mb-4',
            'label_class'    => 'block text-xs font-medium text-slate-600',
            'input_class'    => 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-200 focus:border-brand-500 outline-none text-sm',
            'textarea_class' => 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-200 focus:border-brand-500 outline-none text-sm',
            'button_class'   => 'px-5 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-medium shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed',
            'label_submit'   => 'Post Comment',
            'placeholder'    => 'Share your thoughts...',
            'show_url'       => false,
            'closed_text'    => 'Comments are closed.',
        ]) ?>
    </section>
</article>

<?php $partial('footer'); ?>
