<?php

declare(strict_types=1);

/*
 * Public site routes for apps (1.2.72), through the real front controller,
 * plus the router's precedence rules in-process.
 */

const BH_PR_FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];
const BH_PR_JSON = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

/** An app whose boot() body is $boot and whose class has $methods. */
function bh_pr_app(string $slug, string $boot, string $methods = '', array $files = []): void
{
    $ns = 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    $php = "<?php\ndeclare(strict_types=1);\nnamespace {$ns};\nuse App\\Core\\Request;\nuse App\\Core\\Response;\nuse App\\Core\\Http\\SitePage;\n"
        . "class App extends \\App\\Core\\App\n{\n    public function boot(): void\n    {\n        \$site = \$this->router()->site();\n{$boot}\n    }\n{$methods}\n}\n";
    bh_install_app($slug, ['src/App.php' => $php] + $files, ['namespace' => $ns]);
}

function bh_pr_req(string $method, string $path, ?string $body = null, array $headers = [], ?Browser $b = null): HttpResponse
{
    return ($b ?? bh_browser())->request($method, $path, $body, $headers);
}

test('public routes: an app serves GET, POST, PUT, PATCH and DELETE, with parameters', function () {
    bh_pr_app('bhpr-crud', <<<'PHP'
        $site->get('/bhpr-items', fn(Request $r) => Response::json(['list' => true]));
        $site->get('/bhpr-items/{id}', fn(Request $r, string $id) => Response::json(['id' => $id]), ['where' => ['id' => '\d+']]);
        $site->get('/bhpr-items/{id}/files/{path:*}', fn(Request $r, string $id, string $path) => Response::json(['id' => $id, 'path' => $path]));
        $site->get('/bhpr-tags/{tag}', fn(Request $r, string $tag) => Response::json(['tag' => $tag]));
        $site->post('/bhpr-items', fn(Request $r) => Response::json(['made' => $r->input('name')], 201), ['csrf' => true]);
        $site->put('/bhpr-items/{id}', fn(Request $r, string $id) => Response::json(['put' => $id, 'name' => $r->input('name')]));
        $site->patch('/bhpr-items/{id}', fn(Request $r, string $id) => Response::json(['patch' => $id]));
        $site->delete('/bhpr-items/{id}', fn(Request $r, string $id) => Response::json(['deleted' => $id]));
PHP);
    try {
        assert_same(['list' => true], bh_pr_req('GET', '/bhpr-items')->json());
        assert_same(['id' => '42'], bh_pr_req('GET', '/bhpr-items/42')->json());
        assert_same(404, bh_pr_req('GET', '/bhpr-items/abc')->status, "'where' narrows the parameter");
        assert_same(['id' => '7', 'path' => 'a/b.txt'], bh_pr_req('GET', '/bhpr-items/7/files/a/b.txt')->json());
        assert_same(['tag' => 'café au lait'], bh_pr_req('GET', '/bhpr-tags/caf%C3%A9%20au%20lait')->json(), 'parameters are decoded');
        assert_same(400, bh_pr_req('GET', '/bhpr-tags/a%2Fb')->status, 'an encoded slash in a parameter is refused');
        assert_same(400, bh_pr_req('GET', '/bhpr-tags/a%00b')->status, 'a NUL byte is refused');

        $made = bh_pr_req('POST', '/bhpr-items', json_encode(['name' => 'x']), BH_PR_JSON);
        assert_same(201, $made->status);
        assert_same(['made' => 'x'], $made->json());
        assert_same(['put' => '5', 'name' => 'y'], bh_pr_req('PUT', '/bhpr-items/5', json_encode(['name' => 'y']), BH_PR_JSON)->json());
        assert_same(['patch' => '5'], bh_pr_req('PATCH', '/bhpr-items/5', '{}', BH_PR_JSON)->json());
        assert_same(['deleted' => '5'], bh_pr_req('DELETE', '/bhpr-items/5', null, BH_PR_JSON)->json());

        $head = bh_pr_req('HEAD', '/bhpr-items');
        assert_same(200, $head->status, 'GET routes answer HEAD');
        assert_same('nosniff', $head->header('x-content-type-options'));
    } finally { bh_remove_app('bhpr-crud'); }
});

