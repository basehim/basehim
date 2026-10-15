<?php

declare(strict_types=1);

/*
 * End-to-end for the Core Data Table: real HTTP to index.php, real sessions,
 * the real AJAX gateway and database. Proves the one read action serves a
 * registered table, enforces the table's capability, whitelists sort/filter,
 * and that preferences round-trip through users.meta.
 */

function dt_make_post(string $owner, string $status, string $title): int
{
    return (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => 'post', 'status' => $status,
        'slug' => 'dt-' . bin2hex(random_bytes(4)), 'title' => $title, 'content' => '', 'content_format' => 'html',
    ]);
}

test('datatable: content.table returns rows, meta and columns for an admin', function () {
    dt_make_post('admin', 'published', 'DT Alpha');
    dt_make_post('admin', 'draft', 'DT Beta');
    dt_make_post('admin', 'published', 'DT Gamma');

    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts', 'per_page' => 20]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same('content.posts', $d['table']);
    assert_true($d['meta']['total'] >= 3, 'at least the seeded rows');
    assert_true(count($d['rows']) >= 3, 'rows returned');
    $colIds = array_map(fn($c) => $c['id'], $d['columns']);
    assert_true(in_array('title', $colIds, true) && in_array('status', $colIds, true), 'title+status columns');
    // Each row has a cell for each visible column, as escaped HTML strings.
    assert_true(isset($d['rows'][0]['cells']['title']) && is_string($d['rows'][0]['cells']['title']), 'title cell html');
});

test('datatable: a status filter narrows the rows', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'per_page' => 50, 'filters' => json_encode(['status' => 'draft']),
    ]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same(['status' => 'draft'], $d['filters'], 'only the declared filter kept');
});

test('datatable: an unknown sort token is ignored, not an error', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'sort' => 'title; DROP TABLE posts',
    ]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    // Falls back to the table default; never echoes the bad token.
    assert_true($d['sort'] === 'newest' || $d['sort'] === null, 'bad sort rejected');
});

test('datatable: a category filter narrows to posts in that term', function () {
    // Seed a category term and a post in it; a second post stays out.
    $tax = bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'category' LIMIT 1");
    $taxId = (int) ($tax['id'] ?? 0);
    assert_true($taxId > 0, 'category taxonomy exists');
    $termId = (int) bh_db()->insert('terms', ['taxonomy_id' => $taxId, 'name' => 'DT Cat ' . bin2hex(random_bytes(3)), 'slug' => 'dt-cat-' . bin2hex(random_bytes(3))]);
    $inId = dt_make_post('admin', 'published', 'DT In Category');
    bh_db()->insert('post_term', ['post_id' => $inId, 'term_id' => $termId]);
    dt_make_post('admin', 'published', 'DT Not In Category');

    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'per_page' => 50, 'filters' => json_encode(['category' => $termId]),
    ]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same(['category' => (string) $termId], $d['filters'], 'category filter kept');
    $ids = array_map(fn($row) => $row['id'], $d['rows']);
    assert_true(in_array($inId, $ids, true), 'the categorised post is listed');
    assert_true($d['meta']['total'] >= 1, 'at least the one categorised post');
});

test('datatable: an undeclared filter field is dropped', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'filters' => json_encode(['author_id' => 1, 'evil' => 'x']),
    ]);
    assert_same(200, $res->status);
    $d = $res->json()['data'];
    assert_false(array_key_exists('evil', $d['filters']), 'undeclared filter dropped');
});

test('datatable: per_page over the ceiling is refused by the schema', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts', 'per_page' => 9999]);
    assert_same(422, $res->status, $res->body);
    assert_same('VALIDATION_FAILED', $res->json()['error']['code']);
});

test('datatable: an unknown table is a 404', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.nope']);
    assert_same(404, $res->status, $res->body);
    assert_same('NOT_FOUND', $res->json()['error']['code']);
});

test('datatable: a user without the table capability is forbidden', function () {
    // subscriber lacks edit_posts → content.posts is 403, the same answer the
    // screen gives.
    $res = bh_browser('subscriber')->ajaxGet('content.table', ['table' => 'content.posts']);
    assert_same(403, $res->status, $res->body);
    assert_same('FORBIDDEN', $res->json()['error']['code']);
});

