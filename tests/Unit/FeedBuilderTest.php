<?php

declare(strict_types=1);

use App\Services\FeedBuilder;

/*
 * 1.2.88: the RSS feed. Items carry rendered HTML with absolute URLs and an
 * image (media:content, enclosure, lead <img>) that services such as
 * Pinterest can use.
 */

function fb_post(array $o = []): array
{
    return $o + [
        'id' => 7, 'title' => 'Fire Alarm & Circuit', 'slug' => 'fire-alarm', 'type' => 'post',
        'published_at' => '2026-08-06 07:46:55', 'created_at' => '2026-08-06 07:40:19',
        'excerpt' => 'A heat-activated alarm.', 'author_name' => 'Asif',
        'featured_url' => '/uploads/2026/08/alarm.jpg', 'featured_alt' => 'The alarm on a breadboard',
        'featured_width' => 1200, 'featured_height' => 800,
    ];
}

function fb_doc(FeedBuilder $b, array $items): SimpleXMLElement
{
    $xml = $b->document([
        'title' => 'Electronics360', 'link' => 'https://example.com/', 'description' => 'Projects & tutorials',
        'language' => 'en_US', 'self' => 'https://example.com/feed', 'updated' => '2026-08-06 07:46:55',
    ], $items);
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    assert_true($doc !== false && $errors === [], 'the feed is well-formed XML: ' . json_encode(array_map(fn($e) => trim($e->message), $errors)));
    return $doc;
}

test('feed: an item with a featured image has media:content, an enclosure and a lead image, all absolute', function () {
    $b = new FeedBuilder('https://example.com', fn(string $u) => $u === 'https://example.com/uploads/2026/08/alarm.jpg' ? 48213 : 0);
    $doc = fb_doc($b, [$b->item(fb_post(), '<p>Body</p>', 'https://example.com/projects/fire-alarm', ['Projects', 'Sensors'])]);
    $item = $doc->channel->item[0];
    $media = $item->children('http://search.yahoo.com/mrss/');
    assert_same('https://example.com/uploads/2026/08/alarm.jpg', (string) $media->content->attributes()->url);
    assert_same('image', (string) $media->content->attributes()->medium);
    assert_same('image/jpeg', (string) $media->content->attributes()->type);
    assert_same('1200', (string) $media->content->attributes()->width);
    assert_same('https://example.com/uploads/2026/08/alarm.jpg', (string) $media->thumbnail->attributes()->url);
    assert_same('https://example.com/uploads/2026/08/alarm.jpg', (string) $item->enclosure->attributes()->url);
    assert_same('48213', (string) $item->enclosure->attributes()->length);
    assert_same('image/jpeg', (string) $item->enclosure->attributes()->type);
    $desc = (string) $item->description;
    assert_true(str_starts_with($desc, '<p><img src="https://example.com/uploads/2026/08/alarm.jpg"'), 'description leads with the image');
    assert_true(str_contains($desc, 'alt="The alarm on a breadboard"'));
    assert_true(str_contains($desc, 'A heat-activated alarm.'));
    assert_same(['Projects', 'Sensors'], array_map('strval', iterator_to_array($item->category, false)));
});

test('feed: the author is dc:creator (RSS <author> is for an email address)', function () {
    $b = new FeedBuilder('https://example.com');
    $doc = fb_doc($b, [$b->item(fb_post(), '<p>x</p>', 'https://example.com/p')]);
    $item = $doc->channel->item[0];
    assert_same('Asif', (string) $item->children('http://purl.org/dc/elements/1.1/')->creator);
    assert_same(0, count($item->author));
});

test('feed: without a featured image, the first raster image in the content is used; SVG and data: are skipped', function () {
    $b = new FeedBuilder('https://example.com');
    $html = '<p>Intro</p><img src="data:image/png;base64,AAAA"><img src="/uploads/d.svg">'
          . '<figure><img src="/uploads/2026/05/board.png" width="900" height="600" alt="Board"></figure><img src="/uploads/later.jpg">';
    $doc = fb_doc($b, [$b->item(fb_post(['featured_url' => null]), $html, 'https://example.com/p')]);
    $media = $doc->channel->item[0]->children('http://search.yahoo.com/mrss/');
    assert_same('https://example.com/uploads/2026/05/board.png', (string) $media->content->attributes()->url);
    assert_same('image/png', (string) $media->content->attributes()->type);
    assert_same('900', (string) $media->content->attributes()->width);

    // No image at all: no media elements, still valid.
    $doc = fb_doc($b, [$b->item(fb_post(['featured_url' => null]), '<p>Text only</p><svg><circle/></svg>', 'https://example.com/p')]);
    $item = $doc->channel->item[0];
    assert_same(0, count($item->enclosure));
    assert_same(0, count($item->children('http://search.yahoo.com/mrss/')->content));
});

