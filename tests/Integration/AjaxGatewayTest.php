<?php

declare(strict_types=1);

/*
 * End-to-end: real HTTP requests to the real index.php, with real sessions,
 * the real login form and a real database. Each Browser is a separate cookie
 * jar, i.e. a separate person.
 */

/** @return array{0:Browser,1:Browser,2:Browser} admin, author, subscriber — signed in once and reused */
function people(): array
{
    static $p = null;
    return $p ??= [bh_browser('admin'), bh_browser('author'), bh_browser('subscriber')];
}

function assert_error(HttpResponse $res, int $status, string $code, string $msg = ''): void
{
    $ctx = $msg !== '' ? $msg . ' — ' : '';
    assert_same($status, $res->status, $ctx . 'status (body: ' . substr($res->body, 0, 300) . ')');
    $j = $res->json();
    assert_true(is_array($j), $ctx . 'JSON body');
    assert_same(false, $j['success'] ?? null, $ctx . 'success flag');
    assert_same($code, $j['error']['code'] ?? null, $ctx . 'error code');
    assert_true(is_string($j['error']['message'] ?? null) && $j['error']['message'] !== '', $ctx . 'a message');
    assert_true((bool) preg_match('/^[0-9a-f]{16}$/', (string) ($j['request_id'] ?? '')), $ctx . 'request_id');
    assert_contains('application/json', (string) $res->header('content-type'), $ctx . 'content type');
}

// ---------------------------------------------------------------------------
// Success path and response format
// ---------------------------------------------------------------------------

test('e2e: a registered action runs and returns the standard success envelope', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['msg' => 'hello', 'n' => 3, 'tags' => ['a'], 'meta' => ['color' => 'red']]);
    assert_same(200, $res->status, $res->body);
    $j = $res->json();
    assert_same(true, $j['success']);
    assert_same('hello', $j['data']['msg']);
    assert_same(3, $j['data']['n']);
    assert_same(['a'], $j['data']['tags']);
    assert_same(['color' => 'red'], $j['data']['meta']);
    assert_same(bh_env()['users']['admin'], $j['data']['user_id']);
    assert_false($j['data']['has_hash'], 'handlers never see the password hash');
    assert_same($j['request_id'], $res->header('x-request-id'));
    assert_contains('application/json', (string) $res->header('content-type'));
    assert_same('no-store', $res->header('cache-control'));
    assert_same('nosniff', $res->header('x-content-type-options'));
});

test('e2e: only fields that were sent reach the handler', function () {
    [$admin] = people();
    $j = $admin->ajax('ajax-fixture.echo', ['msg' => 'x'])->json();
    assert_same(['msg'], $j['data']['keys']);
});

test('e2e: the admin layout carries the client and a working token', function () {
    $b = bh_browser('editor');
    $page = $b->request('GET', '/admin/media');
    assert_contains('/admin/assets/js/ajax.js', $page->body);
    assert_contains('window.BASEHIM_AJAX', $page->body);
    $js = $b->request('GET', '/admin/assets/js/ajax.js');
    assert_same(200, $js->status);
    assert_contains('Basehim.ajax', $js->body);
    assert_same(200, $b->ajax('ajax-fixture.echo', ['msg' => 'ok'])->status);
});

test('e2e: an app can load the client on public pages from boot() — URL only, no token, no session', function () {
    $v = bh_browser();
    $home = $v->request('GET', '/');
    assert_same(200, $home->status, substr($home->body, 0, 200));
    if (!preg_match('/window\.BASEHIM_AJAX = (\{.*?\});/', $home->body, $m)) fail('no client config on the home page');
    $cfg = json_decode($m[1], true);
    assert_same(['url' => '/ajax'], $cfg, 'only the endpoint URL');
    assert_contains('/admin/assets/js/ajax.js', $home->body);
    assert_same(null, $v->cookies['BASEHIMSESS'] ?? null, 'a public page view starts no session');
});

// ---------------------------------------------------------------------------
// Unknown actions and injection attempts
// ---------------------------------------------------------------------------

