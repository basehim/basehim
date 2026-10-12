<?php

declare(strict_types=1);

/*
 * 1.2.51 REST API hardening: junk tokens are 401 (not 500), a user's full
 * record is for that user and user managers, credentials are redacted from
 * GET /settings, and a 5xx never returns the exception's message.
 */

function api_login(string $who): string
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
        json_encode(['login' => $who, 'password' => BH_TEST_PASSWORD]), ['Content-Type' => 'application/json']);
    $j = $res->json() ?? [];
    return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
}

function api_get(string $path, string $bearer): HttpResponse
{
    return (new Browser(bh_env()['base_url']))->request('GET', $path, null, ['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json']);
}

test('api: a bearer token with an unknown algorithm is 401, not 500', function () {
    $b = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $none = $b('{"alg":"none"}') . '.' . $b('{"sub":1,"exp":' . (time() + 60) . '}') . '.x';
    assert_same(401, api_get('/api/v1/me', $none)->status);
});

test("api: other users' records show only the public profile", function () {
    $sub = api_login('subscriber');
    assert_true($sub !== '', 'subscriber API login');
    $adminId = bh_env()['users']['admin'];
    $other = api_get('/api/v1/users/' . $adminId, $sub);
    assert_same(200, $other->status, $other->body);
    $d = $other->json()['data'];
    foreach (['email', 'role', 'status', 'meta', 'last_login_at', 'username'] as $k) {
        assert_false(array_key_exists($k, $d), "{$k} exposed to a subscriber");
    }
    assert_same($adminId, (int) $d['id']);
    $self = api_get('/api/v1/users/' . bh_env()['users']['subscriber'], $sub)->json()['data'];
    assert_true(isset($self['email']), 'own record is complete');
    $admin = api_get('/api/v1/users/' . bh_env()['users']['editor'], api_login('admin'))->json()['data'];
    assert_true(isset($admin['email']), 'a user manager sees the full record');
});

test('api: GET /settings never returns credentials', function () {
    $settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
    $settings->set('email', 'smtp_password', 'hunter2-not-for-clients');
    try {
        $key = (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users']['admin'], 'settings test', ['settings:read'])['key'];
        $res = api_get('/api/v1/settings', $key);
        assert_same(200, $res->status, $res->body);
        assert_not_contains('hunter2', $res->body);
        assert_same('[redacted]', $res->json()['data']['email']['smtp_password'] ?? null);
    } finally {
        $settings->set('email', 'smtp_password', '');
    }
});

test('api: writing back a "[redacted]" credential keeps the stored one', function () {
    $settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
    $settings->set('email', 'smtp_password', 'kept-secret-1');
    $port = $settings->get('email', 'smtp_port', '587');
    try {
        $key = (new \App\Services\ApiKeyService(bh_db()))->create(bh_env()['users']['admin'], 'settings rw', ['settings:read', 'settings:write'])['key'];
        $res = (new Browser(bh_env()['base_url']))->request('PUT', '/api/v1/settings',
            json_encode(['email' => ['smtp_password' => '[redacted]', 'smtp_port' => '2525']]),
            ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
        assert_same(200, $res->status, $res->body);
        $row = fn(string $k) => bh_db()->selectOne("SELECT setting_value FROM {settings} WHERE setting_group = 'email' AND setting_key = :k", ['k' => $k])['setting_value'] ?? null;
        assert_contains('kept-secret-1', (string) $row('smtp_password'));
        assert_contains('2525', (string) $row('smtp_port'), 'the other value is saved');
    } finally {
        $settings->set('email', 'smtp_password', '');
        $settings->set('email', 'smtp_port', $port);
    }
});

test('api: a server error does not return the exception message', function () {
    $res = (new Browser(bh_env()['base_url']))->request('GET', '/api/v1/ajax-fixture/boom', null, ['Accept' => 'application/json']);
    assert_same(500, $res->status);
    assert_not_contains('secret_table', $res->body);
    assert_not_contains('/var/www', $res->body);
    assert_contains('logged', (string) ($res->json()['detail'] ?? ''));
});
