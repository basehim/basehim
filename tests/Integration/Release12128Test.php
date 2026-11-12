<?php

declare(strict_types=1);

/*
 * 1.2.128 over HTTP: MCP's post tools take a publish time and the
 * "scheduled" status, as the REST API has since 1.2.126.
 */

function r12128_key(string $who): string
{
    return (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users'][$who], "1.2.128 {$who}", ['posts:read', 'posts:write'])['key'];
}

/** @return array{error: ?string, result: mixed} */
function r12128_call(string $key, string $tool, array $args = []): array
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/mcp', json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args],
    ]), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    $j = $res->json();
    if (isset($j['error'])) return ['error' => (string) $j['error']['message'], 'result' => null];
    $text = $j['result']['content'][0]['text'] ?? '';
    if (!empty($j['result']['isError'])) return ['error' => $text, 'result' => null];
    return ['error' => null, 'result' => json_decode($text, true)];
}

test('mcp 1.2.128: create_post schedules with a future published_at, and backdates with a past one', function () {
    $key = r12128_key('admin');
    $when = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 86400);
    $r = r12128_call($key, 'create_post', ['title' => 'Scheduled by MCP', 'status' => 'scheduled', 'published_at' => $when]);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same('scheduled', $r['result']['status']);
    $row = bh_db()->selectOne('SELECT status, published_at FROM {posts} WHERE id = :i', ['i' => $r['result']['id']]);
    assert_same('scheduled', $row['status']);
    assert_same(gmdate('Y-m-d H:i:s', strtotime($when)), $row['published_at']);

    $old = r12128_call($key, 'create_post', ['title' => 'Backdated by MCP', 'status' => 'published', 'published_at' => '2025-03-01T10:00:00Z']);
    assert_same('published', $old['result']['status']);
    assert_same('2025-03-01 10:00:00', bh_db()->selectOne('SELECT published_at FROM {posts} WHERE id = :i', ['i' => $old['result']['id']])['published_at']);

    // A scheduled post is listable.
    $ids = array_column(r12128_call($key, 'list_posts', ['status' => 'scheduled'])['result']['posts'] ?? [], 'id');
    assert_true(in_array($r['result']['id'], $ids, true), 'list_posts finds scheduled posts');
});

test('mcp 1.2.128: bad or missing publish times are refused, and nothing is saved', function () {
    $key = r12128_key('admin');
    $before = (int) bh_db()->selectOne("SELECT COUNT(*) c FROM {posts}")['c'];
    assert_contains('published_at must be a date', (string) r12128_call($key, 'create_post', ['title' => 'x', 'published_at' => 'next tuesday-ish'])['error']);
    assert_contains('needs a published_at', (string) r12128_call($key, 'create_post', ['title' => 'x', 'status' => 'scheduled'])['error']);
    assert_same($before, (int) bh_db()->selectOne("SELECT COUNT(*) c FROM {posts}")['c']);
});

test('mcp 1.2.128: update_post schedules a draft and clears the time again', function () {
    $key = r12128_key('admin');
    $id = r12128_call($key, 'create_post', ['title' => 'Draft to schedule'])['result']['id'];
    $when = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
    $u = r12128_call($key, 'update_post', ['id' => $id, 'status' => 'scheduled', 'published_at' => $when]);
    assert_same(null, $u['error'], (string) $u['error']);
    assert_same('scheduled', $u['result']['status']);
    $back = r12128_call($key, 'update_post', ['id' => $id, 'status' => 'draft', 'published_at' => '']);
    assert_same('draft', $back['result']['status']);
    assert_same(null, bh_db()->selectOne('SELECT published_at FROM {posts} WHERE id = :i', ['i' => $id])['published_at']);
});

test('mcp 1.2.128: without publish rights a scheduled post goes in for review', function () {
    $r = r12128_call(r12128_key('contributor'), 'create_post', [
        'title' => 'Contributor schedule', 'status' => 'scheduled', 'published_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
    ]);
    assert_same(null, $r['error'], (string) $r['error']);
    assert_same('pending', $r['result']['status']);
});

test('dashboard 1.2.128: At a glance tiles carry their own charts, from real counts', function () {
    $b = bh_browser('admin');
    $vis = dash_visible(dash_state($b));
    $id = $vis['core.dashboard.at-a-glance']['id'] ?? null;
    assert_true($id !== null, 'At a glance is on the default dashboard');

    // A post published two days ago, a comment today, and a PDF in the library.
    $db = bh_db();
    $db->insert('posts', ['uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'r12128-chart-' . bin2hex(random_bytes(3)), 'title' => 'Chart post', 'content' => 'x',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
    $pdf = $db->insert('media', ['uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'mime_type' => 'application/pdf',
        'file_name' => 'r12128.pdf', 'file_size' => 5000, 'storage_path' => 'r12128/r12128.pdf']);

    $res = $b->ajaxGet('widgets.dashboard_data', ['ids' => $id, 'force' => 1]);
    $items = array_column($res->json()['data']['widgets'][$id]['data']['items'] ?? [], null, 'label');
    try {
        $posts = $items['Posts']['chart'] ?? null;
        assert_same('line', $posts['type'] ?? null, json_encode($items['Posts'] ?? null));
        assert_same(12, count($posts['points']));
        assert_true($posts['points'][11]['value'] >= 1, 'the newest week counts the new post');
        assert_contains('Week of', $posts['points'][11]['label']);

        $media = $items['Media']['chart'] ?? null;
        assert_same('split', $media['type'] ?? null);
        assert_same('percent', $media['unit']);
        assert_true(count($media['parts']) <= 3, 'three parts at most');
        assert_true(in_array('Documents', array_column($media['parts'], 'label'), true) || in_array('Other', array_column($media['parts'], 'label'), true));

        assert_same('split', $items['Pages']['chart']['type'] ?? 'none-if-no-pages', 'pages by status');
    } finally {
        $db->execute('DELETE FROM {media} WHERE id = :i', ['i' => $pdf]);
        $db->execute("DELETE FROM {posts} WHERE slug LIKE 'r12128-chart-%'");
    }
});