test('e2e: unknown actions are 404', function () {
    [$admin] = people();
    assert_error($admin->ajax('ajax-fixture.nope'), 404, 'ACTION_NOT_FOUND');
    assert_error($admin->ajax('posts.save'), 404, 'ACTION_NOT_FOUND');
});

test('e2e: handler names, class names and paths in the action are just unknown names', function () {
    [$admin] = people();
    foreach (['phpinfo', 'App\\Core\\Env::all', 'App\\Http\\Controllers\\Ajax\\MediaActions@update',
              '../../../etc/passwd', 'ajax-fixture.echo/../boom', 'ajax-fixture.echo ', "ajax-fixture.echo\0", 'AJAX-FIXTURE.ECHO'] as $name) {
        assert_error($admin->ajax($name), 404, 'ACTION_NOT_FOUND', $name);
    }
    $body = json_encode(['action' => ['App\\Core\\Env', 'all'], 'data' => []]);
    assert_error($admin->request('POST', '/ajax', $body, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admin->csrf]), 400, 'BAD_REQUEST');
});

test('e2e: trying to smuggle a handler or callback in the payload is refused', function () {
    [$admin] = people();
    $body = json_encode(['action' => 'ajax-fixture.echo', 'handler' => 'phpinfo', 'data' => ['msg' => 'x']]);
    assert_error($admin->request('POST', '/ajax', $body, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admin->csrf]), 400, 'BAD_REQUEST');
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x', 'callback' => 'system']), 422, 'VALIDATION_FAILED');
});

// ---------------------------------------------------------------------------
// Methods, content types, parsing, size
// ---------------------------------------------------------------------------

test('e2e: unsupported HTTP methods are 405 with Allow', function () {
    [$admin] = people();
    foreach (['PUT', 'DELETE', 'PATCH'] as $m) {
        $res = $admin->request($m, '/ajax', '{}', ['Content-Type' => 'application/json']);
        assert_error($res, 405, 'METHOD_NOT_ALLOWED', $m);
        assert_same('GET, POST', $res->header('allow'));
    }
});

test('e2e: OPTIONS and HEAD reach the gateway too (405, never the HTML 404 page)', function () {
    [$admin] = people();
    $res = $admin->request('OPTIONS', '/ajax', null, ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST']);
    assert_error($res, 405, 'METHOD_NOT_ALLOWED');
    assert_same(null, $res->header('access-control-allow-origin'));
    $res = $admin->request('HEAD', '/ajax');
    assert_same(405, $res->status);
    assert_contains('application/json', (string) $res->header('content-type'));
});

test('e2e: an action used with a method it did not declare is 405', function () {
    [$admin] = people();
    $res = $admin->ajaxGet('ajax-fixture.echo', ['msg' => 'x']);
    assert_error($res, 405, 'METHOD_NOT_ALLOWED');
    assert_same('POST', $res->header('allow'));
    $res = $admin->ajax('ajax-fixture.read');
    assert_error($res, 405, 'METHOD_NOT_ALLOWED');
    assert_same('GET', $res->header('allow'));
});

test('e2e: only application/json is accepted for POST', function () {
    [$admin] = people();
    $form = http_build_query(['action' => 'ajax-fixture.echo', 'data' => ['msg' => 'x']]);
    assert_error($admin->request('POST', '/ajax', $form, ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => $admin->csrf]), 415, 'UNSUPPORTED_MEDIA_TYPE');
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Content-Type' => 'text/plain']), 415, 'UNSUPPORTED_MEDIA_TYPE');
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Content-Type' => 'application/json; charset=iso-8859-1']), 415, 'UNSUPPORTED_MEDIA_TYPE');
    assert_same(200, $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Content-Type' => 'application/json; charset=UTF-8'])->status);
});

test('e2e: malformed and mis-shaped JSON is 400', function () {
    [$admin] = people();
    $h = ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admin->csrf];
    assert_error($admin->request('POST', '/ajax', '{"action": "ajax-fixture.echo", ', $h), 400, 'MALFORMED_JSON');
    assert_error($admin->request('POST', '/ajax', '', $h), 400, 'BAD_REQUEST');
    assert_error($admin->request('POST', '/ajax', '["ajax-fixture.echo"]', $h), 400, 'BAD_REQUEST');
    $res = $admin->request('POST', '/ajax', '[]', $h);
    assert_error($res, 400, 'BAD_REQUEST');
    assert_contains('JSON object', $res->json()['error']['message'], 'an empty list is not an object');
    assert_error($admin->request('POST', '/ajax', '"ajax-fixture.echo"', $h), 400, 'BAD_REQUEST');
    assert_error($admin->request('POST', '/ajax', '{"data":{}}', $h), 400, 'BAD_REQUEST');
    $deep = str_repeat('[', 100) . str_repeat(']', 100);
    assert_error($admin->request('POST', '/ajax', '{"action":"ajax-fixture.echo","data":' . $deep . '}', $h), 400, 'MALFORMED_JSON');
});

