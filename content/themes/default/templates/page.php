<?php $partial('header'); ?>

<article class="max-w-3xl mx-auto px-4 lg:px-6 py-12">
    <header class="mb-8">
        <h1 class="text-3xl md:text-5xl font-bold text-slate-900 tracking-tight font-serif leading-tight">
            <?= htmlspecialchars($post['title']) ?>
        </h1>
    </header>

    <?php if (!empty($post['featured_url'])): ?>
    <figure class="mb-8 rounded-2xl overflow-hidden border border-slate-200">
        <img src="<?= htmlspecialchars($post['featured_url']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="w-full">
    </figure>
    <?php endif; ?>

    <div class="prose prose-slate prose-lg max-w-none prose-headings:font-serif prose-headings:tracking-tight prose-a:text-brand-600 prose-a:no-underline hover:prose-a:underline prose-code:bg-slate-100 prose-code:rounded prose-code:px-1.5 prose-code:py-0.5">
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
