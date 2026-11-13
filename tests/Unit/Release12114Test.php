<?php

declare(strict_types=1);

/*
 * 1.2.114 — Wide-table mode + corrected mobile side-nav backdrop.
 *
 *  1. Wide-table mode: when the table overflows its wrap (too many columns),
 *     the script flags .bh-dt--xscroll and the wrap becomes a self-contained
 *     scroll box — the header row freezes to its top and the leading identity
 *     columns (select cell + up to the title column) freeze to its left, at
 *     measured cumulative offsets.
 *  2. Mobile side-nav backdrop: the sticky controls/header/pagination sit
 *     ABOVE the table rows but BELOW the admin shell (backdrop z-15, sidebar
 *     z-20), so the nav backdrop dims them with no z-toggle and no jerk —
 *     replacing 1.2.113's bh-nav-open/z-0 approach, which let the header paint
 *     under its own rows.
 */

function r12114_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Backdrop: the sticky chrome sits below the admin shell, not above it', function () {
    $css = r12114_file('admin/assets/css/src/datatable.css');
    // Low single-digit z-indexes — above rows (z-0), below the backdrop (15)
    // and sidebar (20), so the mobile nav dims them.
    assert_contains('.bh-dt--sticky .bh-dt-top { @apply z-[6] bg-white bh-glass;', $css);
    assert_contains('z-index: 4;', $css, 'sticky header z below the shell');
    assert_contains('.bh-dt--sticky-foot .bh-dt-foot { @apply z-[5] bg-white bh-glass;', $css);
    // The replaced, regressing approach is gone from both layers.
    assert_false(str_contains($css, 'bh-nav-open'), 'no bh-nav-open override remains in the CSS');
    $layout = r12114_file('admin/views/layouts/app.php');
    assert_false(str_contains($layout, 'bh-nav-open'), 'no bh-nav-open class toggle remains in the layout');
});

test('Wide-table: the script flags overflow and bounds the scroll box', function () {
    $js = r12114_file('admin/assets/js/datatable.js');
    assert_contains("classList.toggle('bh-dt--xscroll', overflow)", $js);
    assert_contains('r.table.scrollWidth - r.wrap.clientWidth', $js, 'overflow is measured, not assumed');
    assert_contains("setProperty('--dt-grid-max'", $js, 'the box is bounded to the viewport');

    $css = r12114_file('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt--xscroll .bh-dt-wrap { overflow: auto; max-height: var(--dt-grid-max', $css);
    assert_contains('.bh-dt--xscroll .bh-dt-table thead th { position: sticky; top: 0;', $css);
});

test('Wide-table: the leading identity columns freeze at measured offsets', function () {
    $js = r12114_file('admin/assets/js/datatable.js');
    // Freeze through the primary (title) column, capped near the front.
    assert_contains('function frozenCount()', $js);
    assert_contains("cardSlot(columns[i]) === 'title'", $js);
    // Cumulative left offsets set per frozen cell.
    assert_contains("setProperty('--dt-frzL-' + k", $js);

    $css = r12114_file('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt--xscroll .bh-dt-table .bh-dt-frz { position: sticky; z-index: 2; }', $css);
    assert_contains('.bh-dt--xscroll .bh-dt-table .bh-dt-frz--0 { left: var(--dt-frzL-0', $css);
    assert_contains('.bh-dt--xscroll .bh-dt-table thead .bh-dt-frz { z-index: 4; }', $css, 'frozen header corner above both');
});
