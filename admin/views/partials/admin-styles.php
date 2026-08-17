<?php
/**
 * Stylesheets every admin screen needs.
 *
 * Companion to admin-scripts.php. The media picker's styles belong here for
 * the same reason its script does: any layout that can open the picker has to
 * carry them, and a layout that forgets renders an unstyled modal rather than
 * a visibly broken one — which is harder to notice and harder to diagnose.
 *
 * @var string $base
 */
$__v = urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1');
?>
<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/media-picker.css?v=<?= $__v ?>">
