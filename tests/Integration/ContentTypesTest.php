<?php

declare(strict_types=1);

/*
 * 1.2.94 — app content types end to end: their admin list, public pages
 * (/events/{slug}, /events), sitemap and search, taxonomies, custom fields
 * from the editor form, the AJAX draft save, the app API and REST, and that
 * posts and pages are unchanged.
 */

const CT_APP = <<<'PHP'
<?php
namespace BhTest\CtFixture;

use App\Core\Ajax\AjaxContext;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->registerPostType('event', ['label' => 'Events', 'singular' => 'Event',
            'supports' => ['title', 'content', 'excerpt'], 'taxonomies' => ['category']]);
        $this->registerPostType('memo', ['label' => 'Memos', 'public' => false]);
        $this->registerTaxonomy('event-type', ['label' => 'Event types', 'singular' => 'Event type', 'post_types' => ['event'], 'hierarchical' => true]);
        $this->registerFields('event', [
            ['key' => 'starts_at', 'label' => 'Starts', 'type' => 'date', 'required' => true],
            ['key' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 50],
            ['key' => 'seats', 'label' => 'Seats', 'type' => 'number', 'min' => 1, 'max' => 500, 'default' => 40],
            ['key' => 'online', 'label' => 'Online', 'type' => 'checkbox'],
            ['key' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => ['all' => 'Everyone', 'pro' => 'Pros'], 'default' => 'all'],
            ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'private' => true],
        ], ['title' => 'Event details']);
        $this->ajax('fields', ['capability' => 'read', 'input' => ['id' => 'int', 'set' => 'string'], 'handler' => function (AjaxContext $c) {
            $api = $this->api()->content('event');
            $errors = $c->input('set') ? $api->saveFields((int) $c->input('id'), json_decode((string) $c->input('set'), true) ?: []) : [];
            return ['fields' => $api->fields((int) $c->input('id')), 'errors' => (object) $errors];
        }]);
    }
}
PHP;

function ct_setup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    bh_install_app('ct-fixture', ['src/App.php' => CT_APP], ['namespace' => 'BhTest\\CtFixture']);
}

function ct_item(string $type, string $title, string $status = 'published'): array
{
    $slug = 'ct-' . bin2hex(random_bytes(4));
    $id = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => $type, 'status' => $status,
        'slug' => $slug, 'title' => $title, 'content' => '<p>' . $title . '</p>', 'content_format' => 'html',
        'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
    ]);
    return ['id' => $id, 'slug' => $slug];
}

