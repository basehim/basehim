<?php

declare(strict_types=1);

/*
 * 1.2.92 — BH Search in-process: query syntax, PHP matching, snippets, text
 * extraction, LIKE conditions and FULLTEXT expressions.
 */

use App\Core\Search\LikeMatcher;
use App\Core\Search\SearchQuery;
use App\Core\Search\SearchSource;
use App\Core\Search\TextExtractor;

test('bh search: query syntax — words, "phrases", -exclusions', function () {
    $q = SearchQuery::parse('digitalocean "per-second billing" -hetzner Pricing');
    assert_same(['digitalocean', 'pricing'], $q->terms());
    assert_same([['per', 'second', 'billing']], $q->phrases());
    assert_same(['hetzner'], $q->excluded());
    assert_same(['digitalocean', 'pricing', 'per', 'second', 'billing'], $q->words());
    assert_same(['one'], SearchQuery::parse('"one"')->terms(), 'a one-word phrase is a word');
    assert_same(['x'], SearchQuery::parse('-"x" y')->excluded());
    assert_true(SearchQuery::parse('-only')->matchesNothing(), 'exclusions alone match nothing');
    assert_true(SearchQuery::parse('"" -- %%')->matchesNothing());
    assert_same(['a'], SearchQuery::parse("a\x00\x1F")->terms(), 'control characters dropped');
    assert_true(mb_strlen(SearchQuery::parse(str_repeat('ab ', 200))->raw()) <= SearchQuery::MAX_LENGTH, 'length capped');
    assert_same([], SearchQuery::parse("\xff\xfe")->terms(), 'invalid UTF-8 is cleaned, not fatal');
});

test('bh search: matching in PHP (in-memory sources)', function () {
    $q = SearchQuery::parse('public tools -beta');
    assert_true($q->matches('Public Tools: free calculators'));
    assert_true($q->matches('Tools for the public'), 'any order');
    assert_false($q->matches('Public Tools (beta)'), 'excluded');
    assert_false($q->matches('Developer Tools'));
    assert_true(SearchQuery::parse('dev')->matches('Developer Tools'), 'inside words');
    assert_true(SearchQuery::parse('"developer tools"')->matches('Basehim developer-tools app'), 'phrase across a hyphen');
    assert_false(SearchQuery::parse('"tools developer"')->matches('Basehim developer tools'), 'phrase order');
    assert_true(SearchQuery::parse('')->matches('anything'));
    assert_true(SearchQuery::parse('lahore')->matches('لاہور Lahore'));
});

test('bh search: snippets and highlights are escaped HTML', function () {
    $q = SearchQuery::parse('roses <b>');
    $h = $q->highlight('Red <script>roses</script> & Roses');
    assert_same('Red &lt;script&gt;<mark>roses</mark>&lt;/script&gt; &amp; <mark>Roses</mark>', $h);
    $long = str_repeat('filler words here. ', 30) . 'The roses bloom in June. ' . str_repeat('more text. ', 30);
    $s = $q->snippet($long, 80);
    assert_contains('<mark>roses</mark>', $s);
    assert_true(str_starts_with($s, '… ') && str_ends_with($s, ' …'), $s);
    assert_same('Plain', SearchQuery::parse('zzz')->snippet('Plain'));
});

test('bh search: stemming only shortens long ASCII words', function () {
    assert_same('pric', SearchQuery::stem('pricing'));
    assert_same('publish', SearchQuery::stem('published'));
    assert_same('news', SearchQuery::stem('news'), 'short words stay');
    assert_same('cats', SearchQuery::stem('cats'));
    assert_same('خبریں', SearchQuery::stem('خبریں'), 'other scripts stay');
});

