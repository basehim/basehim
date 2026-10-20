<?php
/**
 * GET /example — rendered by SitePage::view() inside the active theme.
 * $e() escapes; everything shown is escaped.
 *
 * @var array $items  slug => item
 * @var array $notes  the latest notes
 * @var string $csrf  the session's CSRF token, for the form
 * @var callable $itemUrl  slug => URL
 *
 * The theme prints the page title (SitePage's 'title') above this content,
 * so the view starts below it. Styles are inline: an app cannot rely on the
 * theme having any particular CSS classes.
 */
?>
<section class="bh-example">
    <p>This page is served by an app through a public site route.</p>

    <?php if (!empty($_GET['thanks'])): ?>
        <p class="mb-6 p-3 rounded bg-green-50 text-green-800" role="status">Thanks — your note was saved.</p>
    <?php endif; ?>

    <ul class="grid gap-4 sm:grid-cols-3 mb-12">
        <?php foreach ($items as $slug => $item): ?>
        <li class="border rounded-lg p-4">
            <a href="<?= $e($itemUrl($slug)) ?>" class="font-semibold"><?= $e($item['name']) ?></a>
            <div class="text-sm opacity-75"><?= $e(number_format($item['price'], 2)) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>

    <h2 class="text-xl font-semibold mb-3">Leave a note</h2>
    <form method="post" action="<?= $e($base) ?>/api/example" class="grid gap-3 mb-8">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Name <input name="name" maxlength="60" required class="border rounded px-2 py-1 w-full"></label>
        <label>Note <textarea name="note" maxlength="500" required class="border rounded px-2 py-1 w-full"></textarea></label>
        <button type="submit" style="justify-self:start;padding:.5rem 1rem;border:0;border-radius:.375rem;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">Send</button>
    </form>

    <?php if ($notes): ?>
    <h2 class="text-xl font-semibold mb-3">Latest notes</h2>
    <ul class="space-y-2">
        <?php foreach (array_reverse($notes) as $n): ?>
        <li><strong><?= $e($n['name']) ?>:</strong> <?= $e($n['note']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
