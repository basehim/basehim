<?php

declare(strict_types=1);

/*
 * 1.2.96 — the frontend admin bar end to end: who gets it and who doesn't,
 * what visitors pay (nothing), the page's own menu for each kind of page,
 * the AJAX actions and their checks (signed context, hidden components),
 * user choices and site settings, and four example apps plus a broken one
 * registering through $this->api()->adminBar().
 */

const AB_APPS = [
    'ab-analytics' => <<<'PHP'
<?php
namespace BhTest\AbAnalytics;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu(['id' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart-bar', 'priority' => 60]);
        $bar->item(['id' => 'overview', 'parent' => 'analytics', 'label' => 'Overview', 'href' => '/admin/settings/reading']);
        $bar->panel(['id' => 'page', 'parent' => 'analytics', 'label' => 'This page', 'context' => ['single'],
            'data' => fn($ctx, $user) => ['rows' => [['label' => 'Views today', 'value' => (string) ($ctx->contentId() * 3)]],
                                          'actions' => [['label' => 'Full report', 'url' => '/admin/settings/reading']]]]);
        $bar->indicator(['id' => 'live', 'parent' => 'analytics', 'type' => 'count', 'tone' => 'info', 'label' => 'visitors now', 'value' => fn() => 3]);
        // Registered once the page is known.
        $this->addAction('admin_bar.register', function ($ctx, $user) {
            if ($ctx->type() === 'single') {
                $this->api()->adminBar()->item(['id' => 'hooked', 'parent' => 'analytics', 'label' => 'Stats for this ' . $ctx->contentType(), 'href' => '/admin']);
            }
        }, 10, 2);
    }
}
PHP,
    'ab-seo' => <<<'PHP'
<?php
namespace BhTest\AbSeo;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->item(['id' => 'seo', 'parent' => 'context', 'label' => 'Edit SEO', 'icon' => 'document-magnifying-glass',
            'context' => ['post', 'page'], 'capability' => 'manage_seo', 'href' => '/admin/settings/seo', 'group' => 'SEO']);
        $bar->panel(['id' => 'score', 'parent' => 'context', 'label' => 'SEO score', 'context' => ['post', 'page'], 'capability' => 'manage_seo',
            'data' => fn($ctx) => ['score' => ['value' => 82, 'max' => 100, 'tone' => 'success', 'label' => 'SEO score'],
                                   'list' => [['title' => 'Title length is good', 'tone' => 'success']]]]);
        $bar->indicator(['id' => 'score-badge', 'parent' => 'seo', 'type' => 'text', 'tone' => 'success', 'lazy' => false, 'value' => fn() => '82']);
    }
}
PHP,
    'ab-comments' => <<<'PHP'
<?php
namespace BhTest\AbComments;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu(['id' => 'discussion', 'label' => 'Discussion', 'icon' => 'chat-bubble-left-right', 'capability' => 'moderate_comments', 'priority' => 61]);
        $bar->item(['id' => 'pending', 'parent' => 'discussion', 'label' => 'Pending comments', 'href' => '/admin/comments?status=pending']);
        $bar->indicator(['id' => 'count', 'parent' => 'pending', 'type' => 'count', 'value' => fn() => 5]);
        $bar->notifications(['id' => 'notes', 'capability' => 'moderate_comments',
            'items' => fn() => [['id' => 'p', 'title' => 'AB comments: 5 waiting', 'tone' => 'warning', 'url' => '/admin/comments']]]);
    }
}
PHP,
    'ab-security' => <<<'PHP'
<?php
namespace BhTest\AbSecurity;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu(['id' => 'security', 'label' => 'Security', 'icon' => 'shield-check', 'capability' => 'manage_options', 'region' => 'end', 'priority' => 5]);
        $bar->item(['id' => 'scan', 'parent' => 'security', 'label' => 'Scan the site', 'href' => '/admin/system']);
        $bar->indicator(['id' => 'status', 'parent' => 'security', 'type' => 'status', 'value' => fn() => ['value' => 'Warning', 'tone' => 'warning']]);
        $bar->search(['id' => 'events', 'label' => 'Security events', 'capability' => 'manage_options',
            'search' => fn($q, $u, $limit) => [['title' => 'Login blocked for ' . $q, 'url' => '/admin/system', 'meta' => 'Event']]]);
    }
}
PHP,
    'ab-broken' => <<<'PHP'
<?php
namespace BhTest\AbBroken;

