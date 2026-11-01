<?php

declare(strict_types=1);

/*
 * 1.2.96 — the frontend admin bar without a database: the registry
 * (validation, ownership, ordering, visibility, failure isolation), the page
 * context and its signed descriptor, settings checks and panel data.
 */

use App\Core\AdminBar\AdminBar;
use App\Core\AdminBar\AdminBarContext;
use App\Core\AdminBar\AdminBarSettings;

function ab_user(string $role = 'admin', int $id = 5): array
{
    // No container in unit tests, so roles carry no capabilities: an
    // "admin" here is given manage_options as a per-user grant.
    return ['id' => $id, 'role' => $role, 'username' => 'u' . $id, 'display_name' => 'User ' . $id,
            'meta' => $role === 'admin' ? json_encode(['caps_grant' => ['manage_options']]) : null];
}

function ab_ctx(array $d = []): AdminBarContext
{
    return AdminBarContext::make($d + ['type' => 'single', 'content_type' => 'post', 'id' => 7]);
}

test('admin bar: menus and items are validated, namespaced owners can\'t take each other\'s ids', function () {
    $bar = new AdminBar(null, false);
    assert_true($bar->menu(['id' => 'shop.menu', 'label' => 'Shop'], 'shop'));
    assert_false($bar->menu(['id' => 'shop.menu', 'label' => 'Other'], 'rival'), 'another owner, same id');
    assert_true($bar->menu(['id' => 'shop.menu', 'label' => 'Shop again'], 'shop'), 'the same owner may register again');
    assert_false($bar->menu(['id' => 'Bad Id', 'label' => 'X'], 'shop'));
    assert_false($bar->menu(['id' => 'shop.nolabel'], 'shop'));
    assert_false($bar->item(['id' => 'shop.orphan', 'label' => 'X'], 'shop'), 'items need a parent');
    assert_false($bar->menu(['id' => 'context', 'label' => 'Mine'], 'shop'), 'core menu ids are core\'s');
    assert_false($bar->item(['id' => 'shop.js', 'parent' => 'shop.menu', 'label' => 'X', 'href' => 'javascript:alert(1)'], 'shop'));
    assert_false($bar->item(['id' => 'shop.act', 'parent' => 'shop.menu', 'label' => 'X', 'action' => 'quick-edit'], 'shop'), 'actions are core\'s');
    assert_false($bar->panel(['id' => 'shop.p', 'parent' => 'shop.menu'], 'shop'), 'a panel needs data');
    assert_false($bar->indicator(['id' => 'shop.i', 'parent' => 'shop.menu', 'value' => 5], 'shop'), 'value must be callable');
    assert_false($bar->item(['id' => 'shop.cap', 'parent' => 'shop.menu', 'label' => 'X', 'capability' => [1]], 'shop'));
    $reasons = array_column($bar->rejected(), 'reason');
    assert_true(count($reasons) >= 9);
    assert_contains("already registered by 'shop'", implode(' ', $reasons));
});

test('admin bar: tree is ordered, filtered by capability, role, context and visible(); empty menus are dropped', function () {
    $bar = new AdminBar(null, false);
    $bar->menu(['id' => 'a.late', 'label' => 'Late', 'priority' => 90, 'href' => '/x'], 'a');
    $bar->menu(['id' => 'a.early', 'label' => 'Early', 'priority' => 5, 'href' => '/y'], 'a');
    $bar->menu(['id' => 'a.end', 'label' => 'End', 'priority' => 1, 'region' => 'end', 'href' => '/z'], 'a');
    $bar->menu(['id' => 'a.empty', 'label' => 'Empty'], 'a');
    $bar->menu(['id' => 'a.admins', 'label' => 'Admins', 'capability' => 'manage_options', 'href' => '/a'], 'a');
    $bar->menu(['id' => 'a.editors', 'label' => 'Editors', 'roles' => ['editor'], 'href' => '/e'], 'a');
    $bar->menu(['id' => 'a.pages', 'label' => 'On pages', 'context' => ['page'], 'href' => '/p'], 'a');
    $bar->menu(['id' => 'a.never', 'label' => 'Never', 'visible' => fn() => false, 'href' => '/n'], 'a');
    $bar->menu(['id' => 'a.parent', 'label' => 'Parent'], 'a');
    $bar->item(['id' => 'a.child2', 'parent' => 'a.parent', 'label' => 'Second', 'priority' => 20, 'href' => '/2'], 'a');
    $bar->item(['id' => 'a.child1', 'parent' => 'a.parent', 'label' => 'First', 'priority' => 10, 'href' => '/1'], 'a');
    $bar->item(['id' => 'a.grand', 'parent' => 'a.child1', 'label' => 'Grandchild', 'href' => '/g'], 'a');

    $ids = fn(array $t) => array_column($t, 'id');
    $admin = $bar->tree(ab_ctx(), ab_user('admin'));
    assert_same(['a.early', 'a.admins', 'a.parent', 'a.late', 'a.end'], $ids($admin));
    $parent = $admin[array_search('a.parent', $ids($admin), true)];
    assert_same(['a.child1', 'a.child2'], $ids($parent['children']));
    assert_same('a.grand', $parent['children'][0]['children'][0]['id']);

    $editor = $bar->tree(ab_ctx(['content_type' => 'page']), ab_user('editor'));
    assert_true(in_array('a.editors', $ids($editor), true));
    assert_true(in_array('a.pages', $ids($editor), true));
    assert_false(in_array('a.admins', $ids($editor), true));
});

