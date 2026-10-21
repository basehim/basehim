<?php

declare(strict_types=1);

/*
 * 1.2.47: the post editor's AJAX actions (Save draft, tags), the admin
 * screens' actions that replaced hand-written JSON routes, and the admin
 * areas that had no permission rule.
 */

/** A tag term id, created on first use. */
function tag_id(string $name): int
{
    $db = bh_db();
    $tax = (int) $db->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag'")['id'];
    $row = $db->selectOne('SELECT id FROM {terms} WHERE taxonomy_id = :t AND name = :n', ['t' => $tax, 'n' => $name]);
    if ($row) return (int) $row['id'];
    return (int) $db->insert('terms', ['taxonomy_id' => $tax, 'name' => $name, 'slug' => strtolower(preg_replace('/\W+/', '-', $name)) . '-' . bin2hex(random_bytes(2))]);
}

function category_id(): int
{
    $db = bh_db();
    $tax = (int) $db->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'category'")['id'];
    $row = $db->selectOne('SELECT id FROM {terms} WHERE taxonomy_id = :t ORDER BY id LIMIT 1', ['t' => $tax]);
    return $row ? (int) $row['id'] : (int) $db->insert('terms', ['taxonomy_id' => $tax, 'name' => 'General', 'slug' => 'general']);
}

function make_post(string $owner, string $status = 'draft', string $type = 'post'): int
{
    return (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => $type, 'status' => $status,
        'slug' => 'p-' . bin2hex(random_bytes(4)), 'title' => "{$owner} {$status}", 'content' => '', 'content_format' => 'html',
    ]);
}

