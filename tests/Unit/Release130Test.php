<?php

declare(strict_types=1);

/*
 * 1.3.0: a full release (the complete tree), with its changelog and notes.
 */

test('1.3.0: the version, its changelog and notes, and no stray files in the tree', function () {
    $root = dirname(__DIR__, 2);
    assert_contains("define('BASEHIM_VERSION', '1.3.0');", (string) file_get_contents($root . '/index.php'));
    assert_true(is_file($root . '/docs/changelog/CHANGELOG-1.3.0.md'), 'changelog');
    assert_true(is_file($root . '/docs/changelog/RELEASE-NOTES-1.3.0.md'), 'release notes');
    assert_contains('| 1.3.0 |', (string) file_get_contents($root . '/CHANGELOG.md'));
    // A full release must be a complete tree: the files CloudHim checks for.
    foreach (['index.php', 'bootstrap.php', 'install.php', 'app/Core/Application.php', 'app/Core/Router.php',
              'app/Core/App.php', 'routes/web.php', 'routes/admin.php', 'admin/assets/js/icons.js'] as $f) {
        assert_true(is_file($root . '/' . $f), "$f is in the tree");
    }
    assert_false(is_file($root . '/error_log'), 'no PHP error log in the tree');
    assert_contains("\nerror_log\n", (string) file_get_contents($root . '/.gitignore'));
});

test('1.3.0: PHP\'s error_log file is refused on Apache, LiteSpeed and the built-in server, and existing sites are upgraded once', function () {
    $root = dirname(__DIR__, 2);
    $ht = (string) file_get_contents($root . '/.htaccess');
    assert_contains('<FilesMatch "(^error_log$|\.(env|log|sql|lock|yml|yaml|ini)$)">', $ht);
    assert_contains("preg_match('#(^|/)error_log$#', \$path)", (string) file_get_contents($root . '/server.php'));

    // An .htaccess as 1.2.128 shipped it gets exactly that line changed, once.
    $old = str_replace('<FilesMatch "(^error_log$|\.(env|log|sql|lock|yml|yaml|ini)$)">', '<FilesMatch "\.(env|log|sql|lock|yml|yaml|ini)$">', $ht);
    assert_true($old !== $ht, 'the test builds the old file');
    $tmp = sys_get_temp_dir() . '/bh-ht-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $old);
    assert_same('updated', \App\Services\SecurityHeaders::upgradeHtaccessErrorLog($tmp));
    assert_same($ht, (string) file_get_contents($tmp), 'only that line changed');
    assert_same('already', \App\Services\SecurityHeaders::upgradeHtaccessErrorLog($tmp));
    file_put_contents($tmp, "# a hand-written file\n");
    assert_same('absent', \App\Services\SecurityHeaders::upgradeHtaccessErrorLog($tmp), 'a file Basehim did not write is left alone');
    @unlink($tmp);
});

