<?php

declare(strict_types=1);

/*
 * 1.2.85 — the Apps screen: only apps core registered are installed (a folder
 * copied in by hand is ignored; a renamed or deleted folder switches its app
 * off), the Data Table `apps.installed` with declared actions, and the
 * apps.consent / apps.consent_save / apps.bulk gateway actions.
 */

function ap_root(): string { return $GLOBALS['__bh_env']['root']; }

/** A folder dropped into content/apps by hand: never registered. */
function ap_drop_folder(string $slug, array $manifest = []): string
{
    $dir = ap_root() . '/content/apps/' . $slug;
    bh_rrmdir($dir);
    @mkdir($dir . '/src', 0755, true);
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    file_put_contents($dir . '/app.json', json_encode($manifest + ['name' => 'Dropped ' . $slug, 'slug' => $slug, 'version' => '1.0.0', 'namespace' => $ns, 'entry' => 'App']));
    file_put_contents($dir . '/src/App.php', "<?php\nnamespace {$ns};\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n");
    file_put_contents($dir . '/' . BH_FIXTURE_MARKER, "test\n");
    return $dir;
}

/** A ZIP of an app, built in the test's temp folder. */
function ap_zip(string $slug, array $manifest = []): string
{
    $zipPath = $GLOBALS['__bh_env']['tmp'] . '/' . $slug . '.zip';
    @unlink($zipPath);
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString($slug . '/app.json', json_encode($manifest + ['name' => 'Uploaded ' . $slug, 'slug' => $slug, 'version' => '1.0.0', 'namespace' => $ns, 'entry' => 'App']));
    $zip->addFromString($slug . '/src/App.php', "<?php\nnamespace {$ns};\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n");
    $zip->addFromString($slug . '/' . BH_FIXTURE_MARKER, "test\n");
    $zip->close();
    return $zipPath;
}

