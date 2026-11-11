<?php

declare(strict_types=1);

/*
 * 1.2.123 — Categories and Tags on the Data Table; the New dialog.
 */

function tm_tax(string $slug): int
{
    return (int) bh_db()->selectOne('SELECT id FROM {taxonomies} WHERE slug = :s', ['s' => $slug])['id'];
}

function tm_term(string $tax, string $name, int $count = 0, ?int $parent = null): int
{
    return (int) bh_db()->insert('terms', [
        'taxonomy_id' => tm_tax($tax), 'name' => $name, 'parent_id' => $parent, 'count' => $count,
        'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . bin2hex(random_bytes(3)),
    ]);
}

function tm_idem(): array
{
    return ['Idempotency-Key' => bin2hex(random_bytes(12))];
}

function tm_rows(\Browser $b, string $table, array $q = []): array
{
    $r = $b->ajaxGet('content.table', $q + ['table' => $table, 'per_page' => 100]);
    assert_same(200, $r->status, $r->body);
    return $r->json()['data'];
}

test('terms table: paged, searched and filtered in SQL; the screen stays small', function () {
    $mark = 'tmz' . bin2hex(random_bytes(3));
    $db = bh_db();
    $tx = tm_tax('tag');
    for ($i = 0; $i < 250; $i++) {
        $db->insert('terms', ['taxonomy_id' => $tx, 'name' => "{$mark} {$i}", 'slug' => "{$mark}-{$i}", 'count' => $i % 3 === 0 ? 0 : $i]);
    }
    $admin = bh_browser('admin');
    $d = tm_rows($admin, 'terms.tag', ['search' => $mark, 'per_page' => 20]);
    assert_same(250, $d['meta']['total']);
    assert_same(20, count($d['rows']));
    $unused = tm_rows($admin, 'terms.tag', ['search' => $mark, 'filters' => json_encode(['usage' => 'unused'])]);
    assert_same(84, $unused['meta']['total'], 'unused = count 0');
    $byCount = tm_rows($admin, 'terms.tag', ['search' => $mark, 'sort' => 'count_desc', 'per_page' => 20]);
    assert_contains("{$mark} 248", $byCount['rows'][0]['cells']['name']);

    // The page no longer prints every term (and twice): it is the table's first page.
    $html = $admin->request('GET', '/admin/taxonomies/tag')->body;
    assert_contains('&quot;table&quot;:&quot;terms.tag&quot;', str_replace('"', '&quot;', $html));
    assert_false(str_contains($html, 'term-edit-'), 'no hidden edit form per term');
    assert_true(substr_count($html, 'data-dt-row=') <= 100);
});

test('terms table: needs manage_taxonomies; the count links to the filtered posts', function () {
    assert_same(403, bh_browser('author')->ajaxGet('content.table', ['table' => 'terms.tag'])->status);
    assert_same(403, bh_browser('author')->request('GET', '/admin/taxonomies/tag')->status);
    $id = tm_term('category', 'TM Linked', 3);
    $rows = tm_rows(bh_browser('editor'), 'terms.category', ['search' => 'TM Linked'])['rows'];
    assert_contains('/admin/posts?category=' . $id, $rows[0]['cells']['count']);
});

test('quick edit: rename keeps the slug; parents stay acyclic and in the taxonomy', function () {
    $admin = bh_browser('admin');
    $top = tm_term('category', 'TM Top');
    $child = tm_term('category', 'TM Child', 0, $top);
    $slug = bh_db()->selectOne('SELECT slug FROM {terms} WHERE id = :i', ['i' => $top])['slug'];
    $form = $admin->ajaxGet('content.quick_edit', ['table' => 'terms.category', 'id' => $top])->json()['data']['form'];
    assert_same(['name', 'slug', 'parent', 'description'], array_column($form['fields'], 'name'));
    $parentOpts = array_column($form['fields'][2]['options'], 'value');
    assert_false(in_array((string) $child, $parentOpts, true), 'its own child is not offered');

    $save = fn(int $id, array $values, string $ver) => $admin->ajax('content.quick_edit_save', [
        'table' => 'terms.category', 'id' => $id, 'version' => $ver, 'values' => json_encode($values)]);
    $r = $save($top, ['name' => 'TM Top Renamed', 'description' => "Two\nlines"], $form['version']);
    assert_same(200, $r->status, $r->body);
    $row = bh_db()->selectOne('SELECT name, slug, description FROM {terms} WHERE id = :i', ['i' => $top]);
    assert_same(['TM Top Renamed', $slug, "Two\nlines"], [$row['name'], $row['slug'], $row['description']]);

    $form2 = $admin->ajaxGet('content.quick_edit', ['table' => 'terms.category', 'id' => $top])->json()['data']['form'];
    $r = $save($top, ['parent' => (string) $child], $form2['version']);
    assert_same(422, $r->status, 'a term cannot sit under its own child');
    $r = $save($top, ['name' => 'x'], $form['version']);
    assert_same(409, $r->status, 'an old version is refused');
    // A tag is not editable through the category table.
    $tag = tm_term('tag', 'TM Tag');
    assert_same(404, $admin->ajaxGet('content.quick_edit', ['table' => 'terms.category', 'id' => $tag])->status);
});

