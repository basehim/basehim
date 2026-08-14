<?php
/**
 * Shared section tab bar (Settings, API, System …).
 *
 * Keeps every section's tabs identical instead of three near-copies drifting
 * apart. Sticks under the header while scrolling so the active section is
 * always visible, and scrolls the active tab into view on narrow screens.
 *
 * Expects:
 *   $tabs   array  key => ['label' => string, 'icon' => string, 'url' => string]
 *                  `url` may be omitted when $urlPrefix is given.
 *   $active string  key of the current tab
 *   $base   string  install base path (shared with every view)
 *   $urlPrefix string|null  e.g. '/admin/settings/' — url becomes prefix.key
 *   $ariaLabel string|null
 */
$tabs      = $tabs ?? [];
$active    = (string) ($active ?? '');
$urlPrefix = $urlPrefix ?? null;
?>
<nav class="bh-tabs" id="bh-tabs" aria-label="<?= htmlspecialchars($ariaLabel ?? 'Section') ?>">
    <?php foreach ($tabs as $key => $t):
        $url = $t['url'] ?? (($urlPrefix !== null) ? $urlPrefix . $key : '#');
        $isActive = $active === (string) $key;
    ?>
    <a href="<?= $base . htmlspecialchars($url) ?>"
       class="bh-tab<?= $isActive ? ' is-active' : '' ?>"
       <?= $isActive ? 'aria-current="page"' : '' ?>>
        <?php if (!empty($t['icon'])): ?><?= icon($t['icon'], 'w-4 h-4') ?><?php endif; ?>
        <?= htmlspecialchars($t['label'] ?? (string) $key) ?>
    </a>
    <?php endforeach; ?>
</nav>