test('public routes: 405 with Allow when the path exists, 404 when it does not, 400 for a malformed body or path', function () {
    bh_pr_app('bhpr-405', <<<'PHP'
        $site->get('/bhpr-only-get', fn() => 'got');
        $site->post('/bhpr-json', fn(Request $r) => Response::json(['ok' => true]));
PHP);
    try {
        $r = bh_pr_req('DELETE', '/bhpr-only-get');
        assert_same(405, $r->status);
        assert_same('GET, HEAD', $r->header('allow'));
        assert_same(404, bh_pr_req('GET', '/bhpr-nothing-here/at-all')->status);
        assert_same(404, bh_pr_req('POST', '/bhpr-nothing-here', 'a=1', BH_PR_FORM)->status, 'not a 405: no route has that path');
        $bad = bh_pr_req('POST', '/bhpr-json', '{not json', BH_PR_JSON);
        assert_same(400, $bad->status);
        assert_same(400, bh_pr_req('GET', '/bhpr-only-get/%2e%2e/x')->status, 'a dot-dot segment');
    } finally { bh_remove_app('bhpr-405'); }
});

test('public routes: HTML, JSON, redirect and plain text answers', function () {
    bh_pr_app('bhpr-kinds', <<<'PHP'
        $site->get('/bhpr-kinds/html', fn() => '<p>hello <b>html</b></p>');
        $site->get('/bhpr-kinds/json', fn() => ['a' => 1]);
        $site->get('/bhpr-kinds/go', fn() => Response::redirect('/bhpr-kinds/html', 302));
        $site->get('/bhpr-kinds/text', fn() => Response::make('plain', 200, ['Content-Type' => 'text/plain; charset=utf-8']));
        $site->get('/bhpr-kinds/teapot', fn() => Response::json(['error' => 'no'], 418));
PHP);
    try {
        $h = bh_pr_req('GET', '/bhpr-kinds/html');
        assert_contains('text/html', (string) $h->header('content-type'));
        assert_contains('<b>html</b>', $h->body);
        $j = bh_pr_req('GET', '/bhpr-kinds/json');
        assert_contains('application/json', (string) $j->header('content-type'));
        assert_same(['a' => 1], $j->json());
        $g = bh_pr_req('GET', '/bhpr-kinds/go');
        assert_same(302, $g->status);
        assert_contains('/bhpr-kinds/html', (string) $g->header('location'));
        $t = bh_pr_req('GET', '/bhpr-kinds/text');
        assert_contains('text/plain', (string) $t->header('content-type'));
        assert_same('plain', $t->body);
        assert_same(418, bh_pr_req('GET', '/bhpr-kinds/teapot')->status);
    } finally { bh_remove_app('bhpr-kinds'); }
});

test('public routes: SitePage renders in the active theme, a theme can override the template, a missing one fails safely', function () {
    bh_pr_app('bhpr-theme', <<<'PHP'
        $site->get('/bhpr-themed', [$this, 'page']);
        $site->get('/bhpr-themed-missing', fn() => SitePage::view($this, 'nope', []));
        $site->get('/bhpr-themed-404', fn() => SitePage::notFound('No such thing'));
        $site->get('/bhpr-bare', fn() => SitePage::view($this, 'card', ['who' => '<script>x</script>'], ['layout' => false]));
PHP, <<<'PHP'
    public function page(Request $r): Response
    {
        return SitePage::view($this, 'card', ['who' => '<script>alert(1)</script>'], ['title' => 'Themed card', 'description' => 'A card']);
    }
PHP, ['views/card.php' => '<div class="bhpr-card">Hello <?= $e($who) ?></div>']);
    $theme = bh_env()['root'] . '/content/themes/' . bh_db()->selectOne("SELECT setting_value AS v FROM {settings} WHERE setting_group='appearance' AND setting_key='theme'")['v'] ?? '';
    try {
        $r = bh_pr_req('GET', '/bhpr-themed');
        assert_same(200, $r->status);
        assert_contains('bhpr-card', $r->body);
        assert_contains('<title>Themed card', $r->body, 'title from the app');
        assert_contains('&lt;script&gt;', $r->body, 'escaped by the template');
        assert_not_contains('<script>alert(1)', $r->body);
        assert_true(substr_count(strtolower($r->body), '<html') === 1, 'one document: the theme layout around the app view');
        assert_true(stripos($r->body, '</footer>') !== false || stripos($r->body, 'footer') !== false, 'theme footer present');

        $miss = bh_pr_req('GET', '/bhpr-themed-missing');
        assert_same(500, $miss->status);
        assert_not_contains('views/nope', $miss->body, 'no internals to visitors');
        assert_contains('nope', bh_app_log('bhpr-theme'), 'logged for the developer');

        assert_same(404, bh_pr_req('GET', '/bhpr-themed-404')->status);
        $bare = bh_pr_req('GET', '/bhpr-bare');
        assert_same('<div class="bhpr-card">Hello &lt;script&gt;x&lt;/script&gt;</div>', trim($bare->body), "layout => false: the template alone");

        // A theme override of the app's template.
        $active = (string) (bh_db()->selectOne("SELECT setting_value AS v FROM {settings} WHERE setting_key = 'active_theme'")['v'] ?? 'default');
        $dir = bh_env()['root'] . '/content/themes/' . trim($active, '"') . '/templates/apps/bhpr-theme';
        if (!is_dir(bh_env()['root'] . '/content/themes/' . trim($active, '"'))) $dir = bh_env()['root'] . '/content/themes/default/templates/apps/bhpr-theme';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/card.php', '<div class="theme-override">Theme says <?= $e($who) ?></div>');
        try {
            assert_contains('theme-override', bh_pr_req('GET', '/bhpr-themed')->body);
        } finally {
            @unlink($dir . '/card.php'); @rmdir($dir); @rmdir(dirname($dir));
        }
    } finally { bh_remove_app('bhpr-theme'); }
});

