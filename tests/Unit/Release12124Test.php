<?php

declare(strict_types=1);

/*
 * 1.2.124: only static files are served from content/; one requirements list
 * for the installer and System → Health; MCP tools for menus, themes and
 * settings.
 */

use App\Core\Http\PublicFiles;
use App\Core\Requirements;

function r12124(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('content: only static assets are public, the last extension decides', function () {
    foreach (['a.css', 'b/c.JS', 'logo.PNG', 'font.woff2', 'clip.mp4', 'x.min.js', 'map.js.map'] as $ok) {
        assert_true(PublicFiles::isPublic($ok), "{$ok} should be public");
    }
    foreach (['single.php.bak2', 'header.php.bak', 'style.css.orig', 'a.php', 'theme.json', 'app.json',
              'README.md', 'tool.zip', 'notes.txt', 'page.html', 'noext', '.htaccess', '.env', 'dump.sql', 'x.phtml'] as $no) {
        assert_false(PublicFiles::isPublic($no), "{$no} should not be public");
    }
    assert_same('text/css', PublicFiles::typeOf('assets/site.css'));
    assert_same(null, PublicFiles::typeOf('assets/site.css.bak'));
});

test('content: .htaccess, server.php and app assets use the one list', function () {
    $ht = r12124('content/.htaccess');
    assert_contains("<FilesMatch \".\">\n    Require all denied\n</FilesMatch>", $ht);
    assert_contains('<FilesMatch "' . PublicFiles::htaccessPattern() . "\">\n    Require all granted", $ht);
    // The deny comes first: on Apache the last matching section decides.
    assert_true(strpos($ht, 'Require all denied') < strpos($ht, 'Require all granted'));

    $srv = r12124('server.php');
    assert_contains("require_once __DIR__ . '/app/Core/Http/PublicFiles.php';", $srv);
    assert_contains("!\\App\\Core\\Http\\PublicFiles::isPublic(\$path)", $srv);

    $ctl = r12124('app/Http/Controllers/Web/AppAssetController.php');
    assert_contains('$mime = PublicFiles::typeOf($real);', $ctl);
    assert_not_contains("'phtml', 'phar'", $ctl, 'the old blocklist is gone');
});

test('requirements: one list, zip recommended, openssl and fileinfo required', function () {
    assert_same(['pdo_mysql', 'mbstring', 'openssl', 'fileinfo'], Requirements::requiredExtensions());
    $byExt = array_column(Requirements::extensions(static fn(string $e): bool => $e !== 'zip'), null, 'extension');
    assert_same(false, $byExt['zip']['required']);
    assert_same(false, $byExt['zip']['loaded']);
    assert_true($byExt['gd']['loaded']);
    assert_false(isset($byExt['json']), 'json is part of PHP 8');
    assert_true(Requirements::phpOk('8.1.0'));
    assert_false(Requirements::phpOk('8.0.30'));
    assert_same('8.1', Requirements::phpMinLabel());

    $install = r12124('install.php');
    assert_contains('foreach (\App\Core\Requirements::extensions() as $e) {', $install);
    assert_not_contains("\$ext('openssl')", $install);
    $health = r12124('app/Services/SystemInfoService.php');
    assert_contains('foreach (\App\Core\Requirements::extensions() as $e) {', $health);
    assert_not_contains("['pdo_mysql', 'mbstring', 'json', 'openssl', 'gd', 'curl', 'fileinfo']", $health);
    // Uploads live in storage/uploads, so that's the folder Health checks.
    assert_contains("'storage/uploads' => [BASEHIM_ROOT . '/storage/uploads', true, null],", $health);
    assert_not_contains("'content/uploads' => BASEHIM_ROOT", $health);
});

test('menus: a tree is validated whole before anything is written', function () {
    // A Database with no connection: validation must fail before any query.
    $db = (new \ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
    $svc = new \App\Services\MenuService($db);
    foreach (['/about', '#top', '?q=1', 'https://example.com/x', 'mailto:hi@example.com', 'tel:+15551234'] as $ok) {
        assert_true(\App\Services\MenuService::isSafeUrl($ok), $ok);
    }
    foreach (['javascript:alert(1)', 'JAVASCRIPT:x', 'data:text/html,x', '', 'http://', "/a\nb", 'vbscript:x'] as $no) {
        assert_false(\App\Services\MenuService::isSafeUrl($no), $no);
    }
    // Invalid trees throw before the database is touched (the stub has none).
    assert_throws(\InvalidArgumentException::class, fn() => $svc->replaceItems(1, [['title' => 'Ok', 'url' => '/'], ['title' => '', 'url' => '/x']]), 'Item 2');
    assert_throws(\InvalidArgumentException::class, fn() => $svc->replaceItems(1, [['title' => 'A', 'children' => [['title' => 'B', 'url' => 'javascript:x']]]]), 'Item 1.1');
    $deep = ['title' => 'L5'];
    foreach (['L4', 'L3', 'L2', 'L1'] as $t) $deep = ['title' => $t, 'children' => [$deep]];
    assert_throws(\InvalidArgumentException::class, fn() => $svc->replaceItems(1, [$deep]), 'levels deep');
});

test('menus: the Menus screen offers the theme\'s locations', function () {
    $themes = r12124('app/Services/ThemeService.php');
    assert_contains('public function menuLocations(): array', $themes);
    $ctl = r12124('app/Http/Controllers/Admin/MenuController.php');
    assert_contains("\$locations[\$current] = \$current . ' (not used by the active theme)';", $ctl);
    foreach (['admin/views/menus/index.php', 'admin/views/menus/edit.php'] as $v) {
        assert_not_contains("'sidebar' => 'Sidebar'", r12124($v));
        assert_not_contains('<option value="sidebar">', r12124($v));
        assert_contains('$locations', r12124($v));
    }
    // REST: items are stored as title (label still accepted).
    $api = r12124('app/Http/Controllers/Api/MenuController.php');
    assert_contains("\$title = trim((string) (\$request->input('title') ?? \$request->input('label', '')));", $api);
    assert_contains("'title'     => \$title,", $api);
});

test('mcp: new tools, their scopes, and one list of core tools', function () {
    $tools = array_column((new \App\Http\Controllers\Api\McpController())->allTools([]), '_scope', 'name');
    foreach ([
        'get_menu' => 'menus:read', 'save_menu' => 'menus:write',
        'list_themes' => 'themes:read', 'activate_theme' => 'themes:write',
        'get_theme_options' => 'themes:read', 'update_theme_options' => 'themes:write',
        'update_settings' => 'settings:write',
    ] as $name => $scope) {
        assert_same($scope, $tools[$name] ?? null, $name);
    }
    foreach (['themes:read', 'themes:write'] as $s) assert_true(isset(\App\Services\ApiKeyService::SCOPES[$s]), $s);
    foreach (['settings:write', 'menus:read', 'menus:write', 'themes:read', 'themes:write'] as $s) {
        assert_true(in_array($s, \App\Services\McpOAuthService::MCP_SCOPES, true), "OAuth offers {$s}");
    }
    $oauth = r12124('app/Http/Controllers/Api/OAuthController.php');
    assert_contains("'themes:write'     => 'manage_themes',", $oauth);
    assert_contains("'menus:write'      => 'manage_menus',", $oauth);

    // Groups an agent may never change.
    foreach (['security', 'authorization', 'email', 'permalinks', 'ai', 'media'] as $g) {
        assert_false(in_array($g, \App\Http\Controllers\Api\McpController::WRITABLE_SETTING_GROUPS, true), $g);
    }
    $mcp = r12124('app/Http/Controllers/Api/McpController.php');
    assert_contains("'general' => ['admin_email', 'admin_email_account', 'admin_email_password'],", $mcp);
    assert_contains("\$everything = array_column(array_filter(\$this->allTools([])", $mcp);

    // The admin page's table is built from the definitions.
    $rows = array_column(\App\Http\Controllers\Admin\ApiController::coreTools(), null, 'name');
    assert_same('Make an installed theme the active one.', $rows['activate_theme']['summary']);
    assert_same(null, $rows['get_site_info']['scope']);
    assert_not_contains('Twenty-five tools', r12124('admin/views/api/mcp.php'));
});