test('e2e: data must be an object', function () {
    [$admin] = people();
    $h = ['Content-Type' => 'application/json', 'X-CSRF-Token' => $admin->csrf, 'Origin' => $admin->baseUrl];
    assert_error($admin->request('POST', '/ajax', '{"action":"ajax-fixture.echo","data":"msg"}', $h), 422, 'VALIDATION_FAILED');
    assert_error($admin->request('POST', '/ajax', '{"action":"ajax-fixture.echo","data":["msg"]}', $h), 422, 'VALIDATION_FAILED');
});

test('e2e: oversized bodies are 413 before parsing', function () {
    [$admin] = people();
    $big = str_repeat('a', 70 * 1024);
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => $big]), 413, 'PAYLOAD_TOO_LARGE');
    // An action's own smaller limit applies too.
    assert_error($admin->ajax('ajax-fixture.small', ['blob' => str_repeat('b', 600)]), 413, 'PAYLOAD_TOO_LARGE');
    assert_same(200, $admin->ajax('ajax-fixture.small', ['blob' => 'tiny'])->status);
});

test('e2e: GET /ajax with no action is a JSON 400, not an HTML page', function () {
    $res = bh_browser()->request('GET', '/ajax', null, ['Sec-Fetch-Site' => 'same-origin']);
    assert_error($res, 400, 'BAD_REQUEST');
});

// ---------------------------------------------------------------------------
// Authentication and sessions
// ---------------------------------------------------------------------------

test('e2e: signed-in actions need a session (401)', function () {
    $anon = bh_browser();
    assert_error($anon->ajax('ajax-fixture.echo', ['msg' => 'x']), 401, 'AUTH_REQUIRED');
    // A made-up session cookie is no better.
    $anon->cookies['BASEHIMSESS'] = str_repeat('a', 26);
    assert_error($anon->ajax('ajax-fixture.echo', ['msg' => 'x']), 401, 'AUTH_REQUIRED');
});

test('e2e: bearer tokens are refused — this endpoint is for browser sessions', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Authorization' => 'Bearer abc.def.ghi']);
    assert_error($res, 401, 'UNSUPPORTED_AUTH');
});

test('e2e: HTTP Basic (a password-protected staging site) is ignored, not refused', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Authorization' => 'Basic ' . base64_encode('staging:secret')]);
    assert_same(200, $res->status, $res->body);
    foreach (['Token abc', 'ApiKey abc', 'bearer abc'] as $h) {
        assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Authorization' => $h]), 401, 'UNSUPPORTED_AUTH', $h);
    }
    // And Basic never signs anyone in.
    $anon = new Browser(bh_env()['base_url']);
    $res = $anon->request('POST', '/ajax', '{"action":"media.update","data":{"id":1}}',
        ['Content-Type' => 'application/json', 'Authorization' => 'Basic ' . base64_encode('admin:' . BH_TEST_PASSWORD)]);
    assert_error($res, 401, 'AUTH_REQUIRED');
});

