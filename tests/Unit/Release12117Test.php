<?php

declare(strict_types=1);

/*
 * 1.2.117 — Phone toolbar polish (install feedback on 1.2.116).
 *  1. The stuck shadow jerked: is-stuck lands a frame after the compositor
 *     moves the sticky bar, and the bar's backdrop blur snapped on/off. The bar
 *     is now opaque; the only stuck cue is a ::after faded by opacity.
 *  2. The Select button shrank to 29px in the no-wrap row: flex none.
 *  3. A focused search lost its stickiness on real phones (the keyboard pans
 *     the visual viewport): interactive-widget=resizes-content; the pager stops
 *     riding on the keyboard while typing.
 *  4. Toolbar controls are the core 36px on phones too (by request).
 */

function r12117(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('phone app bar: no fill change on stick; the cue is an opacity-faded ::after', function () {
    $css = r12117('admin/assets/css/src/datatable.css');
    assert_false(str_contains($css, 'bg-slate-50/95 backdrop-blur-md'), 'no backdrop blur switching on at the boundary');
    assert_contains('.bh-dt--sticky > .bh-dt-top::after { content: ""; position: absolute;', $css);
    assert_contains('opacity: 0; transition: opacity .2s ease; }', $css);
    assert_contains('.bh-dt--sticky > .bh-dt-top.is-stuck::after { opacity: 1; }', $css);
});

test('phone toolbar: 36px controls, and Select never shrinks', function () {
    $css = r12117('admin/assets/css/src/datatable.css');
    assert_false(str_contains($css, '--dt-ctl-h: 2.5rem'), 'phones use the core 36px');
    assert_contains('display: inline-flex; flex: none; width: var(--dt-ctl-h); height: var(--dt-ctl-h); }', $css);
    assert_contains('.bh-dt-bulk__actions .bh-dt-bulkbtn { @apply h-9 flex-1', $css);
});

test('keyboard: the layout viewport shrinks with it, and the pager stops riding on it', function () {
    $layout = r12117('admin/views/layouts/app.php');
    assert_contains('interactive-widget=resizes-content', $layout);
    $js = r12117('admin/assets/js/datatable.js');
    assert_contains("root.classList.add('is-typing')", $js);
    assert_contains("root.classList.remove('is-typing')", $js);
    $css = r12117('admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt.is-typing.bh-dt--sticky-foot .bh-dt-foot { position: relative; }', $css);
});
