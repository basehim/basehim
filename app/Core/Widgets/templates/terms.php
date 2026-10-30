<?php
/**
 * Categories and Tags widgets. Override: templates/widgets/categories.php or
 * templates/widgets/tags.php in your theme.
 *
 * @var array    $data      taxonomy ('category' | 'tag'), display ('list' |
 *                          'dropdown' | 'cloud'), show_counts, select_id, and
 *                          terms: list of [id, name, url, count, depth, size (em)]
 * @var callable $e         escapes text for HTML
 */
$terms = $data['terms'];
$counts = $data['show_counts'];
$list = '<ul class="widget-terms widget-terms--' . $e($data['taxonomy']) . ($counts ? ' widget-terms--counts' : '') . '">';
foreach ($terms as $t) {
    $child = $t['depth'] > 0 ? ' class="widget-terms__child" style="--bh-depth:' . (int) $t['depth'] . '"' : '';
    $list .= '<li' . $child . '><a href="' . $e($t['url']) . '">' . $e($t['name']) . '</a>'
        . ($counts ? '<span class="widget-count">' . (int) $t['count'] . '</span>' : '') . '</li>';
}
if (!$terms) $list .= '<li class="widget-empty">Nothing yet.</li>';
$list .= '</ul>';

if ($data['display'] === 'cloud' && $terms): ?>
<p class="widget-term-cloud widget-term-cloud--<?= $e($data['taxonomy']) ?>"><?php foreach ($terms as $t): ?><a href="<?= $e($t['url']) ?>" style="font-size:<?= $e(rtrim(rtrim(number_format($t['size'], 2, '.', ''), '0'), '.')) ?>em"><?= $e($t['name']) ?><?php if ($counts): ?> <span class="widget-count"><?= (int) $t['count'] ?></span><?php endif; ?></a> <?php endforeach; ?></p>
<?php elseif ($data['display'] === 'dropdown' && $terms):
    $label = 'Select ' . $data['noun']; ?>
<div class="widget-terms-select"><label for="<?= $e($data['select_id']) ?>" class="bh-visually-hidden"><?= $e($label) ?></label><select id="<?= $e($data['select_id']) ?>" data-bh-term-nav><option value=""><?= $e($label) ?></option><?php foreach ($terms as $t): ?><option value="<?= $e($t['url']) ?>"><?= $e(str_repeat('— ', $t['depth']) . $t['name']) ?><?= $counts ? ' (' . (int) $t['count'] . ')' : '' ?></option><?php endforeach; ?></select><noscript><?= $list ?></noscript></div>
<?php else: ?>
<?= $list ?>
<?php endif;
