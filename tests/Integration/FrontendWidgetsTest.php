<?php

declare(strict_types=1);

/*
 * 1.2.93 — frontend widgets end to end: the widgets.* actions and their
 * permissions, core widgets rendering from old saved records, theme template
 * overrides, the output cache, an app's widget and its deactivation, the
 * public API, and the admin screen.
 */

const FW_APP = <<<'PHP'
<?php
namespace BhTest\FwFixture;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->registerWidget('notice', [
            'title' => 'Notice', 'surfaces' => ['frontend'],
            'fields' => [
                ['key' => 'text', 'label' => 'Text', 'type' => 'text', 'required' => true],
                ['key' => 'tone', 'label' => 'Tone', 'type' => 'select', 'options' => ['info' => 'Info', 'warn' => 'Warning'], 'default' => 'info'],
                ['key' => 'token', 'label' => 'Token', 'type' => 'text', 'private' => true],
            ],
            'frontend' => [
                'category' => 'utility',
                'template' => $this->path() . '/views/notice.php',
                'data' => fn(array $s) => ['upper' => strtoupper($s['text'])],
                'assets' => ['inline_css' => '.fw-notice{border:1px solid}'],
            ],
        ]);
    }
}
PHP;

function fw_root(): string { return bh_env()['root']; }

function fw_reset(): void
{
    bh_db()->execute("DELETE FROM {settings} WHERE setting_group = 'widgets' AND setting_key = 'areas'");
    // The settings cache is a file; clear it the way a save would.
    foreach (glob(fw_root() . '/storage/cache/*') ?: [] as $f) {
        if (is_dir($f)) bh_rrmdir($f); elseif (str_ends_with($f, '.cache')) @unlink($f);
    }
}

function fw_state(): array
{
    $j = bh_browser('admin')->ajaxGet('widgets.state')->json();
    assert_true($j['success'] ?? false, json_encode($j));
    return $j['data'];
}

function fw_area(array $state, string $key): array
{
    foreach ($state['areas'] as $a) if ($a['key'] === $key) return $a;
    fail("area {$key} missing");
}

function fw_post_page(): string
{
    $slug = 'fw-' . bin2hex(random_bytes(4));
    bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => $slug, 'title' => 'FW post ' . $slug, 'content' => '<p>x</p>', 'content_format' => 'html',
        'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
    ]);
    return $slug;
}

function fw_site(string $path = '/'): string
{
    return bh_browser()->request('GET', $path)->body;
}

