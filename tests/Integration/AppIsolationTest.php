<?php

declare(strict_types=1);

/*
 * App fault isolation (1.2.71), through the real front controller.
 *
 * Each test installs a throw-away app in content/apps (removed afterwards),
 * marks it active, and requests pages from the test server: a broken app must
 * cost only itself. Statuses are asserted, not just "no exception".
 */

const BH_ISO_FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

/** An app whose entry class has $body; $use is prepended inside the namespace. */
function bh_iso_app(string $slug, string $body, array $extraFiles = [], array $manifest = [], string $use = '', string $status = 'active'): string
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $php = "<?php\ndeclare(strict_types=1);\nnamespace {$ns};\n{$use}\nclass App extends \\App\\Core\\App\n{\n{$body}\n}\n";
    return bh_install_app($slug, ['src/App.php' => $php] + $extraFiles, $manifest + ['namespace' => $ns], $status);
}

function bh_iso_get(string $path, ?Browser $b = null): HttpResponse
{
    return ($b ?? bh_browser())->request('GET', $path);
}

function bh_iso_post(Browser $b, string $path, array $data = []): HttpResponse
{
    return $b->request('POST', $path, http_build_query($data + ['_csrf' => $b->csrf]), BH_ISO_FORM);
}

/** A healthy app with a public page, an admin page and a menu item. */
function bh_iso_healthy(string $slug): void
{
    bh_iso_app($slug, <<<PHP
    public function boot(): void
    {
        \$this->get('/{$slug}', fn() => 'healthy-ok:{$slug}');
        \$this->adminGet('/{$slug}', fn() => 'admin-ok:{$slug}');
        \$this->addAdminMenu(['url' => '/admin/{$slug}', 'label' => 'Iso {$slug}']);
    }
PHP);
}

test('isolation: a healthy app loads and answers', function () {
    bh_iso_healthy('bhiso-healthy');
    try {
        $r = bh_iso_get('/bhiso-healthy');
        assert_same(200, $r->status);
        assert_contains('healthy-ok:bhiso-healthy', $r->body);
        assert_same('active', bh_app_status('bhiso-healthy'));
        assert_same(null, bh_app_health('bhiso-healthy')['failure'] ?? null);
    } finally { bh_remove_app('bhiso-healthy'); }
});

test('isolation: boot() throws after registering a route — the page still renders, the app is off and its route gone', function () {
    bh_iso_healthy('bhiso-ok2');
    bh_iso_app('bhiso-throws', <<<'PHP'
    public function boot(): void
    {
        $this->get('/bhiso-throws', fn() => 'should-not-answer');
        $this->addAdminMenu(['url' => '/admin/bhiso-throws', 'label' => 'Throws']);
        throw new \RuntimeException('library missing; password=hunter2');
    }
PHP);
    try {
        $home = bh_iso_get('/');
        assert_same(200, $home->status, 'the same request still renders');
        $rec = bh_app_health('bhiso-throws');
        assert_same('quarantined', $rec['state'] ?? null);
        assert_same('boot', $rec['failure']['stage'] ?? null);
        assert_same('exception', $rec['failure']['category'] ?? null);
        assert_not_contains('hunter2', $rec['failure']['message'] ?? '', 'secrets redacted');
        assert_same('error', bh_app_status('bhiso-throws'), 'status is error in the database');
        assert_true(!empty($rec['deactivated']));

        $r = bh_iso_get('/bhiso-throws');
        assert_not_contains('should-not-answer', $r->body, 'the route was rolled back');
        assert_same(503, $r->status, "the app's public URL says unavailable");
        assert_not_contains('library missing', $r->body, 'no details for visitors');

        // A healthy app booting in the same requests is unaffected.
        assert_same(200, bh_iso_get('/bhiso-ok2')->status);
        $admin = bh_browser('admin');
        $menu = $admin->request('GET', '/admin/dashboard');
        assert_same(200, $menu->status);
        assert_contains('Iso bhiso-ok2', $menu->body);
        assert_not_contains('>Throws<', $menu->body, 'its menu item was rolled back');
    } finally { bh_remove_app('bhiso-throws'); bh_remove_app('bhiso-ok2'); }
});

test('isolation: a TypeError during initialization is recorded as one', function () {
    bh_iso_app('bhiso-typeerror', "    public function boot(): void { \$n = strlen([]); }");
    try {
        assert_same(200, bh_iso_get('/')->status);
        $rec = bh_app_health('bhiso-typeerror');
        assert_same('type-error', $rec['failure']['category'] ?? null);
        assert_same('TypeError', $rec['failure']['error_type'] ?? null);
    } finally { bh_remove_app('bhiso-typeerror'); }
});

