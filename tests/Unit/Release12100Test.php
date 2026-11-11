<?php

declare(strict_types=1);

/*
 * 1.2.100 — BH-105: Customize offers Logo width and the header switches only to
 * themes that declare them in theme.json `supports`; BH-106: no PHP 8.5
 * deprecations (PDO::MYSQL_ATTR_INIT_COMMAND, curl_close); the data table's
 * Comfortable links live inside their row (one row's own hover shows them);
 * long media titles wrap on phone cards. Uses r99_customizer() from
 * Release1299Test.php.
 */

test('BH-105: a theme that does not declare logo-width / header-text is not offered those controls', function () {
    $none = r99_customizer([], ['custom-logo'])->sections()['identity']['options'];
    assert_same(['site_title', 'tagline', 'logo_url', 'favicon_url'], array_keys($none));
    $width = r99_customizer([], ['logo-width'])->sections()['identity']['options'];
    assert_same(['site_title', 'tagline', 'logo_url', 'logo_width', 'favicon_url'], array_keys($width));
    $text = r99_customizer([], ['header-text'])->sections()['identity']['options'];
    assert_same(['site_title', 'tagline', 'logo_url', 'show_site_title', 'show_tagline', 'favicon_url'], array_keys($text));
    assert_same('Show in the header', $text['show_site_title']['heading']);
});

test('BH-105: a saved logo width adds no CSS variables for a theme that does not use it', function () {
    assert_same('', r99_customizer(['appearance.logo_width' => 160], ['custom-logo'])->cssVariables());
    assert_same(':root{--bh-logo-width: 160px;--bh-logo-height: auto}', r99_customizer(['appearance.logo_width' => 160], ['logo-width'])->cssVariables());
});

test('BH-105: the bundled theme declares both, and the theme docs list them', function () {
    $root = dirname(__DIR__, 2);
    foreach (['default'] as $t) {   // core ships one theme since 11 Nov 2026
        $m = json_decode((string) file_get_contents($root . "/content/themes/{$t}/theme.json"), true);
        assert_true(in_array('logo-width', $m['supports'] ?? [], true) && in_array('header-text', $m['supports'] ?? [], true), $t);
    }
    $doc = (string) file_get_contents($root . '/docs/THEME-DEVELOPMENT.md');
    assert_contains('| `logo-width` |', $doc);
    assert_contains('| `header-text` |', $doc);
});

test('BH-106: core uses Pdo\\Mysql::ATTR_INIT_COMMAND where it exists and never calls curl_close()', function () {
    $root = dirname(__DIR__, 2);
    assert_same(1002, \App\Core\Database::initCommandAttribute(), 'MySQL init command option');
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php') continue;
        $src = (string) file_get_contents($f->getPathname());
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (preg_match('/\bcurl_close\s*\(/', $src)) $hits[] = $rel . ': curl_close';
        if ($rel !== 'app/Core/Database.php' && str_contains($src, 'PDO::MYSQL_ATTR_')) $hits[] = $rel . ': PDO::MYSQL_ATTR_';
    }
    foreach (['bootstrap.php', 'index.php'] as $f) {
        if (preg_match('/\bcurl_close\s*\(|PDO::MYSQL_ATTR_/', (string) file_get_contents($root . '/' . $f))) $hits[] = $f;
    }
    assert_same([], $hits);
    $db = (string) file_get_contents($root . '/app/Core/Database.php');
    assert_same(1, substr_count($db, 'PDO::MYSQL_ATTR_INIT_COMMAND;'), 'the old name only as the fallback');
    assert_contains("defined('Pdo\\\\Mysql::ATTR_INIT_COMMAND')", (string) file_get_contents($root . '/install.php'));
});

test('data table: Comfortable links are inside their row, shown by that row\'s own hover, never on every row at once', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/src/datatable.css');
    assert_contains('.bh-dt-table tbody tr:hover .bh-dt-rowlinks,', $css);
    assert_contains('.bh-dt:not([data-density="compact"]) .bh-dt-table tbody tr.has-rowlinks > td { padding-bottom: 2.1rem; }', $css, 'room kept, so nothing moves');
    assert_not_contains('bh-dt-actrow', $css);
    assert_false((bool) preg_match('/@media \(hover: none\)[^}]*rowlinks/', $css));
    assert_not_contains('bh-dt-actrow', (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/datatable.js'));
});

test('media: a long title wraps inside its phone card instead of running over the size and date', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/media-picker.css');
    assert_contains('.bh-dt-card .bh-media-open { max-width: 100%; white-space: normal; overflow-wrap: anywhere;', $css);
});
