<?php

declare(strict_types=1);

use App\Core\WidgetRegistry;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\InvalidSettings;
use App\Services\Dashboard\LayoutStore;
use App\Services\Dashboard\WidgetContext;
use App\Services\Dashboard\WidgetUnavailable;

/*
 * The dashboard engine (1.2.68) without a database: an in-memory LayoutStore,
 * and users whose capabilities come from users.meta grants (role 'none' has
 * no capabilities of its own), so no role lookup is needed.
 */

function dash_store(): LayoutStore
{
    return new class implements LayoutStore {
        public array $users = [];
        public ?array $default = null;
        public int $writes = 0;
        public function load(int $userId): ?array { return $this->users[$userId] ?? null; }
        public function save(int $userId, array $layout): void { $this->users[$userId] = json_decode(json_encode($layout), true); $this->writes++; }
        public function reset(int $userId): void { unset($this->users[$userId]); }
        public function siteDefault(): ?array { return $this->default; }
        public function saveSiteDefault(?array $layout): void { $this->default = $layout; }
    };
}

function dash_user(int $id, array $caps): array
{
    return ['id' => $id, 'role' => 'none', 'meta' => json_encode(['caps_grant' => $caps])];
}

/** A registry with two "apps" and core-like widgets. */
function dash_registry(): WidgetRegistry
{
    $r = new WidgetRegistry();
    $r->register('core.stats', [
        'title' => 'Stats', 'source' => 'core', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'stat', 'priority' => 1, 'size' => 'full',
            'data' => fn(WidgetContext $c) => ['items' => [['label' => 'Things', 'value' => 3]]]],
    ]);
    $r->register('core.secret', [
        'title' => 'Secret', 'source' => 'core', 'surfaces' => ['dashboard'], 'capability' => 'manage_settings',
        'dashboard' => ['view' => 'list', 'priority' => 2,
            'data' => fn() => ['items' => [['title' => 'Top secret']]]],
    ]);
    $r->register('shop.orders', [
        'title' => 'Orders', 'source' => 'app:shop', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'fields' => [
            ['key' => 'count', 'label' => 'How many', 'type' => 'number', 'min' => 1, 'max' => 10, 'default' => 5],
            ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'default' => 'all', 'options' => ['all' => 'All', 'open' => 'Open']],
        ],
        'dashboard' => ['view' => 'list', 'priority' => 3, 'sizes' => ['third', 'half'], 'size' => 'half',
            'data' => fn(WidgetContext $c) => ['items' => array_fill(0, (int) $c->setting('count'), ['title' => 'Order ' . $c->setting('status')])]],
    ]);
    $r->register('blog.feed', [
        'title' => 'Blog feed', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'feed', 'priority' => 4,
            'data' => fn() => ['items' => [['text' => 'Hello', 'href' => 'javascript:alert(1)']]]],
    ]);
    return $r;
}

// --- Registration contract ------------------------------------------------

test('widgets: two apps register different widgets without colliding, and keys are owned', function () {
    $r = dash_registry();
    assert_true($r->has('shop.orders') && $r->has('blog.feed'));
    assert_same('app:shop', $r->get('shop.orders')['source']);

    // Another app can't take shop's key, or a core key; shop can replace its own.
    assert_false($r->register('shop.orders', ['title' => 'Mine now', 'source' => 'app:blog', 'render' => fn() => 'x']));
    assert_false($r->register('core.evil', ['title' => 'Core?', 'source' => 'app:blog', 'render' => fn() => 'x']));
    assert_same('Orders', $r->get('shop.orders')['title']);
    assert_true($r->register('shop.orders', ['title' => 'Orders v2', 'source' => 'app:shop', 'surfaces' => ['dashboard'],
        'dashboard' => ['view' => 'list', 'data' => fn() => ['items' => []]]]));
    assert_same('Orders v2', $r->get('shop.orders')['title']);
    $reasons = array_column($r->rejected(), 'reason');
    assert_true((bool) preg_grep('/already registered by app:shop/', $reasons));
    assert_true((bool) preg_grep("/reserved for core/", $reasons));
});