test('e2e: after logout the session no longer works, and the old token is dead', function () {
    $b = bh_browser('author2');
    assert_same(200, $b->ajax('ajax-fixture.echo', ['msg' => 'x'])->status);
    $old = $b->csrf;
    $out = $b->request('POST', '/admin/logout', http_build_query(['_csrf' => $old]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $out->status);
    assert_error($b->ajax('ajax-fixture.echo', ['msg' => 'x']), 401, 'AUTH_REQUIRED');
    // Signing back in: the token from before logout must not be accepted.
    $b->login('author2', BH_TEST_PASSWORD);
    assert_true($b->csrf !== $old, 'a new token after signing in again');
    assert_error($b->ajax('ajax-fixture.echo', ['msg' => 'x'], [], $old), 403, 'CSRF_INVALID');
    assert_same(200, $b->ajax('ajax-fixture.echo', ['msg' => 'x'])->status);
});

test('e2e: a token obtained before signing in does not survive the sign-in', function () {
    $b = bh_browser();
    $pre = $b->ajaxGet('core.csrf');
    assert_same(200, $pre->status, $pre->body);
    $preToken = $pre->json()['data']['token'];
    assert_false($pre->json()['data']['signed_in']);
    $b->login('author', BH_TEST_PASSWORD);
    assert_true($b->csrf !== $preToken, 'token rotated at sign-in');
    assert_error($b->ajax('ajax-fixture.echo', ['msg' => 'x'], [], $preToken), 403, 'CSRF_INVALID');
});

test('e2e: a suspended account is signed out on its next AJAX call', function () {
    $b = bh_browser('author2');
    assert_same(200, $b->ajax('ajax-fixture.echo', ['msg' => 'x'])->status);
    bh_db()->update('users', ['status' => 'suspended'], ['id' => bh_env()['users']['author2']]);
    try {
        assert_error($b->ajax('ajax-fixture.echo', ['msg' => 'x']), 401, 'AUTH_REQUIRED');
    } finally {
        bh_db()->update('users', ['status' => 'active'], ['id' => bh_env()['users']['author2']]);
    }
});

test('e2e: a password change elsewhere ends the session (fingerprint)', function () {
    $b = bh_browser('editor');
    assert_same(200, $b->ajax('ajax-fixture.echo', ['msg' => 'x'])->status);
    $orig = bh_db()->selectOne('SELECT password_hash FROM {users} WHERE id = :id', ['id' => bh_env()['users']['editor']])['password_hash'];
    bh_db()->update('users', ['password_hash' => password_hash('changed-elsewhere', PASSWORD_BCRYPT, ['cost' => 4])], ['id' => bh_env()['users']['editor']]);
    try {
        assert_error($b->ajax('ajax-fixture.echo', ['msg' => 'x']), 401, 'AUTH_REQUIRED');
    } finally {
        bh_db()->update('users', ['password_hash' => $orig], ['id' => bh_env()['users']['editor']]);
    }
});

// ---------------------------------------------------------------------------
// CSRF and cross-origin
// ---------------------------------------------------------------------------

test('e2e: a missing CSRF token is 403', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['X-CSRF-Token' => null], '');
    assert_error($res, 403, 'CSRF_INVALID');
});

test('e2e: a wrong CSRF token is 403, and the token is not accepted from the body', function () {
    [$admin] = people();
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], [], str_repeat('0', 64)), 403, 'CSRF_INVALID');
    $body = json_encode(['action' => 'ajax-fixture.echo', 'data' => ['msg' => 'x', '_csrf' => $admin->csrf]]);
    $res = $admin->request('POST', '/ajax', $body, ['Content-Type' => 'application/json', 'Sec-Fetch-Site' => 'same-origin']);
    assert_error($res, 403, 'CSRF_INVALID');
});

test("e2e: another user's token does not work in this session", function () {
    [$admin, $author] = people();
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], [], $author->csrf), 403, 'CSRF_INVALID');
});

