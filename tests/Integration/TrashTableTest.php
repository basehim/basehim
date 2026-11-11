<?php

declare(strict_types=1);

/*
 * 1.2.122 — The posts' and pages' trash on the Data Table.
 */

function tt_post(string $owner, bool $trashed = true, string $type = 'post', string $title = ''): int
{
    return (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => $type, 'status' => 'draft',
        'slug' => 'tt-' . bin2hex(random_bytes(4)), 'title' => $title !== '' ? $title : "tt {$owner} " . bin2hex(random_bytes(3)),
        'content' => '', 'content_format' => 'html',
        'deleted_at' => $trashed ? date('Y-m-d H:i:s', time() - random_int(60, 86400)) : null,
    ]);
}

function tt_form(\Browser $b, string $path, array $data): \HttpResponse
{
    return $b->request('POST', $path, http_build_query(['_csrf' => $b->csrf] + $data), ['Content-Type' => 'application/x-www-form-urlencoded']);
}

function tt_rows(\Browser $b, string $table, array $extra = []): array
{
    $r = $b->ajaxGet('content.table', ['table' => $table, 'per_page' => 100] + $extra);
    assert_same(200, $r->status, $r->body);
    return $r->json()['data']['rows'] ?? [];
}

test('trash table: lists only trashed items of its type, deleted newest first', function () {
    $live = tt_post('admin', false);
    $old = tt_post('admin');
    bh_db()->update('posts', ['deleted_at' => date('Y-m-d H:i:s', time() - 7 * 86400)], ['id' => $old]);
    $new = tt_post('admin');
    bh_db()->update('posts', ['deleted_at' => date('Y-m-d H:i:s', time() - 5)], ['id' => $new]);
    $page = tt_post('admin', true, 'page');

    $ids = array_column(tt_rows(bh_browser('admin'), 'content.posts.trash'), 'id');
    assert_true(in_array($new, $ids, true) && in_array($old, $ids, true));
    assert_false(in_array($live, $ids, true), 'a live post is not in the trash');
    assert_false(in_array($page, $ids, true), 'pages have their own trash');
    assert_true(array_search($new, $ids, true) < array_search($old, $ids, true), 'recently deleted first');
    assert_true(in_array($page, array_column(tt_rows(bh_browser('admin'), 'content.pages.trash'), 'id'), true));

    // Asking for the live list through a filter does not leave the trash.
    $ids2 = array_column(tt_rows(bh_browser('admin'), 'content.posts.trash', ['filters' => json_encode(['trashed' => '0'])]), 'id');
    assert_false(in_array($live, $ids2, true));
});

test('trash table: each row offers only what this user may do; the page shows the table', function () {
    $mine = tt_post('author');
    $theirs = tt_post('admin');
    $rows = [];
    foreach (tt_rows(bh_browser('author'), 'content.posts.trash') as $r) $rows[$r['id']] = $r['flags'] ?? [];
    assert_same(['restore', 'purge'], $rows[$mine] ?? null);
    assert_same([], $rows[$theirs] ?? null, 'nothing on someone else\'s item');
    $admin = [];
    foreach (tt_rows(bh_browser('admin'), 'content.posts.trash') as $r) $admin[$r['id']] = $r['flags'] ?? [];
    assert_same(['restore', 'purge'], $admin[$mine] ?? null);

    $html = bh_browser('admin')->request('GET', '/admin/posts?view=trash')->body;
    assert_contains('&quot;table&quot;:&quot;content.posts.trash&quot;', str_replace(['\u0022', '"'], '&quot;', $html));
    assert_contains('Empty trash', $html);
    assert_false(str_contains(bh_browser('author')->request('GET', '/admin/posts?view=trash')->body, 'Empty trash'), 'authors can\'t empty everyone\'s trash');
    assert_contains('&quot;table&quot;:&quot;content.pages.trash&quot;', str_replace(['\u0022', '"'], '&quot;', bh_browser('admin')->request('GET', '/admin/pages?view=trash')->body));
    assert_same(403, bh_browser('subscriber')->ajaxGet('content.table', ['table' => 'content.posts.trash'])->status);
});

test('trash actions: restore and delete permanently, for trashed items only', function () {
    $admin = bh_browser('admin');
    $a = tt_post('admin');
    $b = tt_post('admin');
    $live = tt_post('admin', false);
    $r = tt_form($admin, '/admin/posts/bulk', ['bulk_action' => 'restore', 'ids' => [$a]]);
    assert_same(302, $r->status);
    assert_same(null, bh_db()->selectOne('SELECT deleted_at FROM {posts} WHERE id = :i', ['i' => $a])['deleted_at']);
    tt_form($admin, '/admin/posts/bulk', ['bulk_action' => 'delete_forever', 'ids' => [$b, $live]]);
    assert_same(null, bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $b]));
    assert_true(bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $live]) !== null, 'a live post is never deleted from here');

    // An author can't restore or delete someone else's item.
    $theirs = tt_post('admin');
    tt_form(bh_browser('author'), '/admin/posts/bulk', ['bulk_action' => 'delete_forever', 'ids' => [$theirs]]);
    assert_true(bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $theirs]) !== null);
});

test('empty trash: needs delete_others; removes only this type', function () {
    $post = tt_post('admin');
    $page = tt_post('admin', true, 'page');
    tt_form(bh_browser('author'), '/admin/posts/empty-trash', []);
    assert_true(bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $post]) !== null, 'refused for an author');
    $r = tt_form(bh_browser('admin'), '/admin/posts/empty-trash', []);
    assert_same(302, $r->status);
    assert_contains('/admin/posts?view=trash', (string) ($r->headers['location'] ?? $r->headers['Location'] ?? ''));
    assert_same(null, bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $post]));
    assert_true(bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $page]) !== null, 'pages untouched');
});