test('admin bar: a callable that throws hides its component only, and is logged once', function () {
    $bar = new AdminBar(null, false);
    $bar->menu(['id' => 'b.ok', 'label' => 'Fine', 'href' => '/ok'], 'b');
    $bar->menu(['id' => 'b.boom', 'label' => 'Broken', 'href' => '/b', 'visible' => function () { throw new RuntimeException('nope'); }], 'b');
    $bar->menu(['id' => 'b.dyn', 'label' => 'Dynamic', 'items' => function () { throw new RuntimeException('items fail'); }, 'href' => '/d'], 'b');
    $bar->indicator(['id' => 'b.count', 'parent' => 'b.ok', 'value' => function () { throw new LogicException('x'); }, 'lazy' => false], 'b');
    $bar->panel(['id' => 'b.panel', 'parent' => 'b.ok', 'data' => function () { throw new RuntimeException('panel'); }], 'b');
    $t = $bar->tree(ab_ctx(), ab_user());
    assert_same(['b.ok', 'b.dyn'], array_column($t, 'id'));
    assert_same([], $t[0]['indicators']);
    $p = $bar->runPanel('b.panel', ab_ctx(), ab_user());
    assert_false($p['ok']);
    assert_contains('could not be loaded', $p['error']);
});

test('admin bar: dynamic items, lazy indicators and panels the user may see', function () {
    $bar = new AdminBar(null, false);
    $bar->menu(['id' => 'c.m', 'label' => 'M', 'items' => fn($ctx, $u) => [
        ['id' => 'one', 'label' => 'One', 'href' => '/one'], ['label' => 'Bad', 'href' => 'ftp://x'], ['label' => ''],
    ]], 'c');
    $bar->indicator(['id' => 'c.n', 'parent' => 'c.m', 'type' => 'count', 'value' => fn() => 4], 'c');
    $bar->indicator(['id' => 'c.zero', 'parent' => 'c.m', 'type' => 'count', 'value' => fn() => 0], 'c');
    $bar->indicator(['id' => 'c.s', 'parent' => 'c.m', 'type' => 'status', 'value' => fn() => ['value' => 'Warning', 'tone' => 'warning']], 'c');
    $bar->indicator(['id' => 'c.secret', 'parent' => 'c.m', 'type' => 'count', 'capability' => 'manage_options', 'value' => fn() => 9], 'c');
    $bar->panel(['id' => 'c.p', 'parent' => 'c.m', 'label' => 'Stats', 'capability' => 'manage_options', 'data' => fn($ctx) => ['rows' => [['label' => 'ID', 'value' => $ctx->contentId()]]]], 'c');

    $t = $bar->tree(ab_ctx(), ab_user());
    assert_same(['c.m.one'], array_column($t[0]['children'], 'id'));
    assert_same(['c.p'], array_column($t[0]['panels'], 'id'));
    assert_same(['c.n', 'c.zero', 'c.s', 'c.secret'], array_column($t[0]['indicators'], 'id'));

    $v = $bar->runIndicators(['c.n', 'c.zero', 'c.s', 'c.secret', 'nope'], ab_ctx(), ab_user());
    assert_same(['c.n', 'c.s', 'c.secret'], array_keys($v), 'a zero count shows nothing');
    assert_same(4, $v['c.n']['value']);
    assert_same('warning', $v['c.s']['tone']);
    $sub = $bar->runIndicators(['c.secret'], ab_ctx(), ab_user('subscriber'));
    assert_same([], $sub, 'an indicator the user can\'t see is not answered');

    assert_same('7', $bar->runPanel('c.p', ab_ctx(), ab_user())['data']['rows'][0]['value']);
    assert_same(null, $bar->runPanel('c.p', ab_ctx(), ab_user('editor')), 'a panel the user can\'t see does not exist for them');
    assert_same(null, $bar->runPanel('c.m', ab_ctx(), ab_user()), 'only panels are panels');
});

