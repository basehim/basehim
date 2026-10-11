<?php
/**
 * The AJAX client (Basehim.ajax.request), in <head> so every later script —
 * core, app or theme — can rely on it, like the icon set.
 *
 * Its settings are printed here rather than read from <body> attributes:
 * the fullscreen layout has no data-base, so a subdirectory install would
 * have called /ajax at the domain root.
 *
 * @var string $base
 */
$__ajaxConfig = [
    'url'  => $base . \App\Core\Request::AJAX_PATH,
    'csrf' => \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken(),
];
?>
<script>window.BASEHIM_AJAX = <?= json_encode($__ajaxConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= htmlspecialchars($base, ENT_QUOTES) ?>/admin/assets/js/ajax.js?v=<?= urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') ?>"></script>