test('widgets: malformed registrations are refused with a reason', function () {
    $r = new WidgetRegistry();
    $bad = [
        'no title'           => ['k.a', ['render' => fn() => '']],
        'no render or data'  => ['k.b', ['title' => 'B']],
        'bad view'           => ['k.c', ['title' => 'C', 'surfaces' => ['dashboard'], 'dashboard' => ['view' => 'pie', 'data' => fn() => []]]],
        'bad size'           => ['k.d', ['title' => 'D', 'render' => fn() => '', 'dashboard' => ['sizes' => ['huge']]]],
        'bad capability'     => ['k.e', ['title' => 'E', 'render' => fn() => '', 'capability' => 'Not A Cap']],
        'url action'         => ['k.f', ['title' => 'F', 'render' => fn() => '', 'dashboard' => ['actions' => [['id' => 'x', 'label' => 'X', 'url' => 'javascript:alert(1)']]]]],
        'foreign endpoint'   => ['k.g', ['title' => 'G', 'source' => 'app:k', 'surfaces' => ['dashboard'], 'dashboard' => ['endpoint' => 'other.data']]],
        'bad key'            => ['Bad Key!!.x.y.z.w', ['title' => 'H', 'render' => fn() => '']],
    ];
    foreach ($bad as $why => [$key, $def]) {
        assert_false($r->register($key, $def), "accepted: {$why}");
    }
    assert_same(count($bad), count($r->rejected()));
    // An app's own endpoint is fine.
    assert_true($r->register('k.ok', ['title' => 'OK', 'source' => 'app:k', 'surfaces' => ['dashboard'], 'dashboard' => ['endpoint' => 'k.data']]));
});

test('widgets: pre-1.2.68 definitions still register and keep their fields as written', function () {
    $r = new WidgetRegistry();
    $opts = fn() => ['a' => 'A'];
    assert_true($r->register('legacy.box', [
        'title' => 'Box', 'render' => fn() => '<p>hi</p>',
        'fields' => [['key' => 'cat', 'type' => 'select', 'options' => $opts], ['key' => 'pick', 'type' => 'media']],
        'dashboard' => ['width' => 'half', 'priority' => 5],
    ]));
    $w = $r->get('legacy.box');
    assert_same(['editor', 'frontend', 'dashboard'], $w['surfaces']);
    assert_same($opts, $w['fields'][0]['options']);      // the lazy callable is left alone
    assert_same('media', $w['fields'][1]['type']);      // unknown types are not rewritten
    assert_same('half', $w['dashboard']['size']);       // 'width' is the old name
    assert_same(['a' => 'A'], WidgetRegistry::fieldOptions($w['fields'][0]));
    assert_same('<p>hi</p>', $r->render('legacy.box', [], 'dashboard'));
});

test('widgets: core\'s dashboard cards sit beside its frontend widgets without replacing them', function () {
    $r = new WidgetRegistry();
    $r->register('core.recent-posts', ['title' => 'Recent Posts', 'source' => 'core', 'surfaces' => ['frontend'], 'render' => fn() => 'front']);
    \App\Services\Dashboard\CoreDashboardWidgets::register($r);
    assert_same(['frontend'], $r->get('core.recent-posts')['surfaces']);
    assert_same([], $r->rejected());
    $keys = array_column($r->all('dashboard'), 'key');
    assert_same(6, count($keys));
    foreach ($keys as $k) assert_true(str_starts_with($k, 'core.dashboard.'), $k);
});

test('widgets: a failing render never puts its exception message in the page', function () {
    $r = new WidgetRegistry();
    $r->register('x.boom', ['title' => 'Boom', 'render' => function () { throw new RuntimeException('SQLSTATE secret /var/www/path'); }]);
    $html = $r->render('x.boom', [], 'frontend');
    assert_contains('failed to render', $html);
    assert_not_contains('SQLSTATE', $html);
    assert_not_contains('/var/www', $html);
});

