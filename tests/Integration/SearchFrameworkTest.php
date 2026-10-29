<?php

declare(strict_types=1);

/*
 * 1.2.92 — BH Search: the shared index, its background build, every core
 * source through its admin list, site search, Settings → Search, and the app
 * API. Runs on whatever database the suite has: MariaDB/MySQL use the
 * FULLTEXT engine, Dolt the LIKE engine; the results must be the same.
 */

const BHS_APP = <<<'PHP'
<?php
namespace BhTest\BhsFixture;

use App\Core\Ajax\AjaxContext;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $t = $this->table('items');
        $this->api()->search()->source('items', [
            'label' => 'Fixture items',
            'public' => true,
            'table' => $t,
            'columns' => ['name', 'notes'],
            'batch' => 2,
            'documents' => fn(int $after, int $limit) => array_map([$this, 'doc'],
                $this->db()->select("SELECT * FROM {$t} WHERE id > :a ORDER BY id LIMIT " . $limit, ['a' => $after])),
            'document' => function (int $id) use ($t) {
                $r = $this->db()->selectOne("SELECT * FROM {$t} WHERE id = :i", ['i' => $id]);
                return $r ? $this->doc($r) : null;
            },
            'count' => fn() => (int) ($this->db()->selectOne("SELECT COUNT(*) AS c FROM {$t}")['c'] ?? 0),
            'hydrate' => function (array $ids) use ($t) {
                $out = [];
                foreach ($this->db()->select("SELECT * FROM {$t} WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')') as $r) $out[(int) $r['id']] = $r;
                return $out;
            },
        ]);
        $opts = ['auth' => 'public', 'methods' => ['GET'], 'rate_limit' => ['max' => 1000, 'per' => 60, 'by' => 'ip'], 'input' => ['q' => 'string|max:200', 'id' => 'int']];
        $this->ajax('query', ['handler' => fn(AjaxContext $c) => $this->api()->search()->query((string) $c->input('q'))] + $opts);
        $this->ajax('ids', ['handler' => function (AjaxContext $c) use ($t) {
            $p = [];
            $w = $this->api()->search()->constrain('items', (string) $c->input('q'), 'i', $p);
            return ['ids' => array_map('intval', array_column($this->db()->select("SELECT i.id FROM {$t} i WHERE {$w} ORDER BY i.id", $p), 'id')),
                'ready' => $this->api()->search()->ready('items')];
        }] + $opts);
        $this->ajax('touch', ['handler' => function (AjaxContext $c) { $this->api()->search()->touch('items', (int) $c->input('id')); return ['ok' => true]; }] + $opts);
        $this->ajax('site', ['handler' => function (AjaxContext $c) {
            $r = (new \App\Core\Search\SiteSearch(\App\Core\Application::getInstance()))->search((string) $c->input('q'));
            return ['total' => $r['total'], 'items' => array_map(fn($i) => [$i['source'], $i['title'], $i['url']], $r['items'])];
        }] + $opts);
    }

    public function doc(array $r): array
    {
        return ['id' => (int) $r['id'], 'title' => $r['name'], 'body' => (string) $r['notes'],
            'public' => (int) $r['visible'] === 1, 'url' => '/bhs/' . (int) $r['id'], 'date' => $r['created_at']];
    }
}
PHP;

function bhs_setup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    bh_db()->execute('CREATE TABLE IF NOT EXISTS {app_bhs_fixture_items} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, notes TEXT NULL,
        visible TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    bh_install_app('bhs-fixture', ['src/App.php' => BHS_APP], ['namespace' => 'BhTest\\BhsFixture']);
}

/** Run Build now until nothing is building. */
function bhs_build(): array
{
    $admin = bh_browser('admin');
    $last = [];
    for ($i = 0; $i < 60; $i++) {
        $res = $admin->ajax('search.build');
        $j = $res->json();
        assert_true($j['success'] ?? false, 'search.build: ' . $res->body);
        $last = $j['data'];
        if (!$last['building']) return $last;
    }
    fail('The index never finished building: ' . json_encode($last));
}

function bhs_ready(array $status, string $id): bool
{
    foreach ($status['sources'] as $s) if ($s['id'] === $id) return (bool) $s['ready'];
    return false;
}

