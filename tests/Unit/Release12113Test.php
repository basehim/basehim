<?php

declare(strict_types=1);

/*
 * 1.2.113 — Hairline gap between the controls and the sticky column header.
 *
 * measure() fed --dt-top-h (the distance the header sticks below the controls)
 * from the integer offsetHeight; on a fractional-height (2x display) control
 * bar the header stuck a sub-pixel low, showing a hairline of page behind it.
 * It now measures the fractional getBoundingClientRect().height, so the header
 * sits exactly flush.
 *
 * (1.2.113 also shipped a mobile side-nav backdrop fix that flagged
 * bh-nav-open and dropped the sticky chrome to z-0; 1.2.114 replaced it — see
 * Release12114Test — because z-0 let the header paint under its own rows.)
 */

function r12113_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('Gap: the controls height is measured sub-pixel so the header sits flush', function () {
    $js = r12113_file('admin/assets/js/datatable.js');
    // Fractional height, not the integer offsetHeight, feeds --dt-top-h.
    assert_contains('getBoundingClientRect().height', $js);
    assert_false(
        (bool) preg_match('/var h = topEl\.offsetHeight;/', $js),
        'the integer offsetHeight measurement is gone'
    );
    assert_contains("setProperty('--dt-top-h', h + 'px')", $js);
});
