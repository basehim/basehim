<?php

declare(strict_types=1);

/*
 * 1.2.51: a commenter's website is stored only as an http(s) link (themes put
 * it in href=""; a javascript: link ran on the site when clicked), and a
 * failed comment post redirects only within the site.
 */

function open_post_for_comments(): array
{
    $slug = 'comments-' . bin2hex(random_bytes(4));
    $id = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => $slug, 'title' => 'Comments here', 'content' => '<p>x</p>', 'content_format' => 'html',
        'comment_status' => 'open', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    return [$id, $slug];
}

function guest_comment(array $fields): array
{
    [$id, $slug] = open_post_for_comments();
    $b = new Browser(bh_env()['base_url']);
    $page = $b->request('GET', '/posts/' . $slug);
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    $res = $b->request('POST', '/comments', http_build_query($fields + [
        '_csrf' => html_entity_decode($m[1] ?? ''), 'post_id' => $id, 'content' => 'Nice post ' . bin2hex(random_bytes(3)),
        'author_name' => 'Visitor', 'author_email' => 'visitor@example.com',
    ]), ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json']);
    $row = bh_db()->selectOne('SELECT author_url FROM {comments} WHERE post_id = :p ORDER BY id DESC LIMIT 1', ['p' => $id]);
    return [$res, $row];
}

test('comments: a javascript: website posted through the form is not stored', function () {
    [$res, $row] = guest_comment(['author_url' => 'javascript:alert(document.cookie)']);
    assert_same(201, $res->status, $res->body);
    assert_same(null, $row['author_url']);
});

test('comments: only http(s) websites are stored (CommentService::create)', function () {
    [$id] = open_post_for_comments();
    $svc = \App\Core\Application::getInstance()->make(\App\Services\CommentService::class);
    $stored = function (mixed $url) use ($svc, $id): ?string {
        $cid = $svc->create(['post_id' => $id, 'author_name' => 'V', 'author_email' => 'v@example.com',
                             'author_url' => $url, 'content' => 'c', 'status' => 'pending']);
        return bh_db()->selectOne('SELECT author_url FROM {comments} WHERE id = :i', ['i' => $cid])['author_url'];
    };
    foreach ([' JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>', 'vbscript:x', 'javascript://example.com/%0Aalert(1)'] as $bad) {
        assert_same(null, $stored($bad), 'stored for ' . json_encode($bad));
    }
    assert_same('https://example.com/me', $stored('https://example.com/me'));
    assert_same('https://example.com/me', $stored('example.com/me'), 'a bare domain gets https://');
});

test('comments: CommentService::safeAuthorUrl', function () {
    assert_same(null, \App\Services\CommentService::safeAuthorUrl(['x']));
    assert_same(null, \App\Services\CommentService::safeAuthorUrl('http://'));
    assert_same(null, \App\Services\CommentService::safeAuthorUrl('https://' . str_repeat('a', 300) . '.com'));
    assert_same('http://example.com', \App\Services\CommentService::safeAuthorUrl('http://example.com'));
});

test('comments: a failed post redirects only within the site', function () {
    $b = new Browser(bh_env()['base_url']);
    foreach (['https://evil.example/phish', '//evil.example', '/\\evil.example', "/ok\r\nX: y"] as $target) {
        $res = $b->request('POST', '/comments', http_build_query(['redirect_to' => $target, 'post_id' => 1, 'content' => 'x']),
            ['Content-Type' => 'application/x-www-form-urlencoded']);
        assert_same(302, $res->status);
        assert_same('/', (string) $res->header('location'), 'for ' . json_encode($target));
    }
    $res = $b->request('POST', '/comments', http_build_query(['redirect_to' => '/posts/hello#comments', 'post_id' => 1]),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same('/posts/hello#comments', (string) $res->header('location'));
});
