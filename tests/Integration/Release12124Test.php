<?php

declare(strict_types=1);

/*
 * 1.2.124: MCP tools for menus, themes and settings, called over HTTP as a
 * client would, with the owner's role deciding what they may do.
 */

const R12124_SCOPES = ['menus:read', 'menus:write', 'themes:read', 'themes:write', 'settings:read', 'settings:write'];

function r12124_key(string $who): string
{
    return (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users'][$who], "1.2.124 {$who}", R12124_SCOPES)['key'];
}

/** @return array{error: ?string, result: mixed} */
function r12124_call(string $key, string $tool, array $args = []): array
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/mcp', json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args],
    ]), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    $j = $res->json();
    if (isset($j['error'])) return ['error' => (string) $j['error']['message'], 'result' => null];
    $text = $j['result']['content'][0]['text'] ?? '';
    if (!empty($j['result']['isError'])) return ['error' => $text, 'result' => null];
    $data = json_decode($text, true);
    return ['error' => is_array($data) && isset($data['error']) ? (string) $data['error'] : null, 'result' => $data];
}

function r12124_setting(string $group, string $key): ?string
{
    $row = bh_db()->selectOne('SELECT setting_value FROM {settings} WHERE setting_group = :g AND setting_key = :k', ['g' => $group, 'k' => $key]);
    return $row ? (string) $row['setting_value'] : null;
}

test('mcp 1.2.124: save_menu builds a menu tree, moves the location, refuses bad trees whole', function () {
    $key = r12124_key('admin');
    $locs = r12124_call($key, 'list_menus')['result']['locations'] ?? [];
    assert_true(isset($locs['primary']), 'the theme\'s locations are listed');

    $a = r12124_call($key, 'save_menu', ['name' => 'Old main', 'location' => 'primary']);
    assert_same(null, $a['error'], (string) $a['error']);
    $r = r12124_call($key, 'save_menu', [
        'name' => 'Main', 'location' => 'primary',
        'items' => [
            ['title' => 'Home', 'url' => '/'],
            ['title' => 'Docs', 'url' => '/docs', 'children' => [
                ['title' => 'Install', 'url' => '/docs/install'],
                ['title' => 'GitHub', 'url' => 'https://github.com/basehim/basehim', 'target' => '_blank'],
            ]],
        ],
    ]);
    assert_same(null, $r['error'], (string) $r['error']);
    $menu = $r['result'];
    assert_true($menu['created']);
    assert_same(['Home', 'Docs'], array_column($menu['items'], 'title'));
    assert_same(['Install', 'GitHub'], array_column($menu['items'][1]['children'], 'title'));
    assert_same('_blank', $menu['items'][1]['children'][1]['target']);
    assert_contains('now has none', implode(' ', $menu['notes'] ?? []));
    assert_same(null, bh_db()->selectOne('SELECT location FROM {menus} WHERE id = :i', ['i' => $a['result']['id']])['location']);

    // get_menu by location returns the same tree.
    $g = r12124_call($key, 'get_menu', ['location' => 'primary'])['result'];
    assert_same($menu['id'], $g['id']);

    // A bad item anywhere: nothing changes.
    $bad = r12124_call($key, 'save_menu', ['id' => $menu['id'], 'items' => [
        ['title' => 'Fine', 'url' => '/fine'], ['title' => 'Evil', 'url' => 'javascript:alert(1)'],
    ]]);
    assert_contains('Item 2', (string) $bad['error']);
    assert_contains('Nothing was saved', (string) $bad['error']);
    assert_same(['Home', 'Docs'], array_column(r12124_call($key, 'get_menu', ['id' => $menu['id']])['result']['items'], 'title'));

    // A location the theme doesn't have is refused.
    assert_contains('no "nowhere" location', (string) r12124_call($key, 'save_menu', ['id' => $menu['id'], 'location' => 'nowhere'])['error']);

    // An author's token can't change menus whatever its scopes.
    assert_contains('manage_menus', (string) r12124_call(r12124_key('author'), 'save_menu', ['name' => 'Nope'])['error']);
});

