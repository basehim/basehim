<?php

declare(strict_types=1);

/*
 * 1.2.90 over HTTP: security headers and CSP reports, Settings → Security,
 * rate limits that ignore forged headers, app assets, the sitemap filter,
 * app page titles and canonicals, app dependencies, and outbound requests.
 */

const R1290_APP = <<<'PHP'
<?php
namespace BhTest\R1290Fixture;

use App\Core\Ajax\AjaxContext;
use App\Core\Http\SitePage;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $site = $this->router()->site();
        $site->get('/r1290/page', fn() => SitePage::view($this, 'page', [], ['title' => 'Fixture Page']));
        $site->get('/r1290/rel', function () {
            bh_seo()->set('canonical', (defined('BASEHIM_BASE') ? BASEHIM_BASE : '') . '/r1290/rel-canonical');
            return SitePage::view($this, 'page', [], ['title' => 'Relative']);
        });
        $this->addFilter('sitemap.index', function (array $list, string $origin) {
            return array_merge($list, [$origin . '/r1290/sitemap.xml', 'https://elsewhere.example/x.xml', $origin . '/r1290/sitemap.xml']);
        }, 10, 2);
        $this->ajax('asset', ['handler' => fn() => ['url' => $this->asset('x.css')], 'auth' => 'public', 'methods' => ['GET'], 'rate_limit' => ['max' => 100, 'per' => 60, 'by' => 'ip']]);
        $this->ajax('fetch', [
            'handler' => [$this, 'fetch'], 'auth' => 'public', 'methods' => ['GET'],
            'input' => ['url' => 'required|string|max:500', 'private' => 'bool'],
            'rate_limit' => ['max' => 100, 'per' => 60, 'by' => 'ip'],
        ]);
    }

    public function fetch(AjaxContext $ctx): array
    {
        $http = $this->api()->http()->timeout(5);
        if ($ctx->input('private')) $http->allowPrivateNetwork();
        $r = $http->get((string) $ctx->input('url'));
        return ['ok' => $r['ok'], 'status' => $r['status'], 'error' => $r['error']];
    }
}
PHP;

function r1290_setup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    bh_install_app('r1290-fixture', [
        'src/App.php' => R1290_APP,
        'views/page.php' => '<p id="r1290">fixture page</p><script>window.r1290 = 1;</script>',
        'assets/x.css' => 'p { color: red; }',
    ], ['namespace' => 'BhTest\\R1290Fixture', 'version' => '2.3.4']);
    bh_install_app('r1290-child', [
        'src/App.php' => "<?php\nnamespace BhTest\\R1290Child;\nclass App extends \\App\\Core\\App { public function boot(): void {} }\n",
    ], ['namespace' => 'BhTest\\R1290Child', 'requires' => ['apps' => ['r1290-fixture' => '>=1.0.0']]]);
}

function r1290_headers(\HttpResponse $r): array
{
    return array_change_key_case($r->headers, CASE_LOWER);
}

function r1290_save_security(\Browser $admin, array $fields): \HttpResponse
{
    $page = $admin->request('GET', '/admin/settings/security');
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    $body = http_build_query($fields + ['_csrf' => $m[1] ?? '', 'hsts' => '1', 'hsts_max_age' => '31536000', 'csp_mode' => 'report']);
    return $admin->request('POST', '/admin/settings/security', $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
}

test('1.2.90 e2e: public pages carry the security headers, and their scripts the CSP nonce', function () {
    r1290_setup();
    $r = bh_browser()->request('GET', '/r1290/page');
    assert_same(200, $r->status, $r->body);
    $h = r1290_headers($r);
    assert_same('Basehim', $h['x-powered-by'] ?? null, 'no PHP version');
    assert_same('0', $h['x-xss-protection'] ?? null);
    assert_contains("object-src 'none'", $h['content-security-policy'] ?? '');
    preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $h['content-security-policy-report-only'] ?? '', $m);
    assert_true(!empty($m[1]), 'report-only policy with a nonce');
    assert_contains('<script nonce="' . $m[1] . '">window.r1290 = 1;</script>', $r->body);
    assert_not_contains('nonce="' . $m[1] . '" type="application/ld+json"', $r->body);
    $again = r1290_headers(bh_browser()->request('GET', '/r1290/page'));
    assert_true(!str_contains($again['content-security-policy-report-only'] ?? '', $m[1]), 'a new nonce per response');
    // The admin: baseline only.
    $a = r1290_headers(bh_browser('admin')->request('GET', '/admin'));
    assert_contains("frame-ancestors 'self'", $a['content-security-policy'] ?? '');
    assert_false(isset($a['content-security-policy-report-only']));
});