test('1.3.0: the named colour palette and fixed status colours', function () {
    $root = dirname(__DIR__, 2);
    $names = ['red', 'orange', 'yellow', 'green', 'blue', 'pink', 'purple', 'brown', 'black', 'white', 'success', 'warning', 'danger', 'info'];
    $lum = function (array $c) { $c = array_map(fn($x) => ($x /= 255) <= .03928 ? $x / 12.92 : (($x + .055) / 1.055) ** 2.4, $c); return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2]; };
    $ratio = function (array $a, array $b) use ($lum) { $x = [$lum($a), $lum($b)]; rsort($x); return ($x[0] + .05) / ($x[1] + .05); };
    foreach (['tailwind.min.css', 'colors.css'] as $file) {
        $css = (string) file_get_contents($root . '/admin/assets/css/' . $file);
        preg_match('/:root,\[data-theme=light\]\{([^}]*)\}/', $css, $l);
        preg_match('/\[data-theme=dark\]\{([^}]*)\}/', $css, $d);
        foreach (['light' => $l[1] ?? '', 'dark' => $d[1] ?? ''] as $mode => $vars) {
            $get = function (string $n) use ($vars) { preg_match('/--bh-' . preg_quote($n, '/') . ':(\d+) (\d+) (\d+)/', $vars, $m); return array_map('intval', array_slice($m, 1)); };
            foreach ($names as $n) {
                assert_same(3, count($get($n)), "$file $mode --bh-$n");
                assert_true($ratio($get($n), $get('on-' . $n)) >= 4.5, "$file $mode: text on $n reads");
                assert_true($ratio($get($n . '-text'), $get($n . '-subtle')) >= 4.5, "$file $mode: $n text on its tint reads");
            }
        }
        foreach ($names as $n) {
            foreach (["bh-bg-$n", "bh-bg-$n-subtle", "bh-text-$n", "bh-text-bg-$n", "bh-border-$n", "bh-border-$n-subtle"] as $c) {
                assert_contains('.' . $c . '{', $css, "$file ships .$c");
            }
        }
    }
    $colors = (string) file_get_contents($root . '/admin/assets/css/colors.css');
    assert_false(str_contains($colors, 'box-sizing'), 'colors.css carries no reset');
    assert_contains('/admin/assets/css/colors.css', (string) file_get_contents($root . '/content/themes/default/templates/partials/header.php'));

    // Statuses never use the accent: a rising figure is success, a falling one danger.
    $src = (string) file_get_contents($root . '/admin/assets/css/src/tailwind.css');
    assert_contains('.bh-dstat__pill--up, .bh-dstat__pill--green { @apply bg-success-subtle text-success; }', $src);
    assert_contains('.bh-dstat__pill--down { @apply bg-danger-subtle text-danger; }', $src);
    assert_contains('.bh-dstat__pill--amber { @apply bg-warning text-on-warning; }', $src);
    foreach (['bh-note--warn', 'bh-badge--green', 'bh-badge--red', 'bh-dstatus__row.is-ok', 'bh-dstate--info'] as $c) {
        preg_match('/^\.' . preg_quote($c, '/') . '[^{]*\{([^}]*)\}/m', $src, $m);
        assert_false(str_contains($m[1] ?? 'accent', 'accent'), ".$c doesn't use the accent");
    }
});

test('1.3.0: pages paint finished, not their plain markup first', function () {
    $root = dirname(__DIR__, 2);
    // No view links a stylesheet in <body>: they ask for it with style().
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/admin/views'));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (!$f->isFile() || !str_ends_with($p, '.php') || str_contains($p, '/layouts/') || str_contains($p, '/errors/') || str_ends_with($p, 'partials/admin-styles.php')) continue;
        assert_false(str_contains((string) file_get_contents($p), 'rel="stylesheet"'), basename($p) . ' links a stylesheet in <body>');
    }
    foreach (['app', 'fullscreen'] as $l) {
        assert_contains('$this->headStyles()', (string) file_get_contents($root . "/admin/views/layouts/$l.php"));
    }

    // View::style(): printed once in the layout's <head>, or first in a page without one.
    \App\Core\Http\HeadStyles::reset();
    $dir = sys_get_temp_dir() . '/bh-view-' . bin2hex(random_bytes(4));
    mkdir($dir . '/layouts', 0777, true);
    file_put_contents($dir . '/layouts/l.php', '<head><?= $this->headStyles() ?></head><body><?= $this->yieldSection("content") ?></body>');
    file_put_contents($dir . '/page.php', '<?php $this->extend("layouts.l"); $this->section("content"); $this->style("/a.css?v=1&x=2"); $this->style("/a.css?v=1&x=2"); $this->style("/b.css", ["data-x" => true]); ?>hi<?php $this->endSection(); ?>');
    file_put_contents($dir . '/frag.php', '<?php $this->style("/c.css"); ?>frag');
    $v = new \App\Core\View($dir);
    $html = $v->render('page');
    assert_same(1, substr_count($html, 'a.css'), 'each stylesheet once');
    assert_true(strpos($html, 'b.css') < strpos($html, '<body>'), 'in <head>');
    assert_contains('href="/a.css?v=1&amp;x=2"', $html);
    assert_contains(' data-x>', $html);
    \App\Core\Http\HeadStyles::reset();
    assert_contains('<link rel="stylesheet" href="/c.css">', $v->render('frag'), 'a fragment still gets its stylesheet');
    \App\Core\Http\HeadStyles::reset();
    array_map('unlink', glob($dir . '/layouts/*') ?: []); array_map('unlink', glob($dir . '/*.php') ?: []); @rmdir($dir . '/layouts'); @rmdir($dir);

    // Scripts that draw a component show it once drawn, with a fallback.
    assert_contains("d.classList.add('bh-js');", (string) file_get_contents($root . '/admin/views/partials/appearance-head.php'));
    $dt = (string) file_get_contents($root . '/admin/assets/js/datatable.js');
    assert_contains("root.classList.add('is-built');", $dt);
    assert_contains("    init();\n    if (document.readyState === 'loading')", $dt, 'tables are built while the page parses');
    assert_contains('void topEl.offsetHeight;', $dt, 'measuring the sticky bar does not animate it');
    assert_contains('.bh-js .bh-dt[data-bh-draw]:not(.is-built) > * { visibility: hidden; animation: bh-dt-reveal 0s 2.5s forwards; }', (string) file_get_contents($root . '/admin/assets/css/src/datatable.css'));
    assert_contains('.bh-js .bhe-root[data-bh-draw]:not(.is-ready) .bhe-body > *', (string) file_get_contents($root . '/admin/assets/css/src/block-editor.css'));
    assert_contains("root.classList.add('is-ready')", (string) file_get_contents($root . '/admin/views/posts/edit.php'));
    $cz = (string) file_get_contents($root . '/admin/views/customizer/index.php');
    assert_contains('aria-expanded="<?= $__czOpen ? \'true\' : \'false\' ?>"', $cz, 'the first section is open from the start');
    assert_contains('id="bh-cz-status" aria-live="polite" hidden', $cz);
    assert_contains('data-months-loaded', (string) file_get_contents($root . '/admin/views/media/index.php'));
    assert_contains("--bh-dcard-h", (string) file_get_contents($root . '/admin/assets/js/dashboard.js'));
    $tw = (string) file_get_contents($root . '/admin/assets/css/src/tailwind.css');
    assert_contains('@font-face { font-family: "Inter Fallback"', $tw);

    // Tabs: glass, no faded edges, and the arrow never makes the bar taller.
    assert_contains('.bh-tabs.has-more-left, .bh-tabs.has-more-right { -webkit-mask-image: none; mask-image: none; }', $tw);
    assert_contains('supports-[backdrop-filter]:bg-slate-50/80 supports-[backdrop-filter]:backdrop-blur-md', $tw);
    assert_contains('.bh-tabs .bh-strip__arrow { margin-bottom: -1px; padding-top: 0; padding-bottom: calc(.625rem + 2px - .5rem); }', $tw);
});

