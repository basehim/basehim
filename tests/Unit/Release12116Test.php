<?php

declare(strict_types=1);

/*
 * 1.2.116 — Selection without a wall of tick boxes.
 *
 *  - Desktop (a mouse): a row's tick shows on hover or focus; all show once
 *    one is ticked (.has-selection); the header Select all always shows.
 *  - Touch: no ticks at rest; select mode (toolbar Select, or a long press)
 *    shows them, a tap on an item toggles it, the bulk bar shows with Done.
 *  - Items with a picture carry their tick on the picture.
 *  - The phone filter button's count is a corner badge (it pushed the icon).
 */

function r12116(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('desktop: ticks reveal on hover or focus, and all show once one is ticked', function () {
    $css = r12116('admin/assets/css/src/datatable.css');
    assert_contains('@media (hover: hover) and (pointer: fine) {', $css);
    assert_contains('.bh-dt-table tbody tr:hover td.bh-dt-check input,', $css);
    assert_contains('.bh-dt-table tbody tr:focus-within td.bh-dt-check input,', $css, 'keyboard users see them');
    assert_contains('.bh-dt.has-selection .bh-dt-table tbody td.bh-dt-check input,', $css);
    assert_false(str_contains($css, 'thead td.bh-dt-check input { opacity: 0'), 'the header Select all never hides');
    $js = r12116('admin/assets/js/datatable.js');
    assert_contains("root.classList.toggle('has-selection', n > 0);", $js);
});

test('touch: select mode via the toolbar or a long press; a tap toggles an item', function () {
    $partial = r12116('admin/views/partials/datatable.php');
    assert_contains('data-dt-selectmode hidden aria-pressed="false" aria-label="Select items"', $partial);
    $js = r12116('admin/assets/js/datatable.js');
    assert_contains('function setSelecting(on)', $js);
    assert_contains("r.bulk.classList.toggle('is-active', n > 0 || selecting);", $js, 'the bar shows in select mode with nothing picked');
    assert_contains("'Tap items to select'", $js);
    assert_contains("selecting = false; syncSelecting();   // clearing also leaves select mode", $js);
    assert_contains("}, 450);", $js, 'long press');
    // The flag that swallows a long press's own click is set AFTER the tick's
    // click, and expires, so it cannot eat the item or the next real tap.
    assert_true(strpos($js, "if (cb && !cb.checked) cb.click();\n                lpFired = true;") !== false);
    assert_contains("if (lpFired) setTimeout(function () { lpFired = false; }, 400);", $js);
    $css = r12116('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt:not(.is-selecting) .bh-dt-card .bh-dt-check:not(.bh-dt-check--over) { display: none; }', $css);
    assert_contains('.bh-dt.is-selecting .bh-dt-check--over { opacity: 1; pointer-events: auto; }', $css);
});

test('items with a picture carry their tick on the picture', function () {
    $js = r12116('admin/assets/js/datatable.js');
    assert_contains("chk.classList.add('bh-dt-check--over'); thumb.appendChild(chk);", $js, 'full cards');
    assert_contains("chk.classList.add('bh-dt-check--over'); media.appendChild(chk);", $js, 'compact cards');
    $css = r12116('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-card .bh-dt-check--over:has(input:checked) { background: rgb(37 99 235 / .85); }', $css);
    assert_contains('-webkit-appearance: none; appearance: none;', $css, 'round card ticks');
});

test('phone filter button: the count is a corner badge, out of the flow', function () {
    $css = r12116('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-filterm { position: relative; }', $css);
    assert_contains('.bh-dt-filterm__badge { @apply absolute right-0.5 top-0.5', $css);
});
