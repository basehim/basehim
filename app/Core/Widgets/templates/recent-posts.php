<?php
/**
 * Recent Posts widget. Override: templates/widgets/recent-posts.php in your theme.
 *
 * @var array    $settings  the saved options (count, order, show_thumbnail, …)
 * @var array    $data      posts: list of [title, url, image (an <img> tag or ''),
 *                          date, date_iso, excerpt]; rich, thumbs, size, shape
 * @var callable $e         escapes text for HTML
 */
$cls = 'widget-recent-posts';
if ($data['rich']) {
    $cls .= ' widget-recent-posts--rich';
    if ($data['thumbs']) $cls .= ' widget-recent-posts--thumbs widget-recent-posts--' . $data['size'] . ' widget-recent-posts--shape-' . $data['shape'];
}
?>
<ul class="<?= $e($cls) ?>"><?php if (!$data['posts']): ?><li class="widget-empty">No posts yet.</li><?php endif;
foreach ($data['posts'] as $p):
    if (!$data['rich']): ?><li><a href="<?= $e($p['url']) ?>"><?= $e($p['title']) ?></a></li><?php continue; endif; ?>
<li class="widget-recent-posts__item"><?php if ($data['thumbs']): ?><a class="widget-recent-posts__thumb" href="<?= $e($p['url']) ?>" tabindex="-1" aria-hidden="true"><?= $p['image'] !== '' ? $p['image'] : '<span class="widget-recent-posts__noimg"></span>' ?></a><?php endif; ?><div class="widget-recent-posts__body"><a class="widget-recent-posts__title" href="<?= $e($p['url']) ?>"><?= $e($p['title']) ?></a><?php if ($p['date'] !== ''): ?><time class="widget-recent-posts__date" datetime="<?= $e($p['date_iso']) ?>"><?= $e($p['date']) ?></time><?php endif; ?><?php if ($p['excerpt'] !== ''): ?><p class="widget-recent-posts__excerpt"><?= $e($p['excerpt']) ?></p><?php endif; ?></div></li>
<?php endforeach; ?></ul>