test('bh search: text from HTML, Markdown and blocks, without running block code', function () {
    assert_same("Title\nHello & welcome", TextExtractor::html('<h2>Title</h2><p>Hello &amp; welcome</p><script>evil()</script>'));
    assert_same("Heading\nSome bold and a link", TextExtractor::fromContent("## Heading\n\nSome **bold** and [a link](https://x.test)", 'markdown'));
    $blocks = json_encode(['blocks' => [
        ['type' => 'heading', 'data' => ['text' => 'Block <b>title</b>', 'level' => 2]],
        ['type' => 'image', 'data' => ['url' => 'https://x.test/a.png', 'caption' => 'A caption']],
        ['type' => 'list', 'data' => ['items' => ['One', 'Two']]],
    ]]);
    assert_same("Block title\nA caption\nOne\nTwo", TextExtractor::fromContent($blocks, 'blocks'));
    assert_same('snipaste 2026 10 27 png', TextExtractor::words('Snipaste_2026-10-27.png'));
    assert_same('digitalocean droplet digital ocean droplet', TextExtractor::words('DigitalOcean Droplet'));
    assert_same(10, mb_strlen(TextExtractor::html(str_repeat('é', 50), 10)));
});

test('bh search: LIKE conditions use one placeholder per use and escape wildcards', function () {
    $p = [];
    $sql = LikeMatcher::condition(SearchQuery::parse('100 "a b" -c'), ['t.x', 't.y'], $p);
    assert_same(6, substr_count($sql, ' LIKE :'), $sql);
    assert_same(count($p), count(array_unique(array_keys($p))));
    assert_contains('NOT (COALESCE(t.x', $sql, 'exclusions treat NULL as empty');
    assert_same(['%100%', '%100%', '%a%b%', '%a%b%', '%c%', '%c%'], array_values($p));
    $p = [];
    assert_same('1=0', LikeMatcher::condition(SearchQuery::parse('%'), ['x'], $p));
    assert_same('1=1', LikeMatcher::condition(SearchQuery::parse(''), ['x'], $p));
    $p = [];
    LikeMatcher::contains('50%_', ['x'], $p);
    assert_same(['%50\\%\\_%'], array_values($p), 'a literal % or _ in a word never acts as a wildcard');
});

test('bh search: source definitions are checked', function () {
    assert_throws(\InvalidArgumentException::class, fn() => new SearchSource('Bad Id', ['documents' => fn() => []]));
    assert_throws(\InvalidArgumentException::class, fn() => new SearchSource('x', []), 'documents');
    assert_throws(\InvalidArgumentException::class, fn() => new SearchSource('x', ['documents' => 'nope']));
    $s = new SearchSource('shop.items', ['documents' => fn(int $a, int $l) => [['id' => $a + 1]], 'columns' => ['name', 'bad col']]);
    assert_true($s->indexed());
    assert_same(['id' => 6], $s->document(6), 'document() from documents()');
    $p = [];
    $cond = $s->fallbackCondition(SearchQuery::parse('x'), 'i', $p, []);
    assert_contains('i.name LIKE :', $cond);
    assert_not_contains('bad', $cond, 'only safe column names');
});

test('bh search: FULLTEXT expressions require every word and leave short words and stopwords to LIKE', function () {
    $db = (new \ReflectionClass(\App\Core\Database::class))->newInstanceWithoutConstructor();
    $engine = new \App\Core\Search\Engine\FulltextEngine($db, null);
    $r = new \ReflectionProperty($engine, 'config');
    $r->setAccessible(true);
    $r->setValue($engine, ['min' => 3, 'stopwords' => ['the' => true]]);
    $m = new \ReflectionMethod($engine, 'match');
    $m->setAccessible(true);
    $p = []; $sp = [];
    [$cond, $score] = $m->invokeArgs($engine, [SearchQuery::parse('the ai pricing -hetzner'), &$p, &$sp, false]);
    assert_contains("AGAINST('+pricing* -hetzner*' IN BOOLEAN MODE)", $cond);
    assert_same("MATCH(si.title, si.body, si.keywords) AGAINST('+pricing* -hetzner*' IN BOOLEAN MODE)", $score, 'the same expression, evaluated once');
    assert_true(in_array('%the%', $p, true) && in_array('%ai%', $p, true), 'short word and stopword by LIKE');
    assert_same([], $sp);
    $p = []; $sp = [];
    [, $score] = $m->invokeArgs($engine, [SearchQuery::parse('pricing'), &$p, &$sp, true]);
    assert_contains("'+pric*'", $score, 'stemmed for site search');
    $p = []; $sp = [];
    [$cond, $score] = $m->invokeArgs($engine, [SearchQuery::parse('ai go'), &$p, &$sp, false]);
    assert_same('1', $score, 'only short words: LIKE alone');
    assert_not_contains('MATCH', $cond);
});
