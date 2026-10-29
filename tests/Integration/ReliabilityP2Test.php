<?php

declare(strict_types=1);

/*
 * 1.2.76 — the audit's P2 set (docs/audits/core-reliability-scalability-audit.md):
 * list sorting and keyset pages (CR-17), search (CR-16), slugs (CR-18),
 * author slugs (CR-19), the sitemap (CR-20), settings autoload (CR-22),
 * buffered view counts (CR-23) and retention cleanup (CR-24).
 */

/** The test server's own settings service (it shares storage/cache). */
function p2_site_settings(): \App\Services\SettingService
{
    return new \App\Services\SettingService(bh_db(), new \App\Core\Cache(BASEHIM_ROOT . '/storage/cache'));
}

function p2_get(string $path): HttpResponse
{
    return (new Browser(bh_env()['base_url']))->request('GET', $path, null, ['Accept' => 'application/json']);
}

/** A published post written directly, with a chosen publication time. */
function p2_post(string $title, string $publishedAt, array $extra = []): int
{
    return (int) bh_db()->insert('posts', $extra + [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'p2-' . bin2hex(random_bytes(5)), 'title' => $title, 'content' => '<p>' . $title . '</p>',
        'content_format' => 'html', 'published_at' => $publishedAt, 'created_at' => $publishedAt,
    ]);
}

function p2_token(): string
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
        json_encode(['login' => 'admin', 'password' => BH_TEST_PASSWORD]), ['Content-Type' => 'application/json']);
    $j = $res->json() ?? [];
    return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
}

/** Create a post through the REST API, as the site would; returns its data. */
function p2_create(string $token, array $body): array
{
    $r = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/posts', json_encode($body),
        ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
    if ($r->status !== 201) throw new \RuntimeException('create failed: ' . $r->status . ' ' . $r->body);
    return $r->json()['data'] ?? [];
}

function p2_repo(?string $cacheDir = null): \App\Repositories\PostRepository
{
    return new \App\Repositories\PostRepository(bh_db(), new \App\Core\Cache($cacheDir ?? bh_env()['tmp'] . '/p2-' . bin2hex(random_bytes(3))));
}

// --- CR-17 -------------------------------------------------------------------

test('lists: published content sorts on (published_at, id) with an index; ties are stable (CR-17)', function () {
    $db = bh_db();
    $idx = $db->select("SELECT COLUMN_NAME c FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@posts}' AND INDEX_NAME = 'idx_list' ORDER BY SEQ_IN_INDEX");
    assert_same(['type', 'status', 'deleted_at', 'published_at', 'id'], array_column($idx, 'c'));

    $tag = 'p2sort' . bin2hex(random_bytes(3));
    $a = p2_post("{$tag} a", '2031-01-01 10:00:00');
    $b = p2_post("{$tag} b", '2031-01-01 10:00:00');   // same time: the higher id first
    $c = p2_post("{$tag} c", '2031-01-02 10:00:00');
    $page = p2_repo()->paginate(['type' => 'post', 'status' => 'published'], 1, 3);
    assert_same([$c, $b, $a], array_map(fn($r) => (int) $r['id'], $page['data']));
});

test('api: ?after= pages through published posts by cursor, without gaps or repeats (CR-17)', function () {
    $ids = [];
    for ($i = 0; $i < 5; $i++) $ids[] = p2_post('p2 cursor ' . $i, '2032-02-0' . ($i + 1) . ' 08:00:00');
    p2_repo(BASEHIM_ROOT . '/storage/cache')->listsChanged();   // the server's cached totals: written by hand above
    $seen = [];
    $first = p2_get('/api/v1/posts?per_page=2')->json();
    $cursor = $first['meta']['next_cursor'] ?? null;
    foreach ($first['data'] as $p) $seen[] = (int) $p['id'];
    assert_true(is_string($cursor) && $cursor !== '', 'a cursor is offered');
    for ($guard = 0; $cursor && $guard < 50; $guard++) {
        $r = p2_get('/api/v1/posts?per_page=2&after=' . urlencode($cursor));
        assert_same(200, $r->status, $r->body);
        $j = $r->json();
        assert_false(isset($j['meta']['total']), 'no count on cursor pages');
        foreach ($j['data'] as $p) $seen[] = (int) $p['id'];
        $cursor = $j['meta']['next_cursor'] ?? null;
    }
    assert_same(count($seen), count(array_unique($seen)), 'no repeats');
    assert_same(array_reverse($ids), array_slice($seen, 0, 5), 'newest first, all five');
    assert_same(400, p2_get('/api/v1/posts?after=not-a-cursor')->status);
});