// --- Layout and preferences -----------------------------------------------

test('dashboard: a new user gets the defaults they may see, in priority order', function () {
    $svc = new DashboardService(dash_registry(), dash_store());
    $items = $svc->resolve(dash_user(1, ['read']))['items'];
    assert_same(['core.stats', 'shop.orders', 'blog.feed'], array_column($items, 'widget'));
    assert_same('half', $items[1]['size']);
    assert_same(['count' => 5, 'status' => 'all'], $items[1]['settings']);
    $admin = $svc->resolve(dash_user(2, ['read', 'manage_settings']))['items'];
    assert_same(['core.stats', 'core.secret', 'shop.orders', 'blog.feed'], array_column($admin, 'widget'));
});

test('dashboard: layouts are per user, and a saved layout is what comes back', function () {
    $store = dash_store();
    $svc = new DashboardService(dash_registry(), $store);
    $a = dash_user(1, ['read']);
    $b = dash_user(2, ['read']);
    $items = $svc->resolve($a)['items'];
    // A: feed first, orders as a third, stats hidden.
    $svc->saveLayout($a, [
        ['id' => $items[2]['id'], 'widget' => 'blog.feed'],
        ['id' => $items[1]['id'], 'widget' => 'shop.orders', 'size' => 'third'],
    ]);
    // Same store, new service = another request / device / after signing in again.
    $again = (new DashboardService(dash_registry(), $store))->resolve($a);
    assert_true($again['customized']);
    $visible = array_values(array_filter($again['items'], fn($i) => $i['visible']));
    assert_same(['blog.feed', 'shop.orders'], array_column($visible, 'widget'));
    assert_same('third', $visible[1]['size']);
    // Stats is hidden, not forgotten (so it can be restored).
    $hidden = array_values(array_filter($again['items'], fn($i) => !$i['visible']));
    assert_same(['core.stats'], array_column($hidden, 'widget'));
    // B is untouched.
    assert_false($svc->resolve($b)['customized']);
    assert_same(3, count($svc->resolve($b)['items']));
});

test('dashboard: add, hide, restore, move and resize; sizes a widget does not support are refused', function () {
    $svc = new DashboardService(dash_registry(), dash_store());
    $u = dash_user(1, ['read']);
    $ids = array_column($svc->resolve($u)['items'], 'id', 'widget');
    $svc->updateInstance($u, $ids['blog.feed'], ['visible' => false]);
    $state = $svc->resolve($u);
    assert_false($state['items'][2]['visible']);
    $cat = array_column($svc->catalog($u), null, 'key');
    assert_same([$ids['blog.feed']], $cat['blog.feed']['hidden']);
    // Restore it first, resize orders to an unsupported size (falls back), add a second stats (not multiple → dropped).
    $res = $svc->saveLayout($u, [
        ['id' => $ids['blog.feed'], 'widget' => 'blog.feed'],
        ['id' => $ids['shop.orders'], 'widget' => 'shop.orders', 'size' => 'full'],
        ['id' => $ids['core.stats'], 'widget' => 'core.stats'],
        ['id' => 'new', 'widget' => 'core.stats'],
    ]);
    assert_same(['blog.feed', 'shop.orders', 'core.stats'], array_column($res['items'], 'widget'));
    assert_same('half', $res['items'][1]['size']);
    assert_true($res['items'][0]['visible']);
});

