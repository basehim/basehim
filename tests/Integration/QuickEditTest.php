<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Time;

/*
 * 1.2.61: Quick Edit on the Posts and Pages data tables, through the real
 * AJAX gateway (content.quick_edit, content.quick_edit_save).
 */

function qe_post(string $owner, string $status = 'draft', array $extra = []): int
{
    return (int) bh_db()->insert('posts', $extra + [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => 'post', 'status' => $status,
        'slug' => 'qe-' . bin2hex(random_bytes(4)), 'title' => "QE {$owner} {$status}", 'content' => '<p>Body text</p>',
        'content_format' => 'html',
    ]);
}

function qe_row(int $id): array
{
    return bh_db()->selectOne('SELECT * FROM {posts} WHERE id = :i', ['i' => $id]);
}

function qe_term(string $taxonomy, string $name): int
{
    $tax = (int) bh_db()->selectOne('SELECT id FROM {taxonomies} WHERE slug = :s', ['s' => $taxonomy])['id'];
    $row = bh_db()->selectOne('SELECT id FROM {terms} WHERE taxonomy_id = :t AND name = :n', ['t' => $tax, 'n' => $name]);
    if ($row) return (int) $row['id'];
    return (int) bh_db()->insert('terms', ['taxonomy_id' => $tax, 'name' => $name, 'slug' => strtolower(preg_replace('/\W+/', '-', $name)) . '-' . bin2hex(random_bytes(2))]);
}

function qe_attach(int $post, int $term): void
{
    bh_db()->insert('post_term', ['post_id' => $post, 'term_id' => $term]);
    bh_db()->execute('UPDATE {terms} SET count = count + 1 WHERE id = :t', ['t' => $term]);   // as attachTerms() keeps it
}