function post_tag_names(int $postId): array
{
    return array_column(bh_db()->select(
        "SELECT t.name FROM {post_term} pt JOIN {terms} t ON t.id = pt.term_id JOIN {taxonomies} x ON x.id = t.taxonomy_id
         WHERE pt.post_id = :p AND x.slug = 'tag' ORDER BY t.name", ['p' => $postId]), 'name');
}

function idem(): array
{
    return ['Idempotency-Key' => bin2hex(random_bytes(12))];
}

// ---------------------------------------------------------------------------
// Save draft
// ---------------------------------------------------------------------------

test('editor: Save draft creates a draft, then updates the same post', function () {
    $author = bh_browser('author');
    $t1 = tag_id('Sensors');
    $res = $author->ajax('posts.save_draft', [
        'type' => 'post', 'title' => 'First draft', 'content' => '{"blocks":[]}', 'content_format' => 'blocks',
        'status' => 'draft', 'term_ids' => [$t1, 999999], 'new_tags' => ['Brand New Tag'],
        'seo' => ['meta_title' => 'SEO title', 'canonical_url' => ''],
    ], idem());
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_true($d['created']);
    $id = (int) $d['id'];
    assert_same('draft', $d['status']);
    assert_contains("/admin/posts/{$id}/edit", $d['edit_url']);
    assert_same(['Sensors'], post_tag_names($id), 'existing tag attached; a missing term id is ignored, not a 500');
    assert_same(['Brand New Tag'], $d['pending_tags'], 'new tag kept, not created');
    assert_same(null, bh_db()->selectOne("SELECT id FROM {terms} WHERE name = 'Brand New Tag'"));
    assert_same('SEO title', bh_db()->selectOne('SELECT meta_title FROM {seo_meta} WHERE post_id = :p', ['p' => $id])['meta_title']);

    $res = $author->ajax('posts.save_draft', ['type' => 'post', 'id' => $id, 'title' => 'Second save'], idem());
    assert_same(200, $res->status, $res->body);
    assert_false($res->json()['data']['created']);
    assert_same('Second save', bh_db()->selectOne('SELECT title FROM {posts} WHERE id = :p', ['p' => $id])['title']);
    assert_same(['Sensors'], post_tag_names($id), 'terms not sent are left alone');
});

test('editor: Save draft never leaves a post published, and never touches a published one', function () {
    $author = bh_browser('author');
    $res = $author->ajax('posts.save_draft', ['type' => 'post', 'title' => 'x', 'status' => 'published'], idem());
    assert_error($res, 422, 'VALIDATION_FAILED');
    $live = make_post('author', 'published');
    $res = $author->ajax('posts.save_draft', ['type' => 'post', 'id' => $live, 'title' => 'unpublish me'], idem());
    assert_error($res, 409, 'ALREADY_PUBLISHED');
    assert_same('published', bh_db()->selectOne('SELECT status FROM {posts} WHERE id = :p', ['p' => $live])['status']);
});

test("editor: an author cannot save someone else's draft; an editor can", function () {
    $other = make_post('admin');
    assert_error(bh_browser('author')->ajax('posts.save_draft', ['type' => 'post', 'id' => $other, 'title' => 'pwned'], idem()), 403, 'FORBIDDEN');
    assert_same('admin draft', bh_db()->selectOne('SELECT title FROM {posts} WHERE id = :p', ['p' => $other])['title']);
    $res = bh_browser('editor')->ajax('posts.save_draft', ['type' => 'post', 'id' => $other, 'title' => 'Edited by editor'], idem());
    assert_same(200, $res->status, $res->body);
});

test('editor: Save draft follows the type and its admin area (pages need edit_pages)', function () {
    assert_error(bh_browser('author')->ajax('posts.save_draft', ['type' => 'page', 'title' => 'A page'], idem()), 403, 'FORBIDDEN');
    assert_error(bh_browser('contributor')->ajax('posts.save_draft', ['type' => 'page', 'title' => 'A page'], idem()), 403, 'FORBIDDEN');
    assert_error(bh_browser('author')->ajax('posts.save_draft', ['type' => 'nope', 'title' => 'x'], idem()), 422, 'VALIDATION_FAILED');
    assert_error(bh_browser('subscriber')->ajax('posts.save_draft', ['type' => 'post', 'title' => 'x'], idem()), 403, 'FORBIDDEN');
    $res = bh_browser('editor')->ajax('posts.save_draft', ['type' => 'page', 'title' => 'A page'], idem());
    assert_same(200, $res->status, $res->body);
});

test('editor: a double click (same idempotency key) creates one post', function () {
    $b = bh_browser('author');
    $key = idem();
    $title = 'Once only ' . bin2hex(random_bytes(3));
    $first = $b->ajax('posts.save_draft', ['type' => 'post', 'title' => $title], $key);
    assert_same(200, $first->status);
    // The second click gets the same answer (the same post id), not a second post (1.2.75).
    $second = $b->ajax('posts.save_draft', ['type' => 'post', 'title' => $title], $key);
    assert_same(200, $second->status, $second->body);
    assert_same($first->json()['data'] ?? null, $second->json()['data'] ?? null);
    // Counted in PHP: Dolt can't run `WHERE title = …` on a column in a
    // FULLTEXT index ("Full-Text index found in filter…", issues.md BH-009).
    $titles = array_column(bh_db()->select('SELECT title FROM {posts} ORDER BY id DESC LIMIT 200'), 'title');
    assert_same(1, count(array_keys($titles, $title, true)));
});

// ---------------------------------------------------------------------------
// Tags
// ---------------------------------------------------------------------------

test('tags: add and remove on a saved post apply at once; a new name waits for publish', function () {
    $b = bh_browser('author');
    $post = make_post('author');
    $res = $b->ajax('posts.tag_add', ['post_id' => $post, 'term_id' => tag_id('Arduino')]);
    assert_same(200, $res->status, $res->body);
    assert_same(['Arduino'], post_tag_names($post));
    $res = $b->ajax('posts.tag_add', ['post_id' => $post, 'name' => '  arduino ']);
    assert_same(false, $res->json()['data']['added']['new'], 'a typed name that exists (any case) is the existing tag');
    $res = $b->ajax('posts.tag_add', ['post_id' => $post, 'name' => 'Totally New']);
    assert_same(['Totally New'], $res->json()['data']['pending_tags']);
    assert_same(null, bh_db()->selectOne("SELECT id FROM {terms} WHERE name = 'Totally New'"));
    $res = $b->ajax('posts.tag_remove', ['post_id' => $post, 'term_id' => tag_id('Arduino')]);
    assert_same([], post_tag_names($post));
    $res = $b->ajax('posts.tag_remove', ['post_id' => $post, 'name' => 'totally new']);
    assert_same([], $res->json()['data']['pending_tags']);
});

test("tags: an author cannot tag someone else's post; only tags can be added", function () {
    $b = bh_browser('author');
    $other = make_post('admin');
    assert_error($b->ajax('posts.tag_add', ['post_id' => $other, 'term_id' => tag_id('Arduino')]), 403, 'FORBIDDEN');
    assert_same([], post_tag_names($other));
    $own = make_post('author');
    assert_error($b->ajax('posts.tag_add', ['post_id' => $own, 'term_id' => category_id()]), 404, 'NOT_FOUND');
    assert_error($b->ajax('posts.tag_add', ['post_id' => $own]), 422, 'VALIDATION_FAILED');
    $page = make_post('admin', 'draft', 'page');
    assert_error(bh_browser('admin')->ajax('posts.tag_add', ['post_id' => $page, 'name' => 'x']), 422, 'VALIDATION_FAILED');
});

test('tags: publishing through the form creates pending tags; a contributor\'s wait for an editor', function () {
    // Contributor: publish becomes pending review, the new tag stays pending.
    $c = bh_browser('contributor');
    $post = make_post('contributor');
    bh_db()->insert('post_meta', ['post_id' => $post, 'meta_key' => '_bh_pending_tags', 'meta_value' => '["Fresh Tag"]', 'is_json' => 1]);
    $form = ['_csrf' => $c->csrf, '_post_settings' => '1', 'title' => 'C post', 'content' => '', 'content_format' => 'html',
             'status' => 'published', 'comment_status' => 'open', 'new_tags' => ['Fresh Tag']];
    $res = $c->request('POST', "/admin/posts/{$post}", http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $res->status);
    assert_same('pending', bh_db()->selectOne('SELECT status FROM {posts} WHERE id = :p', ['p' => $post])['status']);
    assert_same(null, bh_db()->selectOne("SELECT id FROM {terms} WHERE name = 'Fresh Tag'"));

    // Editor publishes it: the tag is created and attached, the pending list cleared.
    $e = bh_browser('editor');
    $form['_csrf'] = $e->csrf;
    $res = $e->request('POST', "/admin/posts/{$post}", http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $res->status);
    assert_same('published', bh_db()->selectOne('SELECT status FROM {posts} WHERE id = :p', ['p' => $post])['status']);
    assert_same(['Fresh Tag'], post_tag_names($post));
    assert_same(null, bh_db()->selectOne("SELECT id FROM {post_meta} WHERE post_id = :p AND meta_key = '_bh_pending_tags'", ['p' => $post]));
});

test('tags: suggestions and overview need edit_posts; names with no Latin letters do not collide', function () {
    tag_id('پاکستان');
    $b = bh_browser('author');
    $res = $b->ajaxGet('terms.tag_suggest', ['q' => 'ardu']);
    assert_same(200, $res->status, $res->body);
    assert_contains('Arduino', json_encode($res->json()['data']['tags']));
    $res = $b->ajaxGet('terms.tag_suggest', ['q' => '%']);
    assert_same(200, $res->status, 'a LIKE wildcard is matched literally');
    assert_same([], $res->json()['data']['tags']);
    // An Urdu tag must not match another Urdu name through the "n-a" slug.
    $post = make_post('author');
    $res = $b->ajax('posts.tag_add', ['post_id' => $post, 'name' => 'لاہور']);
    assert_same(true, $res->json()['data']['added']['new']);
    assert_same(200, $b->ajaxGet('terms.tag_overview')->status);
    assert_error(bh_browser('subscriber')->ajaxGet('terms.tag_suggest', ['q' => 'a']), 403, 'FORBIDDEN');
});

// ---------------------------------------------------------------------------
// Former JSON routes
// ---------------------------------------------------------------------------

test('actions: render, templates and widget preview work for authors', function () {
    $b = bh_browser('author');
    $res = $b->ajax('posts.render', ['from' => 'markdown', 'content' => '**bold**']);
    assert_contains('<strong>bold</strong>', (string) $res->json()['data']['html']);
    assert_same(200, $b->ajaxGet('posts.templates')->status);
    $res = $b->ajax('widgets.render', ['widget' => 'no-such-widget', 'settings' => '{}']);
    assert_error($res, 404, 'NOT_FOUND', 'reaches the handler (the old route needed manage_apps)');
    assert_error($b->ajax('widgets.render', ['widget' => 'x', 'settings' => '[1,2]']), 404, 'NOT_FOUND');
});

test('actions: media.list and media.delete follow the media rules', function () {
    $author = bh_browser('author');
    $res = $author->ajaxGet('media.list', ['per_page' => 5]);
    assert_same(200, $res->status, $res->body);
    assert_true(isset($res->json()['data']['data'], $res->json()['data']['counts']));
    assert_error(bh_browser('subscriber')->ajaxGet('media.list'), 403, 'FORBIDDEN');

    $own = new_media('author');
    $admins = new_media('admin');
    $res = $author->ajax('media.delete', ['ids' => [$own, $admins]]);
    assert_same(200, $res->status, $res->body);
    $data = $res->json()['data'];
    assert_same(['deleted' => 1, 'failed' => 0, 'denied' => 1, 'missing' => 0],
        array_intersect_key($data, array_flip(['deleted', 'failed', 'denied', 'missing'])));
    // 1.2.67: each id is reported on its own as well.
    $byId = array_column((array) ($data['results'] ?? []), 'status', 'id');
    assert_same('deleted', $byId[$own] ?? null);
    assert_same('denied', $byId[$admins] ?? null);
    assert_true(media_exists($admins));
    assert_error($author->ajax('media.delete', ['ids' => [$admins]]), 403, 'FORBIDDEN');
});

test('actions: Customizer, menus, users, logs and the comments badge keep their screen\'s rule', function () {
    $author = bh_browser('author');
    assert_error($author->ajax('customizer.save', ['values' => '{"identity.tagline":"x"}']), 403, 'FORBIDDEN');
    assert_error($author->ajax('menus.reorder', ['menu_id' => 1, 'tree' => []]), 403, 'FORBIDDEN');
    assert_error($author->ajaxGet('users.activity', ['user_id' => 1]), 403, 'FORBIDDEN');
    assert_error(bh_browser('editor')->ajaxGet('system.log', ['name' => 'php-error.log']), 403, 'FORBIDDEN');
    assert_same(200, bh_browser('editor')->ajaxGet('comments.pending_count')->status);
    assert_error($author->ajaxGet('comments.pending_count'), 403, 'FORBIDDEN');

    $admin = bh_browser('admin');
    $res = $admin->ajax('customizer.save', ['values' => '{"identity.tagline":"From the gateway"}']);
    assert_same(200, $res->status, $res->body);
    assert_error($admin->ajax('customizer.save', ['values' => 'not json']), 422, 'VALIDATION_FAILED');
});

test('actions: menu items are checked against their menu, and script links are refused', function () {
    $admin = bh_browser('admin');
    $db = bh_db();
    $m1 = (int) $db->insert('menus', ['name' => 'One', 'slug' => 'one-' . bin2hex(random_bytes(2))]);
    $m2 = (int) $db->insert('menus', ['name' => 'Two', 'slug' => 'two-' . bin2hex(random_bytes(2))]);
    $res = $admin->ajax('menus.items_add', ['menu_id' => $m1, 'items' => [['title' => 'Home', 'url' => '/']]]);
    assert_same(200, $res->status, $res->body);
    $item = (int) $db->selectOne('SELECT id FROM {menu_items} WHERE menu_id = :m', ['m' => $m1])['id'];
    assert_error($admin->ajax('menus.item_update', ['menu_id' => $m2, 'item_id' => $item, 'title' => 'x', 'url' => '/x']), 404, 'NOT_FOUND');
    assert_error($admin->ajax('menus.item_update', ['menu_id' => $m1, 'item_id' => $item, 'title' => 'x', 'url' => 'javascript:alert(1)']), 422, 'VALIDATION_FAILED');
    assert_error($admin->ajax('menus.items_add', ['menu_id' => $m1, 'items' => [['title' => 'x', 'url' => ' JavaScript:alert(1)']]]), 422, 'VALIDATION_FAILED');
    assert_error($admin->ajax('menus.items_add', ['menu_id' => $m1, 'items' => [['title' => 'x', 'url' => "java\tscript:alert(1)"]]]), 422, 'VALIDATION_FAILED');
    assert_error($admin->ajax('menus.item_update', ['menu_id' => $m1, 'item_id' => $item, 'title' => 'x', 'url' => "data:text/html,<script>alert(1)</script>"]), 422, 'VALIDATION_FAILED');
    $res = $admin->ajax('menus.item_update', ['menu_id' => $m1, 'item_id' => $item, 'title' => 'Start', 'url' => 'https://example.com/']);
    assert_same(200, $res->status, $res->body);
    $res = $admin->ajax('menus.reorder', ['menu_id' => $m1, 'tree' => [['id' => $item, 'parent_id' => null]]]);
    assert_same(1, $res->json()['data']['saved']);
    assert_same(200, $admin->ajax('menus.item_remove', ['menu_id' => $m1, 'item_id' => $item])->status);
});

// ---------------------------------------------------------------------------
// Admin areas that had no rule (before 1.2.47 any signed-in user)
// ---------------------------------------------------------------------------

test('areas: authors can no longer change the Customizer, terms or menu items', function () {
    $author = bh_browser('author');
    $h = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
    $res = $author->request('POST', '/admin/customize/save', http_build_query(['_csrf' => $author->csrf, 'values' => '{"identity.tagline":"pwned"}']), $h);
    assert_same(403, $res->status, $res->body);
    assert_same(403, $author->request('GET', '/admin/customize', null, ['Accept' => 'application/json'])->status);
    $cat = category_id();
    $res = $author->request('POST', "/admin/terms/{$cat}", http_build_query(['_csrf' => $author->csrf, 'name' => 'pwned']), $h);
    assert_same(403, $res->status);
    assert_true(bh_db()->selectOne('SELECT name FROM {terms} WHERE id = :i', ['i' => $cat])['name'] !== 'pwned');
    $res = $author->request('POST', '/admin/menu-items/1/delete', http_build_query(['_csrf' => $author->csrf]), $h);
    assert_same(403, $res->status);
});