test('public routes: core, admin and content routes keep working beside app routes', function () {
    $pageId = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'type' => 'page', 'title' => 'About us', 'slug' => 'bhpr-about', 'content' => '<p>About page body</p>',
        'status' => 'published', 'author_id' => bh_env()['users']['admin'], 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    bh_pr_app('bhpr-coexist', <<<'PHP'
        $site->get('/bhpr-shop', fn() => 'shop-page');
        $site->get('/bhpr-shop/{slug}', fn(Request $r, string $slug) => 'shop-item:' . $slug);
        $this->adminGet('/bhpr-coexist', fn() => 'app-admin-screen');
PHP);
    try {
        assert_contains('About page body', bh_pr_req('GET', '/bhpr-about')->body, 'a page by its slug (catch-all) still resolves');
        assert_same('shop-page', bh_pr_req('GET', '/bhpr-shop')->body);
        assert_same('shop-item:mug', bh_pr_req('GET', '/bhpr-shop/mug')->body);
        assert_same(200, bh_pr_req('GET', '/')->status);
        assert_same(200, bh_pr_req('GET', '/search?q=about')->status);
        assert_same(200, bh_pr_req('GET', '/feed')->status);
        assert_same(200, bh_pr_req('GET', '/sitemap.xml')->status);

        $anon = bh_pr_req('GET', '/admin/bhpr-coexist');
        assert_same(302, $anon->status, 'app admin route still needs sign-in');
        $admin = bh_browser('admin');
        assert_same('app-admin-screen', $admin->request('GET', '/admin/bhpr-coexist')->body);
        assert_same(200, $admin->request('GET', '/admin/dashboard')->status);
        assert_same(403, bh_browser('subscriber')->request('GET', '/admin/bhpr-coexist')->status, 'and the app access check');
    } finally {
        bh_remove_app('bhpr-coexist');
        bh_db()->execute('DELETE FROM {posts} WHERE id = :id', ['id' => $pageId]);
    }
});