test('admin bar: notifications and search are checked and shaped', function () {
    $bar = new AdminBar(null, false);
    $bar->notifications(['id' => 'd.n', 'items' => fn() => [
        ['title' => 'Low', 'tone' => 'info', 'url' => '/a'], ['title' => 'High', 'tone' => 'danger', 'url' => 'javascript:x'],
        ['title' => ''], 'junk',
    ]], 'd');
    $bar->notifications(['id' => 'd.hidden', 'capability' => 'manage_options', 'items' => fn() => [['title' => 'Secret']]], 'd');
    $n = $bar->runNotifications(ab_user('editor'), ab_ctx());
    assert_same(['High', 'Low'], array_column($n, 'title'), 'most urgent first; invalid ones dropped');
    assert_same(null, $n[0]['url'], 'an unsafe link is dropped');

    $bar->searchProvider(['id' => 'd.s', 'label' => 'Things', 'search' => fn($q, $u, $limit) => [
        ['title' => 'Found ' . $q, 'url' => '/t/1'], ['title' => 'No url'], ['title' => 'Bad', 'url' => 'data:x'],
    ]], 'd');
    assert_false($bar->searchProvider(['id' => 'd.nolabel', 'search' => fn() => []], 'd'));
    $g = $bar->runSearch('lamp', ab_user(), ab_ctx());
    assert_same('Things', $g[0]['label']);
    assert_same(['Found lamp'], array_column($g[0]['items'], 'title'));
    assert_same([], $bar->runSearch('   ', ab_user(), ab_ctx()));
});

test('admin bar: forgetOwner and remove take back an app\'s components only', function () {
    $bar = new AdminBar(null, false);
    $bar->menu(['id' => 'e.m', 'label' => 'E', 'href' => '/e'], 'e');
    $bar->menu(['id' => 'f.m', 'label' => 'F', 'href' => '/f'], 'f');
    $bar->indicator(['id' => 'e.i', 'parent' => 'f.m', 'value' => fn() => 1], 'e');
    assert_false($bar->remove('f.m', 'e'), 'not yours');
    assert_same(2, $bar->forgetOwner('e'));
    assert_same(['f.m'], array_column($bar->tree(ab_ctx(), ab_user()), 'id'));
});

test('admin bar: panel data keeps only the documented shape, as plain text', function () {
    $d = AdminBar::panelData([
        'text' => "<b>Hi</b>\n there", 'score' => ['value' => '82.25', 'max' => 0, 'tone' => 'success', 'label' => 'SEO'],
        'rows' => [['label' => 'Views', 'value' => 12, 'tone' => 'nope'], ['label' => '', 'value' => 'x'], ['label' => 'Arr', 'value' => ['x']]],
        'list' => [['title' => 'T', 'url' => 'javascript:x']], 'actions' => [['label' => 'Go', 'url' => '/go'], ['label' => 'No url']],
        'html' => '<script>', 'empty' => 'Nothing',
    ]);
    assert_same('<b>Hi</b> there', $d['text'], 'kept as text; the browser escapes it');
    assert_same(82.3, $d['score']['value']);
    assert_same(100.0, $d['score']['max']);
    assert_same(['Views', 'Arr'], array_column($d['rows'], 'label'));
    assert_same('', $d['rows'][0]['tone']);
    assert_same(null, $d['list'][0]['url']);
    assert_same(['Go'], array_column($d['actions'], 'label'));
    assert_false(isset($d['html']));
    assert_same(null, AdminBar::url('//evil.example/x'));
    assert_same('/admin/x', AdminBar::url('/admin/x'));
    assert_same('https://a.example/b', AdminBar::url('https://a.example/b'));
});

