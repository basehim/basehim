<?php

declare(strict_types=1);

/*
 * 1.2.51: an MCP credential acts as its owner. The scope picks the tools; the
 * owner's role decides what they may do, as on the REST API. Until 1.2.51 the
 * MCP tools checked scopes only, so a posts:write token of a contributor or an
 * author could publish, edit and trash anyone's posts, and a suspended
 * account's keys and OAuth tokens kept working.
 */

const MCP_ALL_SCOPES = ['posts:read', 'posts:write', 'comments:read', 'comments:write', 'users:read',
                        'settings:read', 'media:read', 'taxonomies:write', 'menus:write'];

function mcp_key(string $who, array $scopes = MCP_ALL_SCOPES): string
{
    $svc = new \App\Services\ApiKeyService(bh_db());
    return $svc->create(bh_env()['users'][$who], "mcp test {$who}", $scopes)['key'];
}

/** @return array{error: ?string, result: mixed} */
function mcp_call(string $key, string $tool, array $args = []): array
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/mcp', json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args],
    ]), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    $j = $res->json();
    if (isset($j['error'])) return ['error' => (string) $j['error']['message'], 'result' => null];
    $text = $j['result']['content'][0]['text'] ?? '';
    if (!empty($j['result']['isError'])) return ['error' => $text, 'result' => null];
    return ['error' => null, 'result' => json_decode($text, true)];
}

function mcp_post(string $owner, string $status): int
{
    return (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => 'post', 'status' => $status,
        'slug' => 'mcp-' . bin2hex(random_bytes(4)), 'title' => "{$owner} {$status}", 'content' => '<p>x</p>',
        'content_format' => 'html', 'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
    ]);
}

test("mcp: an author's token cannot edit or trash someone else's post", function () {
    $key = mcp_key('author');
    $admins = mcp_post('admin', 'published');
    $r = mcp_call($key, 'update_post', ['id' => $admins, 'title' => 'defaced']);
    assert_contains('edit_others_posts', (string) $r['error']);
    assert_same('admin published', bh_db()->selectOne('SELECT title FROM {posts} WHERE id = :i', ['i' => $admins])['title']);
    $r = mcp_call($key, 'trash_post', ['id' => $admins]);
    assert_contains('delete_others_posts', (string) $r['error']);
    assert_same(null, bh_db()->selectOne('SELECT deleted_at FROM {posts} WHERE id = :i', ['i' => $admins])['deleted_at']);

    // Their own draft is fine.
    $own = mcp_post('author', 'draft');
    $r = mcp_call($key, 'update_post', ['id' => $own, 'title' => 'Mine, edited']);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same('Mine, edited', bh_db()->selectOne('SELECT title FROM {posts} WHERE id = :i', ['i' => $own])['title']);
});

test("mcp: a contributor's token submits for review instead of publishing", function () {
    $key = mcp_key('contributor');
    $r = mcp_call($key, 'create_post', ['title' => 'From an assistant', 'status' => 'published']);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same('pending', $r['result']['status']);
    $own = mcp_post('contributor', 'draft');
    $r = mcp_call($key, 'update_post', ['id' => $own, 'status' => 'published']);
    assert_same('pending', $r['result']['status'] ?? null, (string) $r['error']);
});

test("mcp: drafts are their authors' business", function () {
    $key = mcp_key('author');
    $theirs = mcp_post('editor', 'draft');
    assert_contains('not found', strtolower((string) mcp_call($key, 'get_post', ['id' => $theirs])['error']));
    $list = mcp_call($key, 'list_posts', ['status' => 'draft', 'per_page' => 50])['result'];
    foreach ($list['posts'] as $p) assert_true((int) $p['id'] !== $theirs, 'another author\'s draft listed');
    // An editor (edit_others_posts) sees it.
    assert_same(null, mcp_call(mcp_key('editor'), 'get_post', ['id' => $theirs])['error']);
});

test('mcp: admin-level tools need the admin capability, whatever the scope', function () {
    $key = mcp_key('author');
    foreach (['list_users' => 'manage_users', 'get_settings' => 'manage_settings', 'list_comments' => 'moderate_comments'] as $tool => $cap) {
        assert_contains($cap, (string) mcp_call($key, $tool)['error'], $tool);
    }
    $admin = mcp_key('admin');
    assert_same(null, mcp_call($admin, 'list_users')['error']);
});

test("mcp: a suspended account's key stops working", function () {
    $key = mcp_key('author2');
    assert_same(null, mcp_call($key, 'get_site_info')['error']);
    $id = bh_env()['users']['author2'];
    try {
        bh_db()->execute("UPDATE {users} SET status = 'suspended' WHERE id = :i", ['i' => $id]);
        assert_contains('not active', (string) mcp_call($key, 'get_site_info')['error']);
    } finally {
        bh_db()->execute("UPDATE {users} SET status = 'active' WHERE id = :i", ['i' => $id]);
    }
});

test('oauth: scopes without a capability rule are granted to administrators only', function () {
    $ctrl = new \App\Http\Controllers\Api\OAuthController();
    $m = new ReflectionMethod($ctrl, 'filterByCapability');
    $m->setAccessible(true);
    $db = bh_db();
    $sub = $db->selectOne('SELECT * FROM {users} WHERE id = :i', ['i' => bh_env()['users']['subscriber']]);
    $adm = $db->selectOne('SELECT * FROM {users} WHERE id = :i', ['i' => bh_env()['users']['admin']]);
    assert_same('', $m->invoke($ctrl, 'someapp:write', $sub), 'subscriber');
    assert_same('someapp:write', $m->invoke($ctrl, 'someapp:write', $adm), 'admin');
    assert_same('', $m->invoke($ctrl, 'posts:write', $sub), 'core scope still mapped');
});