test('public routes: reserved paths, core routes, catch-alls and other apps are protected', function () {
    bh_pr_app('bhpr-aaa', <<<'PHP'
        $site->get('/bhpr-shared', fn() => 'first-app');
        $site->get('/bhpr-shared/{a}', fn() => 'first-app-param');
PHP);
    bh_pr_app('bhpr-zzz', <<<'PHP'
        $site->get('/search', fn() => 'hijacked-search');
        $site->get('/admin/bhpr-sneaky', fn() => 'sneaky-admin');
        $site->get('/{anything}', fn() => 'catch-all');
        $site->get('/api/v1/bhpr', fn() => 'core-api-space');
        $site->get('/bhpr-shared', fn() => 'second-app');
        $site->get('/bhpr-shared/{b}', fn() => 'second-app-param');
        $site->post('/ajax', fn() => 'gateway');
        $this->get('/feed', fn() => 'legacy-feed');
        $this->get('/admin/bhpr-legacy', fn() => 'legacy-admin');
        $site->get('/bhpr-mine', fn() => 'mine');
PHP);
    try {
        $search = bh_pr_req('GET', '/search?q=x');
        assert_not_contains('hijacked-search', $search->body, 'core search wins');
        assert_not_contains('sneaky-admin', bh_pr_req('GET', '/admin/bhpr-sneaky')->body, 'no public route under /admin');
        assert_not_contains('legacy-admin', bh_pr_req('GET', '/admin/bhpr-legacy')->body, 'not with the old helper either');
        assert_not_contains('catch-all', bh_pr_req('GET', '/some-page-that-does-not-exist')->body);
        assert_not_contains('core-api-space', bh_pr_req('GET', '/api/v1/bhpr')->body);
        assert_not_contains('legacy-feed', bh_pr_req('GET', '/feed')->body, 'core feed wins over the old helper');
        assert_same('first-app', bh_pr_req('GET', '/bhpr-shared')->body, 'the first app keeps its route');
        assert_same('first-app-param', bh_pr_req('GET', '/bhpr-shared/x')->body);
        assert_same('mine', bh_pr_req('GET', '/bhpr-mine')->body, "a refused route does not stop the app's others");

        $log = bh_app_log('bhpr-zzz');
        foreach (['/search', '/admin/bhpr-sneaky', '/{anything}', '/api/v1/bhpr', '/bhpr-shared', '/ajax', '/feed', '/admin/bhpr-legacy'] as $p) {
            assert_contains($p, $log, "refusal of {$p} is logged");
        }
        assert_contains('core-conflict', $log);
        assert_contains('bhpr-aaa', $log, 'the duplicate names the app that has it');

        // The report shows the app's routes and the refused ones.
        $report = bh_browser('admin')->request('GET', '/admin/apps/bhpr-zzz/health');
        assert_contains('/bhpr-mine', $report->body);
        assert_contains('Core already serves GET /search', $report->body);
    } finally { bh_remove_app('bhpr-aaa'); bh_remove_app('bhpr-zzz'); }
});

test('public routes: replace takes over the home page, only when asked', function () {
    bh_pr_app('bhpr-home', <<<'PHP'
        $site->get('/', fn() => 'app-home', ['replace' => true]);
PHP);
    try {
        assert_same('app-home', bh_pr_req('GET', '/')->body);
    } finally { bh_remove_app('bhpr-home'); }
    assert_not_contains('app-home', bh_pr_req('GET', '/')->body, 'gone with the app');
});

test('public routes: auth, capabilities and CSRF', function () {
    bh_pr_app('bhpr-sec', <<<'PHP'
        $site->get('/bhpr-account', fn() => 'account-of:' . \App\Core\Application::getInstance()->make('auth.user')['username'], ['auth' => true]);
        $site->get('/bhpr-staff', fn() => 'staff-only', ['can' => 'manage_options']);
        $site->post('/bhpr-form', fn(Request $r) => Response::json(['ok' => true]));
        $site->post('/bhpr-hook', fn(Request $r) => Response::json(['hook' => true]), ['csrf' => false]);
        $site->post('/bhpr-bad', fn() => 'x', ['csrf' => false, 'auth' => true]);
        $this->post('/bhpr-legacy-beacon', fn() => Response::json(['beacon' => true]));
PHP);
    try {
        // auth
        $anon = bh_pr_req('GET', '/bhpr-account');
        assert_same(302, $anon->status);
        assert_contains('/admin/login', (string) $anon->header('location'));
        assert_same(401, bh_pr_req('GET', '/bhpr-account', null, ['Accept' => 'application/json'])->status);
        assert_same('account-of:author', bh_browser('author')->request('GET', '/bhpr-account')->body);
        // can
        assert_same(403, bh_browser('editor')->request('GET', '/bhpr-staff')->status);
        assert_same('staff-only', bh_browser('admin')->request('GET', '/bhpr-staff')->body);

        // CSRF: a browser session must send its token; a cookie-less client need not.
        $b = bh_browser('author');
        assert_same(403, $b->request('POST', '/bhpr-form', '{}', BH_PR_JSON)->status, 'session cookie, no token');
        assert_same(200, $b->request('POST', '/bhpr-form', '{}', BH_PR_JSON + ['X-CSRF-Token' => $b->csrf])->status, 'header token');
        assert_same(200, $b->request('POST', '/bhpr-form', http_build_query(['_csrf' => $b->csrf]), BH_PR_FORM)->status, 'form token');
        assert_same(200, bh_pr_req('POST', '/bhpr-form', '{}', BH_PR_JSON)->status, 'no session: stateless');
        assert_same(200, $b->request('POST', '/bhpr-hook', '{}', BH_PR_JSON)->status, "csrf => false for a webhook");
        assert_same(404, bh_pr_req('POST', '/bhpr-bad', '{}', BH_PR_JSON)->status, 'csrf off with auth is refused at registration');
        assert_contains("cannot be combined", bh_app_log('bhpr-sec'));
        // Routes from the old helpers keep their old behaviour (an analytics beacon has no token).
        assert_same(200, $b->request('POST', '/bhpr-legacy-beacon', '{}', BH_PR_JSON)->status);
    } finally { bh_remove_app('bhpr-sec'); }
});

