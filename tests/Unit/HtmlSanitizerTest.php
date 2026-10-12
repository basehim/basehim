<?php

declare(strict_types=1);

use App\Services\HtmlSanitizer;
use App\Services\Markdown;
use App\Services\PostHtmlFilter;

/*
 * 1.2.52: HTML from writers without unfiltered_html is filtered on save.
 * HtmlSanitizer is the allow-list; PostHtmlFilter applies it per format.
 */

function hs(string $html): string
{
    return HtmlSanitizer::clean($html)['html'];
}

test('html filter: ordinary content survives unchanged', function () {
    $keep = [
        '<p>Hello <strong>world</strong>, <em>café</em> — اردو</p>',
        '<h2 id="parts">Parts</h2><ul><li><a href="https://example.com/x?a=1&amp;b=2">link</a></li><li><a href="/posts/y">local</a></li></ul>',
        '<figure class="wp-block-image"><img src="/uploads/a.jpg" alt="A" width="10" loading="lazy"><figcaption>Cap</figcaption></figure>',
        '<table><thead><tr><th>Pin</th></tr></thead><tbody><tr><td colspan="2">5V</td></tr></tbody></table>',
        '<pre><code class="language-cpp">#include &lt;Wire.h&gt;</code></pre>',
        '<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315" allowfullscreen></iframe>',
        '<p style="color: red; text-align: center">x</p>',
        '<a href="mailto:me@example.com">mail</a> <a href="tel:+92300">call</a> <a href="#top">top</a>',
        '<details><summary>More</summary><p>Hidden</p></details>',
        '<img src="data:image/png;base64,iVBORw0KGgo=" alt="">',
    ];
    foreach ($keep as $html) {
        $r = HtmlSanitizer::clean($html);
        assert_same([], $r['removed'], 'nothing reported for ' . $html);
        assert_same($html, $r['html']);
    }
});

test('html filter: script, handlers, unsafe links and frames are removed', function () {
    $cases = [
        '<p>hi</p><script>alert(1)</script>'                          => '<p>hi</p>',
        '<img src="x" onerror="alert(1)">'                            => '<img src="x">',
        '<img/src=x/onerror=alert(1)>'                                => '<img>',
        '<a href="javascript:alert(1)">x</a>'                         => '<a>x</a>',
        '<a href="java&Tab;script:alert(1)">x</a>'                    => '<a>x</a>',
        '<a href="&#106avascript:alert(1)">x</a>'                     => '<a>x</a>',
        '<a href=" JaVaScRiPt:alert(1)">x</a>'                        => '<a>x</a>',
        '<a href="data:text/html,x">x</a>'                            => '<a>x</a>',
        '<iframe src="https://evil.example/"></iframe><p>a</p>'        => '<p>a</p>',
        '<iframe src="https://www.youtube.com.evil.example/embed/x"></iframe>' => '',
        '<iframe src="https://www.youtube.com/embed/x" srcdoc="&lt;script&gt;"></iframe>' => '<iframe src="https://www.youtube.com/embed/x"></iframe>',
        '<svg><script>alert(1)</script></svg>'                        => '',
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>' => '',
        '<form action="https://evil.example"><input type="password"><button>Go</button>text</form>' => 'text',
        '<style>body{display:none}</style><p>x</p>'                   => '<p>x</p>',
        '<object data="x.swf"></object><embed src="x.swf">'           => '',
        '<div style="position: fixed; inset: 0">x</div>'              => '<div style="inset: 0">x</div>',
        '<div style="background:url(javascript:alert(1))">x</div>'    => '<div>x</div>',
        '<div data-bh-action="delete" data-x="1">x</div>'             => '<div data-x="1">x</div>',
        '<p>a<!-- <img src=x onerror=alert(1)> --></p>'               => '<p>a</p>',
        '<body onload="alert(1)"><p>x</p></body>'                     => '<p>x</p>',
        '<a href="https://example.com" target="_blank">x</a>'         => '<a href="https://example.com" target="_blank" rel="noopener noreferrer">x</a>',
        '<font color="red">kept</font>'                               => 'kept',
    ];
    foreach ($cases as $in => $want) {
        assert_same($want, hs($in), 'for ' . $in);
    }
    $r = HtmlSanitizer::clean('<p onclick="x()">a</p><script>y()</script><a href="javascript:z()">b</a>');
    assert_contains('scripts', implode('|', $r['removed']));
    assert_contains('event handlers', implode('|', $r['removed']));
    assert_contains('unsafe addresses', implode('|', $r['removed']));
});

test('html filter: the result is stable (filtering it again changes nothing)', function () {
    foreach (['<p>a</div>b<p>c', '<table><tr><td>x<p>y</table>', '<a href="x"><div>y</div></a>', 'a < b && c'] as $in) {
        $once = hs($in);
        assert_same($once, hs($once), 'for ' . $in);
    }
});