test('isolation: a syntax error is caught before the app is loaded', function () {
    bh_install_app('bhiso-syntax', ['src/App.php' => "<?php\nnamespace BhTest\\BhisoSyntax;\nclass App extends \\App\\Core\\App {\n public function boot(): void { if ( }\n}\n"], ['namespace' => 'BhTest\\BhisoSyntax']);
    try {
        assert_same(200, bh_iso_get('/')->status, 'first request after it was activated');
        $rec = bh_app_health('bhiso-syntax');
        assert_same('validation', $rec['failure']['stage'] ?? null);
        assert_same('syntax', $rec['failure']['category'] ?? null);
        assert_same('error', bh_app_status('bhiso-syntax'));
    } finally { bh_remove_app('bhiso-syntax'); }
});

test('isolation: the KiCad case — schema() narrower than core — never takes the site down', function () {
    bh_iso_app('bhiso-kicad', <<<'PHP'
    public function boot(): void { $this->adminGet('/bhiso-kicad', fn() => 'kicad'); }
    private function schema(): array { return []; }
PHP);
    try {
        foreach (['/', '/admin/login', '/mcp', '/api/v1/posts'] as $path) {
            $r = bh_iso_get($path);
            assert_true($r->status < 500, "{$path} answered {$r->status}");
        }
        $rec = bh_app_health('bhiso-kicad');
        assert_same('quarantined', $rec['state'] ?? null);
        assert_same('validation', $rec['failure']['stage'] ?? null);
        assert_contains('schema()', $rec['failure']['message'] ?? '');
        $codes = array_column($rec['validation']['errors'] ?? [], 'code');
        assert_true(in_array('visibility', $codes, true), export($codes));
    } finally { bh_remove_app('bhiso-kicad'); }
});

test('isolation: an incompatible signature is caught before loading', function () {
    bh_iso_app('bhiso-signature', <<<'PHP'
    public function boot(): void {}
    protected function table(string $name): array { return []; }
PHP);
    try {
        assert_same(200, bh_iso_get('/')->status);
        $rec = bh_app_health('bhiso-signature');
        assert_contains('must be compatible', $rec['failure']['message'] ?? '');
    } finally { bh_remove_app('bhiso-signature'); }
});

test('isolation: a missing dependency (parent class, or a class used in boot) is isolated', function () {
    bh_install_app('bhiso-noparent', ['src/App.php' => "<?php\nnamespace BhTest\\BhisoNoparent;\nclass App extends \\Vendor\\Gone\\Base { public function boot(): void {} }\n"], ['namespace' => 'BhTest\\BhisoNoparent']);
    bh_iso_app('bhiso-noclass', "    public function boot(): void { new \\Vendor\\Gone\\Thing(); }");
    try {
        assert_same(200, bh_iso_get('/')->status);
        assert_same('dependency', bh_app_health('bhiso-noparent')['failure']['category'] ?? null);
        assert_same('autoload', bh_app_health('bhiso-noparent')['failure']['stage'] ?? null);
        assert_same('dependency', bh_app_health('bhiso-noclass')['failure']['category'] ?? null);
        assert_same('boot', bh_app_health('bhiso-noclass')['failure']['stage'] ?? null);
    } finally { bh_remove_app('bhiso-noparent'); bh_remove_app('bhiso-noclass'); }
});

test('isolation: a constructor that throws is recorded at the construct stage', function () {
    bh_iso_app('bhiso-ctor', <<<'PHP'
    public function __construct(\App\Core\Application $app, string $slug = '', array $manifest = [], string $path = '')
    {
        throw new \LogicException('cannot construct');
    }
    public function boot(): void {}
PHP);
    try {
        assert_same(200, bh_iso_get('/')->status);
        $rec = bh_app_health('bhiso-ctor');
        assert_same('construct', $rec['failure']['stage'] ?? null);
        assert_contains('cannot construct', $rec['failure']['message'] ?? '');
    } finally { bh_remove_app('bhiso-ctor'); }
});

test('isolation: a failing app is recorded once, however many requests follow', function () {
    $before = substr_count(bh_app_log('bhiso-repeat'), 'was switched off');
    bh_iso_app('bhiso-repeat', "    public function boot(): void { throw new \\RuntimeException('always fails'); }");
    try {
        for ($i = 0; $i < 4; $i++) assert_same(200, bh_iso_get('/')->status);
        $rec = bh_app_health('bhiso-repeat');
        assert_same(1, (int) ($rec['failures'] ?? 0));
        assert_same([], $rec['history'] ?? []);
        assert_same(1, substr_count(bh_app_log('bhiso-repeat'), 'was switched off') - $before, 'logged once');
    } finally { bh_remove_app('bhiso-repeat'); }
});