class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu(['id' => 'boom', 'label' => 'Boom', 'href' => '/admin', 'visible' => function () { throw new \RuntimeException('visible exploded'); }]);
        $bar->menu(['id' => 'flaky', 'label' => 'Flaky']);
        $bar->item(['id' => 'ok', 'parent' => 'flaky', 'label' => 'Still here', 'href' => '/admin']);
        $bar->panel(['id' => 'bad', 'parent' => 'flaky', 'label' => 'Bad panel', 'data' => function () { throw new \LogicException('panel exploded'); }]);
        $bar->item(['id' => 'js', 'parent' => 'flaky', 'label' => 'Script', 'href' => 'javascript:alert(1)']);
        $bar->item(['id' => 'act', 'parent' => 'flaky', 'label' => 'Act', 'action' => 'quick-edit']);
        $bar->indicator(['id' => 'cnt', 'parent' => 'ab-analytics.analytics', 'type' => 'count', 'value' => function () { throw new \RuntimeException('count exploded'); }]);
        $this->router()->site()->get('/ab-optout', fn() => \App\Core\Response::html('<!doctype html><html><head></head><body><p>No bar here</p></body></html>')
            ->header('X-Basehim-Admin-Bar', 'off'));
    }
}
PHP,
];

function ab_setup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (AB_APPS as $slug => $code) {
        bh_install_app($slug, ['src/App.php' => $code], ['namespace' => 'BhTest\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)))]);
    }
}

/** The bar's config from a page, or null when there is no bar. */
function ab_cfg(\HttpResponse $r): ?array
{
    if (!preg_match('/window\.BASEHIM_ADMIN_BAR = (\{.*?\});<\/script>/s', $r->body, $m)) return null;
    return json_decode($m[1], true);
}

function ab_post(string $author = 'admin', string $status = 'published', string $type = 'post'): array
{
    $slug = 'ab-' . bin2hex(random_bytes(4));
    $id = (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$author], 'type' => $type, 'status' => $status, 'slug' => $slug,
        'title' => 'Bar post ' . $slug, 'content' => '<p>Words for the bar test.</p>', 'content_format' => 'html',
        'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
    ]);
    return ['id' => $id, 'slug' => $slug, 'url' => ($type === 'page' ? '/' : '/posts/') . $slug];
}

/** Save Settings → Admin bar through the real form. */
function ab_settings(array $fields): void
{
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/settings/toolbar');
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    $defaults = ['enabled' => '1', 'mode' => 'horizontal', 'position' => 'bottom-right', 'mobile' => 'same', 'context' => '1', 'search' => '1',
        'notifications' => '1', 'shortcuts' => '1', 'color' => 'dark', 'density' => 'comfortable', 'labels' => 'labels', 'branding' => '1',
        'avatar' => '1', 'height' => '40', 'float_size' => '48', 'panel_width' => '360', 'align' => 'start', 'open_on' => 'click',
        'auto_hide' => '0', 'remember' => '1', 'user_options' => ['mode' => '1', 'position' => '1', 'sections' => '1']];
    $r = $b->request('POST', '/admin/settings/toolbar', http_build_query(['_csrf' => html_entity_decode($m[1] ?? '')] + $fields + $defaults),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $r->status, 'settings saved');
}

test('admin bar: visitors get nothing — no markup, no assets, no session', function () {
    $p = ab_post();
    $page = null;
    $anon = new \Browser(bh_env()['base_url']);
    $r = $anon->request('GET', $p['url']);
    assert_same(200, $r->status);
    assert_not_contains('bh-admin-bar', $r->body);
    assert_not_contains('admin-bar.css', $r->body);
    // Pages without a comment form start no session (BH-082: the widget
    // areas used to start one for every visitor on every page).
    unset($page);
    foreach (['/', '/search?q=bar', '/no-such-page-xyz'] as $url) {
        $v = (new \Browser(bh_env()['base_url']))->request('GET', $url);
        assert_not_contains('BASEHIMSESS', (string) $v->header('set-cookie'), "no session for a visitor on {$url}");
        assert_not_contains('bh-admin-bar', $v->body);
    }
});

