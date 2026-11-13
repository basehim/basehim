<?php

declare(strict_types=1);

/*
 * 1.2.126: menus with a name already in use, the REST API's publish time,
 * the dashboard's growth badge and the editor sidebar's controls.
 */

function r12126_growth(int $total, int $recent): ?array
{
    $m = new ReflectionMethod(\App\Services\Dashboard\CoreDashboardWidgets::class, 'growth');
    $m->setAccessible(true);
    return $m->invoke(null, $total, $recent);
}

test('dashboard: the growth badge shows a count, not a capped percentage, for a young site', function () {
    // 1.2.128: fewer than ten before shows the count, not a percentage.
    assert_same('+6 new', r12126_growth(11, 6)['text'] ?? null, '5 before, 6 new');
    assert_same('+50%', r12126_growth(30, 10)['text'] ?? null, '20 before, 10 new');
    assert_same('+999%', r12126_growth(1099, 999)['text'] ?? null, '100 before, 999 new: exactly 999% is still a percentage');
    assert_same('+10 new', r12126_growth(11, 10)['text'] ?? null, '1 before, 10 new: 1000%');
    assert_same(null, r12126_growth(5, 0), 'nothing new');
    assert_same(null, r12126_growth(5, 5), 'nothing before');
});

test('editor: sidebar labels leave the controls room for "Blocks (visual)"', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/src/block-editor.css');
    assert_contains('.bhe-row > .bhe-row__label { flex: 0 0 72px;', $css);
    assert_not_contains('flex: 0 0 96px', $css);
});

test('menus: a name in use gets a numbered slug; a taken slug is refused, not a server error', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/MenuService.php');
    assert_contains('$slug = $this->uniqueSlug(Helpers::slug($name) ?: \'menu\');', $src);
    assert_contains('$this->assertSlugFree($payload[\'slug\'], $id);', $src);
    foreach (['app/Http/Controllers/Api/MenuController.php', 'app/Http/Controllers/Admin/MenuController.php'] as $f) {
        assert_true(substr_count((string) file_get_contents(dirname(__DIR__, 2) . '/' . $f), 'catch (\InvalidArgumentException $e)') >= 2, $f);
    }
});
