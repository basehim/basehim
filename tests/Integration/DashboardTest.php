<?php

declare(strict_types=1);

/*
 * The dashboard over HTTP (1.2.68): the real page, the real AJAX gateway, real
 * sessions and the real users.meta store. The fixture app registers
 * ajax-fixture.tally, .broken, .admin-card and .legacy-box.
 */

function dash_state(Browser $b): array
{
    $res = $b->ajaxGet('widgets.dashboard_state');
    assert_same(200, $res->status, $res->body);
    return $res->json()['data'];
}

/** @return array<string,array> widget key => item, visible cards only */
function dash_visible(array $state): array
{
    $out = [];
    foreach ($state['layout']['items'] as $it) {
        if ($it['visible']) $out[$it['widget']] = $it;
    }
    return $out;
}

function dash_save(Browser $b, array $items): \HttpResponse
{
    return $b->ajax('widgets.dashboard_save', ['layout' => json_encode($items)]);
}

function dash_reset_all(): void
{
    foreach (['admin', 'author', 'author2', 'editor', 'contributor', 'subscriber'] as $u) {
        $id = bh_env()['users'][$u];
        $row = bh_db()->selectOne('SELECT meta FROM {users} WHERE id = :id', ['id' => $id]);
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        unset($meta['dashboard']);
        bh_db()->update('users', ['meta' => $meta ? json_encode($meta) : null], ['id' => $id]);
    }
    bh_db()->execute("DELETE FROM {settings} WHERE setting_group = 'dashboard'");
}

test('dashboard: core and an app register widgets and the page renders them for an admin', function () {
    dash_reset_all();
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/dashboard');
    assert_same(200, $page->status);
    assert_contains('window.BH_DASHBOARD', $page->body);
    assert_contains('dashboard.js', $page->body);
    // A pre-1.2.68 widget's HTML is rendered into the page, as before.
    assert_contains('<p class="fixture-legacy">legacy on dashboard</p>', $page->body);

    $state = dash_state($b);
    $vis = dash_visible($state);
    foreach (['core.dashboard.at-a-glance', 'core.dashboard.recent-posts', 'core.dashboard.recent-comments', 'core.dashboard.site-health', 'ajax-fixture.tally', 'ajax-fixture.legacy-box'] as $k) {
        assert_true(isset($vis[$k]), "{$k} missing from the default dashboard");
    }
    assert_false(isset($vis['ajax-fixture.admin-card']), 'default=false widgets are offered, not placed');
    $cat = array_column($state['catalog'], null, 'key');
    assert_true(isset($cat['ajax-fixture.admin-card']));
    assert_same('AJAX Fixture', $cat['ajax-fixture.tally']['owner_label']);
    assert_false(isset($cat['core.hijack']), 'an app cannot register under core.');
    assert_contains("keys under 'core.' are reserved for core", bh_app_log());

    $res = $b->ajaxGet('widgets.dashboard_data', ['ids' => $vis['ajax-fixture.tally']['id'] . ',' . $vis['core.dashboard.at-a-glance']['id']]);
    $w = $res->json()['data']['widgets'];
    assert_same(8, $w[$vis['ajax-fixture.tally']['id']]['data']['items'][0]['value']);
    assert_same('stat', $w[$vis['core.dashboard.at-a-glance']['id']]['view']);
});

test('dashboard: a failing widget does not stop the others, and its details stay in the log', function () {
    dash_reset_all();
    $b = bh_browser('admin');
    $vis = dash_visible(dash_state($b));
    $ids = array_map(fn($i) => $i['id'], $vis);
    $res = $b->ajaxGet('widgets.dashboard_data', ['ids' => implode(',', $ids)]);
    assert_same(200, $res->status, $res->body);
    $w = $res->json()['data']['widgets'];
    assert_same('error', $w[$vis['ajax-fixture.broken']['id']]['state']);
    assert_not_contains('fixture-secret-detail', $res->body);
    assert_contains('fixture-secret-detail', bh_app_log());
    assert_true($w[$vis['core.dashboard.recent-posts']['id']]['ok']);
    assert_true($w[$vis['ajax-fixture.tally']['id']]['ok']);
});

