<?php

declare(strict_types=1);

use App\Services\AppearanceService;

/*
 * 1.3.0 — Appearance: light, dark or system, and a four-colour accent
 * palette, saved on each person's account.
 */

test('appearance: the accents the account accepts are the palettes the stylesheet builds', function () {
    $theme = (string) file_get_contents(BASEHIM_ROOT . '/tailwind.theme.js');
    $block = substr($theme, strpos($theme, 'const ACCENTS = {'));
    $block = substr($block, 0, strpos($block, '};'));
    preg_match_all("/^\s+'?([a-z-]+)'?:\s+\{ label: '([^']+)'/m", $block, $m);
    assert_same(AppearanceService::ACCENTS, array_combine($m[1], $m[2]), 'same keys, labels and order');
    $css = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/tailwind.min.css');
    foreach (array_keys(AppearanceService::ACCENTS) as $key) {
        assert_contains('[data-accent=' . $key . ']', $css, "light palette for $key");
        assert_contains('[data-theme=dark][data-accent=' . $key . ']', $css, "dark palette for $key");
    }
    foreach ([1, 2, 3, 4] as $i) assert_contains("--bh-bg-palette-$i:", $css, "palette colour $i");
});

test('appearance: unknown or missing choices fall back to the default', function () {
    assert_same(AppearanceService::DEFAULTS, AppearanceService::clean(null));
    assert_same(['mode' => 'dark', 'accent' => 'blue'], AppearanceService::clean(['mode' => 'dark', 'accent' => 'teal']));
    assert_same(['mode' => 'system', 'accent' => 'kiwi'], AppearanceService::clean(['mode' => 'system', 'accent' => 'kiwi']));
    assert_contains('data-appearance="system" data-theme="light" data-accent="pink-la"', AppearanceService::htmlAttributes(['mode' => 'system', 'accent' => 'pink-la']));
});

test('appearance: the picker shows each palette as two overlapping circles, from the stylesheet', function () {
    $src = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/tailwind.css');
    assert_contains('.bh-swatch::after { @apply left-2.5 bg-palette-2; }', $src);
    assert_contains('bg-palette-1', $src);
    $layout = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/layouts/app.php');
    assert_false(str_contains($layout, 'style="background'), 'no inline colours in the picker');
});

test('appearance for apps: named variables, a preset, Basehim.appearance and the PHP API', function () {
    $css = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/tailwind.min.css');
    foreach (['--bh-surface:', '--bh-text:', '--bh-muted:', '--bh-line:', '--bh-accent:', '--bh-on-accent:', '--bh-accent-soft:', '--bh-palette-1:', '--bh-palette-4:'] as $v) {
        assert_contains($v, $css, "$v is set for apps");
    }
    assert_contains("require('./tailwind.theme.js').preset", (string) file_get_contents(BASEHIM_ROOT . '/tailwind.preset.js'));

    $head = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/partials/appearance-head.php');
    foreach (['B.appearance = {', 'get: get', 'onChange: function', "new CustomEvent('basehim:appearance'"] as $part) assert_contains($part, $head);
    foreach (['app', 'fullscreen'] as $layout) {
        assert_contains("include('partials.appearance-head')", (string) file_get_contents(BASEHIM_ROOT . "/admin/views/layouts/$layout.php"), "$layout layout has the API before any app script");
    }

    assert_true(method_exists(\App\Core\Api\AppApi::class, 'appearance'));
    assert_contains('Light, dark and accent (1.3.0)', (string) file_get_contents(BASEHIM_ROOT . '/docs/ADMIN-UI.md'));
});

test('data tables: theme colours only, the accent for chips, readable secondary text, glass chrome', function () {
    $src = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/datatable.css');
    // Only shadows and scrims keep literal colours (they are dark in both modes).
    $lines = array_filter(explode("\n", $src), fn($l) => preg_match('/#[0-9a-f]{3,6}\b|rgba?\(/i', $l) && !str_contains($l, 'theme('));
    foreach ($lines as $l) assert_true((bool) preg_match('/shadow|backdrop|mask-image|linear-gradient\(rgb\(15 23 42/', $l), 'literal colour left: ' . trim($l));
    assert_false(str_contains($src, 'indigo'), 'chips follow the accent');
    assert_contains('.bh-dt-sub { display: block; font-size: .76rem; color: theme("textColor.slate.500"); }', $src);
    assert_contains('.bh-dt--sticky .bh-dt-top { @apply z-[6] bg-white bh-glass;', $src);
    assert_contains('.bh-dt--sticky-foot .bh-dt-foot { @apply z-[5] bg-white bh-glass;', $src);
});

