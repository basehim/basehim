<?php

declare(strict_types=1);

/*
 * 1.2.118 — The phone search placeholder is 14px like every other field; typed
 * text stays 16px, which is what stops iOS zooming into the box on tap.
 */

test('phone search: 14px placeholder, 16px typed text', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-search input { font-size: 16px; }', $css, 'the iOS zoom guard stays');
    assert_contains('.bh-dt-search input::placeholder { font-size: .875rem; }', $css);
});