test('dashboard: a layout survives signing out and in, and is the same on another device', function () {
    dash_reset_all();
    $b = bh_browser('editor');
    $vis = dash_visible(dash_state($b));
    $layout = [
        ['id' => $vis['core.dashboard.recent-comments']['id'], 'widget' => 'core.dashboard.recent-comments', 'size' => 'half'],
        ['id' => $vis['ajax-fixture.tally']['id'], 'widget' => 'ajax-fixture.tally'],
    ];
    $res = dash_save($b, $layout);
    assert_same(200, $res->status, $res->body);
    $res = $b->ajax('widgets.dashboard_update', ['id' => $vis['ajax-fixture.tally']['id'], 'collapsed' => true]);
    assert_same(200, $res->status, $res->body);

    // A second browser (another device, or after signing in again).
    $other = bh_browser('editor');
    $vis2 = dash_visible(dash_state($other));
    assert_same(['core.dashboard.recent-comments', 'ajax-fixture.tally'], array_keys($vis2));
    assert_same('half', $vis2['core.dashboard.recent-comments']['size']);
    assert_true($vis2['ajax-fixture.tally']['collapsed']);
    // The page carries the same layout.
    assert_contains('"widget":"core.dashboard.recent-comments","size":"half"', $other->request('GET', '/admin/dashboard')->body);
});

test('dashboard: two users keep different layouts', function () {
    dash_reset_all();
    $a = bh_browser('author');
    $b = bh_browser('author2');
    $va = dash_visible(dash_state($a));
    dash_save($a, [['id' => $va['ajax-fixture.tally']['id'], 'widget' => 'ajax-fixture.tally']]);
    $vb = dash_visible(dash_state($b));
    dash_save($b, [['id' => $vb['core.dashboard.recent-posts']['id'], 'widget' => 'core.dashboard.recent-posts'], ['id' => 'new', 'widget' => 'core.dashboard.my-activity']]);
    assert_same(['ajax-fixture.tally'], array_keys(dash_visible(dash_state(bh_browser('author')))));
    assert_same(['core.dashboard.recent-posts', 'core.dashboard.my-activity'], array_keys(dash_visible(dash_state(bh_browser('author2')))));
});

test('dashboard: add, hide, restore, move and resize through the gateway', function () {
    dash_reset_all();
    $b = bh_browser('admin');
    $vis = dash_visible(dash_state($b));
    // Hide one in place.
    $res = $b->ajax('widgets.dashboard_update', ['id' => $vis['core.dashboard.site-health']['id'], 'visible' => false]);
    assert_same(200, $res->status, $res->body);
    $state = dash_state($b);
    assert_false(isset(dash_visible($state)['core.dashboard.site-health']));
    $cat = array_column($state['catalog'], null, 'key');
    assert_same([$vis['core.dashboard.site-health']['id']], $cat['core.dashboard.site-health']['hidden']);
    // Restore it at the top, add the admin card, resize the tally.
    $res = dash_save($b, [
        ['id' => $vis['core.dashboard.site-health']['id'], 'widget' => 'core.dashboard.site-health'],
        ['id' => 'new', 'widget' => 'ajax-fixture.admin-card'],
        ['id' => $vis['ajax-fixture.tally']['id'], 'widget' => 'ajax-fixture.tally', 'size' => 'full'],
    ]);
    assert_same(200, $res->status, $res->body);
    $vis2 = dash_visible($res->json()['data']);
    assert_same(['core.dashboard.site-health', 'ajax-fixture.admin-card', 'ajax-fixture.tally'], array_keys($vis2));
    assert_same($vis['core.dashboard.site-health']['id'], $vis2['core.dashboard.site-health']['id'], 'restored, not re-created');
    assert_same('full', $vis2['ajax-fixture.tally']['size']);
    assert_true(str_starts_with($vis2['ajax-fixture.admin-card']['id'], 'w-'));
    // Reset.
    $res = $b->ajax('widgets.dashboard_reset');
    assert_same(200, $res->status);
    assert_false($res->json()['data']['layout']['customized']);
});