test('html filter: iframe hosts can be extended', function () {
    $html = '<iframe src="https://player.example.org/v/1"></iframe>';
    assert_same('', hs($html));
    assert_same($html, HtmlSanitizer::clean($html, ['player.example.org'])['html']);
    assert_same('', HtmlSanitizer::clean('<iframe src="https://player.example.org.evil.example/v/1"></iframe>', ['player.example.org'])['html']);
    assert_same('', HtmlSanitizer::clean('<iframe src="http://player.example.org/v/1"></iframe>', ['player.example.org'])['html'], 'https only');
});

test('html filter: isSimpleMarkup accepts only the unambiguous subset', function () {
    foreach (['<br>', '<br/>', '<details>', '</details>', '<summary>Click</summary>', '<div align="center">',
              '<img src="https://example.com/x.png" alt="x" width="300">', '<p>caf&eacute; &amp; more</p>',
              '<span style="color:red">r</span>', '<sup>2</sup>', 'plain text'] as $ok) {
        assert_true(HtmlSanitizer::isSimpleMarkup($ok), $ok . ' should be simple');
    }
    foreach (['<script>', '<a href="javascript:x">', '<img src=x onerror=y>', '<img alt=">" onerror="x">',
              '<a href="x&#106;">', '<a href=\'x\'>', '<img src="x" onerror="y">', '<iframe src="https://evil.example">',
              '<a href="https://x" target="_blank">', '<style>', '<!-- x -->', '<form>', '<p title="a<b">'] as $bad) {
        assert_false(HtmlSanitizer::isSimpleMarkup($bad), $bad . ' should not be simple');
    }
});

test('post filter: Markdown is kept as written unless its raw HTML is unsafe', function () {
    $md = "# Title\n\nSome **bold** text and a [link](https://example.com).\n\n```cpp\n#include <Wire.h>\n<script>not run</script>\n```\n\nLine<br>break\n\n<details>\n<summary>More</summary>\n\nHidden\n\n</details>\n";
    $r = PostHtmlFilter::filter($md, 'markdown');
    assert_same($md, $r['content'], 'code samples and simple tags keep the Markdown as it is');
    assert_same(null, $r['format']);

    $bad = "# Title\n\nText <img src=x onerror=alert(1)> more\n\n<script>alert(1)</script>\n";
    $r = PostHtmlFilter::filter($bad, 'markdown');
    assert_same('html', $r['format'], 'unsafe raw HTML: saved as filtered HTML');
    assert_true($r['converted']);
    assert_not_contains('onerror', $r['content']);
    assert_not_contains('<script', $r['content']);
    assert_contains('<h1>Title</h1>', $r['content']);
});

test('post filter: Markdown links cannot hide javascript: behind a tab or an entity', function () {
    foreach (["[x](java\tscript:alert(1))", '[x](java&#9;script:alert(1))', '[x](&#106;avascript:alert(1))', '[x](JAVASCRIPT:alert(1))'] as $md) {
        // Every href as a browser reads it: entities decoded once, controls dropped.
        preg_match_all('/href="([^"]*)"/', Markdown::toHtml($md), $m);
        foreach ($m[1] as $href) {
            $seen = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($href, ENT_QUOTES | ENT_HTML5)));
            assert_false(str_starts_with($seen, 'javascript:'), 'for ' . json_encode($md) . ': ' . $href);
        }
    }
});

test('post filter: HTML blocks inside block JSON are filtered, the rest is left alone', function () {
    $doc = ['version' => 1, 'blocks' => [
        ['type' => 'paragraph', 'data' => ['text' => 'Hello <b>there</b>']],
        ['type' => 'html', 'data' => ['html' => '<p>ok</p><script>alert(1)</script>']],
        ['type' => 'columns', 'data' => [], 'innerBlocks' => [
            ['type' => 'html', 'data' => ['html' => '<img src="x" onerror="alert(1)">']],
        ]],
    ]];
    $r = PostHtmlFilter::filter(json_encode($doc), 'blocks');
    $out = json_decode($r['content'], true);
    assert_same('<p>ok</p>', $out['blocks'][1]['data']['html']);
    assert_same('<img src="x">', $out['blocks'][2]['innerBlocks'][0]['data']['html']);
    assert_same('Hello <b>there</b>', $out['blocks'][0]['data']['text']);

    $clean = json_encode(['version' => 1, 'blocks' => [['type' => 'html', 'data' => ['html' => '<p>ok</p>']]]]);
    assert_same($clean, PostHtmlFilter::filter($clean, 'blocks')['content'], 'safe blocks are not rewritten');
});

test('post filter: plain HTML is filtered; plain text is untouched', function () {
    $r = PostHtmlFilter::filter('<p>x</p><script>y</script>', 'html');
    assert_same('<p>x</p>', $r['content']);
    assert_same('Just words, no markup', PostHtmlFilter::filter('Just words, no markup', 'html')['content']);
});
