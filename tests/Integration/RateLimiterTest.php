<?php

declare(strict_types=1);

use App\Core\Ajax\AjaxGateway;
use App\Core\Application;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Services\RateLimiter;

function limiter(): RateLimiter
{
    return Application::getInstance()->make(RateLimiter::class);
}

/** Run the gateway in this process with a fixed client address. */
function gateway_get(string $action, string $ip): array
{
    $req = new Request('GET', '/ajax', ['action' => $action], [], [], [],
        ['REMOTE_ADDR' => $ip, 'REQUEST_METHOD' => 'GET'], ['sec-fetch-site' => 'same-origin']);
    $res = (new AjaxGateway())->handle($req);
    return ['status' => $res->getStatus(), 'json' => json_decode($res->getBody(), true), 'headers' => $res->getHeaders()];
}

test('limiter: counts within a window and refuses past the limit', function () {
    $bucket = 'test:' . bin2hex(random_bytes(4));
    $now = 1_000_000_040;   // 20 s into a 60 s window (1_000_000_020 is a window start)
    $seen = [];
    for ($i = 1; $i <= 4; $i++) {
        $r = limiter()->hit($bucket, 'c', 3, 60, $now);
        $seen[] = [$r['allowed'], $r['count']];
    }
    assert_same([[true, 1], [true, 2], [true, 3], [false, 4]], $seen);
    assert_same(40, limiter()->hit($bucket, 'c', 3, 60, $now)['retry_after'], 'until the window ends');
});

test('limiter: a new window starts a new count; clients are independent', function () {
    $bucket = 'test:' . bin2hex(random_bytes(4));
    $t = 2_000_000_000;
    limiter()->hit($bucket, 'a', 1, 60, $t);
    assert_false(limiter()->hit($bucket, 'a', 1, 60, $t + 10)['allowed']);
    assert_true(limiter()->hit($bucket, 'b', 1, 60, $t + 10)['allowed'], 'another client');
    assert_true(limiter()->hit($bucket, 'a', 1, 60, $t + 60)['allowed'], 'next window');
});

test('limiter: keys are hashed — no client identifier is stored readable', function () {
    $bucket = 'test:visible-bucket-name';
    limiter()->hit($bucket, 'ip:203.0.113.77', 5, 60);
    $rows = bh_db()->select('SELECT k FROM {rate_limits}');
    foreach ($rows as $r) {
        assert_true((bool) preg_match('/^[0-9a-f]{64}$/', $r['k']));
    }
    assert_not_contains('203.0.113.77', json_encode($rows));
});

test('limiter: concurrent processes cannot overrun a limit (8 processes × 25 hits, limit 50)', function () {
    bh_require_innodb();
    $bucket = 'test:race:' . bin2hex(random_bytes(4));
    $procs = [];
    for ($i = 0; $i < 8; $i++) {
        $procs[] = proc_open(
            [PHP_BINARY, bh_env()['root'] . '/tests/support/rate_worker.php', bh_env()['env_file'], $bucket, '25', '50'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]
        );
    }
    $allowed = 0; $errors = 0; $counts = [];
    foreach ($procs as $i => $p) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        proc_close($p);
        $j = json_decode((string) $out, true);
        if (!is_array($j)) fail("worker {$i} failed: {$out} {$err}");
        $allowed += $j['allowed'];
        $errors += $j['errors'];
        $counts = array_merge($counts, $j['counts']);
    }
    assert_same(0, $errors, 'store errors under contention');
    assert_same(50, $allowed, 'exactly the limit was allowed');
    sort($counts);
    assert_same(range(1, 200), $counts, 'every hit got a distinct count: no lost or duplicated increments');
});