test('media library and picker: built from a source with theme colours, no fixed colours in its scripts', function () {
    $src = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/media-picker.css');
    $lines = array_filter(explode("\n", $src), fn($l) => preg_match('/#[0-9a-f]{3,6}\b|rgba?\(/i', $l) && !str_contains($l, 'theme(') && !str_contains($l, 'var(--bh'));
    foreach ($lines as $l) assert_true((bool) preg_match('/shadow|backdrop|rgba\(15, ?23, ?42|background: ?#000|background:#000/', $l), 'literal colour left: ' . trim($l));
    assert_contains('--nml-accent:theme("backgroundColor.accent.600")', $src, 'the library\'s own variables follow the theme');
    assert_contains('build:media', (string) file_get_contents(BASEHIM_ROOT . '/package.json'));
    foreach (['media-library.js', 'media.js'] as $js) {
        $code = (string) file_get_contents(BASEHIM_ROOT . "/admin/assets/js/$js");
        assert_false((bool) preg_match("/['\"]#[0-9a-f]{6}['\"]|style=\"color:|cssText = '[^']*color/i", $code), "$js has no fixed colours");
    }
});

test('block editor and Customizer: theme colours from a source, solid fills that work in dark, full screen follows the account', function () {
    foreach (['block-editor', 'customizer'] as $name) {
        $src = (string) file_get_contents(BASEHIM_ROOT . "/admin/assets/css/src/$name.css");
        $lines = array_filter(explode("\n", $src), fn($l) => preg_match('/#[0-9a-f]{3,6}\b|rgba?\(/i', $l) && !str_contains($l, 'theme(') && !str_contains($l, 'var(--bh'));
        foreach ($lines as $l) assert_true((bool) preg_match('/shadow|backdrop|background: ?rgba\(0, ?0, ?0/', $l), "$name.css literal colour left: " . trim($l));
        assert_contains("build:" . ($name === 'block-editor' ? 'editor' : 'customizer'), (string) file_get_contents(BASEHIM_ROOT . '/package.json'));
    }
    $editor = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/block-editor.css');
    assert_false((bool) preg_match('/background: var\(--bhe-ink\)/', $editor), 'a text colour is never a fill (it turns light in dark mode)');
    assert_contains('[data-theme="dark"] {', $editor);
    assert_contains('--bhe-solid:', $editor);

    $edit = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/posts/edit.php');
    assert_false(str_contains($edit, 'text-overflow:ellipsis'), 'the post address wraps');
    $full = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/layouts/fullscreen.php');
    assert_false(str_contains($full, "\$__appearance['mode'] = 'light'"), 'the Customizer follows the person\'s mode');
    assert_contains('AuthService::class)->currentUserId()', $full, 'and their accent, from the session');
    assert_contains('html { accent-color: theme("backgroundColor.accent.600"); }', (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/tailwind.css'));
});

test('admin bar: follows each person\'s Appearance by default, with its own scoped colours that never touch the theme\'s page', function () {
    $s = \App\Core\AdminBar\AdminBarSettings::class;
    assert_same('account', $s::DEFAULTS['color']);
    assert_same('account', $s::clean(['color' => 'dark'])['color'], 'a "dark" saved before 1.3.0 was the old default, not a choice');
    assert_same('dark', $s::clean(['color' => 'dark', 'color_chosen' => '1'])['color'], 'a choice made on the 1.3.0 screen is kept');
    assert_same('light', $s::clean(['color' => 'light'])['color']);

    $css = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/admin-bar.css');
    assert_false(str_contains($css, ':root'), 'nothing is set on the theme\'s <html>');
    assert_false(str_contains($css, '--tw-'), 'no Tailwind defaults on the theme\'s elements');
    assert_contains('#bh-admin-bar[data-theme=dark]', $css);
    assert_contains('@media (prefers-color-scheme:dark){#bh-admin-bar[data-appearance=system]', $css, 'System is resolved in CSS');
    foreach (['kiwi', 'pink-la', 'rose'] as $a) assert_contains("#bh-admin-bar[data-accent=$a]", $css);
    $src = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/admin-bar.css');
    $lines = array_filter(explode("\n", $src), fn($l) => preg_match('/#[0-9a-f]{3,6}\b|rgba?\(/i', $l) && !str_contains($l, 'var(--bh'));
    foreach ($lines as $l) assert_true((bool) preg_match('/shadow|backdrop|background: ?rgba\(15, ?23, ?42/', $l), 'literal colour left: ' . trim($l));

    $renderer = (string) file_get_contents(BASEHIM_ROOT . '/app/Core/AdminBar/AdminBarRenderer.php');
    assert_contains("' . \$lookAttrs . '>'", $renderer);
    $js = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/js/admin-bar.js');
    assert_contains('function look(node)', $js, 'dialogs and toasts take the bar\'s look');
    assert_contains('build:adminbar', (string) file_get_contents(BASEHIM_ROOT . '/package.json'));
});

test('scroll strips and sticky stacking: arrows for a mouse, tables below every sticky bar, anchors clear of them', function () {
    $js = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/js/scroll-strip.js');
    foreach (['window.bhScrollStrip = setup', "'.bh-tabs, [data-bh-strip]'", 'new MutationObserver', "b.tabIndex = -1"] as $part) assert_contains($part, $js);
    $layout = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/layouts/app.php');
    assert_contains('/admin/assets/js/scroll-strip.js', $layout);
    assert_false(str_contains($layout, "querySelectorAll('.bh-tabs').forEach"), 'one owner for strips');
    $icons = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/js/icons.js');
    assert_contains('"chevron-left":', $icons);
    assert_contains('"chevron-right":', $icons);

    $css = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/tailwind.css');
    assert_contains('@media (hover: hover) and (pointer: fine)', $css);
    assert_contains('html:has(.bh-tabs) { scroll-padding-top: 8.5rem; }', $css);
    foreach (['partials/tabs.php', 'partials/marketplace.php', 'comments/index.php'] as $v) {
        assert_contains('data-bh-strip', (string) file_get_contents(BASEHIM_ROOT . '/admin/views/' . $v));
    }
    assert_false(str_contains((string) file_get_contents(BASEHIM_ROOT . '/admin/views/comments/index.php'), 'style="scrollbar-width'), 'no inline style');

    $dt = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/js/datatable.js');
    assert_contains(":scope > header, .bh-tabs, [data-bh-sticky]", $dt, 'a table sticks below the tabs, not behind them');
});

test('pre-release audit (1.3.0): views carry no fixed colours, Tailwind copies nothing, grey text reads', function () {
    // Views with their own <style> of hex colours ignored dark mode and the accent.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASEHIM_ROOT . '/admin/views'));
    foreach ($it as $f) {
        if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) continue;
        // The sign-in page wears the site's brand colour (the theme's), not the admin's.
        if (str_ends_with($f->getPathname(), 'layouts/signin.php')) continue;
        $src = (string) file_get_contents($f->getPathname());
        if (!preg_match_all('#<style>(.*?)</style>#s', $src, $m)) continue;
        foreach ($m[1] as $css) assert_false((bool) preg_match('/#[0-9a-f]{3,6}\b/i', $css), 'fixed colour in a <style> of ' . $f->getFilename());
    }
    foreach (['partials/avatar-field.php', 'menus/edit.php', 'updates/index.php', 'widgets/areas.php'] as $v) {
        assert_false(str_contains((string) file_get_contents(BASEHIM_ROOT . '/admin/views/' . $v), '<style'), "$v: styles live in src/tailwind.css");
    }

    // Tailwind copies every plain rule that names a class into each @apply of
    // that class (the menu-panel rule was copied 48 times, a phone gap rule 20).
    $src = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/src/tailwind.css');
    preg_match_all('/@apply ([^;]+);/', $src, $ap);
    $applied = [];
    foreach ($ap[1] as $list) foreach (preg_split('/\s+/', trim($list)) as $c) if (preg_match('/^[a-z][a-z0-9-]*$/', $c)) $applied[$c] = true;
    preg_match_all('/^\s*([^@{}\n\/][^{}\n]*)\{/m', $src, $sel);
    foreach ($sel[1] as $s) {
        preg_match_all('/\.([a-z][a-z0-9-]*)\b/', $s, $cls);
        foreach ($cls[1] as $c) {
            if (str_starts_with($c, 'bh') || str_starts_with($c, 'mb-') || !isset($applied[$c])) continue;
            assert_true(false, "rule names the utility .$c, which Tailwind copies into every @apply $c: " . trim($s));
        }
    }
    $built = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/tailwind.min.css');
    assert_true(substr_count($built, 'data-bh-menu-panel') < 8, 'no copies of the menu-panel rule');

    // Light-mode grey text: 400 on white and 500 on a raised fill at 4.5:1.
    preg_match('/:root,\[data-theme=light\]\{([^}]*)\}/', $built, $light);
    $var = function (string $n) use ($light) { preg_match('/--bh-' . preg_quote($n, '/') . ':(\d+) (\d+) (\d+)/', $light[1] ?? '', $m); return array_map('intval', array_slice($m, 1)); };
    $lum = function (array $c) { $c = array_map(fn($x) => ($x /= 255) <= .03928 ? $x / 12.92 : (($x + .055) / 1.055) ** 2.4, $c); return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2]; };
    $ratio = function (array $a, array $b) use ($lum) { $x = [$lum($a), $lum($b)]; rsort($x); return ($x[0] + .05) / ($x[1] + .05); };
    assert_true($ratio($var('tx-slate-400'), [255, 255, 255]) >= 4.5, 'hint text on white');
    assert_true($ratio($var('tx-slate-500'), [241, 245, 249]) >= 4.5, 'secondary text on slate-100');

    // Stored appearance that isn't a string falls back instead of throwing.
    $a = \App\Services\AppearanceService::clean(['mode' => ['x'], 'accent' => 5]);
    assert_same('system', $a['mode'], 'falls back to the default: System');
    assert_same(['mode' => 'system', 'accent' => 'blue'], \App\Services\AppearanceService::DEFAULTS, 'System and Default until someone chooses');
});