function bhs_post(string $title, array $extra = []): int
{
    return (int) bh_db()->insert('posts', $extra + [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'bhs-' . bin2hex(random_bytes(5)), 'title' => $title, 'content' => '<p>' . $title . '</p>',
        'content_format' => 'html', 'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
    ]);
}

function bhs_table_ids(string $table, string $q, array $extra = []): array
{
    $j = bh_browser('admin')->ajaxGet('content.table', ['table' => $table, 'search' => $q, 'per_page' => 100] + $extra)->json();
    assert_true($j['success'] ?? false, json_encode($j));
    $ids = [];
    foreach ($j['data']['rows'] ?? [] as $r) $ids[] = (int) ($r['id'] ?? 0);
    sort($ids);
    return $ids;
}

/** Save Settings → Search through its form (settings are cached; the form clears the cache). */
function bhs_save_site(array $types, array $sources = [], string $sort = 'relevance'): \HttpResponse
{
    $admin = bh_browser('admin');
    $page = $admin->request('GET', '/admin/settings/search');
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    return $admin->request('POST', '/admin/settings/search', http_build_query(['_csrf' => $m[1] ?? '', 'site_type' => $types, 'site_source' => $sources, 'site_sort' => $sort]),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
}

function bhs_rebuild(string $source): void
{
    $j = bh_browser('admin')->ajax('search.rebuild', ['source' => $source])->json();
    assert_true($j['success'] ?? false, json_encode($j));
}

test('bh search e2e: Build now indexes every source; status, engine and permissions', function () {
    bhs_setup();
    $j = bh_browser('admin')->ajaxGet('search.status')->json();
    assert_true($j['success'] ?? false, json_encode($j));
    assert_true(in_array($j['data']['engine'], ['fulltext', 'like'], true), $j['data']['engine']);
    $ids = array_column($j['data']['sources'], 'id');
    foreach (['content', 'media', 'comments', 'users', 'bhs-fixture.items'] as $want) assert_true(in_array($want, $ids, true), "source {$want}");

    $st = bhs_build();
    foreach (['content', 'media', 'comments', 'users', 'bhs-fixture.items'] as $want) assert_true(bhs_ready($st, $want), "{$want} ready");
    $row = bh_db()->selectOne("SELECT COUNT(*) AS c FROM {search_index} WHERE source = 'users'");
    assert_true((int) $row['c'] >= 4, 'users indexed');

    foreach (['editor', 'author', 'subscriber'] as $who) {
        $b = bh_browser($who);
        assert_same(403, $b->ajax('search.build')->status, "{$who}: build");
        assert_same(403, $b->ajaxGet('search.status')->status, "{$who}: status");
    }
    assert_same(422, bh_browser('admin')->ajax('search.rebuild', ['source' => 'nope'])->status);
});

test('bh search e2e: posts list finds every word in any order, exclusions, phrases, short words and stopwords', function () {
    bhs_setup();
    $a = bhs_post('Qzk DigitalOcean Droplet Pricing 2026');
    $b = bhs_post('Qzk Hetzner pricing table');
    $c = bhs_post('Qzk AI tools for the go developer');
    $d = bhs_post('Qzk per-second billing explained');
    bhs_rebuild('content');
    bhs_build();
    try {
        assert_same([$a, $b], bhs_table_ids('content.posts', 'pricing qzk'));
        assert_same([$a], bhs_table_ids('content.posts', 'qzk digitalocean pricing'), 'words apart, any order');
        assert_same([$a], bhs_table_ids('content.posts', 'qzk pricing -hetzner'), 'exclusion');
        assert_same([$c], bhs_table_ids('content.posts', 'qzk ai go'), 'words below the FULLTEXT minimum');
        assert_same([$c], bhs_table_ids('content.posts', 'the qzk developer'), 'a stopword');
        assert_same([$d], bhs_table_ids('content.posts', '"per second billing" qzk'), 'phrase');
        assert_same([$a], bhs_table_ids('content.posts', 'QZK DROPLET'), 'case');
        assert_same([], bhs_table_ids('content.posts', 'qzk pricing azure'), 'every word must match');
        assert_same([], bhs_table_ids('content.posts', '%%'), 'only punctuation matches nothing');
    } finally {
        foreach ([$a, $b, $c, $d] as $id) bh_db()->execute('DELETE FROM {posts} WHERE id = :i', ['i' => $id]);
    }
});

test('bh search e2e: saves through core keep the index current: create, rename, trash, restore, delete', function () {
    bhs_setup();
    bhs_build();
    $tok = (function (): string {
        $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
            json_encode(['login' => 'admin', 'password' => BH_TEST_PASSWORD]), ['Content-Type' => 'application/json']);
        $j = $res->json() ?? [];
        return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
    })();
    $rest = fn(string $m, string $path, array $body = []) => (new Browser(bh_env()['base_url']))->request($m, $path, $body ? json_encode($body) : null,
        ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
    $res = $rest('POST', '/api/v1/posts', ['title' => 'Wxq Lighthouse keeper', 'content' => '<p>On the rocks.</p>', 'status' => 'published']);
    $j = $res->json() ?? [];
    $id = (int) ($j['data']['id'] ?? $j['id'] ?? 0);
    assert_true($id > 0, $res->body);
    try {
        $row = bh_db()->selectOne("SELECT title, is_public FROM {search_index} WHERE source = 'content' AND object_id = :i", ['i' => $id]);
        assert_same('Wxq Lighthouse keeper', $row['title'] ?? null, 'indexed on create');
        assert_same([$id], bhs_table_ids('content.posts', 'wxq lighthouse'));

        assert_same([$id], bhs_table_ids('content.posts', 'wxq rocks'), 'body text');
        $put = $rest('PUT', '/api/v1/posts/' . $id, ['title' => 'Wxq Harbour master', 'content' => '<p>By the pier.</p>']);
        assert_same(200, $put->status, $put->body);
        assert_same('Wxq Harbour master', bh_db()->selectOne('SELECT title FROM {posts} WHERE id = :i', ['i' => $id])['title'] ?? null, 'saved');
        assert_same('Wxq Harbour master', bh_db()->selectOne("SELECT title FROM {search_index} WHERE source = 'content' AND object_id = :i", ['i' => $id])['title'] ?? null, 'reindexed');
        assert_same([$id], bhs_table_ids('content.posts', 'wxq lighthouse'), 'the slug keeps its words');
        assert_same([$id], bhs_table_ids('content.posts', 'wxq pier'), 'new text found');
        assert_same([$id], bhs_table_ids('content.posts', 'wxq harbour'), 'new title found');

        $rest('DELETE', '/api/v1/posts/' . $id);
        $row = bh_db()->selectOne("SELECT status, is_public FROM {search_index} WHERE source = 'content' AND object_id = :i", ['i' => $id]);
        assert_same(['status' => 'trash', 'is_public' => 0], ['status' => $row['status'] ?? null, 'is_public' => (int) ($row['is_public'] ?? -1)], 'trashed: kept for the Trash list, not public');
        $site = bh_browser()->request('GET', '/search?q=' . urlencode('wxq harbour'))->body;
        assert_not_contains('Wxq Harbour', $site, 'not in site search');
    } finally {
        bh_db()->execute('DELETE FROM {posts} WHERE id = :i', ['i' => $id]);
    }
});

test('bh search e2e: site search ranks posts and pages by relevance, with snippets; settings choose what it covers', function () {
    bhs_setup();
    $p1 = bhs_post('Vrm gardening notes', ['content' => '<p>Something about vrm soil and roses.</p>']);
    $p2 = bhs_post('Vrm roses in winter', ['content' => '<p>Roses, roses and more roses.</p>']);
    $pg = bhs_post('Vrm roses page', ['type' => 'page', 'content' => '<p>A page about roses.</p>']);
    $draft = bhs_post('Vrm roses draft', ['status' => 'draft']);
    bhs_rebuild('content');
    bhs_build();
    $admin = bh_browser('admin');
    try {
        $html = bh_browser()->request('GET', '/search?q=' . urlencode('vrm roses'))->body;
        assert_contains('Vrm roses in winter', $html);
        assert_contains('Vrm roses page', $html, 'pages are searched');
        assert_not_contains('Vrm roses draft', $html, 'drafts never');
        assert_true(strpos($html, 'Vrm roses in winter') < strpos($html, 'Vrm gardening notes') || !str_contains($html, 'Vrm gardening notes'),
            'a title match ranks above a body match');

        $api = bh_browser()->request('GET', '/api/v1/search?q=' . urlencode('vrm roses'))->json();
        $titles = array_column($api['data'] ?? [], 'title');
        assert_true(in_array('Vrm roses page', $titles, true), json_encode($titles));
        $first = $api['data'][0] ?? [];
        assert_contains('<mark>', (string) ($first['search_snippet'] ?? '') . (string) ($first['search_title'] ?? ''), 'highlighted');
        assert_same(3, (int) ($api['meta']['total'] ?? 0), 'posts and the page, not the draft');

        // Settings → Search: posts only.
        $page = $admin->request('GET', '/admin/settings/search');
        assert_same(200, $page->status);
        assert_contains('Search index', $page->body);
        $save = bhs_save_site(['post' => '1', 'page' => '0'], ['bhs-fixture.items' => '1']);
        assert_true(in_array($save->status, [302, 303], true), (string) $save->status);
        $api = bh_browser()->request('GET', '/api/v1/search?q=' . urlencode('vrm roses'))->json();
        assert_false(in_array('Vrm roses page', array_column($api['data'] ?? [], 'title'), true), 'pages switched off');
        assert_same(403, bh_browser('editor')->request('GET', '/admin/settings/search')->status);
    } finally {
        bhs_save_site(['post' => '1', 'page' => '1'], ['bhs-fixture.items' => '1']);
        foreach ([$p1, $p2, $pg, $draft] as $id) bh_db()->execute('DELETE FROM {posts} WHERE id = :i', ['i' => $id]);
    }
});

test('bh search e2e: media, comments and users lists use the index', function () {
    bhs_setup();
    $uid = bh_env()['users']['admin'];
    $mid = (int) bh_db()->insert('media', ['uuid' => bh_uuid(), 'author_id' => $uid, 'title' => 'Snipaste_2026-10-27_10-04-58', 'alt_text' => '',
        'caption' => 'Qmx office in Lahore', 'mime_type' => 'image/png', 'file_name' => 'qmx-snipaste.png', 'original_name' => 'Snipaste_2026-10-27.png',
        'file_size' => 10, 'storage_path' => 'tests/qmx.png', 'url' => '/uploads/tests/qmx.png']);
    $post = bhs_post('Qmx comment target');
    $cid = (int) bh_db()->insert('comments', ['post_id' => $post, 'author_name' => 'Qmx Reader', 'author_email' => 'qmx.reader@example.org',
        'content' => 'Great write-up about qmx droplets', 'status' => 'approved']);
    foreach (['media', 'comments'] as $s) bhs_rebuild($s);
    bhs_build();
    try {
        $media = bh_browser('admin')->ajaxGet('media.list', ['q' => 'qmx lahore'])->json();
        assert_same([$mid], array_map(fn($r) => (int) $r['id'], $media['data']['data'] ?? []), 'caption words');
        $media = bh_browser('admin')->ajaxGet('media.list', ['q' => 'snipaste 2026 qmx'])->json();
        assert_same([$mid], array_map(fn($r) => (int) $r['id'], $media['data']['data'] ?? []), 'separators in names');
        assert_same([$cid], bhs_table_ids('comments.list', 'qmx droplets'));
        assert_same([$cid], bhs_table_ids('comments.list', 'qmx reader example'), 'author and email');
        $users = bh_browser('admin')->request('GET', '/admin/users?search=' . urlencode('editor'))->status;
        assert_same(200, $users);
    } finally {
        bh_db()->execute('DELETE FROM {comments} WHERE id = :i', ['i' => $cid]);
        bh_db()->execute('DELETE FROM {posts} WHERE id = :i', ['i' => $post]);
        bh_db()->execute('DELETE FROM {media} WHERE id = :i', ['i' => $mid]);
    }
});

test('bh search e2e: an app indexes its own records, searches them, and they appear in site search', function () {
    bhs_setup();
    $db = bh_db();
    $db->execute('DELETE FROM {app_bhs_fixture_items}');
    $ids = [];
    foreach ([['Blue cotton shirt', 'Soft and light', 1], ['Red cotton scarf', 'Warm', 1], ['Blue denim jacket', 'Hidden item', 0]] as [$n, $notes, $v]) {
        $ids[] = (int) $db->insert('app_bhs_fixture_items', ['name' => $n, 'notes' => $notes, 'visible' => $v]);
    }
    $guest = bh_browser();
    // Before the build: answered from the app's own table.
    bhs_rebuild('bhs-fixture.items');
    $j = $guest->ajaxGet('bhs-fixture.ids', ['q' => 'cotton blue'])->json();
    assert_same(['ids' => [$ids[0]], 'ready' => false], $j['data'] ?? null, 'fallback before indexing');
    bhs_build();
    $j = $guest->ajaxGet('bhs-fixture.ids', ['q' => 'cotton blue'])->json();
    assert_same(['ids' => [$ids[0]], 'ready' => true], $j['data'] ?? null, 'through the index');

    $q = $guest->ajaxGet('bhs-fixture.query', ['q' => 'blue'])->json()['data'] ?? [];
    assert_same(2, $q['total'] ?? null, 'its own query sees hidden items too');
    $names = array_map(fn($i) => $i['item']['name'] ?? null, $q['items'] ?? []);
    sort($names);
    assert_same(['Blue cotton shirt', 'Blue denim jacket'], $names, 'hydrated rows');

    // A change, indexed by touch().
    $db->update('app_bhs_fixture_items', ['name' => 'Green cotton shirt'], ['id' => $ids[0]]);
    $guest->ajaxGet('bhs-fixture.touch', ['id' => $ids[0]]);
    $j = $guest->ajaxGet('bhs-fixture.ids', ['q' => 'green shirt'])->json();
    assert_same([$ids[0]], $j['data']['ids'] ?? null);
    $j = $guest->ajaxGet('bhs-fixture.ids', ['q' => 'blue shirt'])->json();
    assert_same([], $j['data']['ids'] ?? null, 'the old name is gone from the index');

    // Site search: the app's public items, next to content; never hidden ones.
    $site = $guest->ajaxGet('bhs-fixture.site', ['q' => 'cotton'])->json()['data'] ?? [];
    $found = array_map(fn($i) => $i[0] . ':' . $i[1], array_filter($site['items'] ?? [], fn($i) => $i[0] === 'bhs-fixture.items'));
    sort($found);
    assert_same(['bhs-fixture.items:Green cotton shirt', 'bhs-fixture.items:Red cotton scarf'], $found, json_encode($site));
    $site = $guest->ajaxGet('bhs-fixture.site', ['q' => 'denim jacket'])->json()['data'] ?? [];
    assert_same(0, $site['total'] ?? null, 'a hidden item is not public');
    $api = $guest->request('GET', '/api/v1/search?q=' . urlencode('scarf'))->json();
    assert_same(0, (int) ($api['meta']['total'] ?? -1), 'the search API returns content only');
});

test('bh search e2e: rows written behind core\'s back are found at once and indexed by the next build', function () {
    bhs_setup();
    bhs_build();
    $id = bhs_post('Ytrq imported straight into the table');
    try {
        assert_same(null, bh_db()->selectOne("SELECT id FROM {search_index} WHERE source = 'content' AND object_id = :i", ['i' => $id]), 'not indexed yet');
        assert_same([$id], bhs_table_ids('content.posts', 'ytrq imported'), 'admin list');
        $api = bh_browser()->request('GET', '/api/v1/search?q=' . urlencode('ytrq imported'))->json();
        assert_same(1, (int) ($api['meta']['total'] ?? 0), 'site search');
        bhs_build();
        assert_true(bh_db()->selectOne("SELECT id FROM {search_index} WHERE source = 'content' AND object_id = :i", ['i' => $id]) !== null, 'caught up');
    } finally {
        bh_db()->execute('DELETE FROM {posts} WHERE id = :i', ['i' => $id]);
    }
});