test('admin bar context: matches() and the signed descriptor', function () {
    $c = AdminBarContext::make(['type' => 'archive', 'archive_kind' => 'taxonomy', 'taxonomy' => 'category', 'term_id' => 3, 'route' => 'shop.index', 'app' => 'shop']);
    assert_true($c->matches(['archive']));
    assert_true($c->matches(['taxonomy:category']));
    assert_false($c->matches(['taxonomy:tag']));
    assert_true($c->matches(['route:shop.*']));
    assert_true($c->matches(['app:shop']));
    assert_false($c->matches(['post', 'single']));
    assert_true($c->matches([]));
    $p = ab_ctx(['content_type' => 'event']);
    assert_true($p->matches(['event']));
    assert_false($p->matches(['post']));

    $d = $p->descriptor('secret-a');
    $back = AdminBarContext::fromDescriptor($d, 'secret-a');
    assert_same(7, $back->contentId());
    assert_same(null, AdminBarContext::fromDescriptor($d, 'secret-b'), 'another session\'s key');
    [$payload, $sig] = explode('.', $d);
    $forged = rtrim(strtr(base64_encode(json_encode(['type' => 'single', 'id' => 999])), '+/', '-_'), '=');
    assert_same(null, AdminBarContext::fromDescriptor($forged . '.' . $sig, 'secret-a'), 'a changed payload');
    assert_same(null, AdminBarContext::fromDescriptor('garbage', 'secret-a'));
});

test('admin bar context: captured from what the page rendered', function () {
    AdminBarContext::capture('single', ['post' => ['id' => 12, 'type' => 'page', 'status' => 'draft', 'author_id' => 2, 'title' => 'T'], 'is_preview' => true]);
    $c = AdminBarContext::current('/t', 200, ['pattern' => '/{slug}', 'options' => []]);
    assert_same('single', $c->type());
    assert_same('page', $c->contentType());
    assert_true($c->isPreview());
    AdminBarContext::capture('archive', ['archive_type' => 'category', 'term' => ['id' => 4, 'name' => 'News']]);
    $c = AdminBarContext::current('/category/news', 200);
    assert_same('taxonomy', $c->archiveKind());
    assert_same('category', $c->taxonomy());
    assert_same(4, $c->termId());
    AdminBarContext::capture('page', ['app_page' => true, 'app_slug' => 'shop', 'title' => 'Shop', 'post' => ['id' => 0]]);
    assert_same('app', AdminBarContext::current('/shop', 200)->type());
    AdminBarContext::reset();
    $c = AdminBarContext::current('/nope', 404, ['pattern' => '/{slug}', 'owner' => null, 'options' => []]);
    assert_same('404', $c->type());
    $c = AdminBarContext::current('/x', 200, ['pattern' => '/shop/{id}', 'owner' => 'shop', 'options' => ['name' => 'shop.item']]);
    assert_same('app', $c->type());
    assert_same('shop.item', $c->route());
    AdminBarContext::reset();
});

test('admin bar settings: every value is checked; users only change what the site allows', function () {
    $clean = AdminBarSettings::clean(['mode' => 'sideways', 'height' => '41', 'roles' => '["editor","Bad Role"]', 'enabled' => '0',
        'auto_hide' => '7', 'user_options' => ['mode', 'hack'], 'color' => 'light']);
    assert_same('horizontal', $clean['mode']);
    assert_same(40, $clean['height']);
    assert_same(['editor'], $clean['roles']);
    assert_false($clean['enabled']);
    assert_same(0, $clean['auto_hide']);
    assert_same(['mode'], $clean['user_options']);
    assert_same('light', $clean['color']);

    assert_same(['mode' => 'off', 'hidden' => ['search']], AdminBarSettings::cleanPrefs(['mode' => 'off', 'hidden' => ['search', 'BAD ID'], 'x' => 1]));
    $s = new AdminBarSettings(fw_settings_group(['user_options' => ['position']]));
    $u = ab_user() + [];
    $u['meta'] = json_encode(['admin_bar' => ['mode' => 'floating', 'position' => 'top-left', 'hidden' => ['search']]]);
    $e = $s->effective($u);
    assert_same('horizontal', $e['mode'], 'mode is not a user option here');
    assert_same('top-left', $e['position']);
    assert_same([], $e['hidden']);
});

/** A settings group in memory. */
function fw_settings_group(array $values): \App\Services\SettingService
{
    return new class($values) extends \App\Services\SettingService {
        public function __construct(public array $values) {}
        public function getGroup(string $group): array { return $this->values; }
    };
}
