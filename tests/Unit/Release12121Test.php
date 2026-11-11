<?php

declare(strict_types=1);

/*
 * 1.2.121 — The admin header: search, New, View site, notifications, account.
 */

function r12121(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('admin header: one bar with search, New, View site, the bell and the account', function () {
    $layout = r12121('admin/views/layouts/app.php');
    assert_contains('<header class="bh-top sticky top-0 z-10" data-bh-top data-ctx=', $layout, 'still .bh-main > header for the data table');
    assert_contains("AdminBarContext::make(['type' => 'admin', 'path' => \$__barPath])->descriptor(\$csrfToken)", $layout, 'signed descriptor');
    assert_contains('class="bh-top-search" data-bh-search-open', $layout);
    assert_contains('aria-keyshortcuts="Control+K Meta+K"', $layout);
    assert_contains('<dialog id="bh-cmdk" class="bh-dialog bh-dialog--lg bh-cmdk"', $layout, 'built on the core dialog');
    assert_contains('data-bh-notify', $layout);
    assert_contains('class="bh-notify__search"', $layout, 'phones reach search from the bell');
    assert_contains("<?php if (\$can('manage_settings')): ?>", $layout, 'Settings only for those who may open it');
    assert_contains('/admin/assets/js/topbar.js', $layout);
    assert_false(str_contains($layout, '>Create</span>'), 'Create became New');
    assert_contains('[data-bh-menu-btn] > svg:last-child', $layout, 'only a caret turns, never the bell\'s count');
});

test('admin header: components in the compiled sheet, nothing inline', function () {
    $src = r12121('admin/assets/css/src/tailwind.css');
    foreach (['.bh-kbd {', '.bh-top {', '.bh-top-search {', '.bh-top-badge {', '.bh-top-panel {', '.bh-top-item {', '.bh-notify {', '.bh-cmdk.bh-dialog {', '.bh-cmdk__item[aria-selected="true"]'] as $sel) {
        assert_contains($sel, $src);
    }
    assert_true(strpos($src, '.bh-top {') < strpos($src, '@tailwind utilities;'), 'components before utilities');
    $css = r12121('admin/assets/css/tailwind.min.css');
    foreach (['.bh-top-search{', '.bh-cmdk__item', '.bh-notify__item', '.bh-top-badge'] as $n) assert_contains($n, $css);
    $safe = r12121('tailwind.safelist.js');
    foreach (['"z-30"', '"w-48"', '"sm:gap-3"', '"md:inline"'] as $u) assert_contains($u, $safe, 'utilities only the old header used stay available to apps');
});

test('admin header: the script uses the gateway, text nodes and icons the set has', function () {
    $js = r12121('admin/assets/js/topbar.js');
    assert_contains("request('admin.bar_search', { ctx: ctx, q: q }, { method: 'GET'", $js);
    assert_contains("request('admin.bar_notifications', { ctx: ctx }, { method: 'GET' })", $js);
    assert_false(str_contains($js, 'fetch('), 'Basehim.ajax only');
    assert_false((bool) preg_match('/innerHTML\s*+=\s*+(?!I\()/', $js), 'innerHTML only for icons');
    assert_contains("aria-activedescendant", $js);
    $icons = r12121('admin/assets/js/icons.js');
    foreach (['bell', 'chevron-down', 'newspaper', 'user-circle', 'chat-bubble-left-right', 'arrow-turn-down-left'] as $n) {
        assert_contains('"' . $n . '":', $icons);
    }
});

test('admin header: the server accepts the admin descriptor for search and notifications only', function () {
    $a = r12121('app/Http/Controllers/Ajax/AdminBarActions.php');
    assert_contains('$this->begin($ctx, true);', $a);
    assert_same(2, substr_count($a, '$this->begin($ctx, true);'), 'search and notifications, nothing else');
    assert_contains("if (!\$adminHeader && \$context->type() === 'admin') throw AjaxException::notFound(", $a);
    assert_contains("\$context->type() !== 'admin' && !\$this->settings()->effective(\$user)['search']", $a);
});