test('dashboard: a widget without settings or optional tools renders and refuses settings', function () {
    dash_reset_all();
    $b = bh_browser('admin');
    $state = dash_state($b);
    $cat = array_column($state['catalog'], null, 'key');
    assert_false($cat['core.dashboard.site-health']['settings']);
    assert_same([], $cat['core.dashboard.site-health']['fields']);
    $id = dash_visible($state)['core.dashboard.site-health']['id'];
    $res = $b->ajax('widgets.dashboard_update', ['id' => $id, 'settings' => json_encode(['x' => 1])]);
    assert_same(422, $res->status);
    $res = $b->ajaxGet('widgets.dashboard_data', ['ids' => $id]);
    assert_same('status', $res->json()['data']['widgets'][$id]['view']);
});

test('dashboard: widget settings save, are validated and come back', function () {
    dash_reset_all();
    $b = bh_browser('admin');
    $id = dash_visible(dash_state($b))['ajax-fixture.tally']['id'];
    $res = $b->ajax('widgets.dashboard_update', ['id' => $id, 'settings' => json_encode(['start' => 500])]);
    assert_same(422, $res->status);
    assert_true(isset($res->json()['error']['fields']['start']));
    $res = $b->ajax('widgets.dashboard_update', ['id' => $id, 'settings' => json_encode(['start' => 41])]);
    assert_same(200, $res->status, $res->body);
    $again = bh_browser('admin');
    assert_same(41, dash_visible(dash_state($again))['ajax-fixture.tally']['settings']['start']);
    $data = $again->ajaxGet('widgets.dashboard_data', ['ids' => $id])->json()['data']['widgets'][$id];
    assert_same(42, $data['data']['items'][0]['value']);
});

test('dashboard: protected widget data never reaches a user without the capability', function () {
    dash_reset_all();
    // The admin places the admin-only card; its id is known.
    $admin = bh_browser('admin');
    $res = dash_save($admin, [['id' => 'new', 'widget' => 'ajax-fixture.admin-card']]);
    $adminCard = dash_visible($res->json()['data'])['ajax-fixture.admin-card']['id'];

    $sub = bh_browser('subscriber');
    $state = dash_state($sub);
    $keys = array_column($state['catalog'], 'key');
    foreach (['core.dashboard.recent-comments', 'core.dashboard.recent-posts', 'core.dashboard.site-health', 'core.dashboard.at-a-glance', 'ajax-fixture.admin-card'] as $k) {
        assert_false(in_array($k, $keys, true), "{$k} offered to a subscriber");
    }
    // Asking for the admin's card id, adding the widget, or planting it.
    $res = $sub->ajaxGet('widgets.dashboard_data', ['ids' => $adminCard]);
    assert_same('gone', $res->json()['data']['widgets'][$adminCard]['state']);
    assert_not_contains('fixture-admin-only-data', $res->body);
    assert_same(422, dash_save($sub, [['id' => 'new', 'widget' => 'core.dashboard.recent-comments']])->status);
    $sid = bh_env()['users']['subscriber'];
    bh_db()->update('users', ['meta' => json_encode(['dashboard' => ['v' => 1, 'items' => [
        ['id' => 'w-planted001', 'widget' => 'ajax-fixture.admin-card', 'size' => 'third', 'visible' => true],
    ]]])], ['id' => $sid]);
    $res = $sub->ajaxGet('widgets.dashboard_data', ['ids' => 'w-planted001']);
    assert_same('gone', $res->json()['data']['widgets']['w-planted001']['state']);
    assert_not_contains('fixture-admin-only-data', $res->body);
    // Actions, site default and editor previews are gated too.
    assert_same(403, $sub->ajax('widgets.dashboard_default')->status);
    assert_same(403, $sub->ajax('widgets.render', ['widget' => 'ajax-fixture.admin-card', 'surface' => 'dashboard'])->status);
    assert_same(403, bh_browser('contributor')->ajax('widgets.render', ['widget' => 'core.dashboard.site-health', 'surface' => 'dashboard'])->status);
});

