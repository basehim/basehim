<?php

declare(strict_types=1);

/*
 * The endpoints the gateway did not replace, and the one it did
 * (/admin/media/{id}/update) which is kept for older callers. They must keep
 * working, and the ownership hole they had must stay closed.
 */

function form_post(Browser $b, string $path, array $fields, bool $ajax = true): HttpResponse
{
    $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => $b->csrf];
    if ($ajax) $headers += ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    return $b->request('POST', $path, http_build_query($fields + ['_csrf' => $b->csrf]), $headers);
}

function new_media(string $owner): int
{
    return (int) bh_db()->insert('media', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'title' => "{$owner} temp",
        'alt_text' => 'original', 'mime_type' => 'image/png', 'file_name' => 'x.png', 'original_name' => 'x.png',
        'file_size' => 10, 'storage_path' => 'tests/none-' . bin2hex(random_bytes(4)) . '.png', 'url' => '/uploads/tests/x.png',
    ]);
}

function media_exists(int $id): bool
{
    return bh_db()->selectOne('SELECT id FROM {media} WHERE id = :id', ['id' => $id]) !== null;
}

test('legacy: /admin/media/{id}/update still works for the owner and for admins', function () {
    $author = bh_browser('author');
    $id = new_media('author');
    $res = form_post($author, "/admin/media/{$id}/update", ['alt_text' => 'via legacy']);
    assert_same(200, $res->status, $res->body);
    assert_same(true, $res->json()['success']);
    assert_same('via legacy', $res->json()['data']['alt_text']);
    $admin = bh_browser('admin');
    assert_same(200, form_post($admin, "/admin/media/{$id}/update", ['title' => 'admin edit'])->status);
});

test("legacy: /admin/media/{id}/update no longer lets an author edit someone else's file", function () {
    $id = new_media('admin');
    $res = form_post(bh_browser('author'), "/admin/media/{$id}/update", ['alt_text' => 'pwned']);
    assert_same(403, $res->status, $res->body);
    assert_same('original', bh_db()->selectOne('SELECT alt_text FROM {media} WHERE id = :id', ['id' => $id])['alt_text']);
});

test('legacy: /admin/media/{id}/update refuses non-text values instead of erroring', function () {
    $id = new_media('author');
    $b = bh_browser('author');
    $res = $b->request('POST', "/admin/media/{$id}/update", http_build_query(['alt_text' => ['a', 'b'], '_csrf' => $b->csrf]),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(422, $res->status, $res->body);
    assert_not_contains('SQLSTATE', $res->body);
});

test('legacy: /admin/media/{id}/update still requires its CSRF token', function () {
    $id = new_media('author');
    $b = bh_browser('author');
    $res = $b->request('POST', "/admin/media/{$id}/update", http_build_query(['alt_text' => 'x']),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(419, $res->status);
});

test("legacy: an author can no longer delete an administrator's file (single and bulk)", function () {
    $author = bh_browser('author');
    $a1 = new_media('admin');
    $res = form_post($author, "/admin/media/{$a1}/delete", []);
    assert_same(403, $res->status, $res->body);
    assert_true(media_exists($a1), 'still there after single delete');

    $a2 = new_media('admin');
    $own = new_media('author');
    $res = form_post($author, '/admin/media/bulk-delete', ['ids' => "{$a2},{$own}"]);
    assert_same(200, $res->status, $res->body);
    $j = $res->json();
    assert_same(1, $j['deleted']);
    assert_same(1, $j['denied']);
    assert_true(media_exists($a2), "the admin's file survived the bulk delete");
    assert_false(media_exists($own), "the author's own file was deleted");
});

test('legacy: an admin can still delete anyone\'s file', function () {
    $id = new_media('author');
    $res = form_post(bh_browser('admin'), "/admin/media/{$id}/delete", []);
    assert_same(200, $res->status, $res->body);
    assert_false(media_exists($id));
});

test('compat: the REST API is unchanged — public reads work, cookie writes still need X-CSRF-Token', function () {
    $anon = bh_browser();
    $posts = $anon->request('GET', '/api/v1/posts', null, ['Accept' => 'application/json']);
    assert_same(200, $posts->status, substr($posts->body, 0, 200));
    $admin = bh_browser('admin');
    $res = $admin->request('POST', '/api/v1/posts', json_encode(['title' => 'x']), ['Content-Type' => 'application/json']);
    assert_same(403, $res->status, 'cookie write without the header');
    assert_contains('csrf', strtolower($res->body));
});

test('compat: a page cannot take the slug "ajax" now that the route exists', function () {
    // routes/ajax.php is loaded in this process by the test environment.
    $router = \App\Core\Application::getInstance()->make(\App\Core\Router::class);
    assert_true(in_array('ajax', $router->reservedSegments(), true));
});

test('compat: the admin login and dashboard still render', function () {
    $b = bh_browser('admin');
    $dash = $b->request('GET', '/admin');
    assert_same(200, $dash->status);
    assert_contains('Basehim.ajax', (string) $b->request('GET', '/admin/assets/js/ajax.js')->body);
});
