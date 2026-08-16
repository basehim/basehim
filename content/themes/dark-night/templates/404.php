<?php $partial('header'); ?>

<div class="dn-404">
    <div class="dn-stars"></div>
    <div class="dn-moon"><i class="fa-solid fa-cloud-moon"></i></div>
    <h1>404</h1>
    <p style="font-size:1.25rem;">Lost in the dark</p>
    <p class="dim"><?= htmlspecialchars($message ?? "We couldn't find what you were looking for.") ?></p>
    <div class="dn-404-actions">
        <a href="<?= $base ?>/" class="dn-btn dn-btn-gold"><i class="fa-solid fa-house"></i> Home</a>
        <a href="<?= $base ?>/search" class="dn-btn dn-btn-ghost"><i class="fa-solid fa-magnifying-glass"></i> Search</a>
    </div>
</div>

<?php $partial('footer'); ?>
