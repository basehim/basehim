<?php

declare(strict_types=1);

/*
 * 1.2.90: forwarded headers, outbound requests, the app scanner, icons and
 * the security headers, in-process.
 */

use App\Core\Env;
use App\Core\Icon;
use App\Core\Net\OutboundGuard;
use App\Core\Request;
use App\Core\Response;
use App\Services\SecurityHeaders;

function r1290_request(array $server): Request
{
    return new Request('GET', '/', [], [], [], [], $server + ['REQUEST_METHOD' => 'GET'], []);
}

function r1290_env(array $vars, callable $fn): void
{
    $old = [];
    foreach ($vars as $k => $v) {
        $old[$k] = Env::all()[$k] ?? null;
        Env::set($k, $v);
    }
    try {
        $fn();
    } finally {
        foreach ($old as $k => $v) Env::set($k, $v ?? '');
    }
}

test('1.2.90: X-Forwarded-Proto is believed only from TRUSTED_PROXIES; CF-Visitor only from Cloudflare', function () {
    r1290_env(['TRUSTED_PROXIES' => '10.0.0.0/8', 'APP_URL' => 'http://example.com'], function () {
        assert_true(Request::serverIsSecure(['HTTPS' => 'on']));
        assert_true(Request::serverIsSecure(['SERVER_PORT' => '443']));
        assert_false(Request::serverIsSecure(['HTTPS' => 'off']));
        // A visitor cannot claim https…
        assert_false(Request::serverIsSecure(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com']));
        // …nor CF-Visitor, unless the request really comes from Cloudflare.
        assert_false(Request::serverIsSecure(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_VISITOR' => '{"scheme":"https"}']));
        assert_true(Request::serverIsSecure(['REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_VISITOR' => '{"scheme":"https"}']));
        // A trusted proxy can.
        assert_true(Request::serverIsSecure(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https, http']));
        assert_true(Request::serverIsSecure(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_SSL' => 'on']));
        assert_false(Request::serverIsSecure(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'http']));
    });
    // An https:// APP_URL settles it for its own host, behind any CDN.
    r1290_env(['TRUSTED_PROXIES' => '', 'APP_URL' => 'https://www.example.com'], function () {
        assert_true(Request::serverIsSecure(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.com']));
        assert_true(Request::serverIsSecure(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'WWW.example.com:443']));
        assert_false(Request::serverIsSecure(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'evil.example']));
    });
});

test('1.2.90: visitorIp() ignores forged forwarding headers', function () {
    r1290_env(['TRUSTED_PROXIES' => ''], function () {
        $r = r1290_request(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8', 'HTTP_X_REAL_IP' => '9.9.9.9']);
        assert_same('198.51.100.7', $r->visitorIp());
        assert_same('5.6.7.8', $r->ip(), 'ip() stays the display value (believes anyone)');
        // From Cloudflare, its own header is the visitor; X-Forwarded-For still isn't.
        $cf = r1290_request(['REMOTE_ADDR' => '172.64.1.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50']);
        assert_same('203.0.113.50', $cf->visitorIp());
        $cfBad = r1290_request(['REMOTE_ADDR' => '172.64.1.1', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip']);
        assert_same('172.64.1.1', $cfBad->visitorIp());
    });
    r1290_env(['TRUSTED_PROXIES' => '10.0.0.0/8'], function () {
        $r = r1290_request(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9']);
        assert_same('203.0.113.9', $r->visitorIp(), 'the address the trusted proxy appended');
    });
});

test('1.2.90: outbound requests only reach public addresses unless allowed', function () {
    $g = new OutboundGuard();
    foreach (['http://127.0.0.1/', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5:8080/', 'http://192.168.1.1/',
              'http://[::ffff:127.0.0.1]/', 'http://[fd00::1]/', 'http://100.64.0.1/'] as $url) {
        assert_throws(\RuntimeException::class, fn() => $g->check($url), null);
    }
    foreach (['ftp://example.com/', 'file:///etc/passwd', 'gopher://x.y/', 'http://user:pw@93.184.215.14/', 'http://2130706433/', 'http://0x7f.0.0.1/', "http://x.com/\r\nX: y"] as $url) {
        assert_throws(\RuntimeException::class, fn() => $g->check($url), null);
    }
    $ok = $g->check('https://93.184.215.14:8443/path?q=1');
    assert_same(['scheme' => 'https', 'host' => '93.184.215.14', 'port' => 8443, 'ip' => '93.184.215.14'], $ok);
    $open = (new OutboundGuard(true))->check('http://127.0.0.1:8080/');
    assert_same(null, $open['ip'], 'allowed for this request: curl resolves it');
    r1290_env(['HTTP_ALLOW_PRIVATE' => '10.0.0.0/8, intranet.example'], function () {
        $g = new OutboundGuard();
        assert_same('10.1.1.1', $g->check('http://10.1.1.1/')['ip']);
        assert_same(null, $g->check('http://intranet.example/')['ip']);
        assert_throws(\RuntimeException::class, fn() => $g->check('http://192.168.1.1/'));
    });
});

test('1.2.90: the app scanner no longer reads method calls as shell execution', function () {
    $dir = sys_get_temp_dir() . '/r1290-scan-' . bin2hex(random_bytes(4));
    @mkdir($dir . '/src', 0777, true);
    file_put_contents($dir . '/src/A.php', "<?php\nclass A {\n private function exec(int \$a) {}\n function b(\$pdo, \$ch) { \$this->exec(1); \$pdo->exec('x'); \\App\\Core\\Tls::exec(\$ch); curl_exec(\$ch); }\n}\n");
    file_put_contents($dir . '/src/B.php', "<?php\nfunction run(\$c) { return shell_exec(\$c) . exec('id') . \\system('id'); }\n");
    $r = (new \App\Services\AppScanner())->scan($dir);
    $byFile = [];
    foreach ($r['findings'] as $f) $byFile[basename((string) $f['file'])][] = $f['label'];
    assert_false(isset($byFile['A.php']), 'methods and declarations are not flagged: ' . json_encode($byFile['A.php'] ?? []));
    assert_true(in_array('Shell execution', $byFile['B.php'] ?? [], true), 'real shell calls still are');
});

test('1.2.90: Icon::has() is false for unknown names', function () {
    assert_true(Icon::has('trash'));
    assert_true(Icon::has('fa-trash'));
    assert_false(Icon::has('no-such-icon-anywhere'));
});

test('1.2.90: every executable script gets the nonce; data blocks and existing nonces are left alone', function () {
    $html = '<script>a()</script><SCRIPT src="/x.js" defer></SCRIPT><script type="application/ld+json">{"a":1}</script>'
        . '<script type="module" src="/m.js"></script><script type="text/javascript">b()</script><script nonce="own">c()</script>'
        . '<script type="text/template"><p></p></script><scripts>';
    $out = SecurityHeaders::addNonces($html, 'N0nce');
    assert_same(4, substr_count($out, 'nonce="N0nce"'));
    assert_contains('<script type="application/ld+json">{"a":1}</script>', $out);
    assert_contains('<script nonce="own">', $out);
    assert_contains('<script type="text/template">', $out);
    assert_contains('<scripts>', $out);
});

test('1.2.90: security headers on public pages, admin pages and non-HTML responses', function () {
    $req = r1290_request(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'example.com', 'HTTPS' => 'on']);
    $page = Response::html('<!doctype html><html><head><script>x()</script></head></html>');
    SecurityHeaders::apply($page, $req, false);
    $h = array_change_key_case($page->getHeaders(), CASE_LOWER);
    assert_same('Basehim', $h['x-powered-by'] ?? null);
    assert_same('0', $h['x-xss-protection'] ?? null);
    assert_same('nosniff', $h['x-content-type-options'] ?? null);
    assert_contains('max-age=31536000', $h['strict-transport-security'] ?? '');
    assert_contains("object-src 'none'", $h['content-security-policy'] ?? '');
    assert_contains('upgrade-insecure-requests', $h['content-security-policy'] ?? '');
    assert_not_contains('script-src', $h['content-security-policy'] ?? '', 'report mode by default: scripts are only reported');
    preg_match("/'nonce-([^']+)'/", $h['content-security-policy-report-only'] ?? '', $m);
    assert_true(!empty($m[1]), 'the report-only policy carries the nonce');
    assert_contains('<script nonce="' . $m[1] . '">', $page->getBody());
    assert_contains('report-uri /csp-report', $h['content-security-policy-report-only']);

    // Plain http: no HSTS, no upgrade.
    $http = r1290_request(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'example.com']);
    $p2 = Response::html('<!doctype html><p>x</p>');
    SecurityHeaders::apply($p2, $http, false);
    $h2 = array_change_key_case($p2->getHeaders(), CASE_LOWER);
    assert_false(isset($h2['strict-transport-security']));
    assert_not_contains('upgrade-insecure-requests', $h2['content-security-policy'] ?? '');

    // Admin: baseline only, nothing added to the page.
    $admin = Response::html('<!doctype html><script>y()</script>');
    SecurityHeaders::apply($admin, $req, true);
    $ha = array_change_key_case($admin->getHeaders(), CASE_LOWER);
    assert_contains("frame-ancestors 'self'", $ha['content-security-policy'] ?? '');
    assert_false(isset($ha['content-security-policy-report-only']));
    assert_same('<!doctype html><script>y()</script>', $admin->getBody());

    // JSON: the plain headers, no CSP.
    $json = Response::json(['a' => '<script>']);
    SecurityHeaders::apply($json, $req, false);
    $hj = array_change_key_case($json->getHeaders(), CASE_LOWER);
    assert_same('0', $hj['x-xss-protection'] ?? null);
    assert_false(isset($hj['content-security-policy']));
    assert_false(isset($hj['content-security-policy-report-only']));

    // A response's own values win.
    $own = Response::html('<!doctype html>');
    $own->header('Content-Security-Policy', "default-src 'none'");
    $own->header('X-Frame-Options', 'DENY');
    SecurityHeaders::apply($own, $req, false);
    $ho = $own->getHeaders();
    assert_same("default-src 'none'", $ho['Content-Security-Policy']);
    assert_same('DENY', $ho['X-Frame-Options']);
    assert_false(isset(array_change_key_case($ho, CASE_LOWER)['content-security-policy-report-only']));
});

test('1.2.90: CSP reports are grouped, counted and capped, without visitor details', function () {
    SecurityHeaders::clearReports();
    $report = fn(string $blocked, string $page) => json_encode(['csp-report' => [
        'document-uri' => 'https://example.com' . $page . '?token=secret', 'effective-directive' => 'script-src-elem',
        'blocked-uri' => $blocked, 'source-file' => 'https://example.com/a.js', 'line-number' => 3, 'script-sample' => "alert(1)\n",
    ]]);
    assert_true(SecurityHeaders::record($report('https://evil.example/x.js?k=1', '/post')));
    assert_true(SecurityHeaders::record($report('https://evil.example/x.js?k=2', '/post')));
    assert_true(SecurityHeaders::record($report('inline', '/other')));
    assert_false(SecurityHeaders::record('not json'));
    assert_false(SecurityHeaders::record(json_encode(['csp-report' => ['document-uri' => '/']])));
    $list = SecurityHeaders::reports();
    assert_same(2, count($list));
    $evil = array_values(array_filter($list, fn($r) => str_contains($r['blocked'], 'evil')))[0];
    assert_same(2, $evil['count']);
    assert_same('https://evil.example/x.js', $evil['blocked'], 'query strings are dropped');
    assert_same('/post', $evil['page'], 'the page path only');
    assert_same('alert(1)', $evil['sample'], 'control characters removed');
    for ($i = 0; $i < SecurityHeaders::MAX_REPORTS + 10; $i++) SecurityHeaders::record($report('https://x' . $i . '.example/', '/p'));
    assert_same(SecurityHeaders::MAX_REPORTS, count(SecurityHeaders::reports()));
    SecurityHeaders::clearReports();
    assert_same([], SecurityHeaders::reports());
});

test('1.2.90: the old X-XSS-Protection line in .htaccess is changed, and nothing else', function () {
    $f = sys_get_temp_dir() . '/r1290-htaccess-' . bin2hex(random_bytes(4));
    $before = "# keep me\n<IfModule mod_headers.c>\n    Header set X-Frame-Options \"SAMEORIGIN\"\n    Header set X-XSS-Protection \"1; mode=block\"\n</IfModule>\nRewriteRule ^ index.php [L]\n";
    file_put_contents($f, $before);
    assert_same('updated', SecurityHeaders::upgradeHtaccess($f));
    assert_same(str_replace('"1; mode=block"', '"0"', $before), file_get_contents($f));
    assert_same('already', SecurityHeaders::upgradeHtaccess($f));
    file_put_contents($f, "# a site's own file\n");
    assert_same('absent', SecurityHeaders::upgradeHtaccess($f));
    @unlink($f);
    assert_same('absent', SecurityHeaders::upgradeHtaccess($f));
});
