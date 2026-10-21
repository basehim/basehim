<?php

declare(strict_types=1);

/*
 * 1.2.75 — the audit's P1 set (docs/audits/core-reliability-scalability-audit.md):
 * migrations safe to run twice (CR-07), scheduler leases and backoff (CR-12),
 * idempotency keys with replay for REST, public routes and AJAX (CR-27), the
 * webhook helper (CR-28), settings writes under concurrency (CR-09), and
 * transactions through the App API (CR-08).
 */

const P1_JSON = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

function p1_login(string $who): string
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
        json_encode(['login' => $who, 'password' => BH_TEST_PASSWORD]), ['Content-Type' => 'application/json']);
    $j = $res->json() ?? [];
    return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
}

function p1_api(string $method, string $path, ?array $body, string $bearer, array $headers = []): HttpResponse
{
    return (new Browser(bh_env()['base_url']))->request($method, $path, $body === null ? null : json_encode($body),
        ['Authorization' => 'Bearer ' . $bearer] + P1_JSON + $headers);
}

function p1_titles(string $title): int
{
    return count(array_keys(array_column(bh_db()->select('SELECT title FROM {posts} ORDER BY id DESC LIMIT 500'), 'title'), $title, true));
}

function p1_app(string $slug, string $boot, string $methods = ''): void
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $php = "<?php\ndeclare(strict_types=1);\nnamespace {$ns};\nuse App\\Core\\Request;\nuse App\\Core\\Response;\n"
        . "class App extends \\App\\Core\\App\n{\n    public function boot(): void\n    {\n        \$site = \$this->router()->site();\n{$boot}\n    }\n{$methods}\n}\n";
    bh_install_app($slug, ['src/App.php' => $php], ['namespace' => $ns]);
}

function p1_scheduler(): \App\Services\SchedulerService
{
    $db = bh_db();
    $settings = new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/p1-cache-' . bin2hex(random_bytes(3))));
    return new \App\Services\SchedulerService($db, $settings, new \App\Core\Logger(bh_env()['tmp'] . '/p1-logs'));
}

// --- CR-07: migrations ----------------------------------------------------------

test('migrations: every migration can run a second time without errors or changing data (CR-07)', function () {
    $db = bh_db();
    $env = bh_env();
    $db->insert('posts', ['uuid' => bh_uuid(), 'author_id' => $env['users']['admin'], 'type' => 'page', 'status' => 'published',
        'slug' => 'p1-tz-' . bin2hex(random_bytes(3)), 'title' => 'TZ probe', 'content' => 'x', 'content_format' => 'html',
        'published_at' => '2026-01-01 10:00:00', 'created_at' => '2025-12-31 09:00:00']);
    $snapshot = fn() => [
        'published' => array_column($db->select('SELECT id, published_at FROM {posts} ORDER BY id'), 'published_at', 'id'),
        'terms'     => array_column($db->select('SELECT id, count FROM {terms} ORDER BY id'), 'count', 'id'),
        'post_term' => (int) $db->selectOne('SELECT COUNT(*) c FROM {post_term}')['c'],
        'settings'  => (int) $db->selectOne('SELECT COUNT(*) c FROM {settings}')['c'],
        'apps'      => array_column($db->select('SELECT slug, schema_version FROM {apps} ORDER BY slug'), 'schema_version', 'slug'),
        'taxonomies'=> (int) $db->selectOne('SELECT COUNT(*) c FROM {taxonomies}')['c'],
    ];
    $rerun = function () use ($db, $env): void {
        if ((string) getenv('BASEHIM_TEST_DB_COMPAT') === 'dolt') {
            bh_migrate_compat($env['db'], false);
            return;
        }
        $count = (int) $db->selectOne('SELECT COUNT(*) c FROM {migrations}')['c'];
        $db->execute('DELETE FROM {migrations}');
        $res = (new \App\Services\MigrationService($db))->run();
        assert_same(null, $res['error'], 'rerun failed: ' . (string) $res['error']);
        assert_same($count, count($res['applied']));
    };
    // The first rerun may repair rows other tests wrote by hand (term counts,
    // posts with no category); the second must then change nothing at all.
    $rerun();
    $before = $snapshot();
    $rerun();
    $after = $snapshot();
    foreach ($before as $part => $value) {
        if ($part === 'published' || $part === 'terms') {
            $diff = array_diff_assoc(array_map('strval', $value), array_map('strval', $after[$part]));
            assert_same([], $diff, "{$part} changed: " . json_encode(array_intersect_key($after[$part], $diff)));
        } else {
            assert_same($value, $after[$part], "{$part} changed");
        }
    }
    // 013 recorded each of its steps, so a retry of it changes nothing.
    assert_same(8, (int) $db->selectOne("SELECT COUNT(*) c FROM {migration_progress} WHERE migration = '013_utc_timestamps'")['c']);
});

