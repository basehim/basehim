<?php

declare(strict_types=1);

/*
 * 1.2.111 — Sticky data-table fixes (from install feedback on 1.2.110):
 *  1. Column header was hidden because the layout wrapped the table in an
 *     overflow-x scroll container; the Data Table now opts out.
 *  2. Screen Options popover scrolls (measured max-height) instead of hiding
 *     its lower items.
 *  3. The controls region is always positioned, so the popover anchors to it
 *     with sticky off (it no longer drops to the bottom of the table).
 *  4. The sticky header offset is a measured constant, so it does not jump as
 *     the controls shrink.
 *  5/6. Row-count and page changes scroll back to the first row.
 *  7. On phones the sticky controls have no rounded top and sit below the bar.
 */

function r12111_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Sticky #1: the Data Table opts out of the layout\'s table-scroll wrapper', function () {
    $layout = r12111_file('admin/views/layouts/app.php');
    // wrapTables skips the data table, so its sticky header is not trapped in an
    // overflow-x container.
    assert_contains("t.classList.contains('bh-dt-table') || t.closest('.bh-dt-wrap')", $layout);
});

test('Sticky #2: the Screen Options popover is capped to the viewport and scrolls', function () {
    $css = r12111_file('admin/assets/css/src/datatable.css');
    assert_contains('max-height: var(--dt-panel-max,', $css, 'a measured max-height with a fallback');
    assert_contains('overflow-y: auto', $css);
    $js = r12111_file('admin/assets/js/datatable.js');
    assert_contains("setProperty('--dt-panel-max'", $js, 'the panel measures the space below it on open');
});

test('Sticky #3: the controls are always positioned so the popover anchors with sticky off', function () {
    $css = r12111_file('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-top { @apply relative; }', $css);
});

test('Sticky #4/7: offsets are measured (not hardcoded), and the controls are flat on phones', function () {
    $js = r12111_file('admin/assets/js/datatable.js');
    // The top-bar height is read from the real sticky header; the shrunk
    // controls height is measured once so the header offset never jumps.
    assert_contains('appBarHeight', $js);
    assert_contains("getComputedStyle(hdr).position === 'sticky'", $js, 'measures the real top bar');
    assert_contains('bh-dt-measuring', $js, 'measures the shrunk height with transitions suppressed');
    assert_false(str_contains($js, "rootMargin: '-65px"), 'no hardcoded 65px offset remains');

    $css = r12111_file('admin/assets/css/src/datatable.css');
    // No rounded top on the stuck controls at phone/tablet widths.
    assert_contains('.bh-dt--sticky .bh-dt-top { border-top-left-radius: 0; border-top-right-radius: 0; }', $css);
});