test('dashboard: a subscriber no longer sees drafts, pending comments or site-wide counts (BH-001)', function () {
    dash_reset_all();
    $aid = bh_env()['users']['admin'];
    bh_db()->insert('posts', ['uuid' => bh_uuid(), 'author_id' => $aid, 'type' => 'post', 'status' => 'draft',
        'slug' => 'dash-secret-draft', 'title' => 'Dashboard secret draft title', 'content' => 'x']);
    $postId = (int) bh_db()->selectOne("SELECT id FROM {posts} WHERE slug = 'dash-secret-draft'")['id'];
    bh_db()->insert('comments', ['post_id' => $postId, 'author_name' => 'Pending Person', 'author_email' => 'p@example.test',
        'content' => 'Dashboard pending comment text', 'status' => 'pending']);

    foreach (['subscriber', 'contributor', 'author'] as $u) {
        $b = bh_browser($u);
        $page = $b->request('GET', '/admin/dashboard');
        $vis = dash_visible(dash_state($b));
        $data = $b->ajaxGet('widgets.dashboard_data', ['ids' => implode(',', array_column($vis, 'id'))]);
        foreach ([$page->body, $data->body] as $body) {
            assert_not_contains('Dashboard secret draft title', $body, "{$u} sees an admin's draft");
            assert_not_contains('Dashboard pending comment text', $body, "{$u} sees a pending comment");
        }
    }
    // The editor may see both: they can edit others' posts and moderate.
    $ed = bh_browser('editor');
    $vis = dash_visible(dash_state($ed));
    $data = $ed->ajaxGet('widgets.dashboard_data', ['ids' => $vis['core.dashboard.recent-posts']['id'] . ',' . $vis['core.dashboard.recent-comments']['id']]);
    assert_contains('Dashboard secret draft title', $data->body);
    assert_contains('Dashboard pending comment text', $data->body);
    bh_db()->delete('posts', ['id' => $postId]);
});

test('dashboard: a removed app\'s cards do not break the page and keep their place', function () {
    dash_reset_all();
    $b = bh_browser('editor');
    $eid = bh_env()['users']['editor'];
    // A layout naming a widget from an app that is not installed.
    bh_db()->update('users', ['meta' => json_encode(['dashboard' => ['v' => 1, 'seen' => [], 'items' => [
        ['id' => 'w-goneapp001', 'widget' => 'gone-app.thing', 'size' => 'half', 'visible' => true, 'settings' => ['a' => 1]],
        ['id' => 'w-tally00001', 'widget' => 'ajax-fixture.tally', 'size' => 'third', 'visible' => true],
    ]]])], ['id' => $eid]);
    $page = $b->request('GET', '/admin/dashboard');
    assert_same(200, $page->status);
    $state = dash_state($b);
    assert_same(['ajax-fixture.tally'], array_keys(dash_visible($state)));
    assert_same('gone', $b->ajaxGet('widgets.dashboard_data', ['ids' => 'w-goneapp001'])->json()['data']['widgets']['w-goneapp001']['state']);
    // Saving keeps the missing app's card for when it comes back.
    assert_same(200, dash_save($b, [['id' => 'w-tally00001', 'widget' => 'ajax-fixture.tally', 'size' => 'half']])->status);
    $meta = json_decode((string) bh_db()->selectOne('SELECT meta FROM {users} WHERE id = :id', ['id' => $eid])['meta'], true);
    $saved = array_column($meta['dashboard']['items'], null, 'id');
    assert_same(['a' => 1], $saved['w-goneapp001']['settings']);
});