test('1.3.0: apps that embed core\'s data table (every way they do it) get it styled, never a 500', function () {
    $root = dirname(__DIR__, 2);
    // Core partials are included by apps from their own views, where $this is
    // the app: CloudHim's Sites table answered 500 on $this->style().
    $dt = (string) file_get_contents($root . '/admin/views/partials/datatable.php');
    assert_false((bool) preg_match('/\$this->/', $dt), 'the data table partial does not use $this');
    assert_contains('\\App\\Core\\Http\\HeadStyles::add(', $dt);
    assert_contains('HeadStyles::expectHead();', (string) file_get_contents($root . '/app/Core/App.php'), 'adminView() announces the layout before the app body');

    $H = \App\Core\Http\HeadStyles::class;
    // A layout to come: the link waits for <head>, once.
    $H::reset(); $H::expectHead();
    assert_same('', $H::add('/dt.css?v=1'));
    assert_same('', $H::add('/dt.css?v=1'));
    assert_same('<link rel="stylesheet" href="/dt.css?v=1">' . "\n", $H::printHead());
    assert_same('', $H::add('/dt.css?v=1'), 'already in <head>');
    assert_same('<link rel="stylesheet" href="/late.css">' . "\n", $H::add('/late.css'), 'after <head>: printed in place');
    // No layout (an app's bare view): printed in place.
    $H::reset();
    assert_same('<link rel="stylesheet" href="/frag.css" data-x>' . "\n", $H::add('/frag.css', ['data-x' => true, 'bad attr' => 'x']));
    // A layout that never prints <head>: render() prepends what waited.
    $H::reset(); $H::expectHead(); $H::add('/w.css');
    assert_same('<link rel="stylesheet" href="/w.css">' . "\n", $H::unprinted());
    $H::reset();

    // Script-drawn parts hide only core's own markup, never an app's.
    assert_contains('<div class="bh-dt bh-card overflow-visible" data-bh-draw', $dt);
    assert_contains('id="bhe-root" data-bh-draw>', (string) file_get_contents($root . '/admin/views/posts/edit.php'));

    // Every class 1.2.128's admin stylesheet shipped is still there: apps use it without a build.
    $css = (string) file_get_contents($root . '/admin/assets/css/tailwind.min.css');
    preg_match_all('/\.(-?[a-zA-Z_][a-zA-Z0-9_\\\\:\/\[\]%.-]*?)(?=[\s,{:>+~\[)]|$)/', $css, $m);
    $have = array_flip($m[1]);
    $missing = [];
    foreach (file($root . '/tests/fixtures/admin-css-classes-1.2.128.txt', FILE_IGNORE_NEW_LINES) as $c) {
        if ($c === '' || $c[0] === '#') continue;
        if (!isset($have[$c])) $missing[] = $c;
    }
    assert_same([], $missing, 'classes 1.2.128 shipped');
});

