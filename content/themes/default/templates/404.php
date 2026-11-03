<?php $partial('header'); ?>

<?php
// 1.2.98: the icon sat off-centre at a quarter of its box, and the message
// repeated the heading ("Page not found" twice). The search form is here now,
// so a visitor can look for the page without another click.
$__msg = trim((string) ($message ?? ''));
if ($__msg === '' || strcasecmp($__msg, 'Page not found') === 0) $__msg = "The page you were looking for isn't here. It may have moved, or the address may be mistyped.";
?>
<div class="bht-404">
    <span class="bht-404__icon"><?= icon('map', 'w-8 h-8') ?></span>
    <p class="bht-404__code">404</p>
    <h1 class="bht-404__title">Page not found</h1>
    <p class="bht-404__text"><?= htmlspecialchars($__msg) ?></p>
    <form action="<?= $base ?>/search" method="GET" role="search" class="bht-404__search">
        <input type="search" name="q" placeholder="Search this site" aria-label="Search this site" class="bht-input-touch">
        <button type="submit" class="bht-btn"><?= icon('magnifying-glass', 'w-4 h-4') ?><span>Search</span></button>
    </form>
    <a href="<?= $base ?>/" class="bht-404__home"><?= icon('arrow-left', 'w-4 h-4') ?> Back to the home page</a>
</div>

<?php $partial('footer'); ?>
