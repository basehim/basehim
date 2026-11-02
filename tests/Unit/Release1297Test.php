<?php

declare(strict_types=1);

/*
 * 1.2.97 — BH-084: HTTPS is decided in one place, Request::serverIsSecure()
 * (trusted proxies, Cloudflare, APP_URL), never from $_SERVER['HTTPS']
 * directly; BH-085: on phones the admin bar scrolls as one row.
 */

test('BH-084: no code outside Request reads $_SERVER[\'HTTPS\'] to decide the scheme', function () {
    $root = dirname(__DIR__, 2);
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php' || str_ends_with($f->getPathname(), '/Core/Request.php')) continue;
        if (str_contains((string) file_get_contents($f->getPathname()), "\$_SERVER['HTTPS']")) $hits[] = substr($f->getPathname(), strlen($root) + 1);
    }
    foreach (['bootstrap.php'] as $f) {
        if (str_contains((string) file_get_contents($root . '/' . $f), "\$_SERVER['HTTPS']")) $hits[] = $f;
    }
    assert_same([], $hits, 'use Request::serverIsSecure()');
});

test('BH-084: a trusted proxy\'s X-Forwarded-Proto counts, an untrusted one does not', function () {
    $proxy = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'shop.example'];
    $old = \App\Core\Env::get('TRUSTED_PROXIES', null);
    \App\Core\Env::set('TRUSTED_PROXIES', '10.0.0.0/8');
    try {
        assert_true(\App\Core\Request::serverIsSecure($proxy));
        assert_false(\App\Core\Request::serverIsSecure(['REMOTE_ADDR' => '203.0.113.9'] + $proxy), 'anyone else can\'t claim https');
    } finally {
        \App\Core\Env::set('TRUSTED_PROXIES', $old ?? '');
    }
});

test('BH-085: on phones the whole admin bar row scrolls, so the account menu stays reachable', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/admin-bar.css');
    $phone = substr($css, (int) strpos($css, '@media (max-width: 782px)'));
    assert_contains('.bhab--horizontal .bhab-bar { overflow-x: auto', $phone);
    assert_contains('.bhab--horizontal .bhab-menus { flex: none; }', $phone);
    assert_not_contains('.bhab-menus--main { overflow-x: auto', $phone, 'not only the left group');
});
