<?php

declare(strict_types=1);

/*
 * 1.2.77 — the restyled dashboard cards: header tone and inline setting,
 * stat tiles with icons, trend pills, attention badges and people, icons
 * delivered with the data, and the styles compiled into tailwind.min.css.
 */

function dc_state(Browser $b): array
{
    $res = $b->ajaxGet('widgets.dashboard_state');
    assert_same(200, $res->status, $res->body);
    return $res->json()['data'];
}

/** @return array<string,array> widget key => visible item */
function dc_visible(array $state): array
{
    $out = [];
    foreach ($state['layout']['items'] as $it) if ($it['visible']) $out[$it['widget']] = $it;
    return $out;
}

function dc_reset(string $who): void
{
    $id = bh_env()['users'][$who];
    $row = bh_db()->selectOne('SELECT meta FROM {users} WHERE id = :id', ['id' => $id]);
    $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
    unset($meta['dashboard']);
    bh_db()->update('users', ['meta' => $meta ? json_encode($meta) : null], ['id' => $id]);
}

test('cards: an app tile names icons; the data carries their SVG, unknown names are dropped', function () {
    bh_install_app('dc-shop', ['src/App.php' => <<<'PHP'
<?php
declare(strict_types=1);
namespace BhTest\DcShop;
class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->registerWidget('orders', [
            'surfaces' => ['dashboard'], 'title' => 'Orders', 'description' => 'Sales at a glance', 'icon' => 'shopping-cart',
            'capability' => 'read',
            'dashboard' => [
                'view' => 'stat', 'size' => 'half', 'tone' => 'green',
                'data' => fn($ctx) => ['items' => [
                    ['label' => 'Orders', 'value' => 128, 'tone' => 'green', 'icon' => 'shopping-cart',
                     'trend' => ['direction' => 'up', 'text' => '+12%']],
                    ['label' => 'Refunds', 'value' => 3, 'tone' => 'amber', 'icon' => '../../etc/passwd',
                     'badge' => ['text' => '3', 'tone' => 'amber', 'icon' => 'clock']],
                    ['label' => 'Customers', 'value' => 40, 'tone' => 'indigo', 'icon' => 'user-group', 'wide' => true,
                     'people' => ['items' => [['name' => 'Ayesha'], ['name' => 'Bilal', 'url' => 'javascript:alert(1)']], 'more' => 38]],
                ], 'summary' => '128 orders this month', 'summary_icon' => 'banknotes'],
            ],
        ]);
    }
}
PHP]);
    try {
        dc_reset('admin');
        $b = bh_browser('admin');
        $state = dc_state($b);
        $cat = array_column($state['catalog'], null, 'key');
        assert_same('green', $cat['dc-shop.orders']['tone'] ?? null, 'header tone in the catalogue');
        $vis = dc_visible($state);
        assert_true(isset($vis['dc-shop.orders']), 'placed by default');
        $id = $vis['dc-shop.orders']['id'];
        $w = $b->ajaxGet('widgets.dashboard_data', ['ids' => $id])->json()['data']['widgets'][$id];
        assert_true($w['ok'], json_encode($w));
        [$orders, $refunds, $people] = $w['data']['items'];
        assert_same('shopping-cart', $orders['icon']);
        assert_same('+12%', $orders['trend']['text']);
        assert_same(null, $refunds['icon'], 'a name that is not an icon is dropped');
        assert_same(['text' => '3', 'tone' => 'amber', 'icon' => 'clock'], $refunds['badge']);
        assert_same('indigo', $people['tone']);
        assert_true($people['wide']);
        assert_same(38, $people['people']['more']);
        assert_same(null, $people['people']['items'][1]['url'], 'only site paths or http(s) for pictures');
        assert_same('banknotes', $w['data']['summary_icon'] ?? null);
        foreach (['shopping-cart', 'clock', 'user-group', 'banknotes'] as $n) {
            assert_true(str_starts_with((string) ($w['icons'][$n] ?? ''), '<svg'), "icon {$n} delivered");
        }
        assert_false(isset($w['icons']['../../etc/passwd']));
    } finally {
        bh_remove_app('dc-shop');
        dc_reset('admin');
    }
});

