<?php

declare(strict_types=1);

/*
 * 1.2.51: routes an app registers with adminGet()/adminPost() run behind
 * AdminAreaPolicy, like core's admin routes. They had Authenticate only, so
 * any signed-in account — a subscriber included — could open every app's
 * admin pages and post to its admin endpoints (on a CloudHim hub: read every
 * site key, upload releases).
 */

function fixture_post(Browser $b, string $path, array $fields = []): HttpResponse
{
    return $b->request('POST', $path, http_build_query($fields + ['_csrf' => $b->csrf]),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
}

function set_user_caps(string $who, array $grant, array $deny = []): void
{
    $meta = json_encode(array_filter(['caps_grant' => $grant, 'caps_deny' => $deny]));
    bh_db()->execute('UPDATE {users} SET meta = :m WHERE id = :id', ['m' => $meta === '[]' ? null : $meta, 'id' => bh_env()['users'][$who]]);
}

test('app admin: subscribers, contributors and authors cannot open an app\'s admin pages', function () {
    foreach (['subscriber', 'contributor', 'author'] as $who) {
        $b = bh_browser($who);
        assert_same(403, $b->request('GET', '/admin/ajax-fixture')->status, "{$who}: page under the menu URL");
        assert_same(403, $b->request('GET', '/admin/ajax-fixture-tools')->status, "{$who}: page outside the menu URL");
        $res = fixture_post($b, '/admin/ajax-fixture/save');
        assert_same(403, $res->status, "{$who}: POST");
        assert_not_contains('saved', $res->body, "{$who}: the handler did not run");
    }
});

test('app admin: an administrator can open them', function () {
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/ajax-fixture');
    assert_same(200, $page->status);
    assert_contains('fixture admin page', $page->body);
    assert_same(200, $b->request('GET', '/admin/ajax-fixture-tools')->status, 'a route outside the menu URL is still the app\'s');
    assert_same('{"saved":true}', fixture_post($b, '/admin/ajax-fixture/save')->body);
});

test('app admin: access follows grants and denials (Users > Access)', function () {
    try {
        set_user_caps('editor', ['access_app:ajax-fixture']);
        $b = bh_browser('editor');
        assert_same(200, $b->request('GET', '/admin/ajax-fixture')->status, 'editor with a grant');
        assert_same(200, $b->request('GET', '/admin/ajax-fixture-tools')->status);

        set_user_caps('editor', []);
        assert_same(403, bh_browser('editor')->request('GET', '/admin/ajax-fixture')->status, 'editor without one');

        set_user_caps('admin', [], ['access_app:ajax-fixture']);
        assert_same(403, bh_browser('admin')->request('GET', '/admin/ajax-fixture-tools')->status, 'admin denied this app');
    } finally {
        set_user_caps('editor', []);
        set_user_caps('admin', []);
    }
});

test('app admin: an app can open a route to more roles with admin.area_policy', function () {
    // The rule core checks first: a site or app that maps the path to a
    // capability decides it. Checked in-process: the policy's own lookup.
    $hooks = \App\Core\Application::getInstance()->make(\App\Core\HookRegistry::class);
    $cb = fn(array $map) => $map + ['/admin/ajax-fixture-tools' => 'read'];
    $hooks->addFilter('admin.area_policy', $cb);
    try {
        assert_same('read', \App\Http\Middleware\AdminAreaPolicy::requiredFor('/admin/ajax-fixture-tools'));
    } finally {
        $hooks->removeFilter('admin.area_policy', $cb);
    }
});