test('isolation: an engine fatal that slips past validation costs one request (503, retry), then the site runs without the app', function () {
    bh_iso_healthy('bhiso-latefatal');
    $file = bh_env()['root'] . '/content/apps/bhiso-latefatal/src/App.php';
    try {
        assert_same(200, bh_iso_get('/bhiso-latefatal')->status, 'validated and cached');
        // Edited within the minute its validation is trusted for.
        file_put_contents($file, str_replace("    public function boot(): void", "    private function schema(): array { return []; }\n    public function boot(): void", (string) file_get_contents($file)));
        $r = bh_iso_get('/');
        assert_same(503, $r->status);
        assert_same('1', $r->header('retry-after'));
        assert_same('1', $r->header('refresh'), 'reloads by itself');
        assert_not_contains('schema', $r->body, 'no details for visitors');
        $next = bh_iso_get('/');
        assert_same(200, $next->status, 'the next request runs without it');
        $rec = bh_app_health('bhiso-latefatal');
        assert_same('declaration', $rec['failure']['category'] ?? null);
        assert_same('E_COMPILE_ERROR', $rec['failure']['error_type'] ?? null);
        assert_same('error', bh_app_status('bhiso-latefatal'));
    } finally { bh_remove_app('bhiso-latefatal'); }
});

test('isolation: the report is for administrators only', function () {
    bh_iso_app('bhiso-report', "    public function boot(): void { throw new \\RuntimeException('report me'); }");
    try {
        bh_iso_get('/');
        $admin = bh_browser('admin');
        $r = $admin->request('GET', '/admin/apps/bhiso-report/health');
        assert_same(200, $r->status);
        assert_contains('Disabled — initialization error', $r->body);
        assert_contains('report me', $r->body);
        assert_contains('Starting (boot)', $r->body);

        assert_same(403, bh_browser('editor')->request('GET', '/admin/apps/bhiso-report/health')->status);
        assert_same(403, bh_browser('subscriber')->request('GET', '/admin/apps/bhiso-report/health')->status);
        $anon = bh_browser()->request('GET', '/admin/apps/bhiso-report/health');
        assert_same(302, $anon->status);
        assert_contains('/admin/login', (string) $anon->header('location'));
        assert_not_contains('report me', $anon->body);

        // State changes need the CSRF token and manage_apps.
        $editor = bh_browser('editor');
        assert_same(403, bh_iso_post($editor, '/admin/apps/bhiso-report/validate', ['activate' => '1'])->status);
        $admin->request('POST', '/admin/apps/bhiso-report/validate', http_build_query(['activate' => '1', '_csrf' => 'wrong']), BH_ISO_FORM);
        assert_same('error', bh_app_status('bhiso-report'), 'a bad token changes nothing');
    } finally { bh_remove_app('bhiso-report'); }
});

test('isolation: a broken app cannot be reactivated; once fixed it can, and its history is kept', function () {
    bh_iso_app('bhiso-fixme', <<<'PHP'
    public function boot(): void { $this->get('/bhiso-fixme', fn() => 'fixed-ok'); }
    private function schema(): array { return []; }
PHP);
    $file = bh_env()['root'] . '/content/apps/bhiso-fixme/src/App.php';
    try {
        bh_iso_get('/');
        $admin = bh_browser('admin');
        // Both the Apps screen's Activate and the report's reactivate refuse.
        bh_iso_post($admin, '/admin/apps/bhiso-fixme/activate');
        assert_same('error', bh_app_status('bhiso-fixme'));
        bh_iso_post($admin, '/admin/apps/bhiso-fixme/validate', ['activate' => '1']);
        assert_same('error', bh_app_status('bhiso-fixme'));
        assert_same('quarantined', bh_app_health('bhiso-fixme')['state'] ?? null);

        file_put_contents($file, str_replace('private function schema(): array', 'private function tables(): array', (string) file_get_contents($file)));
        clearstatcache();
        $r = bh_iso_post($admin, '/admin/apps/bhiso-fixme/validate', ['activate' => '1']);
        assert_same(302, $r->status);
        assert_same('active', bh_app_status('bhiso-fixme'));
        $page = bh_iso_get('/bhiso-fixme');
        assert_same(200, $page->status);
        assert_contains('fixed-ok', $page->body);
        $rec = bh_app_health('bhiso-fixme');
        assert_same('ok', $rec['state'] ?? null);
        assert_same('recovered', $rec['recovery'] ?? null);
        assert_same(1, count($rec['history'] ?? []), 'the earlier failure is kept');
    } finally { bh_remove_app('bhiso-fixme'); }
});

