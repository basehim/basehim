<?php

declare(strict_types=1);

/*
 * 1.2.91: App::asset() versions file paths only (BH-070).
 */

use App\Core\App;

test('1.2.91: only file paths get ?v=; folders and app-added queries are left alone', function () {
    $m = new ReflectionMethod(App::class, 'isVersionableAsset');
    $m->setAccessible(true);
    $ok = fn(string $p): bool => (bool) $m->invoke(null, $p);
    foreach (['x.css', 'css/app.css', 'js/app.min.js', '/js/a.js', 'fonts/i.woff2', 'img/logo.PNG'] as $p) {
        assert_true($ok($p), $p);
    }
    foreach (['', '/', 'css', 'css/', 'img/icons', 'img/icons/', 'x.css?v=2', 'x.css#a', '.htaccess', 'a/.env', 'v1.2/'] as $p) {
        assert_false($ok($p), $p === '' ? '(empty)' : $p);
    }
});

test('1.2.92: a search is split into words at spaces and file-name separators (BH-071)', function () {
    $w = fn(string $s) => \App\Core\Search\SearchQuery::parse($s)->terms();
    assert_same(['digitalocean', 'pricing'], $w('  digitalocean   pricing '));
    assert_same(['snipaste', '2026'], $w('snipaste 2026'));
    assert_same(['hetzner', 'pricing'], $w('hetzner-pricing'));
    assert_same(['snipaste', '2026', '10', '27'], $w('Snipaste_2026-10-27'));
    assert_same(['php2shell', 'attack'], $w('PHP2Shell: Attack'));
    assert_same(['pricing'], $w('Pricing pricing PRICING'), 'repeats dropped, ignoring case');
    assert_same(['لاہور', 'دفتر'], $w('لاہور دفتر'), 'non-Latin words');
    assert_same(['100'], $w('100%'), '% is not part of a word');
    assert_true(\App\Core\Search\SearchQuery::parse('_')->matchesNothing(), 'only punctuation: matches nothing');
    assert_true(\App\Core\Search\SearchQuery::parse('   ')->isBlank());
    assert_same(12, count($w('a b c d e f g h i j k l m n')), 'at most 12 words');
});