test('dashboard: a widget the user may not have cannot be added, read or acted on', function () {
    $store = dash_store();
    $svc = new DashboardService(dash_registry(), $store);
    $u = dash_user(1, ['read']);
    assert_throws(InvalidSettings::class, fn() => $svc->saveLayout($u, [['id' => 'new', 'widget' => 'core.secret']]), 'not available');
    // Even planted straight into the store, it never runs for them.
    $store->users[1] = ['v' => 1, 'items' => [['id' => 'w-planted01', 'widget' => 'core.secret', 'size' => 'third', 'visible' => true]]];
    $state = $svc->resolve($u);
    assert_same([], $state['items']);
    assert_same(['core.secret'], array_column($state['dormant'], 'widget'));
    $data = $svc->data($u, ['w-planted01']);
    assert_same('gone', $data['w-planted01']['state']);
    assert_false(str_contains(json_encode($data), 'Top secret'));
    // Another user's id is just "gone" too.
    assert_same('gone', $svc->data(dash_user(2, ['read']), [$svc->resolve(dash_user(3, ['read']))['items'][0]['id'] . 'x'])[$svc->resolve(dash_user(3, ['read']))['items'][0]['id'] . 'x']['state']);
});

test('dashboard: a new widget does not overwrite a saved layout; it shows as new in Add widgets', function () {
    $store = dash_store();
    $u = dash_user(1, ['read']);
    $svc = new DashboardService(dash_registry(), $store);
    $items = $svc->resolve($u)['items'];
    $svc->saveLayout($u, [['id' => $items[1]['id'], 'widget' => 'shop.orders']]);
    $before = $store->users[1];

    $reg = dash_registry();
    $reg->register('blog.new', ['title' => 'New one', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'stat', 'priority' => 0, 'data' => fn() => ['items' => []]]]);
    $svc2 = new DashboardService($reg, $store);
    $state = $svc2->resolve($u);
    assert_same(['shop.orders'], array_column(array_filter($state['items'], fn($i) => $i['visible']), 'widget'));
    assert_false(in_array('blog.new', array_column($state['items'], 'widget'), true));
    assert_same($before, $store->users[1], 'resolve() must not write');
    $cat = array_column($svc2->catalog($u), null, 'key');
    assert_true($cat['blog.new']['new']);
    assert_false($cat['shop.orders']['new']);
    // Someone who never customised simply gets it.
    assert_true(in_array('blog.new', array_column($svc2->resolve(dash_user(9, ['read']))['items'], 'widget'), true));
});

test('dashboard: a disabled app\'s widgets do not run, and come back with their settings', function () {
    $store = dash_store();
    $u = dash_user(1, ['read']);
    $svc = new DashboardService(dash_registry(), $store);
    $id = array_column($svc->resolve($u)['items'], 'id', 'widget')['shop.orders'];
    $svc->updateInstance($u, $id, ['settings' => ['count' => 2, 'status' => 'open']]);

    // "shop" switched off: its widgets are not registered on this request.
    $reg = dash_registry();
    $reg->unregister('shop.orders');
    $off = new DashboardService($reg, $store);
    $state = $off->resolve($u);
    assert_false(in_array('shop.orders', array_column($state['items'], 'widget'), true));
    assert_same('gone', $off->data($u, [$id])[$id]['state']);
    // A save while it is off keeps it.
    $off->saveLayout($u, array_map(fn($i) => ['id' => $i['id'], 'widget' => $i['widget']], $state['items']));
    assert_true(in_array('shop.orders', array_column($store->users[1]['items'], 'widget'), true));

    // Back on: same card, same settings.
    $on = new DashboardService(dash_registry(), $store);
    $item = array_column($on->resolve($u)['items'], null, 'id')[$id];
    assert_same(['count' => 2, 'status' => 'open'], $item['settings']);
    $res = $on->data($u, [$id])[$id];
    assert_true($res['ok']);
    assert_same(2, count($res['data']['items']));
});

test('dashboard: a renamed widget keeps users\' cards through an alias', function () {
    $store = dash_store();
    $u = dash_user(1, ['read']);
    (new DashboardService(dash_registry(), $store))->saveLayout($u, [['id' => 'new', 'widget' => 'blog.feed']]);
    $reg = dash_registry();
    $reg->unregister('blog.feed');
    $reg->register('blog.stream', ['title' => 'Stream', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'feed', 'aliases' => ['blog.feed'], 'data' => fn() => ['items' => []]]]);
    $state = (new DashboardService($reg, $store))->resolve($u);
    $visible = array_values(array_filter($state['items'], fn($i) => $i['visible']));
    assert_same(['blog.stream'], array_column($visible, 'widget'));
});