test('admin bar: an administrator gets it on public pages, private and nonced, never in the admin', function () {
    ab_setup();
    $p = ab_post();
    $b = bh_browser('admin');
    $r = $b->request('GET', $p['url']);
    $cfg = ab_cfg($r);
    assert_true($cfg !== null, 'bar config present');
    assert_contains('id="bh-admin-bar"', $r->body);
    assert_contains('private, no-store', (string) $r->header('cache-control'));
    assert_contains('Cookie', (string) $r->header('vary'));
    assert_true((bool) preg_match('/<script nonce="[^"]+" src="[^"]*admin-bar\.js/', $r->body), 'the script carries the page nonce');
    assert_contains('data-bhab-id="context"', $r->body);
    assert_contains('Edit post', $r->body);
    assert_contains('/admin/posts/' . $p['id'] . '/edit', $r->body);
    assert_contains('data-table="content.posts" data-id="' . $p['id'] . '"', $r->body);
    $admin = $b->request('GET', '/admin/dashboard');
    assert_not_contains('id="bh-admin-bar"', $admin->body);
    $home = $b->request('GET', '/');
    assert_contains('id="bh-admin-bar"', $home->body);
});

test('admin bar: roles — subscriber none; contributor sees it but can\'t edit others\' posts', function () {
    $p = ab_post('admin');
    assert_same(null, ab_cfg(bh_browser('subscriber')->request('GET', $p['url'])));
    $r = bh_browser('contributor')->request('GET', $p['url']);
    assert_true(ab_cfg($r) !== null, 'contributor bar: ' . $r->status . ' ' . (str_contains($r->body, 'bh-admin-bar') ? 'has bar markup' : 'no bar'));
    assert_not_contains('/admin/posts/' . $p['id'] . '/edit', $r->body, 'no edit link for someone else\'s post');
    assert_not_contains('data-bhab-action="quick-edit"', $r->body);
    assert_not_contains('data-bhab-id="site.settings"', $r->body, 'Settings is not offered to a contributor');
    $own = ab_post('contributor', 'published');
    $r2 = bh_browser('contributor')->request('GET', $own['url']);
    assert_contains('/admin/posts/' . $own['id'] . '/edit', $r2->body);
});

test('admin bar: the page\'s own menu follows what is shown — category, search, 404, page', function () {
    $b = bh_browser('admin');
    $cat = bh_db()->selectOne("SELECT t.id, t.slug FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id WHERE x.slug = 'category' LIMIT 1");
    if ($cat) {
        $r = $b->request('GET', '/category/' . $cat['slug']);
        assert_contains('Edit category', $r->body);
        assert_contains('/admin/taxonomies/category?edit=' . (int) $cat['id'], str_replace('&amp;', '&', $r->body));
    }
    $s = $b->request('GET', '/search?q=bar');
    assert_contains('data-bhab-panel="context.details"', $s->body);
    assert_contains('Search posts in the admin', $s->body);
    $nf = $b->request('GET', '/no-such-page-' . bin2hex(random_bytes(3)));
    assert_same(404, $nf->status);
    assert_contains('Not found', $nf->body);
    $page = ab_post('admin', 'published', 'page');
    $pr = $b->request('GET', $page['url']);
    assert_contains('Edit page', $pr->body);
    assert_contains('data-table="content.pages"', $pr->body);
});

test('admin bar: AJAX — panels for the shown page, a forged or borrowed context is refused', function () {
    $p = ab_post();
    $b = bh_browser('admin');
    $cfg = ab_cfg($b->request('GET', $p['url']));
    $r = $b->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'context.details']);
    assert_same(200, $r->status, $r->body);
    $rows = array_column($r->json()['data']['data']['rows'] ?? [], 'value', 'label');
    assert_same('Published', $rows['Status'] ?? null);
    assert_same((string) $p['id'], $rows['ID'] ?? null);

    [$payload, $sig] = explode('.', $cfg['ctx']);
    $d = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $d['id'] = 1;
    $forged = rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=') . '.' . $sig;
    $f = $b->ajaxGet('admin.bar_panel', ['ctx' => $forged, 'id' => 'context.details']);
    assert_same(409, $f->status);
    assert_same('CONTEXT_INVALID', $f->errorCode());
    $other = bh_browser('editor')->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'context.details']);
    assert_same('CONTEXT_INVALID', $other->errorCode(), 'another session cannot use this page\'s context');
    $sub = bh_browser('subscriber')->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'context.details']);
    assert_same(403, $sub->status);
    $missing = $b->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'no.such.panel']);
    assert_same(404, $missing->status);
});