test('isolation: a retried app that fails again goes straight back to quarantine', function () {
    bh_iso_app('bhiso-again', "    public function boot(): void { throw new \\RuntimeException('first'); }");
    $file = bh_env()['root'] . '/content/apps/bhiso-again/src/App.php';
    try {
        bh_iso_get('/');
        file_put_contents($file, str_replace("'first'", "'second'", (string) file_get_contents($file)));
        clearstatcache();
        bh_iso_post(bh_browser('admin'), '/admin/apps/bhiso-again/validate', ['activate' => '1']);
        assert_same('active', bh_app_status('bhiso-again'), 'it validates, so it is turned on');
        assert_same(200, bh_iso_get('/')->status);
        $rec = bh_app_health('bhiso-again');
        assert_same('quarantined', $rec['state'] ?? null);
        assert_same('retry-failed', $rec['recovery'] ?? null);
        assert_contains('second', $rec['failure']['message'] ?? '');
        assert_contains('first', $rec['history'][0]['message'] ?? '');
    } finally { bh_remove_app('bhiso-again'); }
});

test('isolation: admin, apps screen, theme and an app screen behave while an app is off', function () {
    bh_iso_app('bhiso-off', <<<'PHP'
    public function boot(): void { $this->adminGet('/bhiso-off', fn() => 'x'); $this->get('/bhiso-off-public', fn() => 'x'); }
    private function schema(): array { return []; }
PHP);
    try {
        $home = bh_iso_get('/');
        assert_same(200, $home->status);
        assert_contains('<html', strtolower($home->body), 'the theme renders');
        $admin = bh_browser('admin');
        foreach (['/admin/dashboard', '/admin/posts', '/admin/apps', '/admin/settings/general'] as $p) {
            assert_same(200, $admin->request('GET', $p)->status, $p);
        }
        $dash = $admin->request('GET', '/admin/dashboard');
        assert_contains('bhiso-off — view report', $dash->body, 'the admin is told');
        assert_not_contains('bhiso-off — view report', bh_browser('editor')->request('GET', '/admin/dashboard')->body, 'only those who manage apps');
        $apps = $admin->request('GET', '/admin/apps');
        assert_contains('Switched off</span>', $apps->body, 'its row shows it switched off');

        // The app's own admin screen leads to its report; its public page is 503.
        $screen = $admin->request('GET', '/admin/bhiso-off');
        assert_same(302, $screen->status);
        assert_contains('/admin/apps/bhiso-off/health', (string) $screen->header('location'));
        assert_same(503, bh_iso_get('/bhiso-off-public')->status);
    } finally { bh_remove_app('bhiso-off'); }
});

test('isolation: activating, deactivating and reactivating a healthy app still work', function () {
    bh_iso_healthy('bhiso-cycle');
    bh_db()->execute("UPDATE {apps} SET status = 'inactive' WHERE slug = 'bhiso-cycle'");
    try {
        $admin = bh_browser('admin');
        assert_not_contains('healthy-ok:bhiso-cycle', bh_iso_get('/bhiso-cycle')->body, 'inactive: not served');
        bh_iso_post($admin, '/admin/apps/bhiso-cycle/activate');
        assert_same('active', bh_app_status('bhiso-cycle'));
        assert_same(200, bh_iso_get('/bhiso-cycle')->status);
        bh_iso_post($admin, '/admin/apps/bhiso-cycle/deactivate');
        assert_same('inactive', bh_app_status('bhiso-cycle'));
        bh_iso_post($admin, '/admin/apps/bhiso-cycle/activate');
        assert_same('active', bh_app_status('bhiso-cycle'));
        $rec = bh_app_health('bhiso-cycle');
        assert_true(!empty($rec['validation']['ok']), 'activation validated it');
    } finally { bh_remove_app('bhiso-cycle'); }
});

test('isolation: the database being unreachable during boot is not blamed on the app', function () {
    // An app whose boot() hits a PDOException is skipped for the request, not switched off.
    bh_iso_app('bhiso-infra', "    public function boot(): void { throw new \\PDOException('SQLSTATE[HY000] [2002] Connection refused'); }");
    try {
        assert_same(200, bh_iso_get('/')->status);
        assert_same('active', bh_app_status('bhiso-infra'));
        assert_true((bh_app_health('bhiso-infra')['state'] ?? 'ok') !== 'quarantined');
    } finally { bh_remove_app('bhiso-infra'); }
});