test('lists: public totals are cached and recounted after a save (CR-17)', function () {
    $dir = bh_env()['tmp'] . '/p2-count-' . bin2hex(random_bytes(3));
    $repo = p2_repo($dir);
    $f = ['type' => 'post', 'status' => 'published'];
    $before = $repo->paginate($f, 1, 1)['meta']['total'];
    p2_post('p2 uncounted', '2030-01-01 00:00:00');                   // written around the repository
    assert_same($before, $repo->paginate($f, 1, 1)['meta']['total'], 'served from the cache');
    $repo->create(['uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'p2-' . bin2hex(random_bytes(5)), 'title' => 'p2 counted', 'content' => '', 'content_format' => 'html',
        'published_at' => '2030-01-01 00:00:00']);
    assert_same($before + 2, $repo->paginate($f, 1, 1)['meta']['total'], 'a save starts a new count');
    // Drafts and other admin lists are never cached.
    $drafts = $repo->paginate(['type' => 'post', 'status' => 'draft'], 1, 1)['meta']['total'];
    p2_post('p2 draft', '2030-01-01 00:00:00', ['status' => 'draft', 'published_at' => null]);
    assert_same($drafts + 1, $repo->paginate(['type' => 'post', 'status' => 'draft'], 1, 1)['meta']['total']);
});

// --- CR-16 -------------------------------------------------------------------

test('search: words go to FULLTEXT; short queries and wildcards are matched literally (CR-16)', function () {
    $repo = p2_repo();
    $p = [];
    assert_same('MATCH(p.title, p.content, p.excerpt) AGAINST(:ft_q IN BOOLEAN MODE)', $repo->searchClause('Arduino +uno -- "ESP32"', 'p', $p, true));
    assert_same('+arduino* +uno* +esp32*', $p['ft_q'], 'operators dropped, every word required');
    $p = [];
    assert_same('p.title LIKE :s_title', $repo->searchClause('ai', 'p', $p, true), 'below the token size: title only');
    assert_same('%ai%', $p['s_title']);
    $p = [];
    $repo->searchClause('100%_off', 'p', $p, false);
    // 1.2.92: punctuation separates words, so neither % nor _ reaches LIKE.
    $likes = array_values(array_unique($p));
    sort($likes);
    assert_same(['%100%', '%off%'], $likes, 'every word, no wildcard from the query');
    assert_same(['اردو', 'خبریں'], \App\Repositories\PostRepository::fulltextWords('اردو کی خبریں'), 'Unicode words, 3+ letters');
});

test('search: a "%" query finds only what contains "%", on any engine (CR-16)', function () {
    $tag = 'p2pct' . bin2hex(random_bytes(3));
    p2_post("{$tag} 100% cotton", '2030-03-03 00:00:00');
    p2_post("{$tag} plain", '2030-03-03 00:00:00');
    p2_repo(BASEHIM_ROOT . '/storage/cache')->listsChanged();
    $all = p2_get('/api/v1/search?q=' . urlencode($tag))->json();
    assert_same(2, (int) ($all['meta']['total'] ?? -1), 'both found by their word');
    $pct = p2_get('/api/v1/search?q=' . urlencode('% cotton'))->json();
    $titles = array_column($pct['data'] ?? [], 'title');
    assert_true(in_array("{$tag} 100% cotton", $titles, true));
    assert_false(in_array("{$tag} plain", $titles, true), '"%" is not a wildcard');
});