test('e2e: cross-site requests are refused even with a valid token', function () {
    [$admin] = people();
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Origin' => 'https://evil.example']), 403, 'ORIGIN_REJECTED');
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Origin' => 'null']), 403, 'ORIGIN_REJECTED');
    assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Sec-Fetch-Site' => 'cross-site', 'Origin' => null]), 403, 'ORIGIN_REJECTED');
    // A cross-site GET (an <img src>, say) too.
    assert_error($admin->ajaxGet('ajax-fixture.read', [], ['Sec-Fetch-Site' => 'cross-site']), 403, 'ORIGIN_REJECTED');
    // Same-origin with no fetch metadata at all (older browsers) still works.
    assert_same(200, $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], ['Origin' => null, 'Sec-Fetch-Site' => null])->status);
});

test('e2e: the gateway sends no CORS headers', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['msg' => 'x']);
    assert_same(null, $res->header('access-control-allow-origin'));
    assert_same(null, $res->header('access-control-allow-credentials'));
});

// ---------------------------------------------------------------------------
// Authorization and resource ownership
// ---------------------------------------------------------------------------

test('e2e: a user without the declared capability is 403', function () {
    [$admin, , $subscriber] = people();
    assert_error($subscriber->ajax('ajax-fixture.admin-only'), 403, 'FORBIDDEN');
    assert_same(200, $admin->ajax('ajax-fixture.admin-only')->status);
    assert_error($subscriber->ajax('media.update', ['id' => bh_env()['media']['author'], 'title' => 'x']), 403, 'FORBIDDEN');
});

test('e2e: a role or user ID sent by the client changes nothing', function () {
    [, , $subscriber] = people();
    assert_error($subscriber->ajax('ajax-fixture.admin-only', ['role' => 'super_admin']), 403, 'FORBIDDEN');
    assert_error($subscriber->ajax('ajax-fixture.admin-only', [], ['X-User-Role' => 'admin', 'X-User-Id' => '1']), 403, 'FORBIDDEN');
});

test('e2e: media.update — authors can edit their own files', function () {
    [, $author] = people();
    $id = bh_env()['media']['author'];
    $res = $author->ajax('media.update', ['id' => $id, 'alt_text' => 'A heron at dusk — été', 'caption' => 'Caption']);
    assert_same(200, $res->status, $res->body);
    assert_same('A heron at dusk — été', $res->json()['data']['item']['alt_text']);
    $row = bh_db()->selectOne('SELECT alt_text, caption FROM {media} WHERE id = :id', ['id' => $id]);
    assert_same('A heron at dusk — été', $row['alt_text']);
});

test("e2e: media.update — an author cannot edit an administrator's file by changing the ID (IDOR)", function () {
    [, $author] = people();
    $id = bh_env()['media']['admin'];
    $res = $author->ajax('media.update', ['id' => $id, 'alt_text' => 'pwned']);
    assert_error($res, 403, 'FORBIDDEN');
    assert_same('original', bh_db()->selectOne('SELECT alt_text FROM {media} WHERE id = :id', ['id' => $id])['alt_text']);
});