test('dashboard: malformed and oversized requests are refused cleanly', function () {
    $b = bh_browser('admin');
    assert_same(422, $b->ajax('widgets.dashboard_save', ['layout' => '{not json'])->status);
    assert_same(422, $b->ajax('widgets.dashboard_save', ['layout' => json_encode(['a' => 1])])->status);
    assert_same(422, dash_save($b, [['id' => 'new', 'widget' => 'no-such.widget']])->status);
    assert_same(422, dash_save($b, array_fill(0, 41, ['id' => 'new', 'widget' => 'ajax-fixture.tally']))->status);
    assert_same(422, $b->ajax('widgets.dashboard_update', ['id' => 'BAD ID!', 'collapsed' => true])->status);
    assert_same(422, $b->ajax('widgets.dashboard_update', ['id' => 'w-nothere01', 'collapsed' => true])->status);
    assert_same(422, $b->ajaxGet('widgets.dashboard_data', ['ids' => '<script>'])->status);
    assert_same(422, $b->ajax('widgets.dashboard_update', ['id' => 'w-nothere01', 'settings' => '[1,2]'])->status);
    // Signed out: no dashboard at all.
    $anon = new Browser(bh_env()['base_url']);
    assert_same(401, $anon->ajaxGet('widgets.dashboard_state')->status);
    // CSRF on writes.
    assert_same(403, $b->ajax('widgets.dashboard_reset', [], [], 'wrong-token')->status);
    dash_reset_all();
});

test('users.meta: concurrent saves of different keys both land (BH-008)', function () {
    $id = bh_env()['users']['editor'];
    $users = \App\Core\Application::getInstance()->make(\App\Services\UserService::class);
    $users->setMeta($id, 'bh008', null);
    // Eight processes, each saving its own key at the same moment.
    $root = dirname(__DIR__, 2);
    $script = tempnam(sys_get_temp_dir(), 'bh008');
    file_put_contents($script, '<?php
        define("BASEHIM_ROOT", ' . var_export($root, true) . '); define("BASEHIM_BASE", ""); define("BASEHIM_VERSION", "test");
        spl_autoload_register(function ($c) { if (str_starts_with($c, "App\\\\")) require BASEHIM_ROOT . "/app/" . str_replace("\\\\", "/", substr($c, 4)) . ".php"; });
        \\App\\Core\\Env::load($argv[1]);
        $db = new \\App\\Core\\Database(require BASEHIM_ROOT . "/config/database.php");
        $svc = new \\App\\Services\\UserService(new \\App\\Repositories\\UserRepository($db), new \\App\\Core\\HookRegistry());
        time_nanosleep(0, (int) (($argv[3] - microtime(true)) * 1e9) > 0 ? (int) (($argv[3] - microtime(true)) * 1e9) : 0);
        echo $svc->setMeta((int) $argv[2], "bh008_" . $argv[4], ["n" => (int) $argv[4]]) ? "ok" : "fail";');
    $start = microtime(true) + 1.0;
    $procs = [];
    for ($i = 0; $i < 8; $i++) {
        $procs[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(bh_env()['env_file']) . " {$id} {$start} {$i} 2>&1", 'r');
    }
    $out = '';
    foreach ($procs as $p) { $out .= stream_get_contents($p); pclose($p); }
    @unlink($script);
    assert_same(8, substr_count($out, 'ok'), $out);
    for ($i = 0; $i < 8; $i++) {
        assert_same(['n' => $i], $users->meta($id, "bh008_{$i}"), "key {$i} was lost");
        $users->setMeta($id, "bh008_{$i}", null);
    }
});

test('users.meta: setMeta changes one key and leaves the others (BH-008)', function () {
    $id = bh_env()['users']['editor'];
    $users = \App\Core\Application::getInstance()->make(\App\Services\UserService::class);
    assert_true($users->setMeta($id, 'bh008_x', ['a' => 1]));
    assert_true($users->setMeta($id, 'bh008_y', ['b' => 2]));
    assert_same(['a' => 1], $users->meta($id, 'bh008_x'));
    assert_same(['b' => 2], $users->meta($id, 'bh008_y'));
    assert_true($users->setMeta($id, 'bh008_x', null));
    assert_true($users->setMeta($id, 'bh008_x', null), 'removing a missing key is fine');
    assert_same(null, $users->meta($id, 'bh008_x'));
    assert_same(['b' => 2], $users->meta($id, 'bh008_y'));
    assert_false($users->setMeta(999999, 'bh008_x', 1), 'no such user');
    assert_false($users->setMeta($id, 'bh008_big', str_repeat('x', 70000)), 'over the 64 KB cap');
    $users->setMeta($id, 'bh008_y', null);
});