test('admin bar: quick search finds only what the user may edit', function () {
    $draft = ab_post('admin', 'draft');
    bh_db()->execute('UPDATE {posts} SET title = :t WHERE id = :id', ['t' => 'Zebracorn admin draft', 'id' => $draft['id']]);
    try { bh_db()->execute("DELETE FROM {search_index} WHERE source = 'content' AND object_id = :id", ['id' => $draft['id']]); } catch (\Throwable) {}
    $p = ab_post();
    $admin = bh_browser('admin');
    $cfg = ab_cfg($admin->request('GET', $p['url']));
    $r = $admin->ajaxGet('admin.bar_search', ['ctx' => $cfg['ctx'], 'q' => 'Zebracorn']);
    assert_same(200, $r->status, $r->body);
    $titles = [];
    foreach ($r->json()['data']['groups'] ?? [] as $g) foreach ($g['items'] as $it) $titles[] = $it['title'];
    assert_true(in_array('Zebracorn admin draft', $titles, true), 'admin finds the draft: ' . json_encode($titles));

    $contrib = bh_browser('contributor');
    $ccfg = ab_cfg($contrib->request('GET', $p['url']));
    $c = $contrib->ajaxGet('admin.bar_search', ['ctx' => $ccfg['ctx'], 'q' => 'Zebracorn']);
    assert_same(200, $c->status);
    $ct = [];
    foreach ($c->json()['data']['groups'] ?? [] as $g) foreach ($g['items'] as $it) $ct[] = $it['title'];
    assert_false(in_array('Zebracorn admin draft', $ct, true), 'a contributor does not find someone else\'s draft');
    $sec = $admin->ajaxGet('admin.bar_search', ['ctx' => $cfg['ctx'], 'q' => 'mallory']);
    $labels = array_column($sec->json()['data']['groups'] ?? [], 'label');
    assert_true(in_array('Security events', $labels, true), 'an app\'s search source');
    $cl = array_column($c->json()['data']['groups'] ?? [], 'label');
    $c2 = $contrib->ajaxGet('admin.bar_search', ['ctx' => $ccfg['ctx'], 'q' => 'mallory']);
    assert_false(in_array('Security events', array_column($c2->json()['data']['groups'] ?? [], 'label'), true));
    unset($cl);
});

test('admin bar: example apps — menus, items in core menus, panels, badges, notices; a broken app hurts nobody', function () {
    ab_setup();
    $p = ab_post();
    $b = bh_browser('admin');
    $r = $b->request('GET', $p['url']);
    $cfg = ab_cfg($r);
    // Analytics: own menu, item, panel, lazy badge.
    assert_contains('data-bhab-id="ab-analytics.analytics"', $r->body);
    assert_contains('data-bhab-panel="ab-analytics.page"', $r->body);
    assert_true(in_array('ab-analytics.live', $cfg['lazy'], true));
    assert_contains('Stats for this post', $r->body, 'registered from admin_bar.register');
    // SEO: an item and a panel in core's page menu, a badge computed with the page.
    assert_contains('data-bhab-id="ab-seo.seo"', $r->body);
    assert_contains('data-bhab-ind="ab-seo.score-badge"', $r->body);
    // Comments and Security.
    assert_contains('data-bhab-id="ab-comments.discussion"', $r->body);
    assert_contains('data-bhab-id="ab-security.security"', $r->body);
    // Broken: the throwing menu is gone; its other menu works; refused definitions are logged.
    assert_not_contains('data-bhab-id="ab-broken.boom"', $r->body);
    assert_contains('data-bhab-id="ab-broken.flaky"', $r->body);
    assert_not_contains('javascript:alert', $r->body);
    $log = bh_app_log('ab-broken');
    assert_contains('visible exploded', $log);
    assert_contains("'href' must be", $log);
    assert_contains("'action' is core's", $log);

    $ind = $b->ajaxGet('admin.bar_indicators', ['ctx' => $cfg['ctx'], 'ids' => implode(',', $cfg['lazy'])])->json()['data']['values'] ?? [];
    assert_same(3, $ind['ab-analytics.live']['value'] ?? null);
    assert_same(5, $ind['ab-comments.count']['value'] ?? null);
    assert_same('Warning', $ind['ab-security.status']['value'] ?? null);
    assert_false(isset($ind['ab-broken.cnt']), 'a throwing badge is left out');

    $panel = $b->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'ab-analytics.page'])->json()['data'];
    assert_same((string) ($p['id'] * 3), $panel['data']['rows'][0]['value']);
    $seo = $b->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'ab-seo.score'])->json()['data'];
    assert_same(82.0, (float) $seo['data']['score']['value']);
    $bad = $b->ajaxGet('admin.bar_panel', ['ctx' => $cfg['ctx'], 'id' => 'ab-broken.bad'])->json()['data'];
    assert_false($bad['ok']);
    assert_contains('panel exploded', bh_app_log('ab-broken'));

    $notes = $b->ajaxGet('admin.bar_notifications', ['ctx' => $cfg['ctx']])->json()['data']['items'] ?? [];
    assert_true(in_array('AB comments: 5 waiting', array_column($notes, 'title'), true));

    // An editor: no Security (manage_options), SEO not shown (manage_seo).
    $er = bh_browser('editor')->request('GET', $p['url']);
    assert_not_contains('data-bhab-id="ab-security.security"', $er->body);
    assert_not_contains('data-bhab-id="ab-seo.seo"', $er->body);
    assert_contains('data-bhab-id="ab-comments.discussion"', $er->body);
    $ecfg = ab_cfg($er);
    $e = bh_browser('editor');
    $e2 = $e->request('GET', $p['url']);
    $eind = $e->ajaxGet('admin.bar_indicators', ['ctx' => ab_cfg($e2)['ctx'], 'ids' => 'ab-security.status,ab-comments.count'])->json()['data']['values'] ?? [];
    assert_false(isset($eind['ab-security.status']), 'asking for a hidden badge gets nothing');
    unset($ecfg);
});

