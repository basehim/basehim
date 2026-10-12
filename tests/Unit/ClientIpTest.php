<?php

declare(strict_types=1);

use App\Core\Request;

function req_with(array $server): Request
{
    return new Request('GET', '/ajax', [], [], [], [], $server, []);
}

test('client IP: forwarded headers are ignored when no proxy is trusted', function () {
    $r = req_with(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8']);
    assert_same('203.0.113.9', $r->clientIp(''));
    assert_same('203.0.113.9', $r->clientIp([]));
});

test('client IP: forwarded headers are ignored from an untrusted address', function () {
    $r = req_with(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
    assert_same('203.0.113.9', $r->clientIp('10.0.0.0/8'));
});

test('client IP: behind a trusted proxy, the right-most untrusted hop is the client', function () {
    // The client prepended a fake address; the proxy appended the real one.
    $r = req_with(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7']);
    assert_same('198.51.100.7', $r->clientIp('10.0.0.0/8'));
});

test('client IP: chains of trusted proxies are skipped', function () {
    $r = req_with(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.1.2.3']);
    assert_same('198.51.100.7', $r->clientIp('10.0.0.0/8, 192.168.0.1'));
});

test('client IP: a malformed forwarded hop falls back to the connecting address', function () {
    $r = req_with(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, not-an-ip']);
    assert_same('10.0.0.5', $r->clientIp('10.0.0.0/8'));
    assert_same('0.0.0.0', req_with(['REMOTE_ADDR' => 'garbage'])->clientIp(''));
});

test('client IP: CIDR matching for IPv4 and IPv6', function () {
    assert_true(Request::ipMatches('10.1.2.3', '10.0.0.0/8'));
    assert_false(Request::ipMatches('11.1.2.3', '10.0.0.0/8'));
    assert_true(Request::ipMatches('192.168.1.130', '192.168.1.128/25'));
    assert_false(Request::ipMatches('192.168.1.127', '192.168.1.128/25'));
    assert_true(Request::ipMatches('2001:db8::1', '2001:db8::/32'));
    assert_false(Request::ipMatches('2001:db9::1', '2001:db8::/32'));
    assert_false(Request::ipMatches('10.0.0.1', '2001:db8::/32'), 'v4 never matches a v6 range');
    assert_true(Request::ipMatches('203.0.113.9', '203.0.113.9'));
    assert_false(Request::ipMatches('203.0.113.9', '203.0.113.9/33'), 'prefix longer than the address');
    assert_false(Request::ipMatches('203.0.113.9', 'nonsense'));
});