test('1.2.90 e2e: Settings → Security switches the script policy to enforce; only administrators see it', function () {
    r1290_setup();
    $admin = bh_browser('admin');
    $page = $admin->request('GET', '/admin/settings/security');
    assert_same(200, $page->status);
    assert_contains('Content-Security-Policy', $page->body);
    assert_same(403, bh_browser('editor')->request('GET', '/admin/settings/security')->status);
    assert_same(302, r1290_save_security($admin, ['csp_mode' => 'enforce'])->status);
    $h = r1290_headers(bh_browser()->request('GET', '/r1290/page'));
    assert_contains("'strict-dynamic'", $h['content-security-policy'] ?? '');
    assert_false(isset($h['content-security-policy-report-only']));
    // Refused values change nothing.
    r1290_save_security($admin, ['csp_mode' => 'block-everything']);
    assert_contains("'strict-dynamic'", r1290_headers(bh_browser()->request('GET', '/r1290/page'))['content-security-policy'] ?? '');
    r1290_save_security($admin, ['csp_mode' => 'off']);
    $off = r1290_headers(bh_browser()->request('GET', '/r1290/page'));
    assert_false(isset($off['content-security-policy']) || isset($off['content-security-policy-report-only']));
    r1290_save_security($admin, ['csp_mode' => 'report']);
});