function qe_terms(int $post, string $taxonomy): array
{
    return array_map('intval', array_column(bh_db()->select(
        'SELECT t.id FROM {post_term} pt JOIN {terms} t ON t.id = pt.term_id JOIN {taxonomies} x ON x.id = t.taxonomy_id
          WHERE pt.post_id = :p AND x.slug = :s ORDER BY t.id', ['p' => $post, 's' => $taxonomy]), 'id'));
}

function qe_form(Browser $b, int $id, string $table = 'content.posts'): HttpResponse
{
    return $b->ajaxGet('content.quick_edit', ['table' => $table, 'id' => $id]);
}

/** The form's fields by name. */
function qe_fields(HttpResponse $res): array
{
    $out = [];
    foreach ($res->json()['data']['form']['fields'] as $f) $out[$f['name']] = $f;
    return $out;
}

function qe_save(Browser $b, int $id, array $values, array $opts = []): HttpResponse
{
    $table = $opts['table'] ?? 'content.posts';
    $version = $opts['version'] ?? qe_form($b, $id, $table)->json()['data']['form']['version'] ?? str_repeat('0', 32);
    $body = ['table' => $table, 'id' => $id, 'version' => $version, 'values' => json_encode($values === [] ? new stdClass() : $values)];
    if (isset($opts['confirm'])) $body['confirm'] = $opts['confirm'];
    if (isset($opts['columns'])) $body['columns'] = json_encode($opts['columns']);
    return $b->ajax('content.quick_edit_save', $body);
}

test('quick edit: tables say whether they offer it; templates and comments do not', function () {
    $b = bh_browser('admin');
    assert_same(true, $b->ajaxGet('content.table', ['table' => 'content.posts'])->json()['data']['quick_edit']);
    assert_same(true, $b->ajaxGet('content.table', ['table' => 'content.pages'])->json()['data']['quick_edit']);
    assert_same(false, $b->ajaxGet('content.table', ['table' => 'content.templates'])->json()['data']['quick_edit']);
    assert_error(qe_form($b, qe_post('admin'), 'content.templates'), 404, 'NOT_FOUND');
});

test('quick edit: a post opens with its current values and only the fields that apply', function () {
    $cat = qe_term('category', 'QE Electronics');
    $tag = qe_term('tag', 'QE Arduino');
    $id = qe_post('admin', 'published', ['published_at' => '2024-05-01 10:00:00', 'comment_status' => 'closed']);
    qe_attach($id, $cat); qe_attach($id, $tag);
    $res = qe_form(bh_browser('admin'), $id);
    assert_same(200, $res->status, $res->body);
    $f = qe_fields($res);
    assert_same(['title', 'slug', 'author', 'status', 'publish', 'categories', 'tags', 'comments'], array_keys($f));
    assert_same(qe_row($id)['title'], $f['title']['value']);
    assert_same((string) bh_env()['users']['admin'], $f['author']['value']);
    assert_same('published', $f['status']['value']);
    assert_same(false, $f['comments']['value']);
    // Categories, Tags and Publish carry the settings of the block editor's components.
    assert_same([$cat], $f['categories']['value']['selected']);
    assert_true(in_array(['id' => $cat, 'name' => 'QE Electronics'], $f['categories']['value']['options'], true));
    assert_same([['id' => $tag, 'name' => 'QE Arduino']], $f['tags']['value']['tags']);
    assert_same($id, $f['tags']['value']['post_id']);
    assert_same('categories', $f['categories']['type']);
    assert_same('tags', $f['tags']['type']);
    $pub = $f['publish']['value'];
    assert_same(['publish', 'published', 'keep'], [$f['publish']['type'], $pub['status'], $pub['mode']]);
    $local = Time::local('2024-05-01 10:00:00');
    assert_same([$local->format('Y-m-d'), $local->format('H:i')], [$pub['date'], $pub['time']], 'Change date starts from the stored date');
    assert_contains('Published', $pub['summary']);
    assert_same(['field' => 'status', 'in' => ['published', 'scheduled']], $f['publish']['show_when']);
    assert_same(['draft', 'pending', 'published', 'private'], array_column($f['status']['options'], 'value'));
    // Authors offered can edit posts: never a subscriber.
    $authorIds = array_column($f['author']['options'], 'value');
    assert_true(in_array(bh_env()['users']['contributor'], $authorIds, true));
    assert_false(in_array(bh_env()['users']['subscriber'], $authorIds, true));
});

test('quick edit: a page opens with parent and order, and no categories, tags or comments', function () {
    $parent = qe_post('admin', 'published', ['type' => 'page', 'title' => 'QE Parent page']);
    $child = qe_post('admin', 'published', ['type' => 'page', 'parent_id' => $parent, 'menu_order' => 3]);
    $grandchild = qe_post('admin', 'draft', ['type' => 'page', 'parent_id' => $child]);
    $res = qe_form(bh_browser('admin'), $child, 'content.pages');
    assert_same(200, $res->status, $res->body);
    $f = qe_fields($res);
    assert_same(['title', 'slug', 'author', 'status', 'publish', 'parent', 'menu_order'], array_keys($f));
    assert_same((string) $parent, $f['parent']['value']);
    assert_same(3, $f['menu_order']['value']);
    $parents = array_column($f['parent']['options'], 'value');
    assert_true(in_array($parent, $parents, true));
    assert_false(in_array($child, $parents, true), 'not itself');
    assert_false(in_array($grandchild, $parents, true), 'not a descendant');
    // A post id on the pages table is "not found".
    assert_error(qe_form(bh_browser('admin'), qe_post('admin'), 'content.pages'), 404, 'NOT_FOUND');
});

test('quick edit: saving a title updates the record and returns the re-rendered row', function () {
    $b = bh_browser('editor');
    $id = qe_post('editor');
    $res = qe_save($b, $id, ['title' => 'QE  Renamed  <b>post</b>'], ['columns' => ['title', 'status', 'date']]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same('QE Renamed <b>post</b>', qe_row($id)['title']);
    assert_same($id, $d['row']['id']);
    assert_contains('QE Renamed &lt;b&gt;post&lt;/b&gt;', $d['row']['cells']['title'], 'escaped in the cell');
    assert_same(['title', 'status', 'date'], array_keys($d['row']['cells']));
    assert_same('draft', $d['row']['status']);
    assert_true(strlen($d['version']) === 32);
    // The new version lets the same form be saved again without a conflict.
    $res = qe_save($b, $id, ['slug' => 'qe-renamed-' . $id], ['version' => $d['version']]);
    assert_same(200, $res->status, $res->body);
});

test('quick edit: slugs are normalised, unique across posts and pages, and refreshed in the row', function () {
    $b = bh_browser('admin');
    $id = qe_post('admin');
    $page = qe_post('admin', 'published', ['type' => 'page', 'slug' => 'qe-taken-' . $id]);
    $res = qe_save($b, $id, ['slug' => ' Hello World ÄÖ '], ['columns' => ['title']]);
    assert_same(200, $res->status, $res->body);
    $slug = qe_row($id)['slug'];
    assert_same(Application::getInstance() ? \App\Core\Helpers::slug('Hello World ÄÖ') : '', $slug);
    assert_contains('/' . $slug, $res->json()['data']['row']['cells']['title'], 'the row shows the new address');
    // Taken by a page: refused, not silently suffixed.
    $res = qe_save($b, $id, ['slug' => 'qe-taken-' . $id]);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_true(isset($res->json()['error']['fields']['slug']));
    assert_same($slug, qe_row($id)['slug'], 'unchanged');
    // Reserved by a route, or nothing usable left after normalising.
    assert_error(qe_save($b, $id, ['slug' => 'admin']), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($b, $id, ['slug' => 'لاہور']), 422, 'VALIDATION_FAILED');
    assert_same($slug, qe_row($id)['slug'], 'still unchanged');
    // Empty: made from the title.
    $res = qe_save($b, $id, ['slug' => '', 'title' => 'QE From the title']);
    assert_same(200, $res->status, $res->body);
    assert_same('qe-from-the-title', qe_row($id)['slug']);
    // A live post's address change is reported.
    $live = qe_post('admin', 'published', ['published_at' => '2024-01-01 00:00:00']);
    $res = qe_save($b, $live, ['slug' => 'qe-new-address-' . $live]);
    assert_contains('old one will no longer work', implode(' ', $res->json()['data']['notices']));
});

test('quick edit: categories, tags, author and parent change without touching other terms', function () {
    $b = bh_browser('admin');
    $c1 = qe_term('category', 'QE Cat One'); $c2 = qe_term('category', 'QE Cat Two');
    $t1 = qe_term('tag', 'QE Tag One');
    $id = qe_post('admin');
    qe_attach($id, $c1); qe_attach($id, $t1);

    // Categories only: the tag stays.
    $res = qe_save($b, $id, ['categories' => [(string) $c2]]);
    assert_same(200, $res->status, $res->body);
    assert_same([$c2], qe_terms($id, 'category'));
    assert_same([$t1], qe_terms($id, 'tag'), 'tags untouched');

    // Tags only (the Tags component's value): an existing tag and a new name,
    // which waits for publishing (a draft).
    $res = qe_save($b, $id, ['tags' => ['ids' => [$t1], 'names' => ['QE Brand New Tag', 'qe tag one']]]);
    assert_same(200, $res->status, $res->body);
    assert_same([$t1], qe_terms($id, 'tag'));
    assert_same([$c2], qe_terms($id, 'category'), 'categories untouched');
    $pending = Application::getInstance()->make(\App\Services\PostEditorService::class)->pendingTags($id);
    assert_same(['QE Brand New Tag'], $pending);
    // Removing every tag; a tag that does not exist is refused.
    assert_error(qe_save($b, $id, ['tags' => ['ids' => [999999], 'names' => []]]), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($b, $id, ['tags' => 'a, b']), 422, 'VALIDATION_FAILED');
    qe_save($b, $id, ['tags' => ['ids' => [], 'names' => []]]);
    assert_same([], qe_terms($id, 'tag'));

    // Author: to someone who can edit posts.
    $res = qe_save($b, $id, ['author' => (string) bh_env()['users']['author']]);
    assert_same(200, $res->status, $res->body);
    assert_same(bh_env()['users']['author'], (int) qe_row($id)['author_id']);
    // Never to a subscriber, nor to a category that does not exist.
    assert_error(qe_save($b, $id, ['author' => (string) bh_env()['users']['subscriber']]), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($b, $id, ['categories' => ['999999']]), 422, 'VALIDATION_FAILED');

    // Comments.
    qe_save($b, $id, ['comments' => false]);
    assert_same('closed', qe_row($id)['comment_status']);

    // Page parent and order; a loop is refused.
    $a = qe_post('admin', 'published', ['type' => 'page']);
    $c = qe_post('admin', 'published', ['type' => 'page', 'parent_id' => $a]);
    $res = qe_save($b, $a, ['parent' => (string) $c], ['table' => 'content.pages']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    $p = qe_post('admin', 'published', ['type' => 'page']);
    $res = qe_save($b, $c, ['parent' => (string) $p, 'menu_order' => '7'], ['table' => 'content.pages']);
    assert_same(200, $res->status, $res->body);
    assert_same([$p, 7], [(int) qe_row($c)['parent_id'], (int) qe_row($c)['menu_order']]);
    qe_save($b, $c, ['parent' => ''], ['table' => 'content.pages']);
    assert_same(null, qe_row($c)['parent_id']);
});

test('quick edit: ordinary edits keep a draft a draft, a live date, and a schedule', function () {
    $b = bh_browser('editor');
    $draft = qe_post('editor');
    qe_save($b, $draft, ['title' => 'QE still draft']);
    assert_same(['draft', null], [qe_row($draft)['status'], qe_row($draft)['published_at']]);

    $live = qe_post('editor', 'published', ['published_at' => '2024-05-01 10:00:00']);
    qe_save($b, $live, ['title' => 'QE live renamed', 'categories' => [], 'comments' => true]);
    assert_same(['published', '2024-05-01 10:00:00'], [qe_row($live)['status'], qe_row($live)['published_at']]);

    $at = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
    $sched = qe_post('editor', 'scheduled', ['published_at' => $at]);
    $res = qe_save($b, $sched, ['title' => 'QE still scheduled', 'slug' => 'qe-still-sched-' . $sched]);
    assert_same(200, $res->status, $res->body);
    assert_same(['scheduled', $at], [qe_row($sched)['status'], qe_row($sched)['published_at']]);
    $f = qe_fields(qe_form($b, $sched));
    $local = Time::local($at);
    assert_same([$local->format('Y-m-d'), $local->format('H:i'), 'schedule'], [$f['publish']['value']['date'], $f['publish']['value']['time'], $f['publish']['value']['mode']]);
    assert_same(['draft', 'pending', 'scheduled', 'published', 'private'], array_column($f['status']['options'], 'value'));
    // The status alone ("Scheduled", no mode) keeps the schedule too.
    qe_save($b, $sched, ['status' => 'scheduled']);
    assert_same(['scheduled', $at], [qe_row($sched)['status'], qe_row($sched)['published_at']]);
});

test('quick edit: scheduling, rescheduling, publishing now and unpublishing use the publishing rules', function () {
    $b = bh_browser('editor');
    $id = qe_post('editor');
    $at = (int) (ceil((time() + 2 * 86400) / 60) * 60);
    $local = Time::local($at);
    // Published + Schedule: what the shared Publish control sends.
    $res = qe_save($b, $id, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $local->format('Y-m-d'), 'publish_time' => $local->format('H:i')]);
    assert_same(200, $res->status, $res->body);
    assert_same(['scheduled', gmdate('Y-m-d H:i:00', $at)], [qe_row($id)['status'], qe_row($id)['published_at']]);

    // A past time is refused, on the Publish control.
    $past = Time::local(time() - 3600);
    $res = qe_save($b, $id, ['status' => 'scheduled', 'publish_mode' => 'schedule', 'publish_date' => $past->format('Y-m-d'), 'publish_time' => $past->format('H:i')]);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_contains('future', implode(' ', $res->json()['error']['fields']['publish']));
    assert_same('scheduled', qe_row($id)['status']);
    assert_error(qe_save($b, $id, ['status' => 'scheduled', 'publish_mode' => 'whenever']), 422, 'VALIDATION_FAILED');

    // Reschedule: a new time.
    $later = Time::local($at + 3600);
    qe_save($b, $id, ['status' => 'scheduled', 'publish_mode' => 'schedule', 'publish_date' => $later->format('Y-m-d'), 'publish_time' => $later->format('H:i')]);
    assert_same(gmdate('Y-m-d H:i:00', $at + 3600), qe_row($id)['published_at']);

    // Publish now.
    qe_save($b, $id, ['status' => 'published', 'publish_mode' => 'now']);
    $row = qe_row($id);
    assert_same('published', $row['status']);
    assert_true(abs(strtotime($row['published_at'] . ' UTC') - time()) < 120, 'dated now');

    // Taking it off the site needs an explicit confirmation.
    $res = qe_save($b, $id, ['status' => 'draft']);
    assert_error($res, 409, 'CONFIRM_REQUIRED');
    assert_same('published', qe_row($id)['status']);
    $res = qe_save($b, $id, ['status' => 'draft'], ['confirm' => true]);
    assert_same(200, $res->status, $res->body);
    assert_same('draft', qe_row($id)['status']);
});

test('quick edit: a live post\'s date can be changed — back in time it stays live, ahead it is scheduled after confirming', function () {
    $b = bh_browser('editor');
    $id = qe_post('editor', 'published', ['published_at' => '2024-05-01 10:00:00']);
    $back = Time::local(strtotime('2023-02-03 04:05:00 UTC'));
    $res = qe_save($b, $id, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => $back->format('Y-m-d'), 'publish_time' => $back->format('H:i')]);
    assert_same(200, $res->status, $res->body);
    assert_same(['published', '2023-02-03 04:05:00'], [qe_row($id)['status'], qe_row($id)['published_at']]);
    // Keep date: nothing changes.
    qe_save($b, $id, ['status' => 'published', 'publish_mode' => 'keep', 'title' => 'QE kept date']);
    assert_same(['published', '2023-02-03 04:05:00'], [qe_row($id)['status'], qe_row($id)['published_at']]);
    // A future date takes it off the site: confirm first.
    $ahead = Time::local($at = (int) (ceil((time() + 86400) / 60) * 60));
    $vals = ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => $ahead->format('Y-m-d'), 'publish_time' => $ahead->format('H:i')];
    assert_error(qe_save($b, $id, $vals), 409, 'CONFIRM_REQUIRED');
    assert_same('published', qe_row($id)['status']);
    $res = qe_save($b, $id, $vals, ['confirm' => true]);
    assert_same(200, $res->status, $res->body);
    assert_same(['scheduled', gmdate('Y-m-d H:i:00', $at)], [qe_row($id)['status'], qe_row($id)['published_at']]);
});

test('quick edit: contributors cannot publish, schedule or change what is not theirs', function () {
    $c = bh_browser('contributor');
    $own = qe_post('contributor');
    $f = qe_fields(qe_form($c, $own));
    assert_same(['draft', 'pending'], array_column($f['status']['options'], 'value'), 'only review statuses offered');
    assert_false(isset($f['author']), 'no author field');
    assert_false(isset($f['publish']), 'no Publish control');

    assert_error(qe_save($c, $own, ['status' => 'published']), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($c, $own, ['status' => 'private']), 422, 'VALIDATION_FAILED');
    // The Publish control's values are not even in a contributor's form.
    assert_error(qe_save($c, $own, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2030-01-01', 'publish_time' => '10:00']), 403, 'FIELD_NOT_ALLOWED');
    assert_error(qe_save($c, $own, ['status' => 'scheduled']), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($c, $own, ['publish_date' => '2030-01-01']), 403, 'FIELD_NOT_ALLOWED');
    assert_error(qe_save($c, $own, ['author' => (string) bh_env()['users']['admin']]), 403, 'FIELD_NOT_ALLOWED');
    assert_same(['draft', null, bh_env()['users']['contributor']], [qe_row($own)['status'], qe_row($own)['published_at'], (int) qe_row($own)['author_id']]);

    // Submitting for review is allowed.
    assert_same(200, qe_save($c, $own, ['status' => 'pending'])->status);
    assert_same('pending', qe_row($own)['status']);

    // Someone else's post, or a live one of their own: not here.
    assert_error(qe_form($c, qe_post('author')), 403, 'FORBIDDEN');
    $live = qe_post('contributor', 'published', ['published_at' => '2024-01-01 00:00:00']);
    assert_error(qe_form($c, $live), 403, 'FORBIDDEN');
    assert_error(qe_save($c, $live, ['title' => 'x'], ['version' => str_repeat('a', 32)]), 403, 'FORBIDDEN');
    // Authors edit their own posts but cannot reassign them.
    $a = bh_browser('author');
    $mine = qe_post('author');
    assert_false(isset(qe_fields(qe_form($a, $mine))['author']));
    assert_error(qe_form($a, qe_post('author2')), 403, 'FORBIDDEN');
    // Subscribers cannot use the table at all.
    assert_error(qe_form(bh_browser('subscriber'), $own), 403, 'FORBIDDEN');
});

test('quick edit: unknown fields, bad input, stale versions and forged requests are refused', function () {
    $b = bh_browser('admin');
    $id = qe_post('admin');
    assert_error(qe_save($b, $id, ['content' => '<script>x</script>']), 403, 'FIELD_NOT_ALLOWED');
    assert_error(qe_save($b, $id, ['type' => 'page']), 403, 'FIELD_NOT_ALLOWED');
    assert_same('<p>Body text</p>', qe_row($id)['content']);
    assert_same('post', qe_row($id)['type']);
    $res = qe_save($b, $id, ['title' => '']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_true(isset($res->json()['error']['fields']['title']));
    assert_error(qe_save($b, $id, ['status' => 'live']), 422, 'VALIDATION_FAILED');
    assert_error(qe_save($b, $id, ['menu_order' => 'x'], ['table' => 'content.pages']), 404, 'NOT_FOUND');
    assert_error(qe_save($b, 99999999, ['title' => 'x'], ['version' => str_repeat('a', 32)]), 404, 'NOT_FOUND');
    assert_error(qe_save($b, $id, []), 422, 'VALIDATION_FAILED');
    $res = $b->ajax('content.quick_edit_save', ['table' => 'content.posts', 'id' => $id, 'version' => 'abc', 'values' => 'not json']);
    assert_error($res, 422, 'VALIDATION_FAILED');

    // Somebody else changed the post after the form was opened.
    $version = qe_form($b, $id)->json()['data']['form']['version'];
    qe_save(bh_browser('editor'), $id, ['title' => 'QE changed elsewhere']);
    $res = qe_save($b, $id, ['slug' => 'qe-mine-' . $id], ['version' => $version]);
    assert_error($res, 409, 'EDIT_CONFLICT');
    assert_same('QE changed elsewhere', qe_row($id)['title']);

    // Without the CSRF token.
    $res = $b->ajax('content.quick_edit_save', ['table' => 'content.posts', 'id' => $id, 'version' => $version, 'values' => '{"title":"x"}'], [], 'wrong-token');
    assert_error($res, 403, 'CSRF_INVALID');
});

test('quick edit: the table still searches, filters, sorts and pages after a save', function () {
    $b = bh_browser('admin');
    $id = qe_post('admin', 'draft', ['title' => 'QE zzunique before']);
    qe_save($b, $id, ['title' => 'QE zzunique after']);
    $d = $b->ajaxGet('content.table', ['table' => 'content.posts', 'search' => 'zzunique after', 'filters' => json_encode(['status' => 'draft']),
        'sort' => 'title_az', 'per_page' => 8, 'page' => 1])->json()['data'];
    assert_same(1, $d['meta']['total']);
    assert_same($id, $d['rows'][0]['id']);
    assert_same('title_az', $d['sort']);
    assert_same(['status' => 'draft'], $d['filters']);
});

test('quick edit: rows carry their address on the site for View and Preview', function () {
    $b = bh_browser('admin');
    $live = qe_post('admin', 'published', ['published_at' => '2024-01-01 00:00:00', 'title' => 'QE linkcheck live']);
    $draft = qe_post('admin', 'draft', ['title' => 'QE linkcheck draft']);
    $d = $b->ajaxGet('content.table', ['table' => 'content.posts', 'search' => 'QE linkcheck', 'per_page' => 20])->json()['data'];
    $byId = [];
    foreach ($d['rows'] as $r) $byId[$r['id']] = $r;
    $url = \App\Core\Helpers::postUrl(qe_row($live) + ['type' => 'post'], '');
    assert_same(['url' => $url, 'live' => true], $byId[$live]['link']);
    assert_same(false, $byId[$draft]['link']['live'], 'a draft gets Preview');
    // The address works: the live one for anyone, the draft as a preview for an editor only.
    assert_same(200, (new Browser(bh_env()['base_url']))->request('GET', $url)->status);
    assert_same(404, (new Browser(bh_env()['base_url']))->request('GET', $byId[$draft]['link']['url'])->status);
    assert_same(200, $b->request('GET', $byId[$draft]['link']['url'])->status);
    // A Quick Edit save returns the new address with the row.
    $res = qe_save($b, $live, ['slug' => 'qe-linkcheck-moved-' . $live]);
    assert_contains('qe-linkcheck-moved-' . $live, $res->json()['data']['row']['link']['url']);
    // Pages link at the root; templates have no address.
    $page = qe_post('admin', 'published', ['type' => 'page', 'title' => 'QE linkcheck page']);
    $pd = $b->ajaxGet('content.table', ['table' => 'content.pages', 'search' => 'QE linkcheck page'])->json()['data'];
    assert_same('/' . qe_row($page)['slug'], $pd['rows'][0]['link']['url']);
    $td = $b->ajaxGet('content.table', ['table' => 'content.templates'])->json()['data'];
    foreach ($td['rows'] as $r) assert_false(isset($r['link']));
});