test('public routes: rate limits answer 429', function () {
    bh_require_innodb();
    bh_pr_app('bhpr-rate', "        \$site->post('/bhpr-limited', fn() => ['ok' => 1], ['rate' => [2, 60]]);");
    try {
        $codes = [];
        for ($i = 0; $i < 3; $i++) $codes[] = bh_pr_req('POST', '/bhpr-limited', '{}', BH_PR_JSON)->status;
        assert_same([200, 200, 429], $codes);
    } finally { bh_remove_app('bhpr-rate'); }
});

test('public routes: a disabled app\'s routes are gone; a broken app\'s routes never answer', function () {
    bh_pr_app('bhpr-off', "        \$site->get('/bhpr-off', fn() => 'on');");
    bh_pr_app('bhpr-broken', "        \$site->get('/bhpr-broken', fn() => 'should-not');\n        throw new \\RuntimeException('boom');");
    try {
        assert_same('on', bh_pr_req('GET', '/bhpr-off')->body);
        bh_db()->execute("UPDATE {apps} SET status = 'inactive' WHERE slug = 'bhpr-off'");
        assert_true(bh_pr_req('GET', '/bhpr-off')->body !== 'on', 'the inactive app no longer answers');
        assert_same(404, bh_pr_req('GET', '/bhpr-off/nothing')->status);

        $home = bh_pr_req('GET', '/');
        assert_same(200, $home->status, 'the site stays up');
        assert_not_contains('should-not', bh_pr_req('GET', '/bhpr-broken')->body);
        assert_same('error', bh_app_status('bhpr-broken'));
    } finally { bh_remove_app('bhpr-off'); bh_remove_app('bhpr-broken'); }
});

test('public routes: a handler that throws gives visitors a safe 500 and the app a log line', function () {
    bh_pr_app('bhpr-throw', <<<'PHP'
        $site->get('/bhpr-throw', function () { throw new \RuntimeException('secret detail in ' . BASEHIM_ROOT); });
        $site->get('/bhpr-badctl', ['BhTest\\Nope\\Controller', 'index']);
PHP);
    try {
        $r = bh_pr_req('GET', '/bhpr-throw');
        assert_same(500, $r->status);
        assert_not_contains('secret detail', $r->body);
        assert_not_contains(BASEHIM_ROOT, $r->body);
        $j = bh_pr_req('GET', '/bhpr-throw', null, ['Accept' => 'application/json']);
        assert_same(500, $j->status);
        assert_not_contains('secret detail', $j->body);
        assert_same(500, bh_pr_req('GET', '/bhpr-badctl')->status, 'a missing controller fails safely');
        $log = bh_app_log('bhpr-throw');
        assert_contains('Route GET /bhpr-throw failed: RuntimeException', $log);
        assert_not_contains(BASEHIM_ROOT . '/', $log, 'paths relative in the log');
        assert_same('active', bh_app_status('bhpr-throw'), 'a failing page does not switch the app off');
    } finally { bh_remove_app('bhpr-throw'); }
});