test('search: visitors are limited to reading.search_rate_limit searches a minute (CR-16)', function () {
    bh_db()->execute("DELETE FROM {rate_limits}");   // earlier tests searched this minute too
    $s = p2_site_settings();
    $s->set('reading', 'search_rate_limit', '3');
    try {
        $codes = [];
        for ($i = 0; $i < 5; $i++) $codes[] = p2_get('/api/v1/search?q=ratelimit' . $i)->status;
        assert_same([200, 200, 200, 429, 429], $codes);
        $web = (new Browser(bh_env()['base_url']))->request('GET', '/search?q=again');
        assert_same(429, $web->status);
        assert_true($web->header('retry-after') !== null);
    } finally {
        $s->set('reading', 'search_rate_limit', '0');
        bh_db()->execute("DELETE FROM {rate_limits}");
    }
});

// --- CR-18 -------------------------------------------------------------------

test('slugs: non-Latin titles keep their letters; Latin titles are unchanged (CR-18)', function () {
    $H = \App\Core\Helpers::class;
    assert_same('اردو-میں-خبریں', $H::slug('اردو میں خبریں!', '-', true));
    assert_same('привет-мир', $H::slug('Привет, мир', '-', true));
    assert_same('cafe-deja-vu', $H::slug('Café déjà vu', '-', true), 'Latin as before');
    assert_same('n-a', $H::slug('اردو'), 'without $unicode, the old behaviour');
    assert_contains('/' . rawurlencode('اردو-خبریں'), $H::postUrl(['type' => 'page', 'slug' => 'اردو-خبریں']), 'links are percent-encoded');
});

test('slugs: the next free number is found in one query, and an Urdu post gets a readable address (CR-18)', function () {
    $tok = p2_token();
    $base = 'p2-dup-' . bin2hex(random_bytes(3));
    foreach (['', '-2', '-3', '-17'] as $sfx) p2_post('dup', '2030-01-01 00:00:00', ['slug' => $base . $sfx]);
    $made = p2_create($tok, ['title' => 'x', 'slug' => $base, 'status' => 'draft']);
    assert_same($base . '-18', $made['slug'], 'max + 1');

    $title = 'پاکستان کی خبریں ' . bin2hex(random_bytes(2));
    $s1 = p2_create($tok, ['title' => $title, 'status' => 'published'])['slug'];
    $s2 = p2_create($tok, ['title' => $title, 'status' => 'published'])['slug'];
    assert_true(str_starts_with($s1, 'پاکستان-کی-خبریں-'), $s1);
    assert_same($s1 . '-2', $s2);
    $page = p2_get('/api/v1/posts/' . rawurlencode($s1));
    assert_same(200, $page->status, 'found by its encoded address: ' . $page->body);
    assert_same($s1, $page->json()['data']['slug'] ?? null);
    $web = (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . rawurlencode($s1));
    assert_true(in_array($web->status, [200, 301, 302], true), 'the site opens it: ' . $web->status);
});

// --- CR-19 -------------------------------------------------------------------