test("e2e: media.update — an editor (edit_others_posts) can fix alt text on others' files, but not delete them", function () {
    [$admin] = people();
    $editor = bh_browser('editor');
    $authorFile = bh_env()['media']['author'];
    $res = $editor->ajax('media.update', ['id' => $authorFile, 'alt_text' => 'Fixed by the editor']);
    assert_same(200, $res->status, $res->body);
    assert_same('Fixed by the editor', $res->json()['data']['item']['alt_text']);
    assert_same(200, $admin->ajax('media.update', ['id' => $authorFile, 'title' => 'Renamed by admin'])->status);
    // Deleting someone else's file still needs delete_media.
    $res = $editor->request('POST', "/admin/media/{$authorFile}/delete", http_build_query(['_csrf' => $editor->csrf]),
        ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-Token' => $editor->csrf]);
    assert_same(403, $res->status, $res->body);
});

test('e2e: a contributor (no upload_media) cannot edit media at all', function () {
    $c = bh_browser('contributor');
    assert_error($c->ajax('media.update', ['id' => bh_env()['media']['author'], 'title' => 'x']), 403, 'FORBIDDEN');
});

test('e2e: media.update — unknown IDs are 404 and bad input is 422 with field errors', function () {
    [$admin] = people();
    assert_error($admin->ajax('media.update', ['id' => 999999, 'title' => 'x']), 404, 'NOT_FOUND');
    $res = $admin->ajax('media.update', ['id' => '5', 'title' => ['x']]);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_true(isset($res->json()['error']['fields']['id'], $res->json()['error']['fields']['title']));
    assert_error($admin->ajax('media.update', ['id' => bh_env()['media']['admin']]), 422, 'VALIDATION_FAILED');
    assert_error($admin->ajax('media.update', ['id' => bh_env()['media']['admin'], 'title' => str_repeat('x', 256)]), 422, 'VALIDATION_FAILED');
});

test('e2e: media.update — mass assignment of other columns is refused and nothing is written', function () {
    [, $author] = people();
    $id = bh_env()['media']['author'];
    $before = bh_db()->selectOne('SELECT * FROM {media} WHERE id = :id', ['id' => $id]);
    $res = $author->ajax('media.update', ['id' => $id, 'title' => 'ok', 'author_id' => 1, 'storage_path' => '../../.env', 'mime_type' => 'text/html']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    $after = bh_db()->selectOne('SELECT * FROM {media} WHERE id = :id', ['id' => $id]);
    assert_same($before, $after);
});

// ---------------------------------------------------------------------------
// Input validation (fixture schema)
// ---------------------------------------------------------------------------

test('e2e: input that breaks the schema is 422 with per-field messages', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.echo', ['n' => 'three', 'tags' => ['a', 'b', 'c', 'd'], 'meta' => ['color' => 'green', 'x' => 1], 'extra' => true]);
    assert_error($res, 422, 'VALIDATION_FAILED');
    $f = $res->json()['error']['fields'];
    foreach (['msg', 'n', 'tags', 'meta.color', 'meta.x', 'extra'] as $k) {
        assert_true(isset($f[$k]), "error for {$k}: " . json_encode($f));
    }
});

test('e2e: GET actions read typed values from the query string', function () {
    [$admin] = people();
    $res = $admin->ajaxGet('ajax-fixture.read', ['page' => '3', 'flag' => 'true']);
    assert_same(200, $res->status, $res->body);
    assert_same(['page' => 3, 'flag' => true], $res->json()['data']);
    assert_error($admin->ajaxGet('ajax-fixture.read', ['page' => 'abc']), 422, 'VALIDATION_FAILED');
    assert_error($admin->ajaxGet('ajax-fixture.read', ['sneaky' => '1']), 422, 'VALIDATION_FAILED');
});

// ---------------------------------------------------------------------------
// Public actions
// ---------------------------------------------------------------------------

test('e2e: a public action with CSRF off runs anonymously — even for a signed-in caller', function () {
    [$admin] = people();
    $anon = bh_browser();
    $r1 = $anon->ajax('ajax-fixture.public-ping', [], [], '');
    assert_same(200, $r1->status, $r1->body);
    assert_false($r1->json()['data']['signed_in']);
    $r2 = $admin->ajax('ajax-fixture.public-ping', [], [], '');
    assert_same(200, $r2->status);
    assert_false($r2->json()['data']['signed_in'], 'the session is not opened for it');
    assert_same(null, $anon->cookies['BASEHIMSESS'] ?? null, 'no session is started for the visitor');
});

test('e2e: a public action with CSRF needs the visitor session token', function () {
    $v = bh_browser();
    assert_error($v->ajax('ajax-fixture.public-form', ['email' => 'a@example.com'], [], ''), 403, 'CSRF_INVALID');
    $tok = $v->ajaxGet('core.csrf')->json()['data']['token'];
    $res = $v->ajax('ajax-fixture.public-form', ['email' => 'a@example.com'], [], $tok);
    assert_same(200, $res->status, $res->body);
    assert_same('a@example.com', $res->json()['data']['received']);
    assert_error($v->ajax('ajax-fixture.public-form', ['email' => 'nope'], [], $tok), 422, 'VALIDATION_FAILED');
});

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

test('e2e: an unexpected exception is a generic 500 that leaks nothing, and is logged with the request ID', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.boom');
    assert_error($res, 500, 'INTERNAL_ERROR');
    foreach (['SQLSTATE', 'secret_table', '/var/www', 'RuntimeException', '.php', 'trace'] as $leak) {
        assert_not_contains($leak, $res->body, 'response leaks ' . $leak);
    }
    $rid = $res->json()['request_id'];
    $core = bh_core_log();
    assert_contains($rid, $core, 'core log has the request ID');
    assert_contains('secret_table', $core, 'core log has the real message');
    assert_contains($rid, bh_app_log(), "the app's own log has it too");
});

