<?php
/**
 * Search widget. Override: templates/widgets/search.php in your theme.
 *
 * @var array    $settings  title, placeholder, button
 * @var array    $context   base (the install's base path)
 * @var callable $e         escapes text for HTML
 */
$placeholder = $settings['placeholder'] !== '' ? $settings['placeholder'] : 'Search…';
$button = $settings['button'] !== '' ? $settings['button'] : 'Search';
?>
<form class="widget-search" method="get" action="<?= $e($context['base'] . '/search') ?>" role="search"><input type="search" name="q" placeholder="<?= $e($placeholder) ?>" aria-label="Search"><button type="submit"><?= $e($button) ?></button></form>
