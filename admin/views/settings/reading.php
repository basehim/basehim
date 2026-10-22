<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
require_once __DIR__ . '/_ui.php';
$ht = $values['homepage_type'] ?? 'posts';
$sm = (string) ($values['search_mode'] ?? 'auto');
$vc = (string) ($values['view_counting'] ?? 'buffered');
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/reading" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= bh_set_e($csrf) ?>">

        <?= bh_set_card_open('Homepage', 'What visitors see first.') ?>
            <?= bh_set_row('Homepage shows', '<select id="set-homepage-type" name="homepage_type" class="bh-select">' . bh_set_options(['posts' => 'Latest posts', 'page' => 'A static page'], $ht) . '</select>', '', ['for' => 'set-homepage-type']) ?>
            <?= bh_set_row('Static homepage', '<input type="text" id="set-homepage-slug" name="homepage_slug" class="bh-input" placeholder="about" value="' . bh_set_e($values['homepage_slug'] ?? '') . '">', 'The address of the page to show, without slashes.', ['for' => 'set-homepage-slug']) ?>
            <?= bh_set_row('Posts per page', '<input type="number" id="set-posts-per-page" name="posts_per_page" min="1" max="100" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['posts_per_page'] ?? 10) . '">', '', ['for' => 'set-posts-per-page']) ?>
            <?= bh_set_row('Items in feeds', '<input type="number" id="set-feed-items" name="feed_items" min="1" max="50" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['feed_items'] ?? 10) . '">', 'How many recent posts the RSS feed lists.', ['for' => 'set-feed-items']) ?>
            <?php /* The hidden 0 is what an unticked box sends. Without it the
                     setting was never saved as off: once ticked, it could not
                     be unticked. */ ?>
            <?= bh_set_toggle('discourage_search', !empty($values['discourage_search']), 'Discourage search engines', 'Asks search engines not to index this site. It is up to them to honour it.') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Authors', 'Pages and details about the people who write.') ?>
            <?= bh_set_toggle('author_archives', (string) ($values['author_archives'] ?? '1') !== '0', 'Author archive pages', 'Each author who has published a post gets a page listing their posts, at /author/their-name. The address uses the author\'s display name, never their login name.') ?>
            <?= bh_set_toggle('author_box', (string) ($values['author_box'] ?? '1') !== '0', 'Author box on posts', 'Shows the author\'s name, picture and bio under each post, in themes that support it.') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Search and page views') ?>
            <?= bh_set_row('Search method', '<select id="search_mode" name="search_mode" class="bh-select">' . bh_set_options(['auto' => 'Word index (fast, recommended)', 'like' => 'Text match (finds parts of words; slow on large sites)'], $sm === 'like' ? 'like' : 'auto') . '</select>',
                'The word index finds posts containing every word of three letters or more. Choose text match for languages written without spaces between words.', ['for' => 'search_mode']) ?>
            <?= bh_set_row('Searches per minute, per visitor', '<input id="search_rate_limit" type="number" name="search_rate_limit" min="0" max="1000" class="bh-input max-w-[8rem]" value="' . bh_set_e($values['search_rate_limit'] ?? 30) . '">',
                'For the site\'s search page and the search API. 0 means no limit.', ['for' => 'search_rate_limit']) ?>
            <?= bh_set_row('Page views', '<select id="view_counting" name="view_counting" class="bh-select">' . bh_set_options(['buffered' => 'Count, added up every minute (recommended)', 'direct' => 'Count, updated on every view', 'off' => 'Don\'t count'], $vc) . '</select>',
                'Adding views up every minute keeps a popular post fast under heavy traffic; the numbers shown can be a minute behind.', ['for' => 'view_counting']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