test('mcp 1.2.124: themes can be listed and switched, and switched back', function () {
    // Core ships one theme, so the test installs a minimal second one.
    $dir = dirname(__DIR__, 2) . '/content/themes/r12124-switch';
    @mkdir($dir . '/templates', 0755, true);
    file_put_contents($dir . '/theme.json', json_encode(['name' => 'Switch test', 'slug' => 'r12124-switch', 'version' => '1.0.0']));
    file_put_contents($dir . '/templates/index.php', '<?php echo "switch test";');
    $cleanup = static function () use ($dir): void {
        @unlink($dir . '/templates/index.php'); @rmdir($dir . '/templates');
        @unlink($dir . '/theme.json'); @rmdir($dir);
    };
    try {
    $key = r12124_key('admin');
    $list = r12124_call($key, 'list_themes')['result'];
    $slugs = array_column($list['themes'], 'slug');
    assert_true(in_array('default', $slugs, true));
    $other = current(array_filter($slugs, fn($s) => $s !== $list['active']));
    assert_true(is_string($other), 'a second theme to switch to');

    $r = r12124_call($key, 'activate_theme', ['slug' => $other]);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same($other, r12124_setting('appearance', 'active_theme'));
    assert_same($list['active'], $r['result']['previous']);
    assert_contains('No installed theme', (string) r12124_call($key, 'activate_theme', ['slug' => '../config'])['error']);
    r12124_call($key, 'activate_theme', ['slug' => $list['active']]);
    assert_same($list['active'], r12124_setting('appearance', 'active_theme'));

    assert_contains('manage_themes', (string) r12124_call(r12124_key('editor'), 'activate_theme', ['slug' => $other])['error']);
    } finally {
        $cleanup();
    }
});

test('mcp 1.2.124: theme options are checked by type', function () {
    $key = r12124_key('admin');
    $opts = r12124_call($key, 'get_theme_options')['result'];
    assert_true(isset($opts['sections']['identity']['options']['identity.site_title']), 'core identity options listed');
    $r = r12124_call($key, 'update_theme_options', ['values' => [
        'identity.tagline' => 'Set by an agent', 'identity.nope' => 'x',
    ]]);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same(1, $r['result']['saved']);
    assert_same(['identity.nope'], $r['result']['skipped']);
    assert_same('Set by an agent', r12124_setting('general', 'tagline'));
});

test('mcp 1.2.124: update_settings saves screen fields only and keeps the dashboard-only ones out', function () {
    $key = r12124_key('admin');
    $r = r12124_call($key, 'update_settings', ['group' => 'reading', 'values' => ['posts_per_page' => 7, 'not_a_field' => 'x']]);
    assert_same('7', r12124_setting('reading', 'posts_per_page'));
    assert_same(['posts_per_page'], $r['result']['saved'] ?? null, json_encode($r));
    assert_true(isset($r['result']['refused']['not_a_field']), 'unknown keys are reported');
    assert_same(null, r12124_setting('reading', 'not_a_field'));

    // The same checks as the screen.
    $r = r12124_call($key, 'update_settings', ['group' => 'reading', 'values' => ['search_mode' => 'magic']]);
    assert_contains('search method', (string) ($r['result']['message'] ?? ''));

    $r = r12124_call($key, 'update_settings', ['group' => 'general', 'values' => ['admin_email' => 'evil@example.test']]);
    assert_true(isset($r['result']['refused']['admin_email']));
    assert_contains('can\'t be changed through MCP', (string) r12124_call($key, 'update_settings', ['group' => 'security', 'values' => ['csp_mode' => 'off']])['error']);
    assert_contains('manage_settings', (string) r12124_call(r12124_key('editor'), 'update_settings', ['group' => 'reading', 'values' => ['posts_per_page' => 3]])['error']);
});

test('rest 1.2.124: menu items are stored with their title', function () {
    $menuId = (new \App\Services\MenuService(bh_db()))->create(['name' => 'REST menu']);
    $key = (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users']['admin'], 'rest menus', ['menus:write', 'menus:read'])['key'];
    $b = new Browser(bh_env()['base_url']);
    $res = $b->request('POST', "/api/v1/menus/{$menuId}/items", json_encode(['label' => 'From REST', 'url' => '/x']),
        ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    assert_same(201, $res->status, $res->body);
    assert_same('From REST', bh_db()->selectOne('SELECT title FROM {menu_items} WHERE menu_id = :m', ['m' => $menuId])['title']);
});
