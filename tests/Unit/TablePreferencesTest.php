<?php

declare(strict_types=1);

use App\Core\Table\Column;
use App\Core\Table\TableDefinition;
use App\Core\Table\TablePreferences;
use App\Services\UserService;

/** In-memory UserService so preference logic can be tested without a database. */
function memory_users(): UserService
{
    return new class extends UserService {
        private array $store = [];
        public function __construct() {}  // skip the repository/hooks dependencies
        public function meta(int $userId, string $key, mixed $default = null): mixed
        {
            return $this->store[$userId][$key] ?? $default;
        }
        public function setMeta(int $userId, string $key, mixed $value): bool
        {
            $this->store[$userId][$key] = $value;
            return true;
        }
    };
}

function pref_table(): TableDefinition
{
    return new TableDefinition(
        key: 'content.posts',
        label: 'Posts',
        capability: 'edit_posts',
        area: '/admin/posts',
        provider: 'X',
        defaultSort: 'newest',
        pageSizes: [8, 20, 50],
        columns: [],
    );
}

/** @return Column[] */
function pref_columns(): array
{
    return [
        new Column(id: 'title', label: 'Title', value: 'title', sortable: ['title_az', 'title_za'], defaultVisible: true, canHide: false),
        new Column(id: 'author', label: 'Author', value: 'author_name', defaultVisible: true),
        new Column(id: 'views', label: 'Views', value: 'view_count', defaultVisible: false),
        new Column(id: 'date', label: 'Date', value: 'date', sortable: ['newest', 'oldest'], defaultVisible: true),
    ];
}

test('prefs: defaults come from the column definitions when nothing is saved', function () {
    $p = new TablePreferences(memory_users());
    $out = $p->resolve(1, pref_table(), pref_columns());
    assert_same(['title', 'author', 'date'], $out['visible']);
    assert_same(['title', 'author', 'views', 'date'], $out['order']);
    assert_same(8, $out['page_size']);
    assert_same('comfortable', $out['density']);
    assert_same('newest', $out['sort']);
});

test('prefs: a saved set round-trips and is returned reconciled', function () {
    $p = new TablePreferences(memory_users());
    $t = pref_table();
    $cols = pref_columns();
    $stored = $p->save(7, $t, $cols, [
        'visible'   => ['title', 'views'],
        'order'     => ['views', 'title', 'author', 'date'],
        'page_size' => 50,
        'density'   => 'compact',
        'sort'      => 'title_az',
    ]);
    assert_same(50, $stored['page_size']);
    assert_same('compact', $stored['density']);
    assert_same('title_az', $stored['sort']);
    // title is non-hideable, so it stays visible even though order put views first.
    assert_true(in_array('title', $stored['visible'], true));
    assert_true(in_array('views', $stored['visible'], true));
    // Persisted: resolve() returns the same.
    $again = $p->resolve(7, $t, $cols);
    assert_same($stored['visible'], $again['visible']);
    assert_same('compact', $again['density']);
});

test('prefs: an unknown column id in saved prefs is dropped', function () {
    $p = new TablePreferences(memory_users());
    $t = pref_table();
    $cols = pref_columns();
    $out = $p->save(3, $t, $cols, [
        'visible' => ['title', 'ghost', 'date'],
        'order'   => ['ghost', 'title', 'date', 'author', 'views'],
    ]);
    assert_false(in_array('ghost', $out['order'], true), 'unknown id not in order');
    assert_false(in_array('ghost', $out['visible'], true), 'unknown id not visible');
    // All real columns still present in order.
    assert_same(['title', 'date', 'author', 'views'], $out['order']);
});

test('prefs: a disallowed page size falls back to the default', function () {
    $p = new TablePreferences(memory_users());
    $out = $p->save(4, pref_table(), pref_columns(), ['page_size' => 13]);
    assert_same(8, $out['page_size']);
});

test('prefs: a non-hideable column cannot be hidden', function () {
    $p = new TablePreferences(memory_users());
    $out = $p->save(5, pref_table(), pref_columns(), ['visible' => ['author']]);  // tried to hide title
    assert_true(in_array('title', $out['visible'], true), 'title forced visible');
});

test('prefs: a sort token no column declares is rejected', function () {
    $p = new TablePreferences(memory_users());
    $out = $p->save(6, pref_table(), pref_columns(), ['sort' => 'bogus_sort']);
    assert_true($out['sort'] === null || $out['sort'] === 'newest', 'bogus sort not kept');
});

test('prefs: reset forgets the saved set', function () {
    $p = new TablePreferences(memory_users());
    $t = pref_table();
    $cols = pref_columns();
    $p->save(9, $t, $cols, ['page_size' => 50, 'density' => 'compact']);
    $p->reset(9, $t);
    $out = $p->resolve(9, $t, $cols);
    assert_same(8, $out['page_size']);
    assert_same('comfortable', $out['density']);
});