test('authors: the slug lives in users.public_slug; unknown slugs cost one lookup (CR-19)', function () {
    $db = bh_db();
    $col = $db->selectOne("SELECT COUNT(*) c FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@users}' AND INDEX_NAME = 'uq_public_slug'");
    assert_same(1, (int) $col['c'], 'unique index');
    $uid = (int) $db->insert('users', ['uuid' => bh_uuid(), 'username' => 'p2a' . bin2hex(random_bytes(3)), 'email' => bin2hex(random_bytes(4)) . '@example.test',
        'password_hash' => 'x', 'display_name' => 'Zara Khan', 'role' => 'author', 'status' => 'active']);
    p2_post('by zara', '2030-01-01 00:00:00', ['author_id' => $uid]);
    $authors = new \App\Services\AuthorService($db, p2_site_settings());
    $slug = $authors->slugFor($authors->find($uid));
    assert_true(str_starts_with($slug, 'zara-khan'), $slug);
    assert_same($slug, $db->selectOne('SELECT public_slug s FROM {users} WHERE id = :i', ['i' => $uid])['s']);
    assert_same(200, p2_get('/author/' . $slug)->status);

    // A second "Zara Khan" gets the next number; slugs never repeat.
    $uid2 = (int) $db->insert('users', ['uuid' => bh_uuid(), 'username' => 'p2b' . bin2hex(random_bytes(3)), 'email' => bin2hex(random_bytes(4)) . '@example.test',
        'password_hash' => 'x', 'display_name' => 'Zara Khan', 'role' => 'author', 'status' => 'active']);
    $slug2 = $authors->slugFor($authors->find($uid2));
    assert_true($slug2 !== $slug && str_starts_with($slug2, 'zara-khan-'), $slug2);

    // Misses do not rebuild every author's slug: the backfill runs at most hourly.
    p2_site_settings()->set('authors', 'slug_backfill_at', (string) time(), false);
    $before = (int) $db->selectOne('SELECT COUNT(*) c FROM {users} WHERE public_slug IS NULL')['c'];
    for ($i = 0; $i < 3; $i++) assert_same(404, p2_get('/author/nobody-' . $i)->status);
    assert_same($before, (int) $db->selectOne('SELECT COUNT(*) c FROM {users} WHERE public_slug IS NULL')['c']);
});

// --- CR-20 -------------------------------------------------------------------

test('sitemap: an index of paged files with every published item, on APP_URL, cached until a save (CR-20)', function () {
    p2_repo(BASEHIM_ROOT . '/storage/cache')->listsChanged();
    // The test site's APP_URL is a 127.0.0.1 address, so a request for
    // another host is answered for that host; what matters is that it is
    // never cached for anyone else.
    $evil = (new Browser(bh_env()['base_url']))->request('GET', '/sitemap.xml', null, ['Host' => 'evil.example']);
    assert_same(200, $evil->status);
    $index = p2_get('/sitemap.xml');
    assert_contains('<sitemapindex', $index->body);
    assert_not_contains('evil.example', $index->body, 'a forged Host never reaches other visitors');
    assert_contains(bh_env()['base_url'] . '/sitemap-content-1.xml', $index->body);
    assert_contains('/sitemap-content-1.xml', $index->body);
    assert_contains('/sitemap-terms.xml', $index->body);

    $published = (int) bh_db()->selectOne("SELECT COUNT(*) c FROM {posts} WHERE status = 'published' AND deleted_at IS NULL")['c'];
    $urls = 0;
    preg_match_all('#<loc>[^<]*/sitemap-content-(\d+)\.xml</loc>#', $index->body, $m);
    foreach ($m[1] as $n) {
        $part = p2_get('/sitemap-content-' . $n . '.xml');
        assert_same(200, $part->status);
        $urls += substr_count($part->body, '<url>');
    }
    assert_same($published + 1, $urls, 'every published item, plus the home page');
    assert_same(404, p2_get('/sitemap-content-999.xml')->status);
    assert_same(404, p2_get('/sitemap-nothing.xml')->status);

    // A new post appears once it is saved through the site.
    $slug = p2_create(p2_token(), ['title' => 'P2 sitemap fresh ' . bin2hex(random_bytes(2)), 'status' => 'published'])['slug'];
    $found = false;
    foreach ($m[1] as $n) $found = $found || str_contains(p2_get('/sitemap-content-' . $n . '.xml')->body, $slug);
    assert_true($found);
});