test('dashboard: widget settings are validated, saved and restored', function () {
    $store = dash_store();
    $u = dash_user(1, ['read']);
    $svc = new DashboardService(dash_registry(), $store);
    $ids = array_column($svc->resolve($u)['items'], 'id', 'widget');
    $e = assert_throws(InvalidSettings::class, fn() => $svc->updateInstance($u, $ids['shop.orders'], ['settings' => ['count' => 99, 'status' => 'nope']]));
    assert_same(['count', 'status'], array_keys($e->fields));
    $item = $svc->updateInstance($u, $ids['shop.orders'], ['settings' => ['count' => '3', 'status' => 'open', 'extra' => '<script>']]);
    assert_same(['count' => 3, 'status' => 'open'], $item['settings']);
    $again = array_column((new DashboardService(dash_registry(), $store))->resolve($u)['items'], null, 'id')[$ids['shop.orders']];
    assert_same(['count' => 3, 'status' => 'open'], $again['settings']);
    // No settings on a widget without fields.
    assert_throws(InvalidSettings::class, fn() => $svc->updateInstance($u, $ids['core.stats'], ['settings' => ['x' => 1]]), 'no settings');
    // Layout saves never change settings, whatever the client sends.
    $svc->saveLayout($u, [['id' => $ids['shop.orders'], 'widget' => 'shop.orders', 'settings' => ['count' => 10]]]);
    $kept = array_column($svc->resolve($u)['items'], null, 'id')[$ids['shop.orders']];
    assert_same(3, $kept['settings']['count']);
});

test('dashboard: reset goes back to the default; an admin can set a site default', function () {
    $store = dash_store();
    $svc = new DashboardService(dash_registry(), $store);
    $admin = dash_user(1, ['read', 'manage_settings']);
    $ids = array_column($svc->resolve($admin)['items'], 'id', 'widget');
    $svc->saveLayout($admin, [['id' => $ids['blog.feed'], 'widget' => 'blog.feed'], ['id' => $ids['core.secret'], 'widget' => 'core.secret']]);
    $svc->saveSiteDefault($admin);
    // A plain user without a layout gets the site default, minus what they can't see.
    $user = dash_user(5, ['read']);
    assert_same(['blog.feed'], array_column($svc->resolve($user)['items'], 'widget'));
    // Reset puts a customised user back on it.
    $svc->saveLayout($user, [['id' => 'new', 'widget' => 'shop.orders']]);
    $svc->reset($user);
    assert_same(['blog.feed'], array_column($svc->resolve($user)['items'], 'widget'));
    $svc->saveSiteDefault($admin, true);
    assert_same(3, count($svc->resolve($user)['items']));
});

// --- Data -------------------------------------------------------------------

test('dashboard: one failing widget does not stop the others, and its error stays on the server', function () {
    $reg = dash_registry();
    $reg->register('blog.broken', ['title' => 'Broken', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'list', 'data' => function () { throw new RuntimeException('PDO password=hunter2'); }]]);
    $reg->register('blog.offline', ['title' => 'Offline', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'list', 'data' => function () { throw new WidgetUnavailable('Connect a feed first.'); }]]);
    $reg->register('blog.junk', ['title' => 'Junk', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['data' => fn() => 'not an array']]);
    $logged = [];
    $svc = new DashboardService($reg, dash_store(), null, null, function ($lvl, $msg) use (&$logged) { $logged[] = $msg; });
    $u = dash_user(1, ['read']);
    $ids = array_column($svc->resolve($u)['items'], 'id', 'widget');
    $res = $svc->data($u, array_values($ids));
    assert_true($res[$ids['core.stats']]['ok']);
    assert_true($res[$ids['shop.orders']]['ok']);
    assert_same('error', $res[$ids['blog.broken']]['state']);
    assert_not_contains('hunter2', json_encode($res));
    assert_same('unavailable', $res[$ids['blog.offline']]['state']);
    assert_same('Connect a feed first.', $res[$ids['blog.offline']]['message']);
    assert_same('error', $res[$ids['blog.junk']]['state']);
    assert_true((bool) preg_grep('/hunter2/', $logged), 'the real error is logged');
});

