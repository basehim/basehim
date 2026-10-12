<?php

declare(strict_types=1);

use App\Services\Markdown;

/** An AI-written draft as CDIY stores them: Markdown under the 'html' format. */
function md_sample(): string
{
    return "**Introduction**\n\nIn this tutorial we blink an LED.\n\n**Steps**\n\n"
        . "1. Wire the LED.\n2. Write the code:\n```cpp\nvoid setup() {\n  pinMode(13, OUTPUT);\n}\n```\n3. Upload it.\n\n"
        . "**FAQ**\n\nQ: Why a resistor?\nA: To limit the current.\n\n"
        . "Prose that is hard-wrapped by an editor\nand continues on the next line.";
}

test('markdown: detection — Markdown stored as HTML is recognised', function () {
    assert_true(Markdown::looksLikeMarkdown(md_sample()));
    assert_true(Markdown::looksLikeMarkdown("# One\n\nText\n\n## Two"), 'two headings');
    assert_true(Markdown::looksLikeMarkdown("Intro\n\n```js\nx()\n```"), 'a code fence');
    assert_true(Markdown::looksLikeMarkdown("Use `<div>` and **this**\n\n- a\n- b"), 'HTML inside inline code does not count');
});

test('markdown: detection — HTML, block JSON and plain prose are not Markdown', function () {
    foreach ([
        '<p>**not markdown**</p>' . "\n\n- a\n- b",
        "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->",
        '{"version":1,"blocks":[]}',
        'Hello *world* and 2 * 3 = 6.',
        'Just **one** bold word.',
        '',
        "Line one<br>\n- a\n- b\n\n**x**",
    ] as $c) {
        assert_false(Markdown::looksLikeMarkdown($c), json_encode($c));
    }
});

test('markdown: for blocks — bold-only lines become headings, code splits out of the list item', function () {
    $html = Markdown::toHtml(md_sample(), Markdown::FOR_BLOCKS);
    assert_contains('<h2>Introduction</h2>', $html);
    assert_contains('<h2>FAQ</h2>', $html);
    assert_contains('<li>Write the code:' . "\n" . '<pre><code class="language-cpp">', $html);
    assert_contains('pinMode(13, OUTPUT);', $html);
    assert_contains('<li>Upload it.</li>', $html, 'the list continues after the code');
});

test('markdown: for blocks — deliberate line breaks kept, hard-wrapped prose joined', function () {
    $html = Markdown::toHtml(md_sample(), Markdown::FOR_BLOCKS);
    assert_contains("Q: Why a resistor?<br>\nA: To limit the current.", $html);
    assert_contains("Prose that is hard-wrapped by an editor\nand continues", $html);
});

test('markdown: bold headings sit one level below the document headings', function () {
    assert_contains('<h3>Parts</h3>', Markdown::toHtml("# Title\n\n## Section\n\n**Parts**\n\nText", Markdown::FOR_BLOCKS));
    assert_contains('<h2>Parts</h2>', Markdown::toHtml("**Parts**\n\nText", Markdown::FOR_BLOCKS));
    // Not headings: bold at the start of a sentence, or a full sentence in bold.
    $h = Markdown::toHtml("**Step 1:** wire it.\n\n**This is a whole sentence.**", Markdown::FOR_BLOCKS);
    assert_not_contains('<h2', $h);
});

test('markdown: public rendering keeps CommonMark rules (no bold headings, soft breaks joined)', function () {
    $html = Markdown::toHtml(md_sample());
    assert_contains('<p><strong>Introduction</strong></p>', $html);
    assert_contains("Q: Why a resistor?\nA: To limit", $html);
});

test('markdown: an ordered list keeps its first number', function () {
    assert_contains('<ol start="3"><li>Third</li><li>Fourth</li></ol>', Markdown::toHtml("3. Third\n4. Fourth"));
    assert_contains('<ol><li>One</li>', Markdown::toHtml("1. One\n2. Two"));
});
