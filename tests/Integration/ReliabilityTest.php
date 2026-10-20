<?php

declare(strict_types=1);

/*
 * 1.2.73 — fixes from the core reliability audit
 * (docs/audits/core-reliability-scalability-audit.md): BH-022 REST user
 * management, BH-023 scheduler starvation, BH-024 truthful app lifecycle and
 * schema_version, BH-025 cache tags, BH-026 atomic post + terms, BH-027 image
 * pixel limit, and the app-widget default capability (BH-001/BH-013).
 */

const REL_FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

function rel_login(string $who, string $pw = BH_TEST_PASSWORD): string
{
    $res = (new Browser(bh_env()['base_url']))->request('POST', '/api/v1/auth/login',
        json_encode(['login' => $who, 'password' => $pw]), ['Content-Type' => 'application/json']);
    $j = $res->json() ?? [];
    return (string) ($j['access_token'] ?? $j['data']['access_token'] ?? '');
}

function rel_api(string $method, string $path, ?array $body, string $bearer): HttpResponse
{
    return (new Browser(bh_env()['base_url']))->request($method, $path, $body === null ? null : json_encode($body),
        ['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json', 'Content-Type' => 'application/json']);
}

function rel_user(string $username, string $role): int
{
    $db = bh_db();
    $db->execute('DELETE FROM {users} WHERE username = :u', ['u' => $username]);
    return (int) $db->insert('users', ['uuid' => bh_uuid(), 'username' => $username, 'email' => "{$username}@example.test",
        'password_hash' => password_hash(BH_TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        'display_name' => $username, 'role' => $role, 'status' => 'active']);
}

function rel_role(int $id): string
{
    return (string) (bh_db()->selectOne('SELECT role FROM {users} WHERE id = :i', ['i' => $id])['role'] ?? '');
}

/** An app whose entry class has $body. */
function rel_app(string $slug, string $body, string $version = '1.0.0', string $status = 'active'): string
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $php = "<?php\ndeclare(strict_types=1);\nnamespace {$ns};\nclass App extends \\App\\Core\\App\n{\n{$body}\n}\n";
    return bh_install_app($slug, ['src/App.php' => $php], ['namespace' => $ns, 'version' => $version], $status);
}

function rel_rewrite(string $dir, string $slug, string $body, ?string $version = null): void
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    file_put_contents($dir . '/src/App.php', "<?php\ndeclare(strict_types=1);\nnamespace {$ns};\nclass App extends \\App\\Core\\App\n{\n{$body}\n}\n");
    if ($version !== null) {
        $m = json_decode((string) file_get_contents($dir . '/app.json'), true);
        $m['version'] = $version;
        file_put_contents($dir . '/app.json', json_encode($m));
    }
    touch($dir . '/src/App.php', time() + 2);   // a new mtime, so preflight re-validates
    clearstatcache();
}

function rel_app_row(string $slug): array
{
    return bh_db()->selectOne('SELECT version, schema_version, status FROM {apps} WHERE slug = :s', ['s' => $slug]) ?? [];
}

