<?php
/**
 * HTML / Text widget. Override: templates/widgets/html.php in your theme.
 *
 * @var array    $settings  title, html (printed as written: only people with
 *                          the unfiltered_html capability can change it)
 * @var callable $e         escapes text for HTML
 */
?>
<div class="widget-html"><?= $settings['html'] ?></div>