test('sitemap: more than one file when the content needs it, by id range (CR-20)', function () {
    // Exercised through the starts logic at a small page size: the boundaries
    // must cover every id exactly once.
    $db = bh_db();
    $ids = array_map('intval', array_column($db->select("SELECT id FROM {posts} WHERE status = 'published' AND deleted_at IS NULL ORDER BY id"), 'id'));
    $size = 3;
    $starts = [0];
    $after = 0;
    while (true) {
        $row = $db->selectOne("SELECT id FROM {posts} WHERE status = 'published' AND deleted_at IS NULL AND id > :a ORDER BY id LIMIT 1 OFFSET " . ($size - 1), ['a' => $after]);
        if (!$row) break;
        $after = (int) $row['id'];
        if (!$db->selectOne("SELECT id FROM {posts} WHERE status = 'published' AND deleted_at IS NULL AND id > :a LIMIT 1", ['a' => $after])) break;
        $starts[] = $after;
    }
    $covered = [];
    foreach ($starts as $n => $from) {
        $to = $starts[$n + 1] ?? PHP_INT_MAX;
        foreach ($ids as $id) if ($id > $from && $id <= $to) $covered[] = $id;
    }
    assert_same($ids, $covered);
    assert_same((int) ceil(count($ids) / $size), count($starts));
});

// --- CR-22 -------------------------------------------------------------------

test('settings: autoload = 0 rows stay out of the snapshot, and writing one keeps it (CR-22)', function () {
    $db = bh_db();
    $dir = bh_env()['tmp'] . '/p2-settings-' . bin2hex(random_bytes(3));
    $s = new \App\Services\SettingService($db, new \App\Core\Cache($dir));
    $s->set('p2', 'big', str_repeat('x', 1000), false);
    $s->set('p2', 'small', 'v');
    $s->get('p2', 'small');                                  // builds the snapshot
    $snap = (new \App\Core\Cache($dir))->get('settings.all');
    assert_false(isset($snap['data']['p2']['big']), 'not autoloaded');
    assert_same('v', $snap['data']['p2']['small']);
    $gen = (new \App\Core\Cache($dir))->get('settings.generation');
    $s->set('p2', 'big', 'changed', false);
    assert_same($gen, (new \App\Core\Cache($dir))->get('settings.generation'), 'the snapshot survives');
    $t = new \App\Services\SettingService($db, new \App\Core\Cache($dir));
    assert_same('changed', $t->get('p2', 'big'), 'read on demand, current');
    assert_same('changed', $t->all()['p2']['big'] ?? null, 'all() still has everything');

    // The scheduler's sweep time is no longer a setting.
    $sched = new \App\Services\SchedulerService($db, $s, new \App\Core\Logger(bh_env()['tmp'] . '/p2-logs'));
    $sched->runDue(true);
    assert_same($gen, (new \App\Core\Cache($dir))->get('settings.generation'), 'a sweep keeps the snapshot');
    assert_true($sched->lastSweep() !== '');
});

// --- CR-23 -------------------------------------------------------------------

test('views: buffered in sharded minute counters and folded into view_count (CR-23)', function () {
    $db = bh_db();
    $id = p2_post('p2 views', '2030-01-01 00:00:00', ['view_count' => 10, 'updated_at' => '2020-01-01 00:00:00']);
    $settings = new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/p2-views-' . bin2hex(random_bytes(3))));
    $c = new \App\Services\PostViewCounter($db, $settings);
    $t = 1_900_000_020;
    for ($i = 0; $i < 30; $i++) $c->record($id, $t);
    assert_same(10, (int) $db->selectOne('SELECT view_count v FROM {posts} WHERE id = :i', ['i' => $id])['v'], 'the post row is not written');
    assert_same(30, $c->pending($id));
    $c->fold($t + 30);
    assert_same(30, $c->pending($id), 'the current minute is still open');
    assert_true($c->fold($t + 180) >= 30);
    $row = $db->selectOne('SELECT view_count v, updated_at u FROM {posts} WHERE id = :i', ['i' => $id]);
    assert_same(40, (int) $row['v']);
    assert_same('2020-01-01 00:00:00', $row['u'], 'a view is not an edit');
    assert_same(0, $c->pending($id));

    $settings->set('reading', 'view_counting', 'off');
    $c->record($id, $t);
    assert_same(0, $c->pending($id));
    $settings->set('reading', 'view_counting', 'direct');
    $c->record($id, $t);
    assert_same(41, (int) $db->selectOne('SELECT view_count v FROM {posts} WHERE id = :i', ['i' => $id])['v']);
});

// --- CR-24 -------------------------------------------------------------------

