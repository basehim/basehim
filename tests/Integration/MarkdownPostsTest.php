<?php

declare(strict_types=1);

/*
 * 1.2.48: Markdown posts — including Markdown stored under the default 'html'
 * format, as AI tools and the API often leave it — render as HTML on the site
 * and open in the visual editor as blocks.
 */

function md_post(string $content, string $format, string $status = 'published'): array
{
    $slug = 'md-' . bin2hex(random_bytes(4));
    $id = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => $status,
        'slug' => $slug, 'title' => 'Markdown ' . $slug, 'content' => $content, 'content_format' => $format,
        'published_at' => gmdate('Y-m-d H:i:s', time() - 60),
    ]);
    return [$id, $slug];
}

const MD_BODY = "**Introduction**\n\nBlink an LED.\n\n* Arduino Uno\n* LED\n\n```cpp\nvoid setup() {}\n```\n";

test('markdown: a Markdown post stored as html is rendered as HTML on the public site', function () {
    [, $slug] = md_post(MD_BODY, 'html');
    $page = (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . $slug);
    assert_same(200, $page->status, substr($page->body, 0, 300));
    assert_contains('<li>Arduino Uno</li>', $page->body);
    assert_contains('<code class="language-cpp">', $page->body);
    assert_not_contains('* Arduino Uno', $page->body);
    assert_not_contains('**Introduction**', $page->body);
});

test('markdown: real HTML posts are left exactly as they are', function () {
    $html = "<p>Price: 2 * 3 = **6**</p>\n<ul><li>a</li></ul>";
    [, $slug] = md_post($html, 'html');
    $page = (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . $slug);
    assert_contains('Price: 2 * 3 = **6**', $page->body);
});

test('markdown: the editor opens Markdown (stored as html or markdown) with the blocks conversion ready', function () {
    $admin = bh_browser('admin');
    foreach (['html' => true, 'markdown' => false] as $format => $sniffed) {
        [$id] = md_post(MD_BODY, $format, 'draft');
        $page = $admin->request('GET', "/admin/posts/{$id}/edit");
        assert_same(200, $page->status);
        if (!preg_match('/window\.BasehimEditorConfig = (\{.*?\});<\/script>/s', $page->body, $m)) fail('no editor config');
        $cfg = json_decode($m[1], true);
        assert_true(isset($cfg['markdown']['html']), "conversion supplied ({$format})");
        assert_same($sniffed, $cfg['markdown']['sniffed']);
        assert_contains('<h2>Introduction</h2>', $cfg['markdown']['html']);
        assert_contains('<option value="markdown" selected', $page->body, 'the source is labelled Markdown');
        // The stored post is untouched until someone saves.
        assert_same($format, bh_db()->selectOne('SELECT content_format FROM {posts} WHERE id = :i', ['i' => $id])['content_format']);
    }
    // An HTML post gets nothing of the sort.
    [$hid] = md_post('<p>Hello</p>', 'html', 'draft');
    $page = $admin->request('GET', "/admin/posts/{$hid}/edit");
    assert_not_contains('"markdown":{', $page->body);
});

test('markdown: posts.render converts for blocks only when asked', function () {
    $b = bh_browser('author');
    $j = $b->ajax('posts.render', ['from' => 'markdown', 'content' => "**Parts**\n\nText", 'for' => 'blocks'])->json();
    assert_contains('<h2>Parts</h2>', $j['data']['html']);
    $j = $b->ajax('posts.render', ['from' => 'markdown', 'content' => "**Parts**\n\nText"])->json();
    assert_contains('<strong>Parts</strong>', $j['data']['html']);
});