test("e2e: an AjaxException's code, message and status reach the client", function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.safe-error');
    assert_error($res, 409, 'QUOTA_EXCEEDED');
    assert_same('You have used your quota.', $res->json()['error']['message']);
});

test('e2e: stray output from a handler is discarded and logged, the JSON stays valid', function () {
    [$admin] = people();
    $res = $admin->ajax('ajax-fixture.noisy');
    assert_same(200, $res->status);
    assert_same(['ok' => true], $res->json()['data']);
    assert_not_contains('debug output', $res->body);
    assert_contains('printed output', bh_core_log());
});

// ---------------------------------------------------------------------------
// Rate limiting and idempotency
// ---------------------------------------------------------------------------

test('e2e: the per-action limit answers 429 with Retry-After, per user', function () {
    $a = bh_browser('author');
    for ($i = 1; $i <= 5; $i++) {
        assert_same(200, $a->ajax('ajax-fixture.limited')->status, "request {$i}");
    }
    $res = $a->ajax('ajax-fixture.limited');
    assert_error($res, 429, 'RATE_LIMITED');
    $retry = (int) $res->header('retry-after');
    assert_true($retry >= 1 && $retry <= 60, 'Retry-After within the window');
    // Another user has their own allowance.
    assert_same(200, bh_browser('editor')->ajax('ajax-fixture.limited')->status);
});