test('frontend widgets e2e: permissions — only manage_apps may read or change widget areas', function () {
    fw_reset();
    foreach (['editor', 'subscriber'] as $u) {
        $r = bh_browser($u)->ajaxGet('widgets.state');
        assert_same(403, $r->status, "{$u}: " . $r->body);
        assert_same(403, bh_browser($u)->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.search'])->status);
    }
    assert_true(in_array(bh_browser()->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.search'])->status, [401, 403], true));
    // CSRF: a token from somewhere else is refused.
    $r = bh_browser('admin')->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.search'], [], 'not-the-token');
    assert_true(in_array($r->status, [403, 419], true), (string) $r->status);
    assert_same([], fw_area(fw_state(), 'footer')['instances']);
});

test('frontend widgets e2e: add, validate, save, reorder, move, duplicate and remove over AJAX', function () {
    fw_reset();
    $admin = bh_browser('admin');
    $j = $admin->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.recent-posts'])->json();
    assert_true($j['success'] ?? false, json_encode($j));
    $rp = $j['data']['added'];
    $s = $admin->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.search', 'position' => 0])->json()['data']['added'];
    assert_same([$s, $rp], array_column(fw_area(fw_state(), 'footer')['instances'], 'id'));

    $bad = $admin->ajax('widgets.save', ['id' => $rp, 'settings' => json_encode(['count' => 99, 'order' => 'nope'])]);
    assert_same(422, $bad->status, $bad->body);
    $fields = $bad->json()['error']['fields'] ?? [];
    assert_true(isset($fields['settings.count'], $fields['settings.order']), json_encode($fields));
    assert_same(422, $admin->ajax('widgets.save', ['id' => $rp, 'settings' => '[1,2]'])->status);
    assert_same(404, $admin->ajax('widgets.save', ['id' => 'w_nope', 'settings' => '{}'])->status);
    assert_same(404, $admin->ajax('widgets.add', ['area' => 'nowhere', 'widget' => 'core.search'])->status);

    $ok = $admin->ajax('widgets.save', ['id' => $rp, 'settings' => json_encode(['title' => 'Fresh <reads>', 'count' => '3']), 'class' => 'mine'])->json();
    assert_true($ok['success'] ?? false, json_encode($ok));
    $inst = fw_area($ok['data'], 'footer')['instances'][1];
    assert_same(3, $inst['settings']['count']);
    assert_same('mine', $inst['class']);

    $slug = fw_post_page();
    $page = fw_site('/posts/' . $slug);
    assert_contains('Fresh &lt;reads&gt;', $page, 'title escaped');
    assert_contains('widget-core-recent-posts mine', $page);
    assert_contains('class="widget-search"', $page);
    assert_true(strpos($page, 'widget-search') < strpos($page, 'Fresh &lt;reads'), 'order kept');

    $admin->ajax('widgets.order', ['area' => 'footer', 'ids' => [$rp, $s]]);
    assert_same([$rp, $s], array_column(fw_area(fw_state(), 'footer')['instances'], 'id'));
    $admin->ajax('widgets.move', ['id' => $s, 'area' => 'sidebar', 'position' => 0]);
    assert_same([$s], array_column(fw_area(fw_state(), 'sidebar')['instances'], 'id'));
    $copy = $admin->ajax('widgets.duplicate', ['id' => $rp])->json()['data']['added'];
    $side = fw_area(fw_state(), 'footer')['instances'];
    assert_same([$rp, $copy], array_column($side, 'id'));
    assert_same('Fresh <reads>', $side[1]['settings']['title']);

    $admin->ajax('widgets.options', ['id' => $copy, 'disabled' => true]);
    assert_same(1, substr_count(fw_site('/posts/' . $slug), 'Fresh &lt;reads&gt;'), 'a hidden copy is not shown');
    $admin->ajax('widgets.remove', ['id' => $copy]);
    assert_same([$rp], array_column(fw_area(fw_state(), 'footer')['instances'], 'id'));

    $p = $admin->ajax('widgets.preview', ['id' => $rp, 'settings' => json_encode(['title' => 'Preview only', 'count' => '2'])])->json();
    assert_contains('Preview only', $p['data']['html']);
    assert_not_contains('Preview only', fw_site('/posts/' . $slug), 'preview does not save');
});

test('frontend widgets e2e: records saved before 1.2.93 render the same and keep their settings', function () {
    fw_reset();
    $legacy = ['footer' => [
        ['id' => 'w_87c19799', 'widget' => 'core.search', 'settings' => ['title' => 'Search', 'placeholder' => '']],
        ['id' => 'w_97972030', 'widget' => 'core.recent-posts', 'settings' => ['title' => 'Recent Posts', 'count' => '8', 'order' => 'latest',
            'category' => '', 'show_thumbnail' => '1', 'thumbnail_size' => 'medium', 'thumbnail_shape' => 'wide', 'show_date' => '1',
            'show_excerpt' => '0', 'hide_current' => '1']],
        ['id' => 'w_567b006e', 'widget' => 'core.categories', 'settings' => ['title' => 'Categories', 'display' => 'list', 'count' => '',
            'order' => 'count', 'show_counts' => '1', 'hide_empty' => '0']],
        ['id' => 'w_gone0001', 'widget' => 'retired-app.thing', 'settings' => ['keep' => 'me']],
    ]];
    bh_db()->insert('settings', ['setting_group' => 'widgets', 'setting_key' => 'areas', 'setting_value' => json_encode($legacy), 'is_json' => 1, 'autoload' => 1]);
    $slug = fw_post_page();
    $page = fw_site('/posts/' . $slug);
    assert_contains('<form class="widget-search" method="get" action="/search"', $page);
    assert_contains('widget-recent-posts--rich widget-recent-posts--thumbs widget-recent-posts--medium widget-recent-posts--shape-wide', $page);
    assert_contains('<time class="widget-recent-posts__date"', $page);
    assert_contains('widget-terms widget-terms--category widget-terms--counts', $page);
    assert_not_contains('FW post ' . $slug . '</a>', $page, 'the post being read is left out');
    assert_not_contains('retired-app', preg_replace('/<!--.*?-->/s', '', $page));

    $st = fw_state();
    $side = fw_area($st, 'footer')['instances'];
    assert_same(['w_87c19799', 'w_97972030', 'w_567b006e', 'w_gone0001'], array_column($side, 'id'));
    assert_true($side[3]['unavailable'] !== null);
    $raw = json_decode((string) bh_db()->selectOne("SELECT setting_value FROM {settings} WHERE setting_group='widgets' AND setting_key='areas'")['setting_value'], true);
    assert_same(['keep' => 'me'], $raw['footer'][3]['settings'], 'nothing rewritten by reading');
    // Saving one widget keeps the others' settings exactly as stored (the
    // record gains the 1.2.93 option keys with their defaults, nothing else).
    bh_browser('admin')->ajax('widgets.save', ['id' => 'w_87c19799', 'settings' => json_encode(['title' => 'Find'])]);
    $raw2 = json_decode((string) bh_db()->selectOne("SELECT setting_value FROM {settings} WHERE setting_group='widgets' AND setting_key='areas'")['setting_value'], true);
    foreach ([1, 3] as $i) {
        assert_same($raw['footer'][$i]['settings'], $raw2['footer'][$i]['settings']);
        assert_same([$raw['footer'][$i]['id'], $raw['footer'][$i]['widget'], '', false, false],
            [$raw2['footer'][$i]['id'], $raw2['footer'][$i]['widget'], $raw2['footer'][$i]['v'], $raw2['footer'][$i]['hide_title'], $raw2['footer'][$i]['disabled']]);
    }
    assert_same('1.1.0', $raw2['footer'][0]['v']);
});

test('frontend widgets e2e: a theme overrides a core widget\'s template', function () {
    fw_reset();
    $theme = bh_db()->selectOne("SELECT setting_value FROM {settings} WHERE setting_group='appearance' AND setting_key='active_theme'");
    $slug = (string) ($theme['setting_value'] ?? 'default') ?: 'default';
    $dir = fw_root() . '/content/themes/' . $slug . '/templates/widgets';
    $made = !is_dir($dir);
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/search.php', '<div class="theme-search" data-ph="<?= $e($settings[\'placeholder\']) ?>">themed</div>');
    try {
        bh_browser('admin')->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.search']);
        $page = fw_site('/posts/' . fw_post_page());
        assert_contains('<div class="theme-search" data-ph="">themed</div>', $page);
        assert_not_contains('class="widget-search"', $page);
    } finally {
        @unlink($dir . '/search.php');
        if ($made) { @rmdir($dir); }
    }
});

test('frontend widgets e2e: cached output is never shown to signed-in users, and content changes clear it', function () {
    fw_reset();
    $admin = bh_browser('admin');
    $id = $admin->ajax('widgets.add', ['area' => 'footer', 'widget' => 'core.recent-posts'])->json()['data']['added'];
    $admin->ajax('widgets.save', ['id' => $id, 'settings' => json_encode(['title' => 'Cached', 'count' => '20', 'hide_current' => '0'])]);
    $slug = fw_post_page();
    $before = fw_site('/posts/' . $slug);
    $title = 'FW cache probe ' . bin2hex(random_bytes(3));
    // Insert behind the app's back (no hook): the visitor still sees the cached list.
    bh_db()->insert('posts', ['uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'fwc-' . bin2hex(random_bytes(3)), 'title' => $title, 'content' => '<p>x</p>', 'content_format' => 'html',
        'published_at' => '2027-01-01 00:00:00', 'created_at' => '2027-01-01 00:00:00']);
    assert_not_contains($title, fw_site('/posts/' . $slug), 'served from the cache');
    assert_contains($title, $admin->request('GET', '/posts/' . $slug)->body, 'a signed-in user gets fresh output');
    // A widget save (like any post / term / comment change) clears it.
    $admin->ajax('widgets.save', ['id' => $id, 'settings' => json_encode(['title' => 'Cached', 'count' => '20', 'hide_current' => '0'])]);
    assert_contains($title, fw_site('/posts/' . $slug));
    unset($before);
});

test('frontend widgets e2e: an app widget — schema, template, public API filtering, deactivation', function () {
    fw_reset();
    bh_install_app('fw-fixture', ['src/App.php' => FW_APP,
        'views/notice.php' => '<p class="fw-notice fw-<?= $e($settings[\'tone\']) ?>"><?= $e($data[\'upper\']) ?></p>'],
        ['namespace' => 'BhTest\\FwFixture']);
    try {
        $admin = bh_browser('admin');
        $st = fw_state();
        $keys = array_column($st['widgets'], 'key');
        assert_true(in_array('fw-fixture.notice', $keys, true), json_encode($keys));
        $id = $admin->ajax('widgets.add', ['area' => 'footer', 'widget' => 'fw-fixture.notice'])->json()['data']['added'];
        $r = $admin->ajax('widgets.save', ['id' => $id, 'settings' => json_encode(['text' => '', 'tone' => 'warn'])]);
        assert_same(422, $r->status, 'required field');
        $admin->ajax('widgets.save', ['id' => $id, 'settings' => json_encode(['text' => 'hello <x>', 'tone' => 'warn', 'token' => 's3cret'])]);
        $page = fw_site('/');
        assert_contains('<p class="fw-notice fw-warn">HELLO &lt;X&gt;</p>', $page);
        assert_same(1, substr_count($page, '.fw-notice{border:1px solid}'));

        $api = bh_browser()->request('GET', '/api/v1/widget-areas/footer')->json();
        $item = $api['data']['items'][0] ?? [];
        assert_same('fw-fixture.notice', $item['widget'] ?? null, json_encode($api));
        assert_false(isset($item['settings']['token']), 'private settings stay private');
        assert_same('hello <x>', $item['settings']['text']);

        bh_db()->execute("UPDATE {apps} SET status = 'inactive' WHERE slug = 'fw-fixture'");
        assert_not_contains('fw-notice', fw_site('/'), 'gone with its app');
        $inst = fw_area(fw_state(), 'footer')['instances'][0];
        assert_same($id, $inst['id']);
        assert_true($inst['unavailable'] !== null);
        bh_db()->execute("UPDATE {apps} SET status = 'active' WHERE slug = 'fw-fixture'");
        assert_contains('HELLO &lt;X&gt;', fw_site('/'), 'back, settings intact');
    } finally {
        bh_remove_app('fw-fixture');
    }
});

test('frontend widgets e2e: the old form routes still work, through validation', function () {
    fw_reset();
    $admin = bh_browser('admin');
    $page = $admin->request('GET', '/admin/widgets/areas');
    assert_same(200, $page->status);
    assert_contains('widget-areas.js', $page->body);
    preg_match('/data-csrf="([^"]+)"/', $page->body, $m);
    $csrf = $m[1] ?? '';
    $admin->request('POST', '/admin/widgets/areas/footer/add', http_build_query(['_csrf' => $csrf, 'widget' => 'core.tags']), ['Content-Type' => 'application/x-www-form-urlencoded']);
    $id = fw_area(fw_state(), 'footer')['instances'][0]['id'] ?? '';
    assert_true($id !== '');
    $admin->request('POST', '/admin/widgets/areas/footer/' . $id, http_build_query(['_csrf' => $csrf, 'settings' => ['title' => 'Tags', 'count' => '500']]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same('', fw_area(fw_state(), 'footer')['instances'][0]['settings']['title'], 'invalid form not saved');
    $admin->request('POST', '/admin/widgets/areas/footer/' . $id, http_build_query(['_csrf' => $csrf, 'settings' => ['title' => 'Tags', 'count' => '5', 'display' => 'cloud']]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    $inst = fw_area(fw_state(), 'footer')['instances'][0];
    assert_same(['Tags', 5, 'cloud'], [$inst['settings']['title'], $inst['settings']['count'], $inst['settings']['display']]);
    $admin->request('POST', '/admin/widgets/areas/footer/' . $id . '/remove', http_build_query(['_csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same([], fw_area(fw_state(), 'footer')['instances']);
});

test('frontend widgets e2e: the dashboard still loads its widgets', function () {
    $r = bh_browser('admin')->request('GET', '/admin/dashboard');
    assert_same(200, $r->status);
    assert_not_contains('Fatal', $r->body);
    $log = bh_core_log();
    assert_not_contains('failed in area', $log, 'no widget failed to render');
});
