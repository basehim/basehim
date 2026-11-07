<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Settings → Admin bar (1.2.96): the bar signed-in editors and
 * administrators see on the public site. App\Core\AdminBar\AdminBarSettings
 * checks every value; docs/ADMIN-BAR.md explains each one.
 */
use App\Core\AdminBar\AdminBarSettings as S;

require_once __DIR__ . '/_ui.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$b = $bar ?? S::DEFAULTS;
$roles = $roles ?? [];
$select = static fn(string $name, array $opts, $cur, string $id) => '<select id="' . $id . '" name="' . $name . '" class="bh-select max-w-xs">' . bh_set_options($opts, $cur) . '</select>';
$px = static fn(array $list) => array_combine($list, array_map(fn($n) => $n . ' px', $list));
$choices = static function (string $name, array $opts, string $cur, string $labelId) use ($e): string {
    $h = '<div class="bh-choices sm:grid-cols-2" role="radiogroup" aria-labelledby="' . $labelId . '">';
    foreach ($opts as $v => [$label, $help]) {
        $h .= '<label class="bh-choice"><input type="radio" name="' . $name . '" value="' . $e($v) . '"' . ($cur === $v ? ' checked' : '') . '>'
            . '<span class="min-w-0"><span class="bh-choice__title">' . $e($label) . '</span><span class="bh-choice__text">' . $e($help) . '</span></span></label>';
    }
    return $h . '</div>';
};
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/toolbar" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <?= bh_set_card_open('Admin bar', 'A bar on the public site for people who manage it: edit the page you are on, create content, search, see what needs attention. Visitors never see it, and it adds nothing to their pages.') ?>
            <?= bh_set_toggle('enabled', (bool) $b['enabled'], 'Show the admin bar on the site', 'Off: no one sees it, and no admin bar code is sent with any page.') ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text"><span class="bh-set-row__label" id="ab-mode-label">How it is shown</span>
                    <p class="bh-set-row__help">The default for everyone. Users can choose for themselves if you allow it below.</p></div>
                <div class="bh-set-row__control"><?= $choices('mode', [
                    'horizontal' => ['Top bar', 'A slim bar across the top of every page, with dropdown menus. Pages move down to make room.'],
                    'floating'   => ['Floating button', 'A round button in a corner that opens a panel. Takes no space on the page.'],
                ], $b['mode'], 'ab-mode-label') ?></div>
            </div>
            <?= bh_set_row('Floating button position', $select('position', S::POSITIONS, $b['position'], 'ab-position'), 'Where the button sits. Users can drag it to another corner.', ['for' => 'ab-position', 'sub' => true]) ?>
            <?= bh_set_row('On phones', $select('mobile', S::MOBILE, $b['mobile'], 'ab-mobile'), 'Screens 782 px wide or narrower.', ['for' => 'ab-mobile']) ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text"><span class="bh-set-row__label" id="ab-roles-label">Who sees it</span>
                    <p class="bh-set-row__help">None ticked: everyone who can manage something (write posts, upload media, moderate comments, manage the site). Ticked: only these roles.</p></div>
                <div class="bh-set-row__control">
                    <div class="bh-checks" role="group" aria-labelledby="ab-roles-label">
                    <?php foreach ($roles as $slug => $r): ?>
                        <label class="bh-check"><input type="checkbox" class="bh-check__input" name="roles[<?= $e($slug) ?>]" value="1" <?= in_array($slug, $b['roles'], true) ? 'checked' : '' ?>><span class="bh-check__text"><?= $e($r['label']) ?></span></label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Sections', 'What the bar offers. Each link and action is shown only to users allowed to use it.') ?>
            <?= bh_set_toggle('context', (bool) $b['context'], 'This page\'s menu', 'Edit the post, page, category or user being viewed, Quick edit, details, and shortcuts for the home page, search results and missing pages.') ?>
            <?= bh_set_toggle('search', (bool) $b['search'], 'Quick search', 'Search posts, pages, media, users and categories from any page.') ?>
            <?= bh_set_toggle('notifications', (bool) $b['notifications'], 'Notifications', 'Updates, comments waiting for approval, failing scheduled tasks, security warnings and apps\' notices.') ?>
            <?= bh_set_toggle('shortcuts', (bool) $b['shortcuts'], 'Keyboard shortcut', '<kbd>Ctrl</kbd>+<kbd>K</kbd> (<kbd>⌘</kbd>+<kbd>K</kbd> on a Mac) opens quick search.') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Appearance') ?>
            <?= bh_set_row('Colours', $select('color', S::COLORS, $b['color'], 'ab-color'), '', ['for' => 'ab-color']) ?>
            <?= bh_set_row('Spacing', $select('density', S::DENSITIES, $b['density'], 'ab-density'), '', ['for' => 'ab-density']) ?>
            <?= bh_set_row('Menu labels', $select('labels', S::LABELS, $b['labels'], 'ab-labels'), 'Icons only keeps the top bar short; labels are still read out by screen readers.', ['for' => 'ab-labels']) ?>
            <?= bh_set_toggle('branding', (bool) $b['branding'], 'Show the site\'s name', 'In the first menu, next to the home icon.') ?>
            <?= bh_set_toggle('avatar', (bool) $b['avatar'], 'Show the user\'s picture', 'Otherwise an account icon.') ?>
            <?= bh_set_row('Top bar height', $select('height', $px(S::HEIGHTS), $b['height'], 'ab-height'), 'Phones always use 46 px, for touch.', ['for' => 'ab-height']) ?>
            <?= bh_set_row('Floating button size', $select('float_size', $px(S::FLOAT_SIZES), $b['float_size'], 'ab-fab'), '', ['for' => 'ab-fab']) ?>
            <?= bh_set_row('Panel width', $select('panel_width', $px(S::PANEL_WIDTHS), $b['panel_width'], 'ab-panel'), 'The floating panel, and dropdowns that hold live information.', ['for' => 'ab-panel']) ?>
            <?= bh_set_row('Dropdown alignment', $select('align', S::ALIGN, $b['align'], 'ab-align'), '', ['for' => 'ab-align']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Behaviour') ?>
            <?= bh_set_row('Open menus on', $select('open_on', S::OPEN_ON, $b['open_on'], 'ab-open'), 'Menus always close with Escape or a click elsewhere, and work with the arrow keys.', ['for' => 'ab-open']) ?>
            <?= bh_set_row('Hide the top bar', $select('auto_hide', S::AUTO_HIDE, $b['auto_hide'], 'ab-hide'), 'Slides away when not in use and comes back when the pointer nears the top. The page is then not moved down for it.', ['for' => 'ab-hide']) ?>
            <?= bh_set_toggle('remember', (bool) $b['remember'], 'Remember a tucked-away bar', 'When a user tucks the top bar away, it stays that way on the next pages.') ?>
            <div class="bh-set-row bh-set-row--wide">
                <div class="bh-set-row__text"><span class="bh-set-row__label" id="ab-user-label">Users may choose</span>
                    <p class="bh-set-row__help">From the bar's Display menu and their profile. Unticked: everyone gets the settings above.</p></div>
                <div class="bh-set-row__control">
                    <div class="flex flex-col" role="group" aria-labelledby="ab-user-label">
                    <?php foreach (S::USER_OPTIONS as $k => $label): ?>
                        <label class="bh-check"><input type="checkbox" class="bh-check__input" name="user_options[<?= $e($k) ?>]" value="1" <?= in_array($k, $b['user_options'], true) ? 'checked' : '' ?>><span class="bh-check__text"><?= $e($label) ?></span></label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
