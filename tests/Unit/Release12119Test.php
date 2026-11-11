<?php

declare(strict_types=1);

/*
 * 1.2.119 — Dashboard welcome card: Customize is an icon button (a page
 * setting, quieter than the everyday actions); on phones New post and Upload
 * split the row in equal halves and Customize sits in the card's corner.
 * It stays inside the actions container, so edit mode still hides it.
 */

function r12119(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('dashboard: Customize is an icon button that stays inside the actions row', function () {
    $view = r12119('admin/views/dashboard/index.php');
    assert_contains('class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--lg bh-dash-head__customize" data-bh-dash-customize aria-label="Customize dashboard"', $view);
    $actions = substr($view, strpos($view, 'data-bh-dash-view-actions'), 2000);
    assert_contains('data-bh-dash-customize', $actions, 'inside the container edit mode hides');
});

test('dashboard phones: equal halves, Customize in the corner, title kept clear', function () {
    $css = r12119('admin/assets/css/src/tailwind.css');
    assert_contains('grid-template-columns: repeat(auto-fit, minmax(0, 1fr));', $css);
    assert_contains('.bh-dash-head__actions .bh-dash-head__customize { @apply absolute right-3 top-3 w-9 px-0; }', $css);
    assert_contains('.bh-dash-head:has(.bh-dash-head__customize) .bh-dash-head__hello { @apply pr-9; }', $css);
    assert_contains('.bh-dash-head__customize', r12119('admin/assets/css/tailwind.min.css'));
});

/*
 * 1.2.119 — Sidebar refresh (R1).
 */
test('sidebar: one 36px item height, one active marker, accent tokens', function () {
    $layout = r12119('admin/views/layouts/app.php');
    assert_contains('gap-3 h-9 px-3 rounded-lg', $layout);
    assert_false(str_contains($layout, "'bg-blue-50 text-blue-700"), 'nav uses the accent token');
    assert_false(str_contains($layout, 'a.bh-nav-item.bh-active::before'), 'no second (edge bar) marker');
    $css = r12119('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-nav-sep { @apply hidden; }', $css, 'headings alone separate sections');
    assert_contains('.bh-sidebar.is-collapsed .bh-nav-sep { @apply mx-3 my-2 block h-px bg-slate-200; }', $css);
});

test('sidebar: sub-items on a guide line, the current one marked and announced', function () {
    $layout = r12119('admin/views/layouts/app.php');
    assert_contains("<?= \$chActive ? ' aria-current=\"page\"' : '' ?>", $layout);
    assert_false(str_contains($layout, "w-1 h-1 rounded-full"), 'no dots');
    $css = r12119('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-submenu { @apply ml-[1.375rem] border-l border-slate-200; }', $css);
    assert_contains('.bh-submenu > a[aria-current="page"]', $css);
});

test('sidebar search: core size, "/" hint, and a sub-item match shows only that sub-item', function () {
    $css = r12119('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-search-input { @apply h-9 w-full rounded-xl', $css);
    assert_contains('.bh-search-input:focus ~ .bh-search-kbd', $css);
    $layout = r12119('admin/views/layouts/app.php');
    assert_contains('<kbd class="bh-search-kbd"', $layout);
    assert_contains("el.classList.toggle('is-search-open', !!q && match);", $layout);
    assert_contains("var hit = !q || headHit || a.textContent.toLowerCase().indexOf(q) >= 0;", $layout);
});

test('sidebar: collapsed rail keeps counts as a dot; footer is one row; address is View site', function () {
    $layout = r12119('admin/views/layouts/app.php');
    assert_contains('<span class="bh-nav-badge" data-bh-badge=', $layout, 'no longer hidden when collapsed');
    assert_false(str_contains($layout, '<span>View Site</span>'), 'the footer View Site row is gone');
    assert_contains('class="bh-site-addr" title="View site — opens in a new tab"', $layout);
    assert_contains('<a href="<?= $base ?>/admin" class="shrink-0" title="Dashboard">', $layout, 'the logo goes home');
    assert_contains('class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm" title="Collapse sidebar (Ctrl+B)"', $layout);
    $css = r12119('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-sidebar.is-collapsed .bh-nav-badge:not([hidden]) { @apply absolute right-1.5 top-1.5 h-2 w-2', $css);
    assert_contains('.bh-sidebar-foot { @apply flex items-center gap-1 border-t', $css);
    // the moved rules no longer live inline in the layout
    assert_false(str_contains($layout, '.bh-search-input {'), 'search styles moved to core components');
    assert_false(str_contains($layout, '.bh-foot-card {'), 'footer styles moved to core components');
});
