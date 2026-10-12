<?php

declare(strict_types=1);

/*
 * 1.2.52: post HTML from accounts without unfiltered_html is filtered on save,
 * whichever way it arrives (REST, MCP, the editor's AJAX save). Until 1.2.52
 * an author could publish <script> or onerror= and it ran on the public page —
 * same origin as the admin — for any administrator who opened the post.
 */

const AH_EVIL = '<p>hello</p><script>window.ahProbe=1</script><img src="x" onerror="window.ahProbe2=1">';

function ah_token(string $who): string
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
        json_encode(['login' => $who, 'password' => BH_TEST_PASSWORD]), ['Content-Type' => 'application/json']);
    $j = $res->json() ?? [];
    return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
}

function ah_rest(string $method, string $path, array $body, string $token): HttpResponse
{
    return (new Browser(bh_env()['base_url']))->request($method, $path, json_encode($body),
        ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
}

function ah_stored(int $id): array
{
    return bh_db()->selectOne('SELECT content, content_format, status FROM {posts} WHERE id = :i', ['i' => $id]) ?? [];
}

function ah_set_caps(string $who, array $grant, array $deny): void
{
    bh_db()->execute('UPDATE {users} SET meta = :m WHERE id = :i', [
        'm' => json_encode(['caps_grant' => $grant, 'caps_deny' => $deny]), 'i' => bh_env()['users'][$who],
    ]);
}

test("author html: an author's REST post loses script and handlers, and is told so", function () {
    $slug = 'ah-' . bin2hex(random_bytes(3));
    $res = ah_rest('POST', '/api/v1/posts', ['title' => 'Probe', 'slug' => $slug, 'status' => 'published',
        'content' => AH_EVIL, 'content_format' => 'html'], ah_token('author'));
    assert_same(201, $res->status, $res->body);
    $j = $res->json();
    assert_contains('your role cannot publish it', (string) ($j['warnings'][0] ?? ''));
    $row = ah_stored((int) $j['data']['id']);
    assert_same('<p>hello</p><img src="x">', $row['content']);
    $page = (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . $slug);
    assert_same(200, $page->status);
    assert_not_contains('ahProbe', $page->body);
});

test('author html: editors and administrators keep raw HTML; no warning', function () {
    foreach (['editor', 'admin'] as $who) {
        $res = ah_rest('POST', '/api/v1/posts', ['title' => 'Raw', 'status' => 'draft', 'content' => AH_EVIL], ah_token($who));
        assert_same(201, $res->status, $res->body);
        assert_false(isset($res->json()['warnings']), "{$who}: no warning");
        assert_same(AH_EVIL, ah_stored((int) $res->json()['data']['id'])['content'], "{$who} keeps the HTML");
    }
});

test('author html: unfiltered_html follows per-user grants and denials', function () {
    try {
        ah_set_caps('author', ['unfiltered_html'], []);
        $res = ah_rest('POST', '/api/v1/posts', ['title' => 'Granted', 'content' => AH_EVIL], ah_token('author'));
        assert_same(AH_EVIL, ah_stored((int) $res->json()['data']['id'])['content'], 'granted author keeps it');

        ah_set_caps('editor', [], ['unfiltered_html']);
        $res = ah_rest('POST', '/api/v1/posts', ['title' => 'Denied', 'content' => AH_EVIL], ah_token('editor'));
        assert_same('<p>hello</p><img src="x">', ah_stored((int) $res->json()['data']['id'])['content'], 'denied editor is filtered');
    } finally {
        ah_set_caps('author', [], []);
        ah_set_caps('editor', [], []);
    }
});

test("author html: updating someone's content through REST is filtered too", function () {
    $res = ah_rest('POST', '/api/v1/posts', ['title' => 'Mine', 'content' => '<p>ok</p>'], ah_token('author'));
    $id = (int) $res->json()['data']['id'];
    $res = ah_rest('PUT', '/api/v1/posts/' . $id, ['content' => '<a href="javascript:alert(1)">x</a>'], ah_token('author'));
    assert_same(200, $res->status, $res->body);
    assert_same('<a>x</a>', ah_stored($id)['content']);
    assert_true(isset($res->json()['warnings']));
});

test("author html: Markdown keeps its source unless its HTML is unsafe", function () {
    $tok = ah_token('author');
    $md = "# Notes\n\n```cpp\n#include <Wire.h>\n```\n\nA line<br>break.\n";
    $res = ah_rest('POST', '/api/v1/posts', ['title' => 'MD', 'content' => $md, 'content_format' => 'markdown'], $tok);
    $row = ah_stored((int) $res->json()['data']['id']);
    assert_same($md, $row['content']);
    assert_same('markdown', $row['content_format']);

    $res = ah_rest('POST', '/api/v1/posts', ['title' => 'MD bad', 'content' => "# Notes\n\n<img src=x onerror=alert(1)>\n",
        'content_format' => 'markdown'], $tok);
    $row = ah_stored((int) $res->json()['data']['id']);
    assert_same('html', $row['content_format']);
    assert_not_contains('onerror', $row['content']);
    assert_contains('saved as HTML', (string) ($res->json()['warnings'][0] ?? ''));
});

test("author html: the editor's draft save filters HTML blocks and says so", function () {
    $b = bh_browser('author');
    $doc = json_encode(['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'data' => ['text' => 'Intro']],
        ['type' => 'html', 'data' => ['html' => '<div>widget</div><script>window.ahProbe=1</script>']],
    ]]);
    $res = $b->ajax('posts.save_draft', ['type' => 'post', 'title' => 'Blocks', 'content' => $doc, 'content_format' => 'blocks',
        'status' => 'draft'], ['Idempotency-Key' => bin2hex(random_bytes(12))]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_contains('scripts', (string) $d['html_notice']);
    $saved = json_decode(ah_stored((int) $d['id'])['content'], true);
    assert_same('<div>widget</div>', $saved['blocks'][1]['data']['html']);

    $res = bh_browser('editor')->ajax('posts.save_draft', ['type' => 'post', 'title' => 'Blocks', 'content' => $doc,
        'content_format' => 'blocks', 'status' => 'draft'], ['Idempotency-Key' => bin2hex(random_bytes(12))]);
    assert_same(null, $res->json()['data']['html_notice'], 'an editor saves as written');
    assert_same($doc, ah_stored((int) $res->json()['data']['id'])['content']);
});

test("author html: an author's MCP token is filtered as well", function () {
    $key = (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users']['author'], 'ah mcp', ['posts:read', 'posts:write'])['key'];
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/mcp', json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'create_post', 'arguments' => ['title' => 'Agent', 'content' => AH_EVIL]],
    ]), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    $out = json_decode((string) ($res->json()['result']['content'][0]['text'] ?? ''), true);
    assert_true(isset($out['id']), $res->body);
    assert_contains('cannot publish', (string) ($out['warning'] ?? ''));
    assert_same('<p>hello</p><img src="x">', ah_stored((int) $out['id'])['content']);
});
