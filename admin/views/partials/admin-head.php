<?php
/**
 * The admin <title>, robots and favicon, shared by every admin layout (1.2.50).
 *
 *   <title>  "{Page} - {Site name} - Basehim"; the dashboard is "Admin Dashboard".
 *   robots   noindex, nofollow — the admin is never for search engines (the
 *            response also carries X-Robots-Tag; see Router::emit()).
 *   favicon  the site's own icon (Customizer → Site identity → Site icon) when
 *            one is set, otherwise the Basehim mark.
 *
 * See App\Core\AdminHead.
 *
 * @var string|null $title
 * @var string      $base
 */
$__base = (string) ($base ?? (defined('BASEHIM_BASE') ? BASEHIM_BASE : ''));
$__icon = \App\Core\AdminHead::favicon($__base);
?>
    <title><?= htmlspecialchars(\App\Core\AdminHead::title($title ?? null)) ?></title>
    <meta name="robots" content="noindex, nofollow">
<?php if ($__icon !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($__icon, ENT_QUOTES) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($__icon, ENT_QUOTES) ?>">
<?php else: ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($__base) ?>/admin/assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($__base) ?>/admin/assets/img/apple-touch-icon.png">
<?php endif; ?>