test('updates: a failing migration is reported by name, and later ones are not run (CR-05)', function () {
    $dir = BASEHIM_ROOT . '/database/migrations';
    $bad = $dir . '/998_p1_broken.sql';
    $after = $dir . '/999_p1_after.sql';
    file_put_contents($bad, "SELECT * FROM {p1_no_such_table};\n");
    file_put_contents($after, "CREATE TABLE IF NOT EXISTS {p1_after} (id INT);\n");
    try {
        $res = (new \App\Services\MigrationService(bh_db()))->run();
        assert_true(is_string($res['error']) && str_contains($res['error'], '998_p1_broken'), 'error names the file: ' . var_export($res['error'], true));
        assert_false(in_array('998_p1_broken', $res['applied'], true));
        assert_same(0, (int) bh_db()->selectOne("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%p1_after'")['c'], 'stops at the failure');
    } finally {
        @unlink($bad);
        @unlink($after);
        bh_db()->execute("DELETE FROM {migrations} WHERE migration LIKE '99%_p1_%'");
    }
    // The updater passes it on instead of folding it into "ok", and the screen stops the chain.
    $src = (string) file_get_contents(BASEHIM_ROOT . '/app/Services/UpdateService.php');
    assert_contains("'migration_error' => \$migError", $src);
    assert_contains('d.migration_error', (string) file_get_contents(BASEHIM_ROOT . '/admin/views/updates/index.php'));
});

// --- CR-12: scheduler -------------------------------------------------------------

test('scheduler: a task held by another runner is not started twice; a lost run is retried after its lease (CR-12)', function () {
    $db = bh_db();
    $db->execute('DELETE FROM {scheduled_tasks}');
    $sched = p1_scheduler();
    $ran = 0;
    $sched->register('p1-app', 'sync', 60, function () use (&$ran) { $ran++; });
    $due = gmdate('Y-m-d H:i:s', time() - 60);

    // Another runner holds it.
    $db->execute("UPDATE {scheduled_tasks} SET next_run_at = :d, locked_until = :l WHERE app_slug = 'p1-app'",
        ['d' => $due, 'l' => gmdate('Y-m-d H:i:s', time() + 600)]);
    $sched->runDue(true);
    assert_same(0, $ran, 'a leased task does not start');

    // That runner died (lease expired, no end recorded): the task runs again.
    $db->execute("UPDATE {scheduled_tasks} SET locked_until = :l, attempts = 1 WHERE app_slug = 'p1-app'",
        ['l' => gmdate('Y-m-d H:i:s', time() - 1)]);
    $res = $sched->runDue(true);
    assert_same(1, $ran);
    assert_same(1, $res['ran']);
    $row = $db->selectOne("SELECT * FROM {scheduled_tasks} WHERE app_slug = 'p1-app'");
    assert_same(null, $row['locked_until']);
    assert_same(0, (int) $row['attempts'], 'a success resets the attempt count');
    $db->execute('DELETE FROM {scheduled_tasks}');
});