test('public routes: registering twice is one route; named routes give URLs; bad definitions are refused', function () {
    bh_pr_app('bhpr-twice', <<<'PHP'
        for ($i = 0; $i < 2; $i++) {
            $site->get('/bhpr-twice', fn() => 'once');
        }
        $site->get('/bhpr-named/{slug}', fn() => 'n', ['name' => 'bhpr.named']);
        $site->get('/bhpr-url', fn() => (string) $this->router()->url('bhpr.named', ['slug' => 'a b']));
        $site->get('/bhpr-typo', fn() => 'x', ['midleware' => []]);
        $site->get('/bhpr-badwhere/{id}', fn() => 'x', ['where' => ['id' => '(?<x>\d+)']]);
        $site->get('/bhpr bad', fn() => 'x');
        $site->get('/bhpr-dup/{a}/{a}', fn() => 'x');
        $site->get('/bhpr-badmethod', [$this, 'nope']);
        $site->match(['OPTIONS'], '/bhpr-options', fn() => 'x');
PHP);
    try {
        assert_same('once', bh_pr_req('GET', '/bhpr-twice')->body);
        assert_not_contains('duplicate', bh_app_log('bhpr-twice'), 'a repeat is not a conflict');
        assert_same('/bhpr-named/a%20b', bh_pr_req('GET', '/bhpr-url')->body);
        $log = bh_app_log('bhpr-twice');
        foreach (["Unknown option 'midleware'", 'not allowed', 'contains characters', 'appears twice', 'does not exist or is not public', 'OPTIONS is not supported'] as $needle) {
            assert_contains($needle, $log);
        }
    } finally { bh_remove_app('bhpr-twice'); }
});

test('public routes: the example app works as documented', function () {
    $src = bh_env()['root'] . '/docs/examples/public-routes';
    $files = [];
    foreach (['src/App.php', 'views/index.php', 'views/item.php'] as $f) $files[$f] = (string) file_get_contents($src . '/' . $f);
    $manifest = json_decode((string) file_get_contents($src . '/app.json'), true);
    // Installed under its own slug, so a copy of the example on the site is untouched.
    $manifest['slug'] = 'bhpr-example';
    bh_install_app('bhpr-example', $files, $manifest);
    try {
        $page = bh_pr_req('GET', '/example');
        assert_same(200, $page->status);
        assert_contains('Example shop', $page->body);
        assert_contains('href="/example/blue-mug"', $page->body);
        assert_same(200, bh_pr_req('GET', '/example/blue-mug')->status);
        assert_same(404, bh_pr_req('GET', '/example/no-such-item')->status);
        assert_same(3, count(bh_pr_req('GET', '/api/example')->json()['items'] ?? []));
        assert_same(422, bh_pr_req('POST', '/api/example', json_encode(['name' => '']), BH_PR_JSON)->status);
        assert_same(201, bh_pr_req('POST', '/api/example', json_encode(['name' => 'Ann', 'note' => 'Hi']), BH_PR_JSON)->status);
        // A browser form: needs the session's token.
        $b = bh_browser();
        $form = $b->request('GET', '/example');
        preg_match('/name="_csrf" value="([^"]+)"/', $form->body, $m);
        $ok = $b->request('POST', '/api/example', http_build_query(['_csrf' => html_entity_decode($m[1] ?? ''), 'name' => 'Bo', 'note' => 'Form']), BH_PR_FORM);
        assert_same(303, $ok->status);
        assert_same(403, $b->request('POST', '/api/example', http_build_query(['name' => 'Bo', 'note' => 'No token']), BH_PR_FORM)->status);
        assert_contains('Form', $b->request('GET', '/example')->body);
    } finally { bh_remove_app('bhpr-example'); }
});

test('public routes: precedence is deterministic (router table, in-process)', function () {
    $router = new \App\Core\Router(\App\Core\Application::getInstance());
    $router->get('/', fn() => 'home');
    $router->get('/search', fn() => 'search');
    $router->get('/posts/{slug}', fn() => 'post');
    $router->get('/{slug}', fn() => 'page');
    $router->setOwner('b-app');
    $site = $router->site();
    $site->get('/shop/{slug}', fn() => 'b-param');
    $site->get('/shop/cart', fn() => 'b-cart');
    $site->get('/posts/featured', fn() => 'b-shadow');
    $router->setOwner('a-app');
    $router->site()->get('/shop/{id}', fn() => 'a-dup');
    $router->site()->get('/shop/{x:*}', fn() => 'a-wild');
    $router->setOwner(null);

    $order = array_map(fn($r) => ($r['owner'] ?? 'core') . ' ' . $r['pattern'], $router->table()['routes']);
    assert_same(['core /', 'core /search', 'core /posts/{slug}', 'b-app /shop/cart', 'b-app /shop/{slug}', 'a-app /shop/{x:*}', 'core /{slug}'], $order);
    $reasons = array_map(fn($r) => $r['owner'] . ' ' . $r['pattern'] . ' ' . $r['reason'], $router->rejected());
    assert_same(['b-app /posts/featured core-conflict', 'a-app /shop/{id} duplicate'], $reasons);
});