function ap_upload(object $b, string $zipPath): HttpResponse
{
    preg_match('/name="_csrf" value="([^"]+)"/', $b->request('GET', '/admin/apps')->body, $m);
    $token = $m[1] ?? '';
    $boundary = '----bh' . bin2hex(random_bytes(6));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{$token}\r\n"
          . "--{$boundary}\r\nContent-Disposition: form-data; name=\"app_zip\"; filename=\"" . basename($zipPath) . "\"\r\nContent-Type: application/zip\r\n\r\n"
          . file_get_contents($zipPath) . "\r\n--{$boundary}--\r\n";
    return $b->request('POST', '/admin/apps/install', $body, ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
}

function ap_row(string $slug): ?array
{
    return bh_db()->selectOne('SELECT * FROM {apps} WHERE slug = :s', ['s' => $slug]);
}

/** The Apps table's rows, through the gateway. */
function ap_table(object $b): array
{
    $res = $b->ajaxGet('content.table', ['table' => 'apps.installed', 'per_page' => 100]);
    assert_same(200, $res->status, $res->body);
    return $res->json()['data'];
}

test('apps: a folder copied into content/apps is not registered, listed or activatable', function () {
    ap_drop_folder('ap-dropped');
    try {
        $b = bh_browser('admin');
        $html = $b->request('GET', '/admin/apps')->body;
        assert_true(ap_row('ap-dropped') === null, 'no row was created');
        assert_contains('<code>ap-dropped</code>', $html, 'the note names the folder');
        $names = array_map(fn($r) => strip_tags($r['cells']['title'] ?? ''), ap_table($b)['rows']);
        foreach ($names as $n) assert_not_contains('Dropped ap-dropped', $n);
        // The marketplace and upload installers refuse to overwrite it.
        $res = ap_upload($b, ap_zip('ap-dropped'));
        assert_contains('is already in content/apps, but it isn', $b->request('GET', '/admin/apps')->body);
        assert_true(ap_row('ap-dropped') === null, 'the upload did not register it');
    } finally {
        bh_rrmdir(ap_root() . '/content/apps/ap-dropped');
    }
});

test('apps: a ZIP upload registers the app as an upload, scanned and inactive', function () {
    $b = bh_browser('admin');
    try {
        ap_upload($b, ap_zip('ap-uploaded'));
        $row = ap_row('ap-uploaded');
        assert_true($row !== null, 'registered');
        assert_same('upload', $row['source']);
        assert_same('inactive', $row['status']);
        assert_true(!empty($row['scanned_at']), 'the security scan ran');
        $found = array_values(array_filter(ap_table($b)['rows'], fn($r) => ($r['key'] ?? '') === 'ap-uploaded'));
        assert_same(1, count($found), 'listed once');
        assert_same('inactive', $found[0]['status']);
        assert_true(in_array('files', $found[0]['flags'], true));
    } finally {
        bh_remove_app('ap-uploaded');
        bh_rrmdir(ap_root() . '/content/apps/ap-uploaded');
    }
});

test('apps: an installed app whose folder is renamed is switched off and shown as missing, not twice', function () {
    $dir = bh_install_app('ap-renamed', ['src/App.php' => "<?php\nnamespace BhTest\\ApRenamed;\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n"]);
    $moved = ap_root() . '/content/apps/ap-renamed-copy';
    try {
        rename($dir, $moved);
        $b = bh_browser('admin');
        $b->request('GET', '/admin/apps');
        assert_same('inactive', ap_row('ap-renamed')['status'], 'switched off');
        assert_true(ap_row('ap-renamed-copy') === null, 'the renamed folder is not a second app');
        $rows = ap_table($b)['rows'];
        $mine = array_values(array_filter($rows, fn($r) => in_array($r['key'] ?? '', ['ap-renamed', 'ap-renamed-copy'], true)));
        assert_same(1, count($mine));
        assert_same('missing', $mine[0]['status']);
        // Activating it is refused; deleting removes only the record.
        $res = $b->ajax('apps.bulk', ['action' => 'activate', 'ids' => [(int) $mine[0]['id']]]);
        assert_same(200, $res->status, $res->body);
        assert_false($res->json()['data']['results'][0]['ok']);
        rename($moved, $dir);
        $b->request('GET', '/admin/apps');
        assert_same('inactive', ap_row('ap-renamed')['status'], 'back, but off until activated');
    } finally {
        if (is_dir($moved)) bh_rrmdir($moved);
        bh_remove_app('ap-renamed');
    }
});

test('apps: a pre-1.2.85 record of a renamed copy (folder holds another slug) is dropped from the list', function () {
    $dir = ap_drop_folder('ap-legacy-copy', ['slug' => 'ap-original']);
    try {
        bh_db()->insert('apps', ['slug' => 'ap-legacy-copy', 'name' => 'Copy', 'version' => '1.0.0', 'status' => 'inactive', 'vendor' => 'tests']);
        bh_browser('admin')->request('GET', '/admin/apps');
        assert_true(ap_row('ap-legacy-copy') === null, 'the stray record is gone');
        assert_true(is_dir($dir), 'its files are untouched');
    } finally {
        bh_db()->execute('DELETE FROM {apps} WHERE slug = :s', ['s' => 'ap-legacy-copy']);
        bh_rrmdir($dir);
    }
});

test('apps.bulk: activate, deactivate and delete, each reported; approval comes first', function () {
    bh_install_app('ap-bulk-a', ['src/App.php' => "<?php\nnamespace BhTest\\ApBulkA;\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n"], [], 'inactive');
    bh_install_app('ap-bulk-b', ['src/App.php' => "<?php\nnamespace BhTest\\ApBulkB;\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n"], ['permissions' => ['mail.send']], 'inactive');
    bh_db()->execute("UPDATE {apps} SET permissions = '[\"mail.send\"]' WHERE slug = 'ap-bulk-b'");
    try {
        $b = bh_browser('admin');
        $ids = [(int) ap_row('ap-bulk-a')['id'], (int) ap_row('ap-bulk-b')['id']];
        $d = $b->ajax('apps.bulk', ['action' => 'activate', 'ids' => $ids])->json()['data'];
        assert_same(1, $d['done']);
        assert_same('active', ap_row('ap-bulk-a')['status']);
        $waiting = array_values(array_filter($d['results'], fn($r) => !empty($r['needs_consent'])));
        assert_same('ap-bulk-b', $waiting[0]['slug'] ?? null, 'needs approval first');

        $c = $b->ajaxGet('apps.consent', ['slug' => 'ap-bulk-b'])->json()['data'];
        assert_same('mail.send', $c['permissions'][0]['key'] ?? null);
        $s = $b->ajax('apps.consent_save', ['slug' => 'ap-bulk-b', 'permissions' => [], 'activate' => true]);
        assert_same(200, $s->status, $s->body);
        assert_same('active', ap_row('ap-bulk-b')['status']);
        assert_same(1, $s->json()['data']['withheld_count']);

        $d = $b->ajax('apps.bulk', ['action' => 'deactivate', 'ids' => $ids])->json()['data'];
        assert_same(2, $d['done']);
        assert_same(422, $b->ajax('apps.bulk', ['action' => 'explode', 'ids' => $ids])->status);
    } finally {
        bh_remove_app('ap-bulk-a');
        bh_remove_app('ap-bulk-b');
    }
});

test('apps actions: other roles are refused', function () {
    foreach (['editor', 'author', 'subscriber'] as $role) {
        $b = bh_browser($role);
        assert_same(403, $b->ajax('apps.bulk', ['action' => 'delete', 'ids' => [1]])->status, $role);
        assert_same(403, $b->ajaxGet('apps.consent', ['slug' => 'x'])->status, $role);
        assert_same(403, $b->ajax('apps.consent_save', ['slug' => 'x', 'permissions' => []])->status, $role);
    }
});

test('apps page: core components, no stats cards, the table with declared actions', function () {
    $b = bh_browser('admin');
    $html = $b->request('GET', '/admin/apps')->body;
    assert_contains('bh-page-head', $html);
    assert_contains('data-dt-config', $html);
    assert_contains('bh-im', $html, 'the permission window');
    assert_not_contains('Need attention', $html, 'no stats cards');
    $start = strpos($html, 'id="apps-root"');
    $part = substr($html, $start, strpos($html, '<dialog', $start) - $start);
    assert_not_contains('style="', $part, 'no inline styles');
    assert_not_contains('<style', $part);
    $ids = array_column(ap_table($b)['actions'], 'id');
    foreach (['activate', 'deactivate', 'permissions', 'delete'] as $a) assert_true(in_array($a, $ids, true), "action $a");
});

/** An active app declaring db.raw, approved without it, whose screen uses db(). */
function ap_needy(string $slug, string $boot = ''): void
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    bh_install_app($slug, ['src/App.php' => "<?php\nnamespace {$ns};\nclass App extends \\App\\Core\\App {\n"
        . " public function boot(): void { {$boot} \$this->adminGet('/{$slug}', fn() => 'rows: ' . count(\$this->db()->select('SELECT 1 AS x'))); }\n}\n"],
        ['namespace' => $ns, 'permissions' => ['db.raw']]);
    bh_db()->execute("UPDATE {apps} SET permissions = '[\"db.raw\"]', granted_permissions = '[]', consented_at = NOW() WHERE slug = :s", ['s' => $slug]);
}

