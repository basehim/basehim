<?php

declare(strict_types=1);

/*
 * 1.2.122 — The posts' and pages' trash on the Data Table.
 */

function r12122(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('trash: both trash tables are registered with Restore and Delete permanently', function () {
    $t = r12122('routes/tables.php');
    assert_contains("\$tables->register('content.posts.trash', [", $t);
    assert_contains("\$tables->register('content.pages.trash', [", $t);
    assert_contains("'default_sort' => 'deleted_desc'", $t);
    assert_contains("['id' => 'restore', 'label' => 'Restore', 'icon' => 'arrow-uturn-left', 'tone' => 'publish', 'flag' => 'restore']", $t);
    assert_contains("'flag' => 'purge', 'confirm' => 'Permanently delete {n} ' . \$noun . '{s}?", $t);
    assert_contains("'label' => 'Restores as'", $t);
    assert_contains("'empty'        => ['icon' => 'trash', 'title' => 'Trash is empty',", $t);
});

test('trash: the provider always lists the trash and flags what each user may do', function () {
    $p = r12122('app/Services/Table/PostTrashTableProvider.php');
    assert_contains("['trashed' => '1'] + array_intersect_key(", $p);
    assert_contains("'edit_others_' . \$this->type() . 's'", $p);
    assert_contains("'delete_others_' . \$this->type() . 's'", $p);
    assert_contains("return 'page';", r12122('app/Services/Table/PageTrashTableProvider.php'));
    $repo = r12122('app/Repositories/PostRepository.php');
    assert_contains("'deleted_desc' => 'p.deleted_at DESC, p.id DESC'", $repo);
});

test('trash: the list uses the table; handlers are tightened', function () {
    $v = r12122('admin/views/posts/index.php');
    assert_contains("\$__trashTables = ['post' => 'content.posts.trash', 'page' => 'content.pages.trash'];", $v);
    assert_contains('!empty($canEmptyTrash)', $v);
    assert_false(str_contains($v, "can\\'t be undone"), 'no apostrophe inside the confirm() literal');
    $c = r12122('app/Http/Controllers/Admin/PostController.php');
    assert_contains("if (\$trashActions && empty(\$existing['deleted_at'])) { \$skipped++; continue; }", $c);
    assert_false(str_contains($c, "&& !\\App\\Http\\Middleware\\CheckCapability::userCan(\$this->user(), \$this->capabilityFor('delete'))) {"), 'delete_* alone no longer empties everyone\'s trash');
});

test('table empty state: definition, payload, partial and script', function () {
    assert_contains('public readonly array $empty = [],', r12122('app/Core/Table/TableDefinition.php'));
    assert_contains("in_array(\$raw['icon'], ['inbox', 'trash'], true)", r12122('app/Core/Table/TableRegistry.php'));
    assert_contains("'empty'        => \$table->empty === [] ? null : \$table->empty,", r12122('app/Core/Table/TableService.php'));
    assert_contains('$__own = $__def->empty;', r12122('admin/views/partials/datatable.php'));
    assert_contains('var own = payload.empty || {};', r12122('admin/assets/js/datatable.js'));
    assert_contains('"arrow-uturn-left":', r12122('admin/assets/js/icons.js'));
});
