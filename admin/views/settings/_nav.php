<?php
/**
 * Settings tabs — rendered through the shared tab component so Settings, API
 * and System all look and behave the same.
 * Expects $tab (active slug) and $base in scope.
 */
$this->include('partials.tabs', [
    'base'      => $base,
    'active'    => $tab ?? '',
    'urlPrefix' => '/admin/settings/',
    'ariaLabel' => 'Settings sections',
    'tabs'      => array_map(static fn($t) => ['label' => $t['label'], 'icon' => $t['icon']], require __DIR__ . '/_sections.php'),
]);
?>
<script src="<?= htmlspecialchars((string) ($base ?? ''), ENT_QUOTES) ?>/admin/assets/js/settings-save.js?v=<?= urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') ?>" defer></script>
<script src="<?= htmlspecialchars((string) ($base ?? ''), ENT_QUOTES) ?>/admin/assets/js/settings-nav.js?v=<?= urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') ?>" defer></script>