test('feed: every src, href, srcset and poster in the content becomes absolute', function () {
    $b = new FeedBuilder('https://example.com');
    $html = $b->absolutizeHtml(
        '<a href="/projects/x">x</a> <img src="/uploads/a.jpg" srcset="/uploads/a-300.jpg 300w, /uploads/a-1024.jpg 1024w">'
        . '<video poster="/uploads/v.jpg"></video> <a href="https://other.example/">o</a> <a href="#top">t</a>'
        . ' <a href="mailto:me@example.com">m</a> <img src="//cdn.example/i.png"> <img src="uploads/rel.jpg">'
    );
    assert_true(str_contains($html, 'href="https://example.com/projects/x"'));
    assert_true(str_contains($html, 'src="https://example.com/uploads/a.jpg"'));
    assert_true(str_contains($html, 'srcset="https://example.com/uploads/a-300.jpg 300w, https://example.com/uploads/a-1024.jpg 1024w"'));
    assert_true(str_contains($html, 'poster="https://example.com/uploads/v.jpg"'));
    assert_true(str_contains($html, 'href="https://other.example/"'), 'absolute URLs untouched');
    assert_true(str_contains($html, 'href="#top"') && str_contains($html, 'href="mailto:me@example.com"'));
    assert_true(str_contains($html, 'src="https://cdn.example/i.png"'), 'protocol-relative takes the site scheme');
    assert_true(str_contains($html, 'src="https://example.com/uploads/rel.jpg"'));
});

test('feed: a block-editor post is rendered to HTML, not printed as JSON', function () {
    $json = json_encode(['version' => 1, 'blocks' => [
        ['id' => 'a', 'type' => 'paragraph', 'data' => ['text' => 'A fire alarm is a good first project.']],
        ['id' => 'b', 'type' => 'image', 'data' => ['url' => '/uploads/2026/08/alarm-photo.jpg', 'alt' => 'Finished alarm']],
    ]]);
    $html = \App\Services\BlockRenderer::render($json, new \App\Core\HookRegistry());
    $b = new FeedBuilder('https://example.com');
    $doc = fb_doc($b, [$b->item(fb_post(['featured_url' => null, 'content' => $json]), $html, 'https://example.com/p')]);
    $item = $doc->channel->item[0];
    $encoded = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
    assert_true(!str_contains($encoded, '"blocks"'), 'no block JSON in the feed');
    assert_true(str_contains($encoded, 'A fire alarm is a good first project.'));
    assert_true(str_contains($encoded, 'src="https://example.com/uploads/2026/08/alarm-photo.jpg"'));
    $media = $item->children('http://search.yahoo.com/mrss/');
    assert_same('https://example.com/uploads/2026/08/alarm-photo.jpg', (string) $media->content->attributes()->url);
});

test('feed: "]]>" in content, control characters and block markers cannot break the XML', function () {
    $b = new FeedBuilder('https://example.com');
    $html = "<!-- wp:paragraph --><p>Array access: \$a[\$b[1]]> 0 and a bell\x07 here</p><!-- /wp:paragraph -->";
    $doc = fb_doc($b, [$b->item(fb_post(['title' => "Tom & Jerry <3 \x0B"]), $html, 'https://example.com/p?a=1&b=2')]);
    $item = $doc->channel->item[0];
    $encoded = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
    assert_true(str_contains($encoded, '$a[$b[1]]> 0'), 'the text survives intact');
    assert_true(!str_contains($encoded, 'wp:paragraph'), 'editor markers removed');
    assert_same('Tom & Jerry <3 ', (string) $item->title);
    assert_same('https://example.com/p?a=1&b=2', (string) $item->link);
});

test('feed: dates are RFC 822 in UTC, language is lower-case with a hyphen, the channel has lastBuildDate', function () {
    $b = new FeedBuilder('https://example.com');
    $doc = fb_doc($b, [$b->item(fb_post(), '<p>x</p>', 'https://example.com/p')]);
    assert_same('Thu, 06 Aug 2026 07:46:55 +0000', (string) $doc->channel->item[0]->pubDate);
    assert_same('en-us', (string) $doc->channel->language);
    assert_same('Thu, 06 Aug 2026 07:46:55 +0000', (string) $doc->channel->lastBuildDate);
    assert_same('ur-pk', FeedBuilder::language('ur_PK'));
    assert_same('en-us', FeedBuilder::language('not a language!'));
});

test('feed: the summary is the excerpt, or the start of the text without scripts and SVG', function () {
    assert_same('Short excerpt', FeedBuilder::summary('Short excerpt', '<p>ignored</p>'));
    $long = '<p>' . str_repeat('word ', 120) . '</p><script>alert(1)</script>';
    $s = FeedBuilder::summary('', $long);
    assert_true(mb_strlen($s) <= 301 && str_ends_with($s, '…') && !str_contains($s, 'alert'));
    assert_same('Fish & chips', FeedBuilder::summary('', '<svg><text>diagram</text></svg><p>Fish &amp; chips</p>'));
});

test('feed: image types are raster formats only', function () {
    assert_same('image/jpeg', FeedBuilder::imageType('/uploads/a.JPG?v=2'));
    assert_same('image/webp', FeedBuilder::imageType('https://x.example/a.webp'));
    assert_same(null, FeedBuilder::imageType('/uploads/a.svg'));
    assert_same(null, FeedBuilder::imageType('/uploads/a'));
});
