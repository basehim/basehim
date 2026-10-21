<?php

declare(strict_types=1);

/*
 * 1.2.49: reading a post is not editing it. posts.updated_at is
 * ON UPDATE CURRENT_TIMESTAMP, and the view counter used to bump it on every
 * page view, so a migrated site's modified dates (article:modified_time,
 * dateModified, sitemap lastmod) became "today" for every visited post.
 */

test('dates: a page view counts, but does not change the modified date', function () {
    $db = bh_db();
    $slug = 'dates-' . bin2hex(random_bytes(4));
    $id = (int) $db->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => $slug, 'title' => 'Dates', 'content' => '<p>x</p>', 'content_format' => 'html',
        'published_at' => '2019-04-27 04:11:28', 'created_at' => '2019-04-27 04:11:28', 'updated_at' => '2020-01-02 03:04:05',
        'view_count' => 41,
    ]);
    $page = (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . $slug);
    assert_same(200, $page->status);
    // Views are buffered since 1.2.76 and added up by the core task.
    $counter = new \App\Services\PostViewCounter($db, new \App\Services\SettingService($db, new \App\Core\Cache(bh_env()['tmp'] . '/dates-cache')));
    $counter->fold(time() + 180);
    $row = $db->selectOne('SELECT view_count, updated_at, created_at FROM {posts} WHERE id = :i', ['i' => $id]);
    assert_same(42, (int) $row['view_count'], 'the view was counted');
    assert_same('2020-01-02 03:04:05', $row['updated_at'], 'modified date untouched');
    assert_same('2019-04-27 04:11:28', $row['created_at']);
});

test('dates: incrementViewCount leaves updated_at alone (repository level)', function () {
    $db = bh_db();
    $id = (int) $db->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'dates-' . bin2hex(random_bytes(4)), 'title' => 'Dates 2', 'content' => '', 'content_format' => 'html',
        'updated_at' => '2021-05-06 07:08:09',
    ]);
    (new \App\Repositories\PostRepository($db))->incrementViewCount($id);
    assert_same('2021-05-06 07:08:09', $db->selectOne('SELECT updated_at FROM {posts} WHERE id = :i', ['i' => $id])['updated_at']);
});