test('e2e: concurrent requests cannot overrun a limit (20 parallel across 10 sessions, limit 5)', function () {
    bh_require_innodb();
    // Ten separate sessions of the same user. One session would not do: PHP's
    // file sessions lock, so its requests would queue at session_start() and
    // never reach the limiter at the same time.
    $sessions = [];
    for ($i = 0; $i < 10; $i++) $sessions[] = bh_browser('subscriber');
    $mh = curl_multi_init();
    $handles = [];
    foreach ($sessions as $b) {
        $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($b->cookies), $b->cookies));
        for ($j = 0; $j < 2; $j++) {
            $ch = curl_init($b->baseUrl . '/ajax');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                CURLOPT_POSTFIELDS => json_encode(['action' => 'ajax-fixture.limited', 'data' => new stdClass()]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $b->csrf, 'Cookie: ' . $cookie, 'Sec-Fetch-Site: same-origin'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
    $codes = [];
    foreach ($handles as $ch) {
        $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    $counts = array_count_values($codes);
    assert_same(5, $counts[200] ?? 0, 'exactly the limit got through: ' . json_encode($counts));
    assert_same(15, $counts[429] ?? 0, 'the rest were limited: ' . json_encode($counts));
});

test('e2e: X-Forwarded-For from an untrusted client does not reset an IP limit', function () {
    $v = bh_browser();
    for ($i = 1; $i <= 3; $i++) {
        assert_same(200, $v->ajax('ajax-fixture.public-tiny', [], ['X-Forwarded-For' => "198.51.100.{$i}"], '')->status);
    }
    $res = $v->ajax('ajax-fixture.public-tiny', [], ['X-Forwarded-For' => '198.51.100.99', 'CF-Connecting-IP' => '198.51.100.98'], '');
    assert_error($res, 429, 'RATE_LIMITED');
});

test('e2e: idempotent actions need a key, run once per key, and free the key on failure', function () {
    [$admin] = people();
    assert_error($admin->ajax('ajax-fixture.once'), 400, 'IDEMPOTENCY_KEY_REQUIRED');
    assert_error($admin->ajax('ajax-fixture.once', [], ['Idempotency-Key' => 'short']), 400, 'IDEMPOTENCY_KEY_REQUIRED');
    $key = bin2hex(random_bytes(16));
    assert_same(200, $admin->ajax('ajax-fixture.once', [], ['Idempotency-Key' => $key])->status);
    assert_error($admin->ajax('ajax-fixture.once', [], ['Idempotency-Key' => $key]), 409, 'DUPLICATE_REQUEST');
    // Keys are per user: someone else may use the same one.
    assert_same(200, bh_browser('author')->ajax('ajax-fixture.once', [], ['Idempotency-Key' => $key])->status);
    // A failed attempt releases its key, so a retry is not reported as a duplicate.
    $k2 = bin2hex(random_bytes(16));
    assert_error($admin->ajax('ajax-fixture.once-failing', [], ['Idempotency-Key' => $k2]), 503, 'TRY_AGAIN');
    assert_error($admin->ajax('ajax-fixture.once-failing', [], ['Idempotency-Key' => $k2]), 503, 'TRY_AGAIN');
});

// ---------------------------------------------------------------------------
// App lifecycle
// ---------------------------------------------------------------------------

test("e2e: an app's refused registrations are logged and change nothing", function () {
    $log = bh_app_log();
    assert_contains("'ajax-fixture.echo' is already registered", $log);
    assert_contains("must declare a 'capability'", $log);
    assert_contains('cannot switch CSRF protection off', $log);
    assert_contains("'handler' must be a closure", $log);
    foreach (['media.update', 'core.csrf', 'other-app.thing'] as $name) {
        assert_contains('REFUSED ' . $name, $log);
    }
    // media.update is still core's: the hijack handler would have answered {"hijacked":true}.
    [, $author] = people();
    $res = $author->ajax('media.update', ['id' => bh_env()['media']['author'], 'title' => 'Still core']);
    assert_same(200, $res->status, $res->body);
    assert_true(isset($res->json()['data']['item']), 'core handler answered');
});

test("e2e: a deactivated app's actions stop existing; reactivating brings them back", function () {
    [$admin] = people();
    bh_db()->update('apps', ['status' => 'inactive'], ['slug' => 'ajax-fixture']);
    try {
        assert_error($admin->ajax('ajax-fixture.echo', ['msg' => 'x']), 404, 'ACTION_NOT_FOUND');
    } finally {
        bh_db()->update('apps', ['status' => 'active'], ['slug' => 'ajax-fixture']);
    }
    assert_same(200, $admin->ajax('ajax-fixture.echo', ['msg' => 'x'])->status);
});

// ---------------------------------------------------------------------------
// Logs never hold secrets
// ---------------------------------------------------------------------------

test('e2e: logs never contain CSRF tokens or session IDs', function () {
    [$admin, $author, $subscriber] = people();
    // Make sure there is something logged for these users.
    $admin->ajax('ajax-fixture.echo', ['msg' => 'x'], [], str_repeat('f', 64));
    $subscriber->ajax('ajax-fixture.admin-only');
    $logs = bh_core_log() . bh_app_log();
    foreach ([$admin, $author, $subscriber] as $b) {
        assert_not_contains($b->csrf, $logs, 'a CSRF token is in the logs');
        $sid = $b->cookies['BASEHIMSESS'] ?? '';
        assert_true($sid !== '', 'browser has a session');
        assert_not_contains($sid, $logs, 'a session ID is in the logs');
    }
});
