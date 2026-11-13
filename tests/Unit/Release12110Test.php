<?php

declare(strict_types=1);

/*
 * 1.2.110 — Sticky table chrome. The controls and the column header stick under
 * the app's top bar, the pagination sticks to the bottom and is compacted, the
 * controls shrink once stuck, and a page change scrolls back to the first row.
 */

function r12110_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Data table: sticky controls, header and pagination (CSS, gated by a class)', function () {
    $css = r12110_file('admin/assets/css/src/datatable.css');
    // The controls region is always positioned (so the popover anchors) and
    // becomes sticky under the measured top-bar offset only when enabled.
    assert_contains('.bh-dt-top { @apply relative; }', $css);
    assert_contains('position: sticky; top: var(--dt-sticky-top);', $css);
    assert_contains('.bh-dt--sticky .bh-dt-top.is-stuck', $css, 'a stuck state to shrink the controls');
    // Column header sticks just under the controls (offset tracks their height).
    assert_contains('.bh-dt--sticky .bh-dt-table thead th { @apply bh-glass; position: sticky; top: calc(var(--dt-sticky-top) + var(--dt-top-h));', $css);
    // Pagination sticks to the bottom, under its own toggle class.
    assert_contains('.bh-dt--sticky-foot .bh-dt-foot', $css);
    // The wrap no longer clips, so the sticky header is not trapped.
    assert_contains('.bh-dt-wrap { position: relative; overflow: visible; }', $css);
});

test('Data table: the controls height and stuck state are driven from the script', function () {
    $js = r12110_file('admin/assets/js/datatable.js');
    assert_contains('stickyChrome', $js);
    assert_contains('--dt-top-h', $js, 'the header offset tracks the measured shrunk controls height');
    assert_contains("classList.toggle('is-stuck'", $js, 'the controls are flagged stuck on scroll');
    assert_contains('appBarHeight', $js, 'the top-bar offset is measured, not hardcoded');
    assert_contains('scrollToListTop', $js, 'a page change scrolls back to the first row');
});

test('Data table: Screen Options has sticky toggles, on by default', function () {
    $js = r12110_file('admin/assets/js/datatable.js');
    // Both toggles default to true and persist per-browser.
    assert_contains("sticky: lsGet('sticky', true)", $js, 'sticky header on by default');
    assert_contains("stickyFoot: lsGet('stickyFoot', true)", $js, 'sticky pagination on by default');
    assert_contains('stickyRow(', $js, 'the panel builds switch rows');
    assert_contains("el('div', 'bh-dt-panel__label', 'Sticky')", $js, 'under a Sticky heading');
    assert_contains('applySticky', $js, 'toggling applies the gating classes at once');
});

test('Data table: a page change scrolls the list to its first row', function () {
    $js = r12110_file('admin/assets/js/datatable.js');
    // The page buttons call the scroll helper before loading the new page.
    assert_contains('state.page = page; scrollToListTop(); load();', $js);
});