test('cards: At a glance has icons, tones, a pending badge and the people; Publishing activity has an inline period', function () {
    dc_reset('admin');
    $db = bh_db();
    $post = (int) $db->insert('posts', ['uuid' => bh_uuid(), 'author_id' => bh_env()['users']['admin'], 'type' => 'post', 'status' => 'published',
        'slug' => 'dc-' . bin2hex(random_bytes(4)), 'title' => 'DC', 'content' => '', 'content_format' => 'html', 'published_at' => gmdate('Y-m-d H:i:s')]);
    $db->insert('comments', ['post_id' => $post,
        'author_name' => 'DC', 'author_email' => 'dc@example.test', 'content' => 'waiting', 'status' => 'pending']);
    $b = bh_browser('admin');
    $state = dc_state($b);
    $vis = dc_visible($state);
    $glance = $vis['core.dashboard.at-a-glance']['id'];
    $w = $b->ajaxGet('widgets.dashboard_data', ['ids' => $glance, 'force' => '1'])->json()['data']['widgets'][$glance];
    $items = array_column($w['data']['items'], null, 'label');
    assert_same(['blue', 'document-text'], [$items['Posts']['tone'], $items['Posts']['icon']]);
    assert_same(['violet', 'document'], [$items['Pages']['tone'], $items['Pages']['icon']]);
    assert_same(['green', 'photo'], [$items['Media']['tone'], $items['Media']['icon']]);
    assert_same('amber', $items['Comments']['tone']);
    assert_same('clock', $items['Comments']['badge']['icon'] ?? null, 'pending comments as a badge');
    assert_true((int) $items['Comments']['badge']['text'] >= 1);
    assert_true($items['People']['wide']);
    assert_true(count($items['People']['people']['items']) >= 1);
    assert_same($items['People']['value'], count($items['People']['people']['items']) + $items['People']['people']['more']);

    $cat = array_column($state['catalog'], null, 'key');
    $period = array_column($cat['core.dashboard.publishing-activity']['fields'], null, 'key')['months'];
    assert_true($period['inline'] ?? false, 'shown in the header');
    assert_same(['6', 'year', '12'], array_map('strval', array_keys($period['options'])));
    $pub = $vis['core.dashboard.publishing-activity']['id'];
    $res = $b->ajax('widgets.dashboard_update', ['id' => $pub, 'settings' => json_encode(['months' => 'year'])]);
    assert_same(200, $res->status, $res->body);
    $d = $b->ajaxGet('widgets.dashboard_data', ['ids' => $pub, 'force' => '1'])->json()['data']['widgets'][$pub]['data'];
    assert_same((int) gmdate('n'), count($d['labels']), 'This year: January to this month');
    assert_contains('since January ' . gmdate('Y'), $d['summary']);
    assert_same('document-text', $d['summary_icon']);
    dc_reset('admin');
});

test('cards: the page uses the compiled components; no separate dashboard stylesheet', function () {
    $html = bh_browser('admin')->request('GET', '/admin/dashboard')->body;
    assert_not_contains('dashboard.css', $html);
    assert_contains('bh-btn bh-btn--primary bh-btn--lg', $html);
    assert_false(is_file(BASEHIM_ROOT . '/admin/assets/css/dashboard.css'));
    $css = (string) file_get_contents(BASEHIM_ROOT . '/admin/assets/css/tailwind.min.css');
    foreach (['.bh-btn{', '.bh-btn--lg{', '.bh-card{', '.bh-badge{', '.bh-dcard{', '.bh-btn--icon{', 'bh-dstat--indigo', '.bh-dchart.is-animated .bh-dchart__bar{', '@keyframes bh-grow', 'container-type:inline-size', 'grid-auto-flow:column'] as $needle) {
        assert_contains($needle, $css);
    }
    assert_not_contains('@apply', $css, 'compiled, not source');
    $layout = (string) file_get_contents(BASEHIM_ROOT . '/admin/views/layouts/app.php');
    assert_not_contains('.bh-btn {', $layout, 'components live in the compiled CSS, not the layout');
});

test('cards: the greeting says morning, afternoon or evening in the person\'s own timezone, else the site\'s', function () {
    $g = fn(array $u, string $utc) => \App\Http\Controllers\Admin\DashboardController::greeting($u, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
    assert_same(['hello' => 'Good morning', 'date' => 'Wednesday, 21 October'], $g(['timezone' => 'Asia/Karachi'], '2026-10-21 04:00'));
    assert_same('Good afternoon', $g(['timezone' => 'Asia/Karachi'], '2026-10-21 12:00')['hello']);   // 17:00 there
    assert_same('Good evening', $g(['timezone' => 'Asia/Karachi'], '2026-10-21 14:00')['hello']);     // 19:00
    assert_same('Good evening', $g(['timezone' => 'Asia/Karachi'], '2026-10-21 21:30')['hello']);     // 02:30
    assert_same('Good afternoon', $g(['timezone' => 'America/New_York'], '2026-10-21 18:00')['hello']); // 14:00
    $html = bh_browser('admin')->request('GET', '/admin/dashboard')->body;
    assert_contains('bh-dash-head__date', $html);
    assert_contains('what&rsquo;s happening on', $html);
});
