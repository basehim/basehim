<?php

declare(strict_types=1);

use App\Core\HookRegistry;
use App\Core\Logger;
use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Core\Table\TableRegistry;

final class RegTestProvider implements TableProvider
{
    public function sortKeys(): array { return ['newest', 'oldest']; }
    public function filterKeys(): array { return ['status']; }
    public function query(TableQuery $q): array { return ['data' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]; }
}

function fresh_table_registry(): TableRegistry
{
    return new TableRegistry(new HookRegistry(), new Logger(sys_get_temp_dir()));
}

function good_table(array $extra = []): array
{
    return $extra + [
        'label'      => 'Posts',
        'capability' => 'edit_posts',
        'provider'   => RegTestProvider::class,
        'default_sort' => 'newest',
        'page_sizes' => [8, 20, 50],
        'columns'    => [
            ['id' => 'title', 'label' => 'Title', 'value' => 'title', 'can_hide' => false],
            ['id' => 'status', 'label' => 'Status', 'value' => 'status'],
        ],
    ];
}

test('table registry: a valid table registers and is retrievable', function () {
    $r = fresh_table_registry();
    $t = $r->register('content.posts', good_table());
    assert_same('content.posts', $t->key);
    assert_true($r->has('content.posts'));
    assert_same(8, $t->defaultPageSize());
    assert_same(50, $t->maxPageSize());
    assert_true($t->allowsPageSize(8));
    assert_false($t->allowsPageSize(13));
});

test('table registry: duplicate keys are refused, not overwritten', function () {
    $r = fresh_table_registry();
    $r->register('content.posts', good_table());
    assert_throws(\RuntimeException::class, fn() => $r->register('content.posts', good_table()), 'already registered');
});

test('table registry: an invalid key is refused', function () {
    $r = fresh_table_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('Posts', good_table()));
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('nodot', good_table()));
});

test('table registry: a provider must implement TableProvider', function () {
    $r = fresh_table_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('content.posts', good_table(['provider' => \stdClass::class])));
});

test('table registry: a missing capability is refused', function () {
    $r = fresh_table_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('content.posts', good_table(['capability' => ''])));
});

test('table registry: a column with neither value nor render is refused', function () {
    $r = fresh_table_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('content.posts', good_table([
        'columns' => [['id' => 'x', 'label' => 'X']],
    ])));
});

test('table registry: a string render target is refused (no config-to-call)', function () {
    $r = fresh_table_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('content.posts', good_table([
        'columns' => [['id' => 'x', 'label' => 'X', 'render' => 'SomeClass::method']],
    ])));
});

test('table registry: columnsFor returns core columns for a permitted user', function () {
    $r = fresh_table_registry();
    $r->register('content.posts', good_table());
    // No capability on these columns, so a null user still sees them.
    $cols = $r->columnsFor('content.posts', null);
    assert_same(['title', 'status'], array_map(fn($c) => $c->id, $cols));
});

test('table registry: an extension column is appended via the filter', function () {
    $hooks = new HookRegistry();
    $r = new TableRegistry($hooks, new Logger(sys_get_temp_dir()));
    $r->register('content.posts', good_table());
    $hooks->addFilter('datatable.columns.content.posts', function (array $cols) {
        $cols[] = ['id' => 'seo.score', 'label' => 'SEO', 'render' => fn($row) => 'x', 'owner' => 'seo'];
        return $cols;
    });
    $cols = $r->columnsFor('content.posts', null);
    assert_same(['title', 'status', 'seo.score'], array_map(fn($c) => $c->id, $cols));
    assert_same('seo', $cols[2]->owner);
});

test('table registry: a duplicate extension id is dropped', function () {
    $hooks = new HookRegistry();
    $r = new TableRegistry($hooks, new Logger(sys_get_temp_dir()));
    $r->register('content.posts', good_table());
    $hooks->addFilter('datatable.columns.content.posts', function (array $cols) {
        $cols[] = ['id' => 'status', 'label' => 'Dupe', 'value' => 'status', 'owner' => 'evil'];
        return $cols;
    });
    $cols = $r->columnsFor('content.posts', null);
    // 'status' stays the core column; the duplicate is not added.
    assert_same(['title', 'status'], array_map(fn($c) => $c->id, $cols));
    assert_same('core', $cols[1]->owner);
});

test('table registry: a capability-gated column is removed for a user who lacks it', function () {
    $hooks = new HookRegistry();
    $r = new TableRegistry($hooks, new Logger(sys_get_temp_dir()));
    $r->register('content.posts', good_table());
    $hooks->addFilter('datatable.columns.content.posts', function (array $cols) {
        $cols[] = ['id' => 'secret', 'label' => 'Secret', 'value' => 'x', 'capability' => 'manage_settings', 'owner' => 'app'];
        return $cols;
    });
    // null user has no capabilities: the gated column is filtered out entirely.
    $cols = $r->columnsFor('content.posts', null);
    assert_false(in_array('secret', array_map(fn($c) => $c->id, $cols), true), 'gated column removed, not hidden');
});