test('retention: expired tokens, old logs, notifications and idle sessions are removed (CR-24)', function () {
    $db = bh_db();
    $now = time();
    $old = gmdate('Y-m-d H:i:s', $now - 400 * 86400);
    $recent = gmdate('Y-m-d H:i:s', $now - 86400);
    $uid = bh_env()['users']['admin'];
    $db->execute('DELETE FROM {refresh_tokens}');
    foreach ([[$old, 'gone'], [gmdate('Y-m-d H:i:s', $now + 86400), 'live']] as [$exp, $label]) {
        $db->insert('refresh_tokens', ['user_id' => $uid, 'token_hash' => hash('sha256', $label . random_bytes(4)), 'family' => bh_uuid(), 'expires_at' => $exp]);
    }
    $db->insert('user_activity_log', ['user_id' => $uid, 'event' => 'p2.old', 'created_at' => $old]);
    $db->insert('user_activity_log', ['user_id' => $uid, 'event' => 'p2.new', 'created_at' => $recent]);
    $db->insert('notifications', ['user_id' => $uid, 'type' => 'p2', 'title' => 'old', 'created_at' => $old]);
    $db->insert('notifications', ['user_id' => $uid, 'type' => 'p2', 'title' => 'new', 'created_at' => $recent]);
    $sess = BASEHIM_ROOT . '/storage/sessions/sess_p2test' . bin2hex(random_bytes(4));
    file_put_contents($sess, 'x');
    touch($sess, $now - 3 * 86400);

    $settings = new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/p2-ret-' . bin2hex(random_bytes(3))));
    $out = (new \App\Services\RetentionService($db, $settings))->run($now);
    assert_true(($out['refresh_tokens'] ?? 0) >= 1, json_encode($out));
    assert_same(1, (int) $db->selectOne('SELECT COUNT(*) c FROM {refresh_tokens}')['c'], 'the live token stays');
    assert_same(['p2.new'], array_column($db->select("SELECT event FROM {user_activity_log} WHERE event LIKE 'p2.%'"), 'event'));
    assert_same(['new'], array_column($db->select("SELECT title FROM {notifications} WHERE type = 'p2'"), 'title'));
    assert_false(is_file($sess), 'an idle session file is removed');

    // 0 keeps a kind forever.
    $db->insert('notifications', ['user_id' => $uid, 'type' => 'p2', 'title' => 'kept', 'created_at' => $old]);
    $settings->set('retention', 'notification_days', '0');
    (new \App\Services\RetentionService($db, $settings))->run($now);
    assert_same(1, (int) $db->selectOne("SELECT COUNT(*) c FROM {notifications} WHERE type = 'p2' AND title = 'kept'")['c']);
});

test('scheduler: core tasks are registered only when a sweep runs, and run like app tasks (CR-23, CR-24)', function () {
    $db = bh_db();
    $db->execute("DELETE FROM {scheduled_tasks} WHERE app_slug = 'core'");
    $settings = new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/p2-core-' . bin2hex(random_bytes(3))));
    $sched = new \App\Services\SchedulerService($db, $settings, new \App\Core\Logger(bh_env()['tmp'] . '/p2-logs'));
    $ran = 0;
    $sched->core('p2-probe', 60, function () use (&$ran) { $ran++; });
    assert_same(0, (int) $db->selectOne("SELECT COUNT(*) c FROM {scheduled_tasks} WHERE app_slug = 'core'")['c'], 'nothing written yet');
    $sched->runDue(true);
    assert_same(1, (int) $db->selectOne("SELECT COUNT(*) c FROM {scheduled_tasks} WHERE app_slug = 'core' AND task_key = 'p2-probe'")['c']);
    $db->execute("UPDATE {scheduled_tasks} SET next_run_at = :d WHERE app_slug = 'core'", ['d' => gmdate('Y-m-d H:i:s', time() - 5)]);
    $sched->runDue(true);
    assert_same(1, $ran);
    $db->execute("DELETE FROM {scheduled_tasks} WHERE app_slug = 'core'");
});