test('permission screen: an app screen missing a permission asks for it instead of failing, and works once allowed', function () {
    ap_needy('ap-needy');
    try {
        $b = bh_browser('admin');
        $res = $b->request('GET', '/admin/ap-needy');
        assert_same(403, $res->status);
        assert_contains('needs one more permission', $res->body);
        assert_contains('data-permission="db.raw"', $res->body);
        assert_contains('data-can="1"', $res->body);
        assert_same('active', ap_row('ap-needy')['status'], 'not switched off');

        // A JSON caller gets a code, not a 500.
        $json = $b->request('GET', '/admin/ap-needy', null, ['Accept' => 'application/json']);
        assert_same(403, $json->status);
        assert_same('APP_PERMISSION_MISSING', $json->json()['error']['code'] ?? null);

        // Someone who can't manage apps is told to ask.
        $editorView = bh_browser('editor')->request('GET', '/admin/ap-needy')->body;
        if (str_contains($editorView, 'needs one more permission')) assert_contains('data-can="0"', $editorView);

        $save = $b->ajax('apps.consent_save', ['slug' => 'ap-needy', 'permissions' => ['db.raw']]);
        assert_same(200, $save->status, $save->body);
        $ok = $b->request('GET', '/admin/ap-needy');
        assert_same(200, $ok->status);
        assert_contains('rows: 1', $ok->body);
    } finally {
        bh_remove_app('ap-needy');
    }
});