test('scheduler: a failing task backs off exponentially and is listed as failing (CR-12)', function () {
    $db = bh_db();
    $db->execute('DELETE FROM {scheduled_tasks}');
    $sched = p1_scheduler();
    $sched->register('p1-app', 'flaky', 120, function () { throw new \RuntimeException('remote API down'); });
    $waits = [];
    for ($i = 1; $i <= 3; $i++) {
        $db->execute("UPDATE {scheduled_tasks} SET next_run_at = :d WHERE app_slug = 'p1-app'", ['d' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $t = time();
        $sched->runDue(true);
        $row = $db->selectOne("SELECT next_run_at, attempts, last_status FROM {scheduled_tasks} WHERE app_slug = 'p1-app'");
        $waits[] = strtotime($row['next_run_at'] . ' UTC') - $t;
        assert_same($i, (int) $row['attempts']);
        assert_same('error', $row['last_status']);
    }
    foreach ([120, 240, 480] as $n => $expected) {
        assert_true(abs($waits[$n] - $expected) <= 2, "wait {$n}: {$waits[$n]}s, expected about {$expected}s");
    }
    assert_same(86400, \App\Services\SchedulerService::backoff(3600, 30), 'capped at a day');
    $failing = $sched->failingTasks();
    assert_same(['flaky'], array_column($failing, 'task_key'));
    $db->execute('DELETE FROM {scheduled_tasks}');
});

// --- CR-27: idempotency keys ----------------------------------------------------

test('api: an Idempotency-Key makes a create run once; the repeat gets the first answer (CR-27)', function () {
    $tok = p1_login('admin');
    $title = 'P1 idem ' . bin2hex(random_bytes(3));
    $key = bin2hex(random_bytes(16));
    $first = p1_api('POST', '/api/v1/posts', ['title' => $title, 'content' => 'x'], $tok, ['Idempotency-Key' => $key]);
    assert_same(201, $first->status, $first->body);
    $again = p1_api('POST', '/api/v1/posts', ['title' => $title, 'content' => 'x'], $tok, ['Idempotency-Key' => $key]);
    assert_same(201, $again->status, $again->body);
    assert_same('true', $again->header('idempotent-replayed'));
    assert_same($first->json()['data']['id'] ?? null, $again->json()['data']['id'] ?? null);
    assert_same(1, p1_titles($title));

    // The same key for something else is refused, and a bad key is a 400.
    $other = p1_api('POST', '/api/v1/posts', ['title' => $title . ' (2)', 'content' => 'x'], $tok, ['Idempotency-Key' => $key]);
    assert_same(422, $other->status, $other->body);
    assert_same(400, p1_api('POST', '/api/v1/posts', ['title' => 'x'], $tok, ['Idempotency-Key' => 'short'])->status);
    // Keys are per caller: another account's identical key is its own.
    $ed = p1_api('POST', '/api/v1/posts', ['title' => $title, 'content' => 'x'], p1_login('editor'), ['Idempotency-Key' => $key]);
    assert_same(201, $ed->status, $ed->body);
    assert_same(null, $ed->header('idempotent-replayed'));
    // Without a key, nothing changes.
    assert_same(201, p1_api('POST', '/api/v1/posts', ['title' => $title, 'content' => 'x'], $tok)->status);
    assert_same(3, p1_titles($title));
});

test('api: a failed request (422) is replayed, a 5xx is not kept (CR-27)', function () {
    $store = new \App\Services\IdempotencyStore(bh_db());
    $hash = \App\Services\IdempotencyStore::requestHash('POST', '/x', '{}');
    $b = $store->begin('test:p1', 'k-' . bin2hex(random_bytes(8)), $hash);
    assert_same('new', $b['state']);
    $store->complete($b['k'], \App\Core\Response::json(['boom' => true], 500));
    $key = 'k-' . bin2hex(random_bytes(8));
    $b1 = $store->begin('test:p1', $key, $hash);
    assert_same('running', $store->begin('test:p1', $key, $hash)['state'], 'in progress');
    $store->complete($b1['k'], \App\Core\Response::json(['made' => 7], 201)->header('Location', '/x/7'));
    $r = $store->begin('test:p1', $key, $hash);
    assert_same('replay', $r['state']);
    assert_same(201, $r['response']->getStatus());
    assert_same('/x/7', $r['response']->getHeaders()['Location'] ?? null);
    $ran = 0;
    assert_true($store->once('p1-hook', 'evt_1', function () use (&$ran) { $ran++; }));
    assert_false($store->once('p1-hook', 'evt_1', function () use (&$ran) { $ran++; }));
    assert_same(1, $ran, 'a webhook event is handled once');
    assert_throws(\RuntimeException::class, fn() => $store->once('p1-hook', 'evt_2', function () { throw new \RuntimeException('db down'); }));
    assert_true($store->once('p1-hook', 'evt_2', function () {}), 'a failed event can be retried');
});

test('public routes: \'idempotent\' requires a key and replays; App API transactions roll back (CR-27, CR-08)', function () {
    p1_app('p1-shop', <<<'PHP'
        $site->post('/p1-shop/orders', [$this, 'order'], ['csrf' => false, 'idempotent' => true]);
        $site->post('/p1-shop/broken', [$this, 'broken'], ['csrf' => false]);
PHP, <<<'PHP'
    public function order(Request $r): Response
    {
        $n = $this->api()->settings()->updateOwn('orders', fn($n) => (int) $n + 1, 0);
        return Response::json(['order' => $n, 'sku' => $r->input('sku')], 201);
    }
    public function broken(Request $r): Response
    {
        try {
            $this->api()->transaction(function () {
                $this->api()->settings()->updateOwn('half', fn($v) => 'written', '');
                throw new \RuntimeException('payment declined');
            });
        } catch (\RuntimeException $e) {
            return Response::json(['rolled_back' => $this->getSetting('half', 'nothing')], 409);
        }
        return Response::json(['rolled_back' => false]);
    }
PHP);
    try {
        $b = new Browser(bh_env()['base_url']);
        assert_same(400, $b->request('POST', '/p1-shop/orders', json_encode(['sku' => 'A']), P1_JSON)->status, 'a key is required');
        $key = bin2hex(random_bytes(16));
        $one = $b->request('POST', '/p1-shop/orders', json_encode(['sku' => 'A']), P1_JSON + ['Idempotency-Key' => $key]);
        $two = $b->request('POST', '/p1-shop/orders', json_encode(['sku' => 'A']), P1_JSON + ['Idempotency-Key' => $key]);
        assert_same(201, $one->status, $one->body);
        assert_same($one->json(), $two->json());
        assert_same('true', $two->header('idempotent-replayed'));
        $three = $b->request('POST', '/p1-shop/orders', json_encode(['sku' => 'A']), P1_JSON + ['Idempotency-Key' => bin2hex(random_bytes(16))]);
        assert_same(2, $three->json()['order'] ?? null, 'the handler ran twice for two keys, not three times');

        $rb = $b->request('POST', '/p1-shop/broken', '{}', P1_JSON);
        assert_same(409, $rb->status, $rb->body);
        assert_same('nothing', $rb->json()['rolled_back'] ?? null, 'the write inside the transaction was undone');
    } finally { bh_remove_app('p1-shop'); }
});

// --- CR-28: webhook helper ------------------------------------------------------

test('webhooks: HMAC and timestamped signatures are verified, replays outside the window refused (CR-28)', function () {
    $body = '{"id":"evt_1","type":"payment.succeeded"}';
    $secret = 'whsec_test';
    $hex = hash_hmac('sha256', $body, $secret);
    assert_true(\App\Core\Http\Webhook::verifyHmac($body, 'sha256=' . $hex, $secret));
    assert_true(\App\Core\Http\Webhook::verifyHmac($body, base64_encode(hash_hmac('sha256', $body, $secret, true)), $secret));
    assert_false(\App\Core\Http\Webhook::verifyHmac($body . ' ', 'sha256=' . $hex, $secret));
    assert_false(\App\Core\Http\Webhook::verifyHmac($body, 'sha256=' . $hex, ''));
    $t = 1_800_000_000;
    $sig = hash_hmac('sha256', $t . '.' . $body, $secret);
    assert_true(\App\Core\Http\Webhook::verifyTimestamped($body, "t={$t},v1=bad,v1={$sig}", $secret, 300, $t + 60));
    assert_false(\App\Core\Http\Webhook::verifyTimestamped($body, "t={$t},v1={$sig}", $secret, 300, $t + 301), 'too old');
    assert_false(\App\Core\Http\Webhook::verifyTimestamped($body, "v1={$sig}", $secret, 300, $t));
});

// --- CR-09: settings --------------------------------------------------------------

test('settings: a snapshot built before a save is never served after it (CR-09)', function () {
    $db = bh_db();
    $dir = bh_env()['tmp'] . '/p1-settings-cache';
    $a = new \App\Services\SettingService($db, new \App\Core\Cache($dir));
    $a->set('p1', 'price', '10');
    assert_same('10', $a->get('p1', 'price'));
    $cache = new \App\Core\Cache($dir);
    $stale = $cache->get('settings.all');          // what a slow request built before the save
    $a->set('p1', 'price', '12');
    $cache->set('settings.all', $stale, 3600);       // …and wrote back after it
    $b = new \App\Services\SettingService($db, new \App\Core\Cache($dir));
    assert_same('12', $b->get('p1', 'price'));
});

test('settings: parallel first saves and parallel appends all succeed (CR-09)', function () {
    bh_require_innodb();
    $db = bh_db();
    $db->execute("DELETE FROM {settings} WHERE setting_group = 'p1-race'");
    $env = bh_env();
    $dir = $env['tmp'] . '/p1-race-cache';
    $script = $env['tmp'] . '/p1-race-child.php';
    // Separate processes, each with its own connection (a fork would share the parent's).
    file_put_contents($script, '<?php
        define("BASEHIM_ROOT", ' . var_export(BASEHIM_ROOT, true) . ');
        spl_autoload_register(function ($c) { if (str_starts_with($c, "App\\\\")) { $f = BASEHIM_ROOT . "/app/" . str_replace("\\\\", "/", substr($c, 4)) . ".php"; if (is_file($f)) require $f; } });
        [$i, $c, $dir] = [(int) $argv[1], json_decode($argv[2], true), $argv[3]];
        $db = new \App\Core\Database($c);
        $s = new \App\Services\SettingService($db, new \App\Core\Cache($dir));
        $s->set("p1-race", "first", "v" . $i);
        $s->update("p1-race", "list", function ($l) use ($i) { $l = (array) $l; $l[] = $i; return $l; }, []);
    ');
    $d = $env['db'];
    $conf = json_encode(['driver' => 'mysql', 'host' => $d['host'], 'port' => $d['port'], 'database' => $d['name'],
        'username' => $d['user'], 'password' => $d['pass'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => $d['prefix']]);
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = proc_open([PHP_BINARY, $script, (string) $i, $conf, $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $errors = '';
    foreach ($procs as $i => $p) {
        $errors .= stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        if (proc_close($p) !== 0) $errors .= " [child {$i} failed]";
    }
    assert_same('', trim($errors));
    assert_same(1, (int) $db->selectOne("SELECT COUNT(*) c FROM {settings} WHERE setting_group = 'p1-race' AND setting_key = 'first'")['c']);
    $list = json_decode((string) $db->selectOne("SELECT setting_value v FROM {settings} WHERE setting_group = 'p1-race' AND setting_key = 'list'")['v'], true);
    sort($list);
    assert_same([0, 1, 2, 3, 4, 5], $list, 'every append kept');
});