test('limiter: claims are once-only until expiry or release', function () {
    $scope = 'test:claim:' . bin2hex(random_bytes(4));
    $t = 1_500_000_000;
    assert_same(true, limiter()->claim($scope, 'k1', 100, $t));
    assert_same(false, limiter()->claim($scope, 'k1', 100, $t + 50));
    assert_same(true, limiter()->claim($scope, 'k2', 100, $t + 50), 'another key');
    assert_same(true, limiter()->claim($scope, 'k1', 100, $t + 101), 'expired claims can be taken again');
    limiter()->release($scope, 'k1');
    assert_same(true, limiter()->claim($scope, 'k1', 100, $t + 102), 'released claims can be taken again');
});

test('limiter: the table is created on first use when the migration has not run', function () {
    $db = bh_db();
    $db->execute('DROP TABLE {rate_limits}');
    $fresh = new RateLimiter($db, Application::getInstance()->make(Logger::class));
    $r = $fresh->hit('test:selfheal', 'c', 5, 60);
    assert_false($r['error']);
    assert_same(1, $r['count']);
});

test('limiter: an unreachable store reports an error instead of throwing', function () {
    $broken = new Database(['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x', 'collation' => 'utf8mb4_unicode_ci']);
    $r = new RateLimiter($broken, Application::getInstance()->make(Logger::class));
    assert_true($r->hit('b', 'c', 1, 60)['error']);
    assert_same(null, $r->claim('s', 'k', 60));
});

test('gateway (in-process): the global per-IP limit applies across actions', function () {
    $config = Application::getInstance()->make(Config::class);
    $old = $config->get('ajax.global_limit');
    $config->set('ajax.global_limit', 3);
    $ip = '198.51.100.' . random_int(10, 250);
    // The limit counts in fixed one-minute windows on the clock: four requests
    // that straddle a minute were counted in two windows, and the fourth got
    // through (a test that failed now and then). Start well inside a window.
    $left = 60 - time() % 60;
    if ($left <= 5) sleep($left + 1);
    try {
        for ($i = 1; $i <= 3; $i++) {
            assert_same(200, gateway_get('core.csrf', $ip)['status'], "request {$i}");
        }
        $r = gateway_get('ajax-fixture.nope', $ip);
        assert_same(429, $r['status'], 'unknown actions count too');
        assert_same('RATE_LIMITED', $r['json']['error']['code']);
        assert_true(isset($r['headers']['Retry-After']));
        assert_same(200, gateway_get('core.csrf', '198.51.100.251')['status'], 'other addresses unaffected');
    } finally {
        $config->set('ajax.global_limit', $old);
    }
});

test('gateway (in-process): when the store is down it fails open by default, closed when configured', function () {
    $app = Application::getInstance();
    $real = $app->make(RateLimiter::class);
    $broken = new Database(['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x', 'collation' => 'utf8mb4_unicode_ci']);
    $app->instance(RateLimiter::class, new RateLimiter($broken, $app->make(Logger::class)));
    $config = $app->make(Config::class);
    $old = $config->get('ajax.rate_limit_fail');
    try {
        $config->set('ajax.rate_limit_fail', 'open');
        assert_same(200, gateway_get('core.csrf', '192.0.2.1')['status']);
        $config->set('ajax.rate_limit_fail', 'closed');
        $r = gateway_get('core.csrf', '192.0.2.1');
        assert_same(503, $r['status']);
        assert_same('SERVICE_UNAVAILABLE', $r['json']['error']['code']);
    } finally {
        $config->set('ajax.rate_limit_fail', $old);
        $app->instance(RateLimiter::class, $real);
    }
});

test('gateway (in-process): a declared Content-Length over the limit is refused before the body is read', function () {
    $read = false;
    $gw = new AjaxGateway(function (int $limit) use (&$read) { $read = true; return '{}'; });
    $req = new Request('POST', '/ajax', [], [], [], [],
        ['REMOTE_ADDR' => '192.0.2.50', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '99999999'],
        ['content-type' => 'application/json']);
    $res = $gw->handle($req);
    assert_same(413, $res->getStatus());
    assert_false($read, 'the body reader was never called');
});
