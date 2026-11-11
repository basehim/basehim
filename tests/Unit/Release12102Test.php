<?php

declare(strict_types=1);

/*
 * 1.2.102 — admin responsive and component pass (audit: 71 screens × 7
 * widths). The navigation drawer for keyboards and screen readers, data-table
 * cards on phones, Users on the Data Table, a shared dialog, checkbox and
 * stacking table, and screens moved onto core components.
 */


function r102_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('drawer: Escape closes it, the toggle says whether it is open, and a closed drawer is inert so Tab skips it', function () {
    $l = r102_file('admin/views/layouts/app.php');
    assert_contains('id="bh-mobile-toggle"', $l);
    assert_contains('aria-controls="bh-sidebar" aria-expanded="false"', $l);
    assert_contains("if (e.key === 'Escape') { e.preventDefault(); closeMobile(); return; }", $l);
    assert_contains("sidebar.setAttribute('inert', '')", $l);
    assert_contains('// Keep focus inside the open drawer.', $l);
    assert_contains("if (was && restoreFocus !== false && mobileToggle && drawerMq.matches) mobileToggle.focus();", $l);
});

test('data-table cards: on a phone-width card (< 420px) the values move under the title', function () {
    $css = r102_file('admin/assets/css/src/datatable.css');
    assert_contains('@container dtcard (max-width: 419px) {', $css);
    assert_false(str_contains($css, '@container dtcard (max-width: 299px)'), 'the old 300px threshold is gone');
    assert_false(str_contains($css, '(min-width: 300px) and (max-width: 344px)'));
    assert_contains('.bh-dt[data-select="none"] .bh-dt-check', $css, 'no checkboxes without a bulk action');
});

test('shared components: dialog, checkbox and stacking table live in ui.css; the layout opens dialogs and leaves stacking tables alone', function () {
    $ui = r102_file('admin/assets/css/ui.css');
    foreach (['.bh-dialog {', '.bh-dialog__head {', '.bh-dialog__foot {', '.bh-check {', '.bh-check__input {', '.bh-checks {', '.bh-table {', '.bh-table--stack td[data-label]::before', '.bh-table--stack tr.hidden'] as $needle) {
        assert_contains($needle, $ui);
    }
    $l = r102_file('admin/views/layouts/app.php');
    assert_contains('window.bhDialog = function (target)', $l);
    assert_contains('[data-bh-dialog-open]', $l);
    assert_contains('main table:not(.bh-table--stack) {', $l);
});

test('Users: core Data Table with search, role and status filters, sorts, Edit, and Delete only where the user may manage that person', function () {
    // The definition (the screen itself is exercised in a browser at 375 and 1280px).
    $t = r102_file('routes/tables.php');
    $def = substr($t, strpos($t, "\$tables->register('users.list'"));
    assert_contains("'capability'   => 'manage_users'", $def);
    assert_contains("'area'         => '/admin/users'", $def);
    assert_contains("'filter' => ['field' => 'status', 'in' => array_keys(\$userStatuses)", $def);
    assert_contains("->make(\\App\\Services\\AccessControl::class)->roles() as \$slug => \$r", $def, 'roles from AccessControl::roles(), not the config key');
    assert_contains("['id' => 'edit', 'label' => 'Edit', 'icon' => 'pencil-square', 'href' => '/admin/users/{id}/edit']", $def);
    assert_contains("'flag' => 'manage', 'bulk' => false, 'event' => true", $def);
    $p = r102_file('app/Services/Table/UserTableProvider.php');
    assert_contains("\$this->access->canManage(\$me, \$row) ? ['manage'] : []", $p);
    assert_same(array_keys(\App\Repositories\UserRepository::SORTS), (new ReflectionClass(\App\Services\Table\UserTableProvider::class))->newInstanceWithoutConstructor()->sortKeys());
    assert_contains("'tableKey'   => 'users.list'", r102_file('admin/views/users/index.php'));
    assert_false(str_contains(r102_file('admin/views/users/index.php'), '<table'), 'no hand-built table');
});

test('API keys: the shared dialog and core fields; its own stylesheet and modal script are gone', function () {
    $v = r102_file('admin/views/api/keys.php');
    assert_contains('<dialog class="bh-dialog bh-dialog--lg" id="bh-key-dialog"', $v);
    assert_contains('class="bh-check__input" name="scopes[]"', $v);
    assert_contains('class="bh-table bh-table--stack"', $v);
    assert_false(str_contains($v, '<style'), 'no page stylesheet');
    assert_false(str_contains($v, 'function openModal'), 'no hand-made modal');
    assert_false((bool) preg_match('/#[0-9a-f]{6}\b/i', $v), 'no hex colours');
});

test('screens on core components: Widgets tabs, menu editor buttons, taxonomy and menu tables, checkboxes', function () {
    assert_contains("'partials.tabs'", r102_file('admin/views/widgets/_nav.php'));
    assert_contains("\$this->include('widgets._nav'", r102_file('admin/views/widgets/index.php'));
    assert_contains("\$this->include('widgets._nav'", r102_file('admin/views/widgets/areas.php'));
    assert_false(str_contains(r102_file('admin/views/menus/edit.php'), 'bg-slate-800'), 'menu editor buttons are bh-btn');
    // 1.2.123: the taxonomy screen is the core Data Table now.
    assert_contains("\$this->include('partials.datatable'", r102_file('admin/views/taxonomies/index.php'));
    assert_contains('class="bh-table bh-table--stack"', r102_file('admin/views/menus/index.php'));
    assert_contains('class="bh-check__input" name="roles[', r102_file('admin/views/settings/toolbar.php'));
    assert_false(str_contains(r102_file('admin/views/updates/index.php'), 'text-xs text-slate-500 truncate">This site checks'), 'the status line wraps');
});
