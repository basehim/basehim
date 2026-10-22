<?php

declare(strict_types=1);

/*
 * BH-036 (1.2.83) — a busy button gets one spinner, and done / failed marks
 * are icons. A busy button had two spinners: bhLoader() put its own inside
 * the button and set aria-busy, and a stylesheet rule drew another for every
 * .bh-btn[aria-busy]. These checks keep a second source from coming back.
 */

function busy_sources(): array
{
    $root = BASEHIM_ROOT . '/admin/assets/css/';
    return [
        'tailwind source' => (string) file_get_contents($root . 'src/tailwind.css'),
        'tailwind.min.css' => (string) file_get_contents($root . 'tailwind.min.css'),
        'ui.css' => (string) file_get_contents($root . 'ui.css'),
        'admin-styles' => (string) file_get_contents(BASEHIM_ROOT . '/admin/views/partials/admin-styles.php'),
        'layout' => (string) file_get_contents(BASEHIM_ROOT . '/admin/views/layouts/app.php'),
    ];
}

test('busy buttons: no stylesheet draws a spinner or mark on a busy element', function () {
    foreach (busy_sources() as $name => $css) {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);   // comments may mention it
        assert_false((bool) preg_match('/aria-busy[^{}]*::?(before|after)\s*\{[^}]*content/i', $css), "$name styles ::before/::after on [aria-busy]");
        assert_false((bool) preg_match('/\.bh-btn-busy[^{}]*::?(before|after)\s*\{[^}]*content\s*:\s*["\']/i', $css), "$name puts content on a busy button");
    }
});

test('busy buttons: one loader for both bhLoader() and bhBusy(), with the guard in place', function () {
    $layout = busy_sources()['layout'];
    preg_match('/window\.bhBusy = function.*?\n    \};/s', $layout, $m);
    assert_true(isset($m[0]), 'bhBusy found');
    assert_contains('buttonLoader(btn', $m[0], 'bhBusy draws with the loader');
    assert_not_contains("setAttribute('aria-busy'", $m[0], 'bhBusy does not set its own busy state');
    $ui = busy_sources()['ui.css'];
    assert_contains('.bh-btn-busy::before, .bh-btn-busy::after { content: none !important;', $ui);
    assert_contains('.bh-btn-busy > :not(.bh-btn-busy__icon):not(.bh-btn-busy__text) { display: none !important; }', $ui);
});

test('busy buttons: done and failed show icons from the icon set, not text characters', function () {
    $layout = busy_sources()['layout'];
    foreach (['\\u2713', '\\u2714', '✓', '✔'] as $glyph) assert_not_contains($glyph, $layout);
    assert_contains("BasehimIcon(ok ? 'check' : 'exclamation-circle'", $layout);
    $ui = (string) preg_replace('#/\*.*?\*/#s', '', busy_sources()['ui.css']);
    assert_false((bool) preg_match('/content\s*:\s*"(\\\\2713|\\\\2714|!)"/', $ui), 'ui.css draws no ✓ or ! with content');
    foreach (glob(BASEHIM_ROOT . '/admin/assets/js/*.js') as $f) {
        if (str_ends_with($f, '.min.js')) continue;
        $js = (string) file_get_contents($f);
        assert_false((bool) preg_match("/textContent\s*=\s*[^;]*['\"](\\\\u2713|\\\\u2714|✓|✔)['\"]/u", $js), basename($f) . ' sets a ✓ character as a status mark');
    }
});
