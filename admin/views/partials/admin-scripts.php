<?php
/**
 * Assets every admin screen needs, in one place.
 *
 * Included by both admin layouts. Before this existed the list was duplicated
 * in each, and the Customizer's layout was written without media.js — so the
 * media picker was simply undefined on that screen and the Choose button did
 * nothing. Anything shared belongs here so a new layout cannot forget it.
 *
 * The media picker in particular is not optional: it is used by the post
 * editor, the Customizer, widget settings and any app that asks for an image.
 *
 * @var string $base
 */
$__v = urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1');
// The main layout loads the icon set in <head> already, so it asks for it to be
// skipped here rather than defining the whole set a second time.
$__skipIcons = !empty($skipIcons);
?>
<?php if (!$__skipIcons): ?>
<script src="<?= $base ?>/admin/assets/js/icons.js?v=<?= $__v ?>"></script>
<?php endif; ?>
<script src="<?= $base ?>/admin/assets/js/media.js?v=<?= $__v ?>"></script>