/** Post the editor form the way the browser does. */
function ct_form(\Browser $b, string $path, array $fields): \HttpResponse
{
    $page = $b->request('GET', $path);
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    return $b->request('POST', preg_replace('#/(create|\d+/edit)$#', '', $path) . (preg_match('#/(\d+)/edit$#', $path, $id) ? '/' . $id[1] : ''),
        http_build_query(['_csrf' => $m[1] ?? ''] + $fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
}

test('content types e2e: public pages at /events/{slug} and /events; drafts and non-public types stay hidden', function () {
    ct_setup();
    $ev = ct_item('event', 'Harbour talk');
    $draft = ct_item('event', 'Draft talk', 'draft');
    $memo = ct_item('memo', 'Private memo');
    $anon = bh_browser();
    $r = $anon->request('GET', '/events/' . $ev['slug']);
    assert_same(200, $r->status);
    assert_contains('Harbour talk', $r->body);
    $arch = $anon->request('GET', '/events');
    assert_same(200, $arch->status);
    assert_contains('Harbour talk', $arch->body);
    assert_not_contains('Draft talk', $arch->body);
    assert_same(404, $anon->request('GET', '/events/' . $draft['slug'])->status, 'draft hidden from visitors');
    assert_same(200, bh_browser('admin')->request('GET', '/events/' . $draft['slug'])->status, 'previewed by its editors');
    assert_same(404, $anon->request('GET', '/' . $ev['slug'])->status, 'not at the root');
    assert_same(404, $anon->request('GET', '/' . $memo['slug'])->status);
    assert_same(404, $anon->request('GET', '/memos/' . $memo['slug'])->status, 'non-public: no URLs');
    assert_same(404, $anon->request('GET', '/events/not-a-thing')->status);
});

test('content types e2e: sitemap and site search list only content with a page', function () {
    ct_setup();
    $ev = ct_item('event', 'Sitemap event zebra');
    $memo = ct_item('memo', 'Sitemap memo zebra');
    $map = '';
    $idx = bh_browser()->request('GET', '/sitemap.xml')->body;
    preg_match_all('#<loc>[^<]*/(sitemap-content-\d+\.xml)</loc>#', $idx, $m);
    foreach ($m[1] as $f) $map .= bh_browser()->request('GET', '/' . $f)->body;
    assert_contains('/events/' . $ev['slug'] . '</loc>', $map);
    assert_not_contains($memo['slug'], $map, 'non-public types are not in the sitemap');
    $s = bh_browser()->request('GET', '/search?q=' . urlencode('Sitemap memo zebra'))->body;
    assert_not_contains($memo['slug'], $s, 'no result for a non-public type');
});

test('content types e2e: the admin list shows the type\'s items with working links, not posts', function () {
    ct_setup();
    $ev = ct_item('event', 'Listed event');
    $j = bh_browser('admin')->ajaxGet('content.table', ['table' => 'content.type.event', 'per_page' => 100])->json();
    assert_true($j['success'] ?? false, json_encode($j));
    $titles = [];
    foreach ($j['data']['rows'] as $r) $titles[] = strip_tags((string) ($r['cells']['title'] ?? json_encode($r)));
    $all = implode(' | ', $titles) . json_encode($j['data']['rows']);
    assert_contains('Listed event', $all);
    assert_contains('/admin/content/event/' . $ev['id'] . '/edit', str_replace('\\/', '/', $all));
    assert_not_contains('"type":"post"', $all);
    $page = bh_browser('admin')->request('GET', '/admin/content/event');
    assert_contains('content.type.event', $page->body);
    assert_same(403, bh_browser('subscriber')->ajaxGet('content.table', ['table' => 'content.type.event'])->status);
});

test('content types e2e: the editor follows supports, taxonomies and fields', function () {
    ct_setup();
    $html = bh_browser('admin')->request('GET', '/admin/content/event/create')->body;
    foreach (['summary', 'categories', 'tax-event-type', 'excerpt', 'seo'] as $p) assert_contains('data-bhe-panel="' . $p . '"', $html, $p);
    foreach (['featured', 'discussion', 'tags'] as $p) assert_not_contains('data-bhe-panel="' . $p . '"', $html, "{$p} is not in supports");
    assert_contains('name="fields[starts_at]"', $html);
    assert_contains('id="bhe-permalink"', $html);
    assert_contains('/events/', $html);
    // Posts keep every panel.
    $post = bh_browser('admin')->request('GET', '/admin/posts/create')->body;
    foreach (['featured', 'categories', 'tags', 'excerpt', 'discussion', 'seo'] as $p) assert_contains('data-bhe-panel="' . $p . '"', $post, "post {$p}");
    assert_not_contains('name="fields[', $post);
    $row = bh_db()->selectOne("SELECT label, hierarchical FROM {taxonomies} WHERE slug = 'event-type'");
    assert_same('Event types', $row['label'] ?? null, 'the taxonomy row is created');
});

test('content types e2e: fields save from the form, invalid ones keep their value, terms attach', function () {
    ct_setup();
    $admin = bh_browser('admin');
    $termId = (int) bh_db()->insert('terms', ['taxonomy_id' => (int) bh_db()->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'event-type'")['id'],
        'name' => 'Workshops', 'slug' => 'workshops-' . bin2hex(random_bytes(3))]);
    $r = ct_form($admin, '/admin/content/event/create', ['title' => 'Form event', 'status' => 'published', '_post_settings' => '1', '_fields' => '1',
        'term_ids' => [$termId], 'fields' => ['starts_at' => '2026-12-01', 'venue' => 'Hall <2>', 'seats' => '120', 'online' => '1', 'level' => 'pro', 'note' => 'secret']]);
    assert_true(in_array($r->status, [302, 303], true), (string) $r->status);
    preg_match('#/admin/content/event/(\d+)/edit#', (string) $r->header('Location'), $m);
    $id = (int) ($m[1] ?? 0);
    assert_true($id > 0, (string) $r->header('Location'));
    $meta = [];
    foreach (bh_db()->select('SELECT meta_key, meta_value FROM {post_meta} WHERE post_id = :p', ['p' => $id]) as $row) $meta[$row['meta_key']] = $row['meta_value'];
    assert_same(['2026-12-01', 'Hall <2>', '120', '1', 'pro', 'secret'], [$meta['starts_at'] ?? null, $meta['venue'] ?? null, $meta['seats'] ?? null, $meta['online'] ?? null, $meta['level'] ?? null, $meta['note'] ?? null]);

    // An invalid value is not saved; the others are.
    $r = ct_form($admin, '/admin/content/event/' . $id . '/edit', ['title' => 'Form event', 'status' => 'published', '_post_settings' => '1', '_fields' => '1',
        'term_ids' => [$termId], 'fields' => ['starts_at' => '2026-12-02', 'seats' => '9999', 'venue' => 'Hall 3']]);
    $after = [];
    foreach (bh_db()->select('SELECT meta_key, meta_value FROM {post_meta} WHERE post_id = :p', ['p' => $id]) as $row) $after[$row['meta_key']] = $row['meta_value'];
    assert_same(['2026-12-02', '120', 'Hall 3', '0'], [$after['starts_at'], $after['seats'], $after['venue'], $after['online']], 'seats kept; unchecked box saved as 0');
    $edit = $admin->request('GET', '/admin/content/event/' . $id . '/edit')->body;
    assert_contains('kept their previous values', html_entity_decode($edit));

    // A save without the field panels changes nothing.
    ct_form($admin, '/admin/content/event/' . $id . '/edit', ['title' => 'Form event renamed', 'status' => 'published']);
    assert_same('Hall 3', bh_db()->selectOne("SELECT meta_value FROM {post_meta} WHERE post_id = :p AND meta_key = 'venue'", ['p' => $id])['meta_value']);

    // Public page and REST: values, no private field, term archive.
    $slug = bh_db()->selectOne('SELECT slug FROM {posts} WHERE id = :i', ['i' => $id])['slug'];
    $api = bh_browser()->request('GET', '/api/v1/content/event/' . $slug)->json();
    assert_same('Hall 3', $api['data']['fields']['venue'] ?? null, json_encode($api));
    assert_false(isset($api['data']['fields']['note']), 'private field left out');
    assert_same('/events/' . $slug, $api['data']['url']);
    assert_same(404, bh_browser()->request('GET', '/api/v1/content/memo')->status);
    $term = bh_db()->selectOne('SELECT slug FROM {terms} WHERE id = :i', ['i' => $termId])['slug'];
    $arch = bh_browser()->request('GET', '/event-type/' . $term);
    assert_same(200, $arch->status);
    assert_contains('Form event renamed', $arch->body);
});

test('content types e2e: AJAX draft save and the app API save fields through the schema', function () {
    ct_setup();
    $admin = bh_browser('admin');
    $j = $admin->ajax('posts.save_draft', ['type' => 'event', 'title' => 'Ajax event', 'content' => '<p>x</p>', 'content_format' => 'html',
        'fields' => json_encode(['venue' => 'Dock', 'seats' => '0', 'online' => '1'])], ['Idempotency-Key' => bin2hex(random_bytes(16))])->json();
    assert_true($j['success'] ?? false, json_encode($j));
    $id = (int) $j['data']['id'];
    assert_true(isset($j['data']['field_errors']['seats']), 'seats 0 refused');
    assert_contains('/events/', (string) $j['data']['view_url']);
    $r = $admin->ajax('ct-fixture.fields', ['id' => $id])->json()['data']['fields'];
    assert_same(['Dock', 40, '1', 'all'], [$r['venue'], $r['seats'], $r['online'], $r['level']], 'defaults where nothing valid was saved');
    $r = $admin->ajax('ct-fixture.fields', ['id' => $id, 'set' => json_encode(['level' => 'pro', 'seats' => '12'])])->json()['data'];
    assert_same([], (array) $r['errors']);
    assert_same(['Dock', 12, 'pro'], [$r['fields']['venue'], $r['fields']['seats'], $r['fields']['level']], 'a partial save keeps the rest');
    $r = $admin->ajax('ct-fixture.fields', ['id' => $id, 'set' => json_encode(['level' => 'nope'])])->json()['data'];
    assert_true(isset(((array) $r['errors'])['level']));
});

test('content types e2e: comments only on types that support them', function () {
    ct_setup();
    $ev = ct_item('event', 'No comments here');
    $anon = bh_browser();
    $r = $anon->request('POST', '/comments', http_build_query(['post_id' => $ev['id'], 'content' => 'Hello', 'author_name' => 'A', 'author_email' => 'a@example.test']),
        ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    assert_true(in_array($r->status, [403, 419], true), $r->status . ' ' . substr($r->body, 0, 200));
    assert_same(0, (int) bh_db()->selectOne('SELECT COUNT(*) AS c FROM {comments} WHERE post_id = :p', ['p' => $ev['id']])['c']);
});

test('content types e2e: switching the app off hides its types, keeps the content', function () {
    ct_setup();
    $ev = ct_item('event', 'Kept event');
    bh_db()->execute("UPDATE {apps} SET status = 'inactive' WHERE slug = 'ct-fixture'");
    try {
        assert_same(404, bh_browser()->request('GET', '/events/' . $ev['slug'])->status);
        assert_true((bool) bh_db()->selectOne('SELECT id FROM {posts} WHERE id = :i', ['i' => $ev['id']]));
        assert_same(200, bh_browser('admin')->request('GET', '/admin/posts')->status);
    } finally {
        bh_db()->execute("UPDATE {apps} SET status = 'active' WHERE slug = 'ct-fixture'");
    }
    assert_same(200, bh_browser()->request('GET', '/events/' . $ev['slug'])->status);
});
