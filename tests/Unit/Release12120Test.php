<?php

declare(strict_types=1);

/*
 * 1.2.120 — Sidebar footer status in words; Settings menu from one list.
 */

function r12120(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('footer: the update status is in words beside a tile as tall as both lines', function () {
    $layout = r12120('admin/views/layouts/app.php');
    assert_contains("\$__updN . ' update' . (\$__updN === 1 ? '' : 's') . ' available'", $layout);
    assert_contains('<span data-bh-update-text><?= $__pillText ?></span>', $layout, 'visible, not screen-reader only');
    assert_contains("n + ' update' + (n === 1 ? '' : 's') + ' available'", $layout, 'the live refresh says the same');
    $css = r12120('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-foot-mark { @apply grid h-8 w-8', $css, '32px tile');
    assert_contains('.bh-foot-ver { @apply truncate text-xs leading-4', $css, 'two 16px lines');
    assert_contains('.bh-foot-pill { @apply flex min-w-0 items-center gap-1.5 text-[0.6875rem] font-medium leading-4', $css);
});

test('settings: one list feeds the tabs, the phone home and the sidebar sub-menu', function () {
    $sections = require dirname(__DIR__, 2) . '/admin/views/settings/_sections.php';
    assert_true(($sections['appearance']['menu'] ?? true) === false, 'Appearance is kept (its link goes on to Customize) but not listed');
    $layout = r12120('admin/views/layouts/app.php');
    assert_contains("['url' => '/admin/settings', 'label' => 'Settings'", $layout, 'phones open the Settings home');
    assert_contains("require __DIR__ . '/../settings/_sections.php'", $layout, 'the sub-menu comes from the same list');
    assert_false(str_contains($layout, "['url' => '/admin/settings/authorization', 'label' => 'Authentication'"), 'no hand-kept copy');
    assert_contains("\$ch['url'] === '/admin/settings/general'", $layout, 'General reads as current on /admin/settings');
    assert_contains("(\$t['menu'] ?? true) !== false", r12120('admin/views/settings/_nav.php'));
    assert_contains("if ((\$__s['menu'] ?? true) === false) continue;", r12120('admin/views/settings/_head.php'));
});