function rel_activate(Browser $b, string $slug): array
{
    $r = $b->request('POST', "/admin/apps/{$slug}/activate", http_build_query(['_csrf' => $b->csrf]),
        REL_FORM + ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    return $r->json() ?? ['raw' => $r->body, 'status' => $r->status];
}

// --- BH-022: REST user management -----------------------------------------

test('users api: changing your own email or password needs current_password (BH-022)', function () {
    $id = rel_user('rel_self', 'subscriber');
    $tok = rel_login('rel_self');
    $r = rel_api('PUT', "/api/v1/users/{$id}", ['email' => 'rel-taken@example.test', 'password' => 'Attacker-pass-1'], $tok);
    assert_same(403, $r->status, $r->body);
    assert_same('rel_self@example.test', bh_db()->selectOne('SELECT email FROM {users} WHERE id = :i', ['i' => $id])['email']);
    assert_same('', rel_login('rel_self', 'Attacker-pass-1'));

    $ok = rel_api('PUT', "/api/v1/users/{$id}", ['password' => 'New-pass-123', 'current_password' => BH_TEST_PASSWORD], $tok);
    assert_same(200, $ok->status, $ok->body);
    assert_true(rel_login('rel_self', 'New-pass-123') !== '');
    // Harmless fields need no password.
    assert_same(200, rel_api('PUT', "/api/v1/users/{$id}", ['display_name' => 'Renamed'], rel_login('rel_self', 'New-pass-123'))->status);
});

test('users api: no self role change, no role above your own, no unknown role (BH-022)', function () {
    $adminId = bh_env()['users']['admin'];
    $tok = rel_login('admin');
    $self = rel_api('PUT', "/api/v1/users/{$adminId}", ['role' => 'super_admin'], $tok);
    assert_same(403, $self->status, $self->body);
    assert_same('admin', rel_role($adminId));

    $sa = rel_api('POST', '/api/v1/users', ['username' => 'rel_sa', 'email' => 'rel_sa@example.test', 'password' => 'Long-enough-1', 'role' => 'super_admin'], $tok);
    assert_same(403, $sa->status, $sa->body);
    $ghost = rel_api('POST', '/api/v1/users', ['username' => 'rel_ghost', 'email' => 'rel_ghost@example.test', 'password' => 'Long-enough-1', 'role' => 'no-such-role'], $tok);
    assert_same(422, $ghost->status, $ghost->body);
    assert_same(null, bh_db()->selectOne("SELECT id FROM {users} WHERE username IN ('rel_sa','rel_ghost')"));

    // Within the rules it still works, and is logged.
    $made = rel_api('POST', '/api/v1/users', ['username' => 'rel_ed', 'email' => 'rel_ed@example.test', 'password' => 'Long-enough-1', 'role' => 'editor'], $tok);
    assert_same(201, $made->status, $made->body);
    $edId = (int) ($made->json()['data']['id'] ?? 0);
    $promote = rel_api('PUT', "/api/v1/users/{$edId}", ['role' => 'author'], $tok);
    assert_same(200, $promote->status, $promote->body);
    assert_same('author', rel_role($edId));
    $log = bh_db()->selectOne("SELECT detail FROM {user_activity_log} WHERE user_id = :i AND event = 'user.role_changed' ORDER BY id DESC LIMIT 1", ['i' => $edId]);
    assert_contains('editor → author', (string) ($log['detail'] ?? ''));
});

test('users api: an admin cannot edit or delete another admin; editors cannot manage users (BH-022)', function () {
    $other = rel_user('rel_admin2', 'admin');
    $tok = rel_login('admin');
    assert_same(403, rel_api('PUT', "/api/v1/users/{$other}", ['password' => 'Takeover-pass-1'], $tok)->status);
    assert_same(403, rel_api('DELETE', "/api/v1/users/{$other}", null, $tok)->status);
    $ed = rel_login('editor');
    assert_same(403, rel_api('GET', '/api/v1/users', null, $ed)->status);
    assert_same(403, rel_api('PUT', '/api/v1/users/' . bh_env()['users']['author'], ['display_name' => 'x'], $ed)->status);
    bh_db()->execute('DELETE FROM {users} WHERE id = :i', ['i' => $other]);
});

// --- BH-023: scheduler ---------------------------------------------------------

test('scheduler: due tasks of an app that is not running no longer block the others (BH-023)', function () {
    $db = bh_db();
    $db->execute('DELETE FROM {scheduled_tasks}');
    $past = gmdate('Y-m-d H:i:s', time() - 7200);
    for ($i = 1; $i <= 7; $i++) {
        $db->insert('scheduled_tasks', ['app_slug' => 'ghost-app', 'task_key' => "t{$i}", 'interval_seconds' => 3600, 'next_run_at' => $past, 'created_at' => $past]);
    }
    $settings = new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/rel-cache-s'));
    $sched = new \App\Services\SchedulerService($db, $settings, new \App\Core\Logger(bh_env()['tmp'] . '/rel-logs'));
    $ran = 0;
    $sched->register('live-app', 'orders.reconcile', 60, function () use (&$ran) { $ran++; });
    $db->execute("UPDATE {scheduled_tasks} SET next_run_at = :p WHERE app_slug = 'live-app'", ['p' => gmdate('Y-m-d H:i:s', time() - 60)]);
    $res = $sched->runDue(true);
    assert_same(1, $ran);
    assert_same(0, $res['skipped']);
    // The orphaned rows are kept, untouched, for when the app comes back…
    assert_same(7, (int) $db->selectOne("SELECT COUNT(*) c FROM {scheduled_tasks} WHERE app_slug = 'ghost-app' AND next_run_at = :p", ['p' => $past])['c']);
    // …and removed with the app.
    assert_same(7, $sched->forgetApp('ghost-app'));
    $db->execute('DELETE FROM {scheduled_tasks}');
});

// --- BH-024: app lifecycle -----------------------------------------------------

test('apps: a throwing onActivate() refuses activation, says why, and works once fixed (BH-024)', function () {
    $slug = 'rel-activate';
    $dir = rel_app($slug, " public function boot(): void {}\n public function onActivate(): void { throw new \\RuntimeException('CREATE TABLE shop_orders failed'); }", '1.0.0', 'inactive');
    $b = bh_browser('admin');
    $r = rel_activate($b, $slug);
    assert_same(false, $r['ok'] ?? null, json_encode($r));
    assert_contains('CREATE TABLE shop_orders failed', (string) ($r['error'] ?? ''));
    assert_true(in_array(bh_app_status($slug), ['inactive', 'error'], true), 'status ' . bh_app_status($slug));
    assert_same('lifecycle:onActivate', bh_app_health($slug)['failure']['stage'] ?? null);

    rel_rewrite($dir, $slug, " public function boot(): void {}\n public function onActivate(): void {}");
    $r2 = rel_activate($b, $slug);
    assert_same(true, $r2['ok'] ?? null, json_encode($r2));
    assert_same('active', bh_app_status($slug));
    bh_remove_app($slug);
});

test('apps: a failed onUpgrade() keeps the old schema_version, switches the app off, and is retried (BH-024)', function () {
    $slug = 'rel-upgrade';
    $marker = bh_env()['tmp'] . '/rel-upgrade-calls';
    @unlink($marker);
    $dir = rel_app($slug, " public function boot(): void {}", '1.0.0', 'active');
    bh_db()->execute('UPDATE {apps} SET schema_version = :v WHERE slug = :s', ['v' => '1.0.0', 's' => $slug]);
    $b = bh_browser('admin');

    rel_rewrite($dir, $slug, " public function boot(): void {}\n public function onUpgrade(string \$from, string \$to): void { file_put_contents('{$marker}', \"fail \$from>\$to\\n\", FILE_APPEND); throw new \\RuntimeException('ALTER TABLE failed'); }", '1.1.0');
    $b->request('GET', '/admin/apps');
    $row = rel_app_row($slug);
    assert_same('1.1.0', $row['version']);
    assert_same('1.0.0', $row['schema_version']);
    assert_same('error', $row['status']);
    assert_same('lifecycle:onUpgrade', bh_app_health($slug)['failure']['stage'] ?? null);

    // Switched off, it is not retried on every page load…
    $b->request('GET', '/admin/apps');
    assert_same(1, substr_count((string) @file_get_contents($marker), 'fail'));

    // …but when activated after a fix, the upgrade runs again, then the app starts.
    rel_rewrite($dir, $slug, " public function boot(): void {}\n public function onUpgrade(string \$from, string \$to): void { file_put_contents('{$marker}', \"ok \$from>\$to\\n\", FILE_APPEND); }");
    $r = rel_activate($b, $slug);
    assert_same(true, $r['ok'] ?? null, json_encode($r));
    $row = rel_app_row($slug);
    assert_same('1.1.0', $row['schema_version']);
    assert_same('active', $row['status']);
    assert_contains('ok 1.0.0>1.1.0', (string) file_get_contents($marker));
    bh_remove_app($slug);
});

test('apps: a successful upgrade moves schema_version; an inactive app is upgraded too (BH-024)', function () {
    $slug = 'rel-upgrade-ok';
    $dir = rel_app($slug, " public function boot(): void {}", '2.0.0', 'inactive');
    bh_db()->execute('UPDATE {apps} SET schema_version = :v WHERE slug = :s', ['v' => '2.0.0', 's' => $slug]);
    rel_rewrite($dir, $slug, " public function boot(): void {}\n public function onUpgrade(string \$from, string \$to): void {}", '2.1.0');
    bh_browser('admin')->request('GET', '/admin/apps');
    $row = rel_app_row($slug);
    assert_same('2.1.0', $row['schema_version']);
    assert_same('inactive', $row['status']);
    bh_remove_app($slug);
});

test('apps: a failed onInstall() is run again on activation (BH-024)', function () {
    $slug = 'rel-install';
    $flag = bh_env()['tmp'] . '/rel-install-ok';
    @unlink($flag);
    $dir = rel_app($slug, " public function boot(): void {}\n public function onInstall(): void { if (!is_file('{$flag}')) throw new \\RuntimeException('seed failed'); }", '1.0.0', 'inactive');
    bh_db()->execute("UPDATE {apps} SET schema_version = '' WHERE slug = :s", ['s' => $slug]);   // install pending, as sync() records it
    $b = bh_browser('admin');
    $r = rel_activate($b, $slug);
    assert_same(false, $r['ok'] ?? null, json_encode($r));
    assert_contains('onInstall', (string) ($r['error'] ?? ''));
    assert_same('', rel_app_row($slug)['schema_version']);
    touch($flag);
    $r2 = rel_activate($b, $slug);
    assert_same(true, $r2['ok'] ?? null, json_encode($r2));
    assert_same('1.0.0', rel_app_row($slug)['schema_version']);
    bh_remove_app($slug);
});

// --- BH-025: cache --------------------------------------------------------------

test('cache: flushTag() removes the tag\'s keys and only those (BH-025)', function () {
    $c = new \App\Core\Cache(bh_env()['tmp'] . '/rel-cache');
    $c->set('app.shop.price', 10);
    $c->set('app.shop.stock', 3);
    $c->set('app.blog.views', 7);
    $c->set('settings.all', ['x' => 1]);
    $c->set('null.value', null);
    assert_true($c->has('null.value'), 'a cached null is a hit');
    assert_true($c->flushTag('app.shop'));
    assert_same(null, $c->get('app.shop.price'));
    assert_same(null, $c->get('app.shop.stock'));
    assert_same(7, $c->get('app.blog.views'));
    assert_same(['x' => 1], $c->get('settings.all'));
    $c->flush();
    assert_same(null, $c->get('app.blog.views'));
});

test('cache: the updater\'s cache clear removes cached entries (BH-025)', function () {
    $cache = new \App\Core\Cache(BASEHIM_ROOT . '/storage/cache');
    $cache->set('rel.probe', 'stale', 3600);
    $svc = (new ReflectionClass(\App\Services\UpdateService::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod($svc, 'clearCaches');
    $m->setAccessible(true);
    $m->invoke($svc);
    assert_same(null, $cache->get('rel.probe'));
});

// --- BH-026: posts and terms -------------------------------------------------

test('database: a nested transaction joins the outer one and rolls back with it (BH-026)', function () {
    $db = bh_db();
    $key = 'rel_tx_' . bin2hex(random_bytes(3));
    $e = assert_throws(\RuntimeException::class, function () use ($db, $key) {
        $db->transaction(function () use ($db, $key) {
            $db->transaction(fn() => $db->insert('settings', ['setting_group' => 'rel', 'setting_key' => $key, 'setting_value' => '1']));
            throw new \RuntimeException('outer failed');
        });
    });
    assert_same('outer failed', $e->getMessage());
    assert_same(null, $db->selectOne('SELECT id FROM {settings} WHERE setting_key = :k', ['k' => $key]));
    assert_false($db->inTransaction());
});

test('posts api: an unknown term id is a 422 and nothing is saved (BH-026)', function () {
    $tok = rel_login('admin');
    $count = fn() => count(array_filter(bh_db()->select('SELECT title FROM {posts}'), fn($r) => $r['title'] === 'Rel bad terms'));
    $before = $count();
    $r = rel_api('POST', '/api/v1/posts', ['title' => 'Rel bad terms', 'content' => 'x', 'term_ids' => [987654321]], $tok);
    assert_same(422, $r->status, $r->body);
    assert_contains('987654321', $r->body);
    assert_same($before, $count());
});

test('posts api: parallel term updates of one post all succeed and counts stay true (BH-026)', function () {
    $tok = rel_login('admin');
    $db = bh_db();
    $tax = (int) $db->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag'")['id'];
    $ids = [];
    foreach (['rel-a', 'rel-b', 'rel-c'] as $s) {
        $db->execute('DELETE FROM {terms} WHERE taxonomy_id = :t AND slug = :s', ['t' => $tax, 's' => $s]);
        $ids[] = (int) $db->insert('terms', ['taxonomy_id' => $tax, 'name' => $s, 'slug' => $s, 'count' => 0]);
    }
    $c = rel_api('POST', '/api/v1/posts', ['title' => 'Rel race terms', 'content' => 'x'], $tok);
    $pid = (int) ($c->json()['data']['id'] ?? 0);
    assert_true($pid > 0, $c->body);

    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < 8; $i++) {
        $ch = curl_init(bh_env()['base_url'] . "/api/v1/posts/{$pid}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['term_ids' => $i % 2 ? [$ids[0], $ids[1]] : [$ids[1], $ids[2]]])]);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05); } while ($running > 0);
    $codes = [];
    $bodies = [];
    foreach ($hs as $ch) { $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $bodies[] = substr((string) curl_multi_getcontent($ch), 0, 300); curl_multi_remove_handle($mh, $ch); }
    curl_multi_close($mh);
    // Overlapping saves conflict (a deadlock on InnoDB, a serialization
    // failure on Dolt); Database::transaction() runs the loser again.
    assert_same([], array_values(array_filter($codes, fn($s) => $s >= 500)), 'statuses ' . json_encode($codes) . ' ' . json_encode($bodies));
    foreach ($ids as $t) {
        $real = (int) $db->selectOne('SELECT COUNT(*) c FROM {post_term} WHERE term_id = :t', ['t' => $t])['c'];
        $stored = (int) $db->selectOne('SELECT count FROM {terms} WHERE id = :t', ['t' => $t])['count'];
        assert_same($real, $stored, "term {$t}");
    }
});

// --- BH-027: image pixel limit --------------------------------------------

test('media: an image above the pixel limit is refused before it is stored (BH-027)', function () {
    $w = 9000; $h = 9000;    // 81 megapixels, about 120 KB as a blank PNG
    $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $idat = '';
    $row = str_repeat("\0", $w + 1);
    for ($y = 0; $y < $h; $y++) $idat .= deflate_add($ctx, $row, ZLIB_NO_FLUSH);
    $idat .= deflate_add($ctx, '', ZLIB_FINISH);
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $file = bh_env()['tmp'] . '/rel-big.png';
    file_put_contents($file, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', $idat) . $chunk('IEND', ''));
    $rows = (int) bh_db()->selectOne('SELECT COUNT(*) c FROM {media}')['c'];
    $t = microtime(true);
    $ch = curl_init(bh_env()['base_url'] . '/api/v1/media');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . rel_login('author'), 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($file, 'image/png', 'big.png')]]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    assert_same(422, $status, $body);
    assert_contains('megapixels', $body);
    assert_true(microtime(true) - $t < 5, 'refused without decoding');
    assert_same($rows, (int) bh_db()->selectOne('SELECT COUNT(*) c FROM {media}')['c']);
    assert_false(\App\Services\ImageProcessor::safeToDecode(\App\Services\ImageProcessor::HARD_MAX_PIXELS + 1));
});

// --- BH-001 / BH-013: app widgets default to the app's own access -----------

test('widgets: an app widget without a capability is for people who can use the app (BH-001/BH-013)', function () {
    $reg = new \App\Core\WidgetRegistry();
    assert_true($reg->register('relwidget.card', ['title' => 'Card', 'source' => 'app:relwidget', 'render' => fn() => 'x']));
    assert_same(['access_app:relwidget'], $reg->get('relwidget.card')['capability']);
    assert_true($reg->register('relwidget.open', ['title' => 'Open', 'source' => 'app:relwidget', 'capability' => 'read', 'render' => fn() => 'x']));
    assert_same(['read'], $reg->get('relwidget.open')['capability']);
    $sub = ['id' => 999, 'role' => 'subscriber', 'meta' => null];
    $admin = ['id' => 998, 'role' => 'admin', 'meta' => null];
    assert_false(\App\Core\WidgetRegistry::userCan($reg->get('relwidget.card'), $sub));
    assert_true(\App\Core\WidgetRegistry::userCan($reg->get('relwidget.card'), $admin));
    assert_true(\App\Core\WidgetRegistry::userCan($reg->get('relwidget.open'), $sub));
});

test('database: a deadlock or serialization failure runs the transaction again; other errors do not (BH-026)', function () {
    $db = bh_db();
    $calls = 0;
    $result = $db->transaction(function () use (&$calls) {
        if (++$calls === 1) {
            $e = new \PDOException('serialization failure', 0);
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
            throw $e;
        }
        return 'done';
    });
    assert_same('done', $result);
    assert_same(2, $calls);

    $calls = 0;
    assert_throws(\RuntimeException::class, function () use ($db, &$calls) {
        $db->transaction(function () use (&$calls) { $calls++; throw new \RuntimeException('not retryable'); });
    });
    assert_same(1, $calls);
});

test('apps: new files before migration 015 — the schema_version column is added on first use (BH-024)', function () {
    $db = bh_db();
    $slug = 'rel-premigration';
    rel_app($slug, ' public function boot(): void {}', '3.1.0', 'inactive');
    $db->execute('ALTER TABLE {apps} DROP COLUMN schema_version');
    bh_browser('admin')->request('GET', '/admin/apps');
    $col = $db->selectOne("SHOW COLUMNS FROM {apps} LIKE 'schema_version'");
    assert_true($col !== null, 'column re-created');
    assert_same('3.1.0', rel_app_row($slug)['schema_version']);
    bh_remove_app($slug);
});