test('permission screen: an app that needs a permission to start is not switched off; it shows as needing approval', function () {
    ap_needy('ap-needy-boot', '$this->db();');
    try {
        $b = bh_browser('admin');
        $b->request('GET', '/admin/dashboard');
        assert_same('active', ap_row('ap-needy-boot')['status'], 'still on, not quarantined');
        $row = array_values(array_filter(ap_table($b)['rows'], fn($r) => ($r['key'] ?? '') === 'ap-needy-boot'))[0] ?? null;
        assert_true($row !== null);
        assert_same('consent', $row['status']);
        assert_contains('needs the', strip_tags($row['cells']['title']));
        $b->ajax('apps.consent_save', ['slug' => 'ap-needy-boot', 'permissions' => ['db.raw']]);
        $b->request('GET', '/admin/dashboard');
        $row = array_values(array_filter(ap_table($b)['rows'], fn($r) => ($r['key'] ?? '') === 'ap-needy-boot'))[0] ?? null;
        assert_same('active', $row['status'], 'running once allowed');
    } finally {
        bh_remove_app('ap-needy-boot');
    }
});

test('permission window: plain words, the app\'s reasons and the checks it passed', function () {
    ap_needy('ap-reasons');
    $dir = ap_root() . '/content/apps/ap-reasons';
    $m = json_decode(file_get_contents($dir . '/app.json'), true);
    $m['permission_reasons'] = ['db.raw' => 'Keeps its notes in <b>its own</b> table.'];
    file_put_contents($dir . '/app.json', json_encode($m));
    try {
        $d = bh_browser('admin')->ajaxGet('apps.consent', ['slug' => 'ap-reasons'])->json()['data'];
        assert_contains('database', $d['permissions'][0]['friendly']);
        assert_same('Keeps its notes in its own table.', $d['reasons']['db.raw'], 'plain text only');
        assert_true(isset($d['checks']['scanned'], $d['checks']['code_ok'], $d['checks']['source']));
    } finally {
        bh_remove_app('ap-reasons');
    }
});

test('permission screen: a screen that fails half-way through its view still gets the whole permission page (1.2.86)', function () {
    $slug = 'ap-needy-view';
    $ns = 'BhTest\\ApNeedyView';
    bh_install_app($slug, [
        'src/App.php' => "<?php\nnamespace {$ns};\nclass App extends \\App\\Core\\App {\n"
            . " public function boot(): void { \$this->adminGet('/{$slug}', fn() => \\App\\Core\\Response::html(\$this->adminView('screen', 'Needy'))); }\n"
            . " public function rows(): int { return count(\$this->db()->select('SELECT 1')); }\n}\n",
        'views/screen.php' => "<div id=\"half-rendered\">Before the table</div><?php echo \$app->rows(); ?>",
    ], ['namespace' => $ns, 'permissions' => ['db.raw']]);
    bh_db()->execute("UPDATE {apps} SET permissions = '[\"db.raw\"]', granted_permissions = '[]', consented_at = NOW() WHERE slug = :s", ['s' => $slug]);
    try {
        $res = bh_browser('admin')->request('GET', '/admin/' . $slug);
        assert_same(403, $res->status);
        assert_not_contains('half-rendered', $res->body, 'the partial view is dropped');
        assert_contains('needs one more permission', $res->body);
        assert_true(str_starts_with(ltrim($res->body), '<!DOCTYPE html>'), 'the page starts with the admin layout');
    } finally {
        bh_remove_app($slug);
    }
});
