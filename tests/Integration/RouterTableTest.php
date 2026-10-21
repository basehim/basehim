<?php

declare(strict_types=1);

/*
 * 1.2.79 — Router::table() by lookup instead of comparing every app route
 * with every core and earlier app route (about a million regex calls per
 * request on a site with ~300 core and ~800 app routes: 2–4 s per page).
 */

test('router: a table of ~1,100 routes is built in well under a second, with the same precedence', function () {
    $router = new \App\Core\Router(\App\Core\Application::getInstance());
    for ($i = 0; $i < 300; $i++) {
        $router->get($i % 3 ? "/admin/core{$i}/{id}" : "/admin/core{$i}", fn() => 'core');
    }
    $router->get('/{slug}', fn() => 'page');
    for ($i = 0; $i < 800; $i++) {
        $router->setOwner('app' . ($i % 28));
        $router->site()->get(($i % 2 ? "/app{$i}/item/{id}" : "/app{$i}/list"), fn() => 'app');
    }
    $router->setOwner('late');
    $router->site()->get('/app1/item/{other}', fn() => 'dup');          // same shape as app1's: refused
    $router->setOwner(null);

    $t0 = hrtime(true);
    $table = $router->table();
    $ms = (hrtime(true) - $t0) / 1e6;
    assert_true($ms < 250, sprintf('table() took %.0f ms', $ms));   // ~10–20 ms; the pairwise version ~400–600 ms
    assert_same(1101, count($table['routes']));
    assert_same(['duplicate'], array_column($table['rejected'], 'reason'));
    assert_same('/{slug}', end($table['routes'])['pattern'], 'core catch-alls stay last');
});

test('router: a fixed app path still loses to a core route with a parameter inside its first segment', function () {
    $router = new \App\Core\Router(\App\Core\Application::getInstance());
    $router->get('/sitemap-{name}.xml', fn() => 'core');
    $router->get('/posts/{slug}', fn() => 'core');
    $router->setOwner('shop');
    $router->site()->get('/sitemap-shop.xml', fn() => 'app');
    $router->site()->get('/posts/featured', fn() => 'app');
    $router->site()->post('/posts/featured', fn() => 'app');             // POST: core has no POST there
    $router->setOwner(null);
    $rej = array_map(fn($r) => $r['reason'] . ' ' . $r['pattern'], $router->rejected('shop'));
    assert_same(['core-conflict /sitemap-shop.xml', 'core-conflict /posts/featured'], $rej);
    assert_same(1, count(array_filter($router->table()['routes'], fn($r) => $r['pattern'] === '/posts/featured')));
});

test('router: repeated registrations and route names are still recognised, also after forgetOwner()', function () {
    $router = new \App\Core\Router(\App\Core\Application::getInstance());
    $router->setOwner('shop');
    $router->site()->get('/shop', fn() => 'a', ['name' => 'shop.index']);
    $router->site()->get('/shop', fn() => 'again');                       // boot() run twice: not a second route
    $router->setOwner(null);
    assert_same(1, count(array_filter($router->table()['routes'], fn($r) => $r['pattern'] === '/shop')));
    assert_true($router->hasName('shop.index'));
    assert_same(1, $router->forgetOwner('shop'));
    assert_false($router->hasName('shop.index'), 'names leave with their app');
    $router->setOwner('shop');
    $router->site()->get('/shop', fn() => 'b', ['name' => 'shop.index']);  // and can be registered again
    $router->setOwner(null);
    assert_same(1, count($router->table()['routes']));
});
