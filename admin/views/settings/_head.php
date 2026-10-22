<?php
/**
 * Settings page header (1.2.82). Desktop: "Settings", the section's line and
 * the tabs. Phones: a back link to the Settings home and the section's name
 * as a large title; on the home itself (/admin/settings), the sections as a
 * grouped list instead of General's form.
 *
 * Expects $tab, $base; $settingsHome (bool) on /admin/settings.
 */
$__sections = require __DIR__ . '/_sections.php';
$__cur = $__sections[$tab ?? 'general'] ?? ['label' => 'Settings', 'sub' => 'Configure your site.'];
$__home = !empty($settingsHome);
?>
<header class="bh-set__head">
    <a class="bh-set__back" href="<?= $base ?>/admin/settings"><?= icon('chevron-left', 'w-5 h-5') ?>Settings</a>
    <h1 class="bh-set__title"><span class="bh-set__title-all">Settings</span><span class="bh-set__title-section"><?= htmlspecialchars($__cur['label']) ?></span></h1>
    <p class="bh-set__sub"><?= htmlspecialchars($__cur['sub']) ?></p>
</header>
<?php if ($__home): ?>
<nav class="bh-set-home" aria-label="Settings sections">
    <ul class="bh-set-home__list">
        <?php foreach ($__sections as $__slug => $__s): ?>
        <li class="bh-set-home__item">
            <a class="bh-set-home__link" href="<?= $base ?>/admin/settings/<?= $__slug ?>">
                <span class="bh-set-home__icon" aria-hidden="true"><?= icon($__s['icon'], 'w-5 h-5') ?></span>
                <span class="bh-set-home__name"><?= htmlspecialchars($__s['label']) ?></span>
                <span class="bh-set-home__chev" aria-hidden="true"><?= icon('chevron-right', 'w-4 h-4') ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
<?php $this->include('settings._nav', ['tab' => $tab ?? '', 'base' => $base]); ?>
