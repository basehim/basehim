<?php

declare(strict_types=1);

/*
 * 1.2.50: Settings screens save through the AJAX gateway (settings.save) with
 * the same checks as the form routes; admin pages are titled
 * "{Page} - {Site} - Basehim", use the site's icon, and are never indexed.
 */

function settings_value(string $group, string $key): ?string
{
    $row = bh_db()->selectOne('SELECT setting_value FROM {settings} WHERE setting_group = :g AND setting_key = :k', ['g' => $group, 'k' => $key]);
    return $row ? (string) $row['setting_value'] : null;
}

function settings_set(string $group, string $key, string $value): void
{
    \App\Core\Application::getInstance()->make(\App\Services\SettingService::class)->set($group, $key, $value);
}

test('settings.save: a screen saves as its form would post, hidden "0" and checkbox "1" included', function () {
    $res = bh_browser('admin')->ajax('settings.save', [
        'tab' => 'reading',
        'form' => http_build_query(['posts_per_page' => '7']) . '&author_box=0&author_box=1&discourage_search=0',
    ]);
    assert_same(200, $res->status, $res->body);
    $d = $res->json()['data'];
    assert_same('success', $d['type']);
    assert_same('Reading settings saved.', $d['message']);
    assert_same('7', settings_value('reading', 'posts_per_page'));
    assert_same('1', settings_value('reading', 'author_box'), 'the checked box wins over its hidden 0, as in a POST');
    assert_same('0', settings_value('reading', 'discourage_search'));
    assert_same('7', $d['values']['posts_per_page'] ?? null, 'stored values come back');
});

test('settings.save: refused values are not saved, and the reason comes back', function () {
    $before = settings_value('general', 'timezone');
    $res = bh_browser('admin')->ajax('settings.save', ['tab' => 'general', 'form' => 'timezone=Mars%2FBase&tagline=nope']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_same('Choose a timezone from the list.', $res->json()['error']['message']);
    assert_same($before, settings_value('general', 'timezone'));
    assert_true(settings_value('general', 'tagline') !== 'nope', 'nothing from the refused form was stored');

    assert_error(bh_browser('admin')->ajax('settings.save', ['tab' => 'nope', 'form' => 'a=1']), 422, 'VALIDATION_FAILED');
});

test('settings.save: email gets the same clean-up as the form (default sender, clamped port)', function () {
    $res = bh_browser('admin')->ajax('settings.save', ['tab' => 'email', 'form' => 'from_email=&driver=smtp&smtp_port=99999&smtp_encryption=rot13']);
    assert_same(200, $res->status, $res->body);
    $v = $res->json()['data']['values'];
    assert_contains('noreply@', $v['from_email']);
    assert_same('587', $v['smtp_port']);
    assert_same('tls', $v['smtp_encryption']);
    assert_true(!array_key_exists('smtp_password', $v), 'the SMTP password is never sent back');
    bh_browser('admin')->ajax('settings.save', ['tab' => 'email', 'form' => 'driver=mail']);
});

test('settings.save: only people who may change settings', function () {
    foreach (['editor', 'author', 'subscriber'] as $who) {
        assert_error(bh_browser($who)->ajax('settings.save', ['tab' => 'reading', 'form' => 'posts_per_page=1']), 403, 'FORBIDDEN', $who);
    }
    assert_true(settings_value('reading', 'posts_per_page') !== '1');
});

test('admin head: "{Page} - {Site} - Basehim", the site icon when set, never indexed', function () {
    settings_set('general', 'site_title', 'Title Test Site');
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/dashboard');
    assert_same(200, $page->status);
    assert_contains('<title>Admin Dashboard - Title Test Site - Basehim</title>', $page->body);
    assert_contains('<meta name="robots" content="noindex, nofollow">', $page->body);
    assert_same('noindex, nofollow', $page->header('X-Robots-Tag'));
    assert_contains('/admin/assets/img/favicon-32.png', $page->body, 'no site icon: the Basehim mark');

    assert_contains('<title>Apps - Title Test Site - Basehim</title>', $b->request('GET', '/admin/apps')->body);

    settings_set('appearance', 'favicon_url', '/uploads/2026/10/site-icon.png');
    $page = $b->request('GET', '/admin/settings/general');
    assert_contains('<title>General Settings - Title Test Site - Basehim</title>', $page->body);
    assert_contains('<link rel="icon" href="/uploads/2026/10/site-icon.png">', $page->body);
    settings_set('appearance', 'favicon_url', '');

    $login = (new Browser(bh_env()['base_url']))->request('GET', '/admin/login');
    assert_contains('<title>Sign in - Title Test Site - Basehim</title>', $login->body);
    assert_same('noindex, nofollow', $login->header('X-Robots-Tag'));
    $home = (new Browser(bh_env()['base_url']))->request('GET', '/');
    assert_same(null, $home->header('X-Robots-Tag'), 'public pages are not touched');
});