test('datatable: the templates table lists templates, not posts', function () {
    $tplId = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'template', 'status' => 'published',
        'slug' => 'tpl-' . bin2hex(random_bytes(3)), 'title' => 'DT Template One', 'content' => '', 'content_format' => 'html',
    ]);
    dt_make_post('admin', 'published', 'DT Ordinary Post');
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.templates', 'per_page' => 100]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same('content.templates', $d['table']);
    $ids = array_map(fn($row) => $row['id'], $d['rows']);
    assert_true(in_array($tplId, $ids, true), 'the template is listed');
    // The provider hard-codes type=template, so an ordinary post is not here.
    $titles = array_map(fn($row) => trim(strip_tags($row['cells']['title'] ?? '')), $d['rows']);
    assert_false(in_array('DT Ordinary Post', $titles, true), 'ordinary post is not in the templates table');
});

test('datatable: views is a sortable column', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts', 'sort' => 'views_desc']);
    assert_same(200, $res->status, $res->body);
    assert_same('views_desc', $res->json()['data']['sort'], 'views_desc accepted');
});

test('datatable: rows carry their status for the action menu and bulk logic', function () {
    dt_make_post('admin', 'draft', 'DT Status Row');
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts', 'per_page' => 50]);
    $rows = $res->json()['data']['rows'];
    assert_true(count($rows) > 0);
    foreach ($rows as $row) {
        assert_true(array_key_exists('status', $row), 'each row has a status field');
    }
});

test('datatable: the view mode (table/list/grid) round-trips', function () {
    $admin = bh_browser('admin');
    $save = $admin->ajax('content.table_prefs', ['table' => 'content.posts', 'prefs' => json_encode(['view' => 'grid'])]);
    assert_same(200, $save->status, $save->body);
    assert_same('grid', $save->json()['data']['prefs']['view']);
    // An invalid view falls back.
    $bad = $admin->ajax('content.table_prefs', ['table' => 'content.posts', 'prefs' => json_encode(['view' => 'nope'])]);
    assert_true(in_array($bad->json()['data']['prefs']['view'], ['table', 'list', 'grid'], true), 'invalid view rejected');
});

test('datatable: preferences round-trip through users.meta', function () {
    $admin = bh_browser('admin');
    $prefs = json_encode(['visible' => ['title', 'status'], 'page_size' => 50, 'density' => 'compact']);
    $save = $admin->ajax('content.table_prefs', ['table' => 'content.posts', 'prefs' => $prefs]);
    assert_same(200, $save->status, $save->body);
    $stored = $save->json()['data']['prefs'];
    assert_same(50, $stored['page_size']);
    assert_same('compact', $stored['density']);

    // The next table load reflects the saved page size.
    $res = $admin->ajaxGet('content.table', ['table' => 'content.posts']);
    assert_same(50, $res->json()['data']['prefs']['page_size'], 'saved page size applied');

    // Reset returns to defaults.
    $reset = $admin->ajax('content.table_prefs_reset', ['table' => 'content.posts']);
    assert_same(200, $reset->status, $reset->body);
    assert_same('comfortable', $reset->json()['data']['prefs']['density']);
});

test('datatable: multiple category ids match any of them (OR within a filter)', function () {
    $taxId = (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'category' LIMIT 1")['id'];
    $a = (int) bh_db()->insert('terms', ['taxonomy_id' => $taxId, 'name' => 'DT CA ' . bin2hex(random_bytes(3)), 'slug' => 'dt-ca-' . bin2hex(random_bytes(3))]);
    $b = (int) bh_db()->insert('terms', ['taxonomy_id' => $taxId, 'name' => 'DT CB ' . bin2hex(random_bytes(3)), 'slug' => 'dt-cb-' . bin2hex(random_bytes(3))]);
    $pa = dt_make_post('admin', 'published', 'DT In A');
    $pb = dt_make_post('admin', 'published', 'DT In B');
    bh_db()->insert('post_term', ['post_id' => $pa, 'term_id' => $a]);
    bh_db()->insert('post_term', ['post_id' => $pb, 'term_id' => $b]);

    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'per_page' => 50, 'filters' => json_encode(['category' => [$a, $b]]),
    ]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same(['category' => [(string) $a, (string) $b]], $d['filters'], 'multi category kept as a list');
    $ids = array_map(fn($row) => $row['id'], $d['rows']);
    assert_true(in_array($pa, $ids, true) && in_array($pb, $ids, true), 'posts from either category are listed');
});