test('dashboard: provider output is bounded and links are only site paths or http(s)', function () {
    $reg = dash_registry();
    $reg->register('blog.html', ['title' => 'HTML', 'source' => 'app:blog', 'surfaces' => ['dashboard'], 'capability' => 'read',
        'dashboard' => ['view' => 'html', 'data' => fn() => ['html' => '<p onclick="x()">Hi<script>alert(1)</script></p><a href="javascript:x">y</a>']]]);
    $svc = new DashboardService($reg, dash_store());
    $u = dash_user(1, ['read']);
    $ids = array_column($svc->resolve($u)['items'], 'id', 'widget');
    $res = $svc->data($u, [$ids['blog.feed'], $ids['blog.html']]);
    assert_same(null, $res[$ids['blog.feed']]['data']['items'][0]['href']);
    $html = $res[$ids['blog.html']]['data']['html'];
    assert_not_contains('<script', $html);
    assert_not_contains('onclick', $html);
    assert_not_contains('javascript:', $html);
    assert_contains('Hi', $html);
});

test('dashboard: cached data is per user unless the widget says otherwise; Refresh skips the cache', function () {
    $dir = sys_get_temp_dir() . '/bh-dash-cache-' . getmypid();
    $cache = new \App\Core\Cache($dir);
    $calls = 0;
    $reg = new WidgetRegistry();
    $reg->register('core.count', ['title' => 'Count', 'source' => 'core', 'surfaces' => ['dashboard'],
        'dashboard' => ['view' => 'stat', 'cache' => 60, 'data' => function (WidgetContext $c) use (&$calls) {
            $calls++;
            return ['items' => [['label' => 'User', 'value' => $c->userId()]]];
        }]]);
    $svc = new DashboardService($reg, dash_store(), $cache);
    $a = dash_user(1, ['read']); $b = dash_user(2, ['read']);
    $id = $svc->resolve($a)['items'][0]['id'];
    $svc->data($a, [$id]); $svc->data($a, [$id]);
    assert_same(1, $calls);
    $r = $svc->data($b, [$id]);
    assert_same(2, $r[$id]['data']['items'][0]['value']);
    assert_same(2, $calls);
    $svc->data($a, [$id], true);
    assert_same(3, $calls);
    $cache->flush();
    @rmdir($dir);
});

test('dashboard: actions run only for a card on the user\'s dashboard and with the action capability', function () {
    $ran = 0;
    $reg = new WidgetRegistry();
    $reg->register('core.act', ['title' => 'Act', 'source' => 'core', 'surfaces' => ['dashboard'],
        'dashboard' => ['view' => 'stat', 'data' => fn() => ['items' => []], 'actions' => [
            ['id' => 'purge', 'label' => 'Purge', 'capability' => 'manage_settings', 'handler' => function () use (&$ran) { $ran++; return ['message' => 'Purged.']; }],
        ]]]);
    $svc = new DashboardService($reg, dash_store());
    $plain = dash_user(1, ['read']);
    $id = $svc->resolve($plain)['items'][0]['id'];
    assert_throws(DomainException::class, fn() => $svc->runAction($plain, $id, 'purge'));
    assert_same([], array_column($svc->catalog($plain), 'actions')[0], 'the action is not offered either');
    $admin = dash_user(2, ['read', 'manage_settings']);
    $aid = $svc->resolve($admin)['items'][0]['id'];
    assert_same('Purged.', $svc->runAction($admin, $aid, 'purge')['message']);
    assert_throws(InvalidSettings::class, fn() => $svc->runAction($admin, $aid, 'nope'));
    assert_same(1, $ran);
});