test('admin bar: a page can opt out with the header, which is not sent on', function () {
    ab_setup();
    $r = bh_browser('admin')->request('GET', '/ab-optout');
    assert_same(200, $r->status);
    assert_contains('No bar here', $r->body);
    assert_not_contains('bh-admin-bar', $r->body);
    assert_same(null, $r->header('x-basehim-admin-bar'));
});

test('admin bar: user choices — floating, hidden sections, off; refused when the site doesn\'t allow them', function () {
    $p = ab_post();
    $b = bh_browser('editor');
    $r = $b->ajax('admin.bar_prefs', ['mode' => 'floating', 'hidden' => json_encode(['search'])]);
    assert_same(200, $r->status, $r->body);
    $page = $b->request('GET', $p['url']);
    assert_contains('bhab--floating', $page->body);
    assert_contains('bhab-menu--search is-optional is-user-hidden', $page->body);
    assert_same(422, $b->ajax('admin.bar_prefs', ['mode' => 'sideways'])->status);
    $b->ajax('admin.bar_prefs', ['mode' => 'off']);
    assert_same(null, ab_cfg($b->request('GET', $p['url'])), 'off: no bar at all');
    // The profile brings it back.
    $prof = $b->request('GET', '/admin/profile');
    assert_contains('name="admin_bar_mode"', $prof->body);
    preg_match('/name="_csrf" value="([^"]+)"/', $prof->body, $m);
    $b->request('POST', '/admin/profile/admin-bar', http_build_query(['_csrf' => html_entity_decode($m[1]), 'admin_bar_mode' => 'default']),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    $back = $b->request('GET', $p['url']);
    assert_true(ab_cfg($back) !== null);
    assert_contains('bhab--horizontal', $back->body);

    ab_settings(['user_options' => []]);
    try {
        $x = $b->ajax('admin.bar_prefs', ['mode' => 'floating']);
        assert_same(403, $x->status, 'mode is not a user option now');
        assert_not_contains('name="admin_bar_mode"', $b->request('GET', '/admin/profile')->body);
    } finally {
        ab_settings([]);
        $b->ajax('admin.bar_prefs', ['hidden' => '[]']);
    }
});

test('admin bar: site settings — off for everyone, limited to roles, sections switched off', function () {
    $p = ab_post();
    try {
        ab_settings(['enabled' => '0']);
        assert_same(null, ab_cfg(bh_browser('admin')->request('GET', $p['url'])));
        ab_settings(['roles' => ['editor' => '1']]);
        assert_same(null, ab_cfg(bh_browser('admin')->request('GET', $p['url'])), 'admin is not an editor');
        assert_true(ab_cfg(bh_browser('editor')->request('GET', $p['url'])) !== null);
        ab_settings(['search' => '0', 'notifications' => '0', 'context' => '0', 'color' => 'light', 'mode' => 'floating']);
        $adm = bh_browser('admin');
        $r = $adm->request('GET', $p['url']);
        assert_not_contains('data-bhab-id="search"', $r->body);
        assert_not_contains('data-bhab-id="notifications"', $r->body);
        assert_not_contains('data-bhab-id="context"', $r->body);
        assert_contains('bhab--color-light', $r->body);
        assert_contains('bhab--floating', $r->body);
        $cfg = ab_cfg($r);
        assert_same('FORBIDDEN', $adm->ajaxGet('admin.bar_search', ['ctx' => $cfg['ctx'], 'q' => 'xy'])->errorCode());
    } finally {
        ab_settings([]);
    }
    assert_true(ab_cfg(bh_browser('admin')->request('GET', $p['url'])) !== null);
});
