<?php

declare(strict_types=1);

/*
 * 1.2.126 over HTTP: menus whose name is already used, and the REST API's
 * published_at and "scheduled" status.
 */

function r12126_key(string $who, array $scopes): string
{
    return (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users'][$who], "1.2.126 {$who}", $scopes)['key'];
}

function r12126_req(string $key, string $method, string $path, ?array $body = null): array
{
    $res = (new Browser(bh_env()['base_url']))->request($method, '/api/v1' . $path, $body === null ? null : json_encode($body),
        ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key]);
    return [$res->status, json_decode($res->body, true) ?? $res->body];
}

test('rest 1.2.126: a menu named like an existing one gets its own slug; a taken slug is a 422', function () {
    $key = r12126_key('admin', ['menus:read', 'menus:write']);
    [$s1, $a] = r12126_req($key, 'POST', '/menus', ['name' => 'Twin menu']);
    [$s2, $b] = r12126_req($key, 'POST', '/menus', ['name' => 'Twin menu']);
    assert_same(201, $s1, json_encode($a));
    assert_same(201, $s2, json_encode($b));
    assert_same('twin-menu', $a['data']['slug']);
    assert_same('twin-menu-2', $b['data']['slug']);

    [$s3, $c] = r12126_req($key, 'POST', '/menus', ['name' => 'Other', 'slug' => 'twin-menu']);
    assert_same(422, $s3);
    assert_contains('already used by the menu "Twin menu"', (string) ($c['error'] ?? ''));

    [$s4, $d] = r12126_req($key, 'PUT', '/menus/' . $b['data']['id'], ['slug' => 'twin-menu']);
    assert_same(422, $s4);
    assert_contains('already used', (string) ($d['error'] ?? ''));
    [$s5] = r12126_req($key, 'PUT', '/menus/' . $b['data']['id'], ['slug' => 'twin-menu-2', 'name' => 'Twin menu (copy)']);
    assert_same(200, $s5, 'keeping its own slug is fine');
    assert_same(0, (int) bh_db()->selectOne("SELECT COUNT(*) c FROM {menus} WHERE slug = ''")['c']);
});

test('rest 1.2.126: published_at backdates and schedules posts; bad dates are a 422', function () {
    $key = r12126_key('admin', ['posts:read', 'posts:write']);
    [$s, $old] = r12126_req($key, 'POST', '/posts', ['title' => 'Backdated', 'status' => 'published', 'published_at' => '2026-01-15T09:30:00Z']);
    assert_same(201, $s, json_encode($old));
    assert_same('published', $old['data']['status']);
    assert_same('2026-01-15 09:30:00', $old['data']['published_at']);

    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400);
    [$s, $sch] = r12126_req($key, 'POST', '/posts', ['title' => 'Later', 'status' => 'scheduled', 'published_at' => $future]);
    assert_same(201, $s, json_encode($sch));
    assert_same('scheduled', $sch['data']['status']);

    [$s, $e] = r12126_req($key, 'POST', '/posts', ['title' => 'No date', 'status' => 'scheduled']);
    assert_same(422, $s);
    assert_contains('needs a published_at', (string) ($e['error'] ?? ''));
    [$s, $e] = r12126_req($key, 'POST', '/posts', ['title' => 'Bad date', 'status' => 'published', 'published_at' => 'next tuesday-ish']);
    assert_same(422, $s);
    assert_contains('published_at must be a date', (string) ($e['error'] ?? ''));

    // An update can move the date on its own, and a scheduled post keeps its
    // date when only its title changes.
    [$s, $u] = r12126_req($key, 'PUT', '/posts/' . $old['data']['id'], ['published_at' => '2026-02-01 08:00:00']);
    assert_same(200, $s, json_encode($u));
    assert_same('2026-02-01 08:00:00', $u['data']['published_at']);
    [$s, $u] = r12126_req($key, 'PUT', '/posts/' . $sch['data']['id'], ['title' => 'Later, renamed', 'status' => 'scheduled']);
    assert_same(200, $s, json_encode($u));
    assert_same('scheduled', $u['data']['status']);
});

test('rest 1.2.126: a contributor cannot schedule; the post goes in for review', function () {
    $key = r12126_key('contributor', ['posts:read', 'posts:write']);
    [$s, $p] = r12126_req($key, 'POST', '/posts', ['title' => 'Contributor schedule', 'status' => 'scheduled', 'published_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    assert_same(201, $s, json_encode($p));
    assert_same('pending', $p['data']['status']);
});