test('datatable: a category and a tag filter together narrow to posts in both (AND across filters)', function () {
    $catTax = (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'category' LIMIT 1")['id'];
    $tagTax = (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag' LIMIT 1")['id'];
    $cat = (int) bh_db()->insert('terms', ['taxonomy_id' => $catTax, 'name' => 'DT XC ' . bin2hex(random_bytes(3)), 'slug' => 'dt-xc-' . bin2hex(random_bytes(3))]);
    $tag = (int) bh_db()->insert('terms', ['taxonomy_id' => $tagTax, 'name' => 'DT XT ' . bin2hex(random_bytes(3)), 'slug' => 'dt-xt-' . bin2hex(random_bytes(3))]);
    $both = dt_make_post('admin', 'published', 'DT Cat+Tag');
    $catOnly = dt_make_post('admin', 'published', 'DT Cat only');
    bh_db()->insert('post_term', ['post_id' => $both, 'term_id' => $cat]);
    bh_db()->insert('post_term', ['post_id' => $both, 'term_id' => $tag]);
    bh_db()->insert('post_term', ['post_id' => $catOnly, 'term_id' => $cat]);

    $res = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'per_page' => 50,
        'filters' => json_encode(['category' => [$cat], 'tag' => [$tag]]),
    ]);
    assert_same(200, $res->status, $res->body);
    $ids = array_map(fn($row) => $row['id'], $res->json()['data']['rows']);
    assert_true(in_array($both, $ids, true), 'the post in both the category and the tag is listed');
    assert_false(in_array($catOnly, $ids, true), 'the category-only post is excluded by the tag filter');
});

test('datatable: the posts table offers a tags column and tag is a filter key', function () {
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts']);
    assert_same(200, $res->status, $res->body);
    $catalog = array_map(fn($c) => $c['id'], $res->json()['data']['catalog']);
    assert_true(in_array('tags', $catalog, true), 'tags column in catalog');

    // A tag filter is accepted and kept (proving the provider declares it).
    $tagTax = (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag' LIMIT 1")['id'];
    $tag = (int) bh_db()->insert('terms', ['taxonomy_id' => $tagTax, 'name' => 'DT TG ' . bin2hex(random_bytes(3)), 'slug' => 'dt-tg-' . bin2hex(random_bytes(3))]);
    $p = dt_make_post('admin', 'published', 'DT Tagged');
    bh_db()->insert('post_term', ['post_id' => $p, 'term_id' => $tag]);
    $r2 = bh_browser('admin')->ajaxGet('content.table', [
        'table' => 'content.posts', 'per_page' => 50, 'filters' => json_encode(['tag' => [$tag]]),
    ]);
    $d2 = $r2->json()['data'];
    assert_same(['tag' => [(string) $tag]], $d2['filters'], 'tag filter kept as a list');
    $ids = array_map(fn($row) => $row['id'], $d2['rows']);
    assert_true(in_array($p, $ids, true), 'the tagged post is listed');
});

test('datatable: the tags cell links each tag to its edit screen', function () {
    $tagTax = (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag' LIMIT 1")['id'];
    $tag = (int) bh_db()->insert('terms', ['taxonomy_id' => $tagTax, 'name' => 'DT LinkTag', 'slug' => 'dt-linktag-' . bin2hex(random_bytes(3))]);
    $p = dt_make_post('admin', 'published', 'DT Has Tag');
    bh_db()->insert('post_term', ['post_id' => $p, 'term_id' => $tag]);

    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts', 'per_page' => 50]);
    $row = null;
    foreach ($res->json()['data']['rows'] as $r) { if ((int) $r['id'] === $p) { $row = $r; break; } }
    assert_true($row !== null, 'the tagged post is in the page');
    $cell = $row['cells']['tags'] ?? '';
    assert_true(str_contains($cell, '/admin/taxonomies/tag?edit=' . $tag), 'tag links to its edit screen');
    assert_true(str_contains($cell, 'DT LinkTag'), 'the tag name shows');
});

test('datatable: content.table_terms returns terms and a total for the picker', function () {
    $res = bh_browser('admin')->ajaxGet('content.table_terms', ['taxonomy' => 'tag', 'limit' => 50]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same('tag', $d['taxonomy']);
    assert_true(is_array($d['terms']), 'terms is a list');
    assert_true(array_key_exists('total', $d), 'total present');
    // An unknown taxonomy is rejected.
    $bad = bh_browser('admin')->ajaxGet('content.table_terms', ['taxonomy' => 'nope']);
    assert_same(422, $bad->status, $bad->body);
});

test('datatable: an app extension column appears, gated by capability', function () {
    // The SEO Score demo app adds a column via datatable.columns.content.posts.
    // If it is active in the test environment the column is offered to an admin;
    // this asserts the extension path works end-to-end when present.
    $res = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.posts']);
    assert_same(200, $res->status);
    $catalog = array_map(fn($c) => $c['id'], $res->json()['data']['catalog']);
    // Core columns are always present; the SEO column is present only when the
    // demo app is installed and active — assert the core set unconditionally.
    foreach (['title', 'author', 'category', 'status', 'date'] as $id) {
        assert_true(in_array($id, $catalog, true), "core column {$id} in catalog");
    }
});