test('new dialog: one, duplicates refused, several with preview and skip', function () {
    $admin = bh_browser('admin');
    $name = 'TM New ' . bin2hex(random_bytes(3));
    $r = $admin->ajax('terms.create', ['taxonomy' => 'tag', 'name' => $name], tm_idem());
    assert_same(200, $r->status, $r->body);
    $id = (int) $r->json()['data']['id'];
    assert_true($id > 0);
    $r = $admin->ajax('terms.create', ['taxonomy' => 'tag', 'name' => strtoupper($name)], tm_idem());
    assert_same(422, $r->status, 'the same name in another case is a duplicate');
    $r = $admin->ajax('terms.create', ['taxonomy' => 'tag', 'name' => 'TM Orphan', 'parent_id' => $id], tm_idem());
    assert_same(422, $r->status, 'tags have no parents');

    $fresh = 'TM Fresh ' . bin2hex(random_bytes(3));
    $list = "{$name}\n{$fresh}, {$fresh}\n\n" . strtolower($fresh) . ', TM Other ' . bin2hex(random_bytes(2));
    $p = $admin->ajax('terms.create_many', ['taxonomy' => 'tag', 'names' => $list, 'preview' => true], tm_idem())->json()['data'];
    assert_same(2, count($p['new']));
    assert_same([$name], $p['existing']);
    assert_same(0, (int) bh_db()->selectOne('SELECT COUNT(*) c FROM {terms} WHERE name = :n', ['n' => $fresh])['c'], 'preview writes nothing');
    $c = $admin->ajax('terms.create_many', ['taxonomy' => 'tag', 'names' => $list], tm_idem())->json()['data'];
    assert_same(2, count($c['created']));
    assert_same(1, (int) bh_db()->selectOne('SELECT COUNT(*) c FROM {terms} WHERE name = :n', ['n' => $fresh])['c']);

    assert_same(403, bh_browser('author')->ajax('terms.create', ['taxonomy' => 'tag', 'name' => 'nope'], tm_idem())->status);
    assert_same(404, $admin->ajax('terms.create', ['taxonomy' => 'nope', 'name' => 'x'], tm_idem())->status);
});

test('bulk delete: only terms of the screen\'s taxonomy', function () {
    $admin = bh_browser('admin');
    $a = tm_term('tag', 'TM Del A');
    $cat = tm_term('category', 'TM Keep Cat');
    $r = $admin->request('POST', '/admin/taxonomies/tag/bulk',
        http_build_query(['_csrf' => $admin->csrf, 'bulk_action' => 'delete', 'ids' => [$a, $cat]]),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $r->status);
    assert_same(null, bh_db()->selectOne('SELECT id FROM {terms} WHERE id = :i', ['i' => $a]));
    assert_true(bh_db()->selectOne('SELECT id FROM {terms} WHERE id = :i', ['i' => $cat]) !== null, 'a category is not deleted from the Tags screen');
});

test('?edit={id} finds the term so its Quick Edit can open', function () {
    $id = tm_term('category', 'TM Deep Link');
    $r = bh_browser('admin')->request('GET', '/admin/taxonomies/category?edit=' . $id);
    assert_same(302, $r->status);
    assert_contains('q=TM+Deep+Link&edit=' . $id, (string) ($r->headers['location'] ?? ''));
});
