<?php
/**
 * GET /example/{slug} — one item, in the active theme.
 *
 * @var array $item
 * @var string $slug
 *
 * The theme prints the title (the item's name) above this.
 */
?>
<article class="bh-example">
    <p><a href="<?= $e($base) ?>/example">&larr; All items</a></p>
    <p><strong><?= $e(number_format($item['price'], 2)) ?></strong></p>
    <p><?= $e($item['summary']) ?></p>
</article>