test('1.2.90 e2e: browsers can report CSP violations; they are listed for administrators and can be cleared', function () {
    $v = bh_browser();
    $report = json_encode(['csp-report' => ['document-uri' => bh_env()['base_url'] . '/r1290/page', 'effective-directive' => 'script-src-attr', 'blocked-uri' => 'inline', 'script-sample' => 'onclick-r1290']]);
    assert_same(204, $v->request('POST', '/csp-report', $report, ['Content-Type' => 'application/csp-report'])->status);
    assert_same(415, $v->request('POST', '/csp-report', $report, ['Content-Type' => 'text/plain'])->status);
    assert_same(413, $v->request('POST', '/csp-report', str_repeat('x', 20000), ['Content-Type' => 'application/csp-report'])->status);
    $admin = bh_browser('admin');
    $page = $admin->request('GET', '/admin/settings/security');
    assert_contains('onclick-r1290', $page->body);
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    $admin->request('POST', '/admin/settings/security/clear-reports', http_build_query(['_csrf' => $m[1] ?? '']), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_not_contains('onclick-r1290', $admin->request('GET', '/admin/settings/security')->body);
});

test('1.2.90 e2e: a forged X-Forwarded-For does not escape the search limit', function () {
    $v = bh_browser();
    $codes = [];
    for ($i = 1; $i <= 32; $i++) {
        $codes[] = $v->request('GET', '/search?q=r1290-' . $i, null, ['X-Forwarded-For' => "198.51.100.{$i}", 'CF-Connecting-IP' => "198.51.100.{$i}"])->status;
    }
    $counts = array_count_values($codes);
    // Leave the counters as they were, for the search tests that follow.
    bh_db()->execute('DELETE FROM {rate_limits}');
    assert_true(isset($counts[429]), 'limited: ' . json_encode($counts));
});

test('1.2.90 e2e: app asset URLs carry the app version; unversioned ones are revalidated', function () {
    r1290_setup();
    $url = bh_browser()->ajaxGet('r1290-fixture.asset')->json()['data']['url'] ?? '';
    assert_true(str_ends_with($url, '/content/apps/r1290-fixture/assets/x.css?v=2.3.4'), $url);
    $path = parse_url($url, PHP_URL_PATH);
    assert_contains('max-age=2592000', r1290_headers(bh_browser()->request('GET', $path . '?v=2.3.4'))['cache-control'] ?? '');
    assert_contains('no-cache', r1290_headers(bh_browser()->request('GET', $path))['cache-control'] ?? '');
});

test('1.2.90 e2e: apps can add sitemaps to the index, on this site only', function () {
    r1290_setup();
    bh_db()->execute("UPDATE {posts} SET updated_at = updated_at WHERE 1 = 0");
    $xml = bh_browser()->request('GET', '/sitemap.xml')->body;
    assert_same(1, substr_count($xml, '/r1290/sitemap.xml</loc>'), 'added once');
    assert_not_contains('elsewhere.example', $xml);
});

test('1.2.90 e2e: app pages get the SEO title format once, and absolute canonicals without a trailing slash', function () {
    r1290_setup();
    $base = bh_env()['base_url'];
    $r = bh_browser()->request('GET', '/r1290/page/');
    if ($r->status === 301 || $r->status === 404) $r = bh_browser()->request('GET', '/r1290/page');
    preg_match('#<title>(.*?)</title>#s', $r->body, $t);
    assert_contains('Fixture Page', $t[1] ?? '');
    assert_true(substr_count($t[1] ?? '', ' – ') + substr_count($t[1] ?? '', ' | ') <= 1, 'the site name once: ' . ($t[1] ?? ''));
    assert_contains('<link rel="canonical" href="' . $base . '/r1290/page">', $r->body);
    $rel = bh_browser()->request('GET', '/r1290/rel');
    assert_contains('<link rel="canonical" href="' . $base . '/r1290/rel-canonical">', $rel->body);
    assert_contains('"og:url" content="' . $base . '/r1290/rel-canonical"', str_replace('property=', '', $rel->body));
});

test('1.2.90 e2e: an app other apps need cannot be switched off or deleted alone', function () {
    r1290_setup();
    $admin = bh_browser('admin');
    $ids = [];
    foreach (bh_db()->select("SELECT id, slug FROM {apps} WHERE slug IN ('r1290-fixture', 'r1290-child')") as $row) $ids[$row['slug']] = (int) $row['id'];
    $res = $admin->ajax('apps.bulk', ['action' => 'deactivate', 'ids' => [$ids['r1290-fixture']]]);
    $one = $res->json()['data']['results'][0] ?? [];
    assert_false($one['ok'] ?? true, 'refused');
    assert_contains('r1290-child depends on it', $one['message'] ?? '');
    assert_same('active', bh_app_status('r1290-fixture'));
    // Both at once: the dependent goes first, then what it needs.
    $res = $admin->ajax('apps.bulk', ['action' => 'deactivate', 'ids' => [$ids['r1290-fixture'], $ids['r1290-child']]]);
    assert_same(2, $res->json()['data']['done'] ?? null, $res->body);
    bh_db()->execute("UPDATE {apps} SET status = 'active' WHERE slug IN ('r1290-fixture', 'r1290-child')");
});

test('1.2.90 e2e: api()->http() refuses private addresses and redirects into them', function () {
    r1290_setup();
    $v = bh_browser();
    $self = bh_env()['base_url'];
    $r = $v->ajaxGet('r1290-fixture.fetch', ['url' => $self . '/'])->json()['data'] ?? [];
    assert_false($r['ok'] ?? true);
    assert_contains('loopback', (string) ($r['error'] ?? ''));
    foreach (['http://169.254.169.254/latest/meta-data/', 'http://10.0.0.1/', 'file:///etc/passwd', 'http://2130706433/'] as $url) {
        $r = $v->ajaxGet('r1290-fixture.fetch', ['url' => $url])->json()['data'] ?? [];
        assert_false($r['ok'] ?? true, $url);
    }
    // Allowed for one request, on purpose.
    $r = $v->ajaxGet('r1290-fixture.fetch', ['url' => $self . '/robots.txt', 'private' => '1'])->json()['data'] ?? [];
    assert_true($r['ok'] ?? false, json_encode($r));
});