test('1.3.0: every page drawn outside the admin layout follows the person\'s appearance', function () {
    $root = dirname(__DIR__, 2);
    $html = \App\Core\Http\StatusPage::html(500, 'Internal Server Error', 'It broke <b>', [
        'actions' => [['label' => 'Home', 'href' => '/', 'primary' => true]],
    ]);
    assert_true((bool) preg_match('/<html lang="en" data-appearance="(light|dark|system)" data-theme="(light|dark)" data-accent="[a-z-]+">/', $html), 'appearance on <html>');
    assert_contains('/admin/assets/css/tailwind.min.css?v=', $html);
    assert_contains('prefers-color-scheme: dark', $html, 'System follows the device');
    assert_contains('It broke &lt;b&gt;', $html, 'escaped');
    assert_contains('class="bh-btn bh-btn--primary"', $html);
    assert_false((bool) preg_match('/<style|style="|#[0-9a-f]{6}\b/i', $html), 'no inline CSS or fixed colours');
    // A failure that is the database itself: defaults, no throw.
    $a = \App\Services\AppearanceService::forRequest();
    assert_true(isset($a['mode'], $a['accent']));

    // Each standalone answer uses it.
    foreach (['app/Core/ErrorHandler.php', 'app/Core/Router.php', 'app/Services/AppHealth/AppGuard.php',
              'app/Http/Controllers/Api/OAuthController.php', 'app/Http/Middleware/AdminAreaPolicy.php',
              'admin/views/errors/404.php', 'admin/views/errors/500.php', 'admin/views/errors/app-disabled.php'] as $f) {
        $src = (string) file_get_contents($root . '/' . $f);
        assert_contains('StatusPage::html(', $src, "$f draws with StatusPage");
        assert_false((bool) preg_match('/<style|<!DOCTYPE/i', $src), "$f writes no page of its own");
    }
    // Try again stays on this site.
    $old = $_SERVER['REQUEST_URI'] ?? null;
    $m = new ReflectionMethod(\App\Core\ErrorHandler::class, 'genericHtml');
    $_SERVER['REQUEST_URI'] = '//evil.example/x';
    assert_false(str_contains($m->invoke(null, 500), 'evil.example'), '"//host" is not followed');
    $_SERVER['REQUEST_URI'] = '/admin/cloudhim?tab=sites';
    assert_contains('href="/admin/cloudhim?tab=sites"', $m->invoke(null, 500));
    if ($old === null) unset($_SERVER['REQUEST_URI']); else $_SERVER['REQUEST_URI'] = $old;
    // An unknown admin address gets the admin's 404, not the theme's.
    assert_contains("if (\$category === 'admin')", (string) file_get_contents($root . '/app/Http/Controllers/Web/ResolveController.php'));
});

test('1.3.0: the dashboard on a phone: even spacing, tiles as cells of one grid', function () {
    $tw = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/src/tailwind.css');
    assert_contains('@media (max-width: 639px) { .bh-dash { --dash-gap: theme("spacing.4"); } }', $tw, 'cards as far apart as the side gutter');
    assert_contains('@apply relative mb-[var(--dash-gap)] flex', $tw, 'the welcome card too');
    assert_contains('.bh-dcard__body:has(> .bh-dview--stat) { @apply p-0; }', $tw);
    assert_contains('.bh-dview--stat > .bh-dstat { @apply rounded-none border-0 bg-white p-4; }', $tw);
});
