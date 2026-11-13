<?php

declare(strict_types=1);

/*
 * 1.2.115 — Data table design audit + core component consistency.
 *
 *  - Core: one-line fields are exactly button height (36px); .bh-seg takes
 *    <button aria-pressed> / <a aria-current> segments and an --icon variant;
 *    the build safelists the runtime classes so rebuilding core is safe.
 *  - The table's one-off controls (bulk pills, pager, view switch, ⋮, drawer
 *    close, sort direction, term Clear, Quick Edit close) are core components.
 *  - Phones: the controls are an edge-to-edge app bar with 10px top/bottom
 *    padding (they touched the top nav when stuck); the stuck footer is one row.
 *  - Desktop: the Views column no longer shares .bh-dt-views with the view
 *    switch; dates and slugs stay on one line; one-page lists show no pager.
 *  - Wide grid: detection measures the normal layout, so the mode turns off again.
 */

function r12115(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('core: one-line fields are button height, and segments take buttons and links', function () {
    $tw = r12115('admin/assets/css/src/tailwind.css');
    assert_contains('.bh-input, .bh-select { @apply h-9 py-0; }', $tw);
    assert_contains('.bh-select[multiple], .bh-select[size]:not([size="1"]) { @apply h-auto py-2; }', $tw, 'list boxes keep their height');
    assert_contains('.bh-seg > button[aria-pressed="true"], .bh-seg > a[aria-current="page"]', $tw);
    assert_contains('.bh-seg--icon > button, .bh-seg--icon > a { @apply w-8 px-0; }', $tw);
    $min = r12115('admin/assets/css/tailwind.min.css');
    assert_contains('.bh-seg>button', $min, 'the compiled core bundle carries the new segments');
});

test('core build: runtime classes are safelisted, so a rebuild keeps them', function () {
    $cfg = r12115('tailwind.config.js');
    assert_contains("safelist: require('./tailwind.safelist.js')", $cfg);
    $list = r12115('tailwind.safelist.js');
    assert_true(substr_count($list, "\n    \"") >= 500, 'the 516 runtime classes are listed');
    $min = r12115('admin/assets/css/tailwind.min.css');
    foreach (['.bg-amber-200', '.bg-emerald-900', '.-mb-px'] as $cls) {
        assert_contains($cls, $min, "$cls survives the rebuild");
    }
});

test('table controls are core components, not one-offs', function () {
    $js = r12115('admin/assets/js/datatable.js');
    assert_contains("'bh-btn bh-btn--sm bh-btn--' + tone + ' bh-dt-bulkbtn'", $js, 'bulk actions');
    assert_contains("{ publish: 'primary', neutral: 'secondary', danger: 'danger' }", $js);
    assert_contains("'bh-btn bh-btn--sm bh-dt-page '", $js, 'pager');
    assert_contains("'bh-btn bh-btn--ghost bh-btn--sm bh-dt-filterclear'", $js, 'term filter Clear (was the round bulk-close class)');
    assert_contains("'bh-seg bh-seg--icon bh-dt-sortdir'", $js, 'sort direction');
    assert_contains("'bh-btn bh-btn--ghost bh-btn--icon bh-dt-drawer__x'", $js);
    $partial = r12115('admin/views/partials/datatable.php');
    assert_contains('class="bh-seg bh-seg--icon bh-dt-views"', $partial, 'view switch');
    assert_contains('aria-pressed="false"', $partial);
    assert_contains('bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm bh-dt-bulkclear', $partial);
    $css = r12115('admin/assets/css/src/datatable.css');
    assert_false(str_contains($css, '.bh-dt-bulkbtn--danger'), 'no tinted one-off delete');
    assert_false(str_contains($css, '.bh-dt-viewbtn.is-active'), 'no one-off view switch styles');
    $list = r12115('admin/views/posts/index.php');
    assert_contains('<nav class="bh-seg" aria-label="Show">', $list, 'All / Trash');
});

test('phones: controls are an app bar with even 10px padding; the stuck footer is one row', function () {
    $css = r12115('admin/assets/css/src/datatable.css');
    assert_false(str_contains($css, 'padding: 0 0 .75rem !important'), 'the zero top padding is gone');
    assert_contains('padding-top: .625rem !important; padding-bottom: .625rem !important;', $css);
    assert_contains('@apply -mx-4 mb-3 bg-slate-50 px-4 supports-[backdrop-filter]:bg-slate-50/80;', $css, 'edge to edge across the p-4 gutter');
    // (40px phone controls became the core 36px in 1.2.117, by request.)
    assert_contains('.bh-dt-count__lead, .bh-dt-count__noun { display: none; }', $css);
    assert_contains('padding-bottom: calc(.5rem + env(safe-area-inset-bottom));', $css);
    assert_contains('.bh-dt-card .bh-dt-check { padding: .75rem; margin: -.75rem;', $css, 'tick boxes get a ~42px target');
    $js = r12115('admin/assets/js/datatable.js');
    assert_contains("el('label', 'bh-dt-check bh-dt-card__check')", $js, 'the hit area is a <label>, so it toggles');
});

test('desktop: Views column class, one-line dates and slugs, no lone pager', function () {
    $js = r12115('admin/assets/js/datatable.js');
    assert_contains("cls += ' bh-dt-col-views'", $js);
    assert_false(str_contains($js, "cls += ' bh-dt-views'"), 'the column no longer shares the view switch class');
    assert_contains('r.pages.hidden = (m.last_page || 1) <= 1;', $js);
    assert_contains("if (!total) return 'No ' + noun;", $js);
    $css = r12115('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-table .bh-dt-date { white-space: nowrap; }', $css);
    assert_contains('-webkit-line-clamp: 1;', $css, 'slug clamps to one line without setting the column width');
    assert_contains('.bh-dt-table .bh-dt-check input { display: block; margin: 0; }', $css);
    assert_contains('@container dttoolbar (max-width: 54rem)', $css, 'narrow toolbars go icon-only before wrapping');
});

test('wide grid: detection measures the normal layout, so the mode can turn off', function () {
    $js = r12115('admin/assets/js/datatable.js');
    assert_contains("if (was) root.classList.remove('bh-dt--xscroll');", $js);
    assert_contains("w.classList.toggle('has-more-right'", $js);
    $css = r12115('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt--xscroll .bh-dt-table td:not(.bh-dt-frz) { white-space: nowrap; }', $css);
});
