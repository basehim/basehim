<?php

declare(strict_types=1);

/*
 * 1.2.108 — Data table mobile refinements on top of 1.2.107. One compact
 * toolbar row (select all · search · filter & sort · screen options), a search
 * that expands on focus, Screen Options reachable again, a smoother drawer, a
 * skeleton with a minimum visible time, standard 14/13px type, and a fix for
 * the sort "Default order" control snapping back to the default column.
 */

function r12108_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Data table: a compact single-row toolbar with Select all and Screen Options', function () {
    $p = r12108_file('admin/views/partials/datatable.php');
    assert_contains('data-dt-selall', $p, 'a Select all control in the toolbar row');
    assert_contains('data-dt-selall-input', $p);
    assert_contains('bh-dt-filterm__text', $p, 'the Filter & sort label can collapse to an icon');

    $css = r12108_file('admin/assets/css/src/datatable.css');
    // Screen Options is no longer hidden on mobile.
    assert_false(str_contains($css, '.bh-dt-tools [data-dt-options] { display: none !important; }'), 'Screen Options cog is reachable on mobile');
    assert_contains('.bh-dt-toolbar.is-searching', $css, 'search expands on focus, folding the rest away');
    assert_contains('.bh-dt-selall', $css);
});

test('Data table: search expands on focus (wired in the script)', function () {
    $js = r12108_file('admin/assets/js/datatable.js');
    assert_contains("'is-searching'", $js, 'focus toggles the searching state');
    assert_contains('toolAll', $js, 'the toolbar Select all is wired');
});

test('Data table: the drawer opens smoothly and the skeleton has a minimum visible time', function () {
    $js = r12108_file('admin/assets/js/datatable.js');
    assert_contains('requestAnimationFrame', $js, 'two-frame mount so the slide animates from display:none');
    assert_contains('SKEL_MIN', $js, 'the skeleton stays up for a minimum time');
});

test('Sort: the control follows an explicit choice, not the server echo', function () {
    // The fix for "Default order" snapping back to the default column.
    $js = r12108_file('admin/assets/js/datatable.js');
    assert_contains('sortChoice', $js);
    assert_contains('sortSel.value = sortChoice', $js, 'the select mirrors the explicit choice');
});

test('Data table: body text is standard 14/13px', function () {
    $css = r12108_file('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-card__title { font-size: .875rem;', $css, 'card title is 14px');
    assert_contains('.bh-dt-panel__title { display: flex; align-items: center; gap: .5rem; font-size: .875rem;', $css);
    // No rem-based UI font above 15px remains (the one 16px rule is the search
    // input, set deliberately so iOS doesn't zoom when it's tapped).
    assert_false((bool) preg_match('/font-size:\s*(1\.[1-9]\d*rem|[2-9]\d*rem)/', $css), 'no oversized rem fonts remain');
});
