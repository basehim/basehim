<?php

declare(strict_types=1);

/*
 * 1.2.84 — the Marketplace on the AJAX gateway and the core components:
 * apps.market_* / themes.market_* actions (gated like the screens they stand
 * in for), no stylesheet or colours of its own, and every icon its script
 * draws present in the JavaScript icon set.
 */

test('marketplace actions: read actions answer GET only, and every action is rate limited', function () {
    $src = file_get_contents(BASEHIM_ROOT . '/routes/ajax.php');
    $block = substr($src, strpos($src, 'use App\Http\Controllers\Ajax\MarketplaceActions;'));
    // Three registered per namespace in the loop, plus three on their own.
    assert_same(6, substr_count($block, '$ajax->register('), 'six register() calls');
    assert_same(6, substr_count($block, "'rate_limit'"), 'each has a rate limit');
    $b = bh_browser('admin');
    foreach (['apps.market_browse', 'apps.market_facets', 'themes.market_browse', 'themes.market_facets', 'apps.market_consent'] as $read) {
        assert_same(405, $b->ajax($read, ['slug' => 'x'])->status, "$read refuses POST");
    }
});

test('marketplace actions: roles without the capability are refused, as a gateway error', function () {
    foreach (['editor', 'author', 'subscriber'] as $role) {
        $res = bh_browser($role)->ajaxGet('apps.market_browse', []);
        assert_same(403, $res->status, "$role: " . $res->body);
        assert_same('FORBIDDEN', $res->json()['error']['code'] ?? null, $role);
        assert_same(403, bh_browser($role)->ajax('themes.market_install', ['slug' => 'x'])->status, $role);
    }
});

test('marketplace actions: input is checked before anything is downloaded or changed', function () {
    $b = bh_browser('admin');
    assert_same(422, $b->ajaxGet('apps.market_browse', ['sort' => 'random'])->status, 'unknown sort');
    assert_same(422, $b->ajax('apps.market_install', ['slug' => '../../etc'])->status, 'slug with a path');
    assert_same(422, $b->ajax('apps.market_install', [])->status, 'no slug');
    $res = $b->ajaxGet('apps.market_consent', ['slug' => 'not-installed-app']);
    assert_same(404, $res->status, $res->body);
    assert_same(404, $b->ajax('apps.market_activate', ['slug' => 'not-installed-app'])->status);
    assert_same(404, $b->ajax('themes.market_activate', ['slug' => 'not-installed-theme'])->status);
});

test('marketplace page: core components only, no own stylesheet, no inline styles', function () {
    $html = bh_browser('admin')->request('GET', '/admin/marketplace')->body;
    assert_contains('class="bh-mk"', $html);
    assert_contains('bh-mk-head', $html);
    assert_contains('?tab=themes', $html, 'an admin sees the Themes tab');
    assert_not_contains('marketplace.css', $html);
    assert_not_contains('data-csrf="', substr($html, strpos($html, 'id="mk-root"'), 400), 'the gateway client carries the token');
    $start = strpos($html, 'id="mk-root"');
    $part = substr($html, $start, strpos($html, '</dialog>', $start) - $start);
    assert_not_contains('style="', $part);
    assert_not_contains('<style', $part);
    assert_true(!preg_match('/#[0-9a-f]{3,6}\b/i', $part), 'no fixed colours in the page');
    assert_false(is_file(BASEHIM_ROOT . '/admin/assets/css/marketplace.css'), 'marketplace.css is gone');
});

test('marketplace script: Basehim.ajax and core classes, no fetch, no fixed colours', function () {
    $js = file_get_contents(BASEHIM_ROOT . '/admin/assets/js/marketplace.js');
    assert_contains('Basehim.ajax.request', $js);
    assert_not_contains('fetch(', $js);
    assert_not_contains('_csrf', $js);
    assert_not_contains('style="', $js);
    assert_true(!preg_match('/#[0-9a-f]{3,6}\b/i', $js), 'no fixed colours');
    assert_true(!preg_match('/\b(bg|text|border)-(red|amber|emerald|teal|green)-\d+/', $js), 'colours come from bh-badge / bh-note, not utilities');
    assert_not_contains('animate-spin', $js, 'busy buttons use bhBusy');
});

test('marketplace script: every icon it draws exists in the JavaScript icon set', function () {
    $js = file_get_contents(BASEHIM_ROOT . '/admin/assets/js/marketplace.js');
    preg_match_all("/\\bic\\('([a-z0-9-]+)'/", $js, $m);
    $names = array_unique($m[1]);
    assert_true(count($names) > 10, 'found the icon calls');
    $icons = file_get_contents(BASEHIM_ROOT . '/admin/assets/js/icons.js');
    foreach ($names as $n) assert_contains('"' . $n . '":', $icons, "icons.js has '$n'");
});

test('marketplace details window: styled by the core sheet, not an inline block', function () {
    $src = file_get_contents(BASEHIM_ROOT . '/admin/views/partials/item-modal.php');
    assert_not_contains('<style', $src);
    $css = file_get_contents(BASEHIM_ROOT . '/admin/assets/css/tailwind.min.css');
    foreach (['.bh-im{', '.bh-mk-card{', '.bh-mk-feature{', '.bh-mk-perm{'] as $sel) assert_contains($sel, $css, $sel);
});
