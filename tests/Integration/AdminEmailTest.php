<?php

declare(strict_types=1);

/*
 * 1.2.66: the administration email (Settings → General), the administrator's
 * account email and ADMIN_EMAIL in .env stay in step — without letting a
 * setting change an account behind My Profile's protections.
 */

function admin_email_setting(): ?string
{
    $row = bh_db()->selectOne("SELECT setting_value FROM {settings} WHERE setting_group = 'general' AND setting_key = 'admin_email'");
    return $row ? trim((string) $row['setting_value'], '"') : null;
}

function admin_email_account(string $user): string
{
    return (string) bh_db()->selectOne('SELECT email FROM {users} WHERE id = :id', ['id' => bh_env()['users'][$user]])['email'];
}

function admin_email_env(): ?string
{
    return preg_match('/^ADMIN_EMAIL=(.*)$/m', (string) file_get_contents(bh_env()['env_file']), $m) ? $m[1] : null;
}

function admin_email_reset(): void
{
    \App\Core\Application::getInstance()->make(\App\Services\SettingService::class)->set('general', 'admin_email', 'admin@example.test');
    foreach (['admin', 'editor'] as $u) {
        bh_db()->update('users', ['email' => $u . '@example.test'], ['id' => bh_env()['users'][$u]]);
    }
}

function admin_email_save(string $user, array $form): \HttpResponse
{
    return bh_browser($user)->ajax('settings.save', ['tab' => 'general', 'form' => http_build_query($form)]);
}

test('admin email: a new address is saved lower-cased and written to .env; the account is left alone', function () {
    admin_email_reset();
    \App\Core\Application::getInstance()->make(\App\Services\SettingService::class)->set('general', 'admin_email', 'someone@example.test');
    $res = admin_email_save('admin', ['admin_email' => 'Notices@Example.TEST']);
    assert_same(200, $res->status, $res->body);
    assert_same('notices@example.test', admin_email_setting());
    assert_same('notices@example.test', admin_email_env(), 'ADMIN_EMAIL written to the .env the site loaded');
    assert_same('admin@example.test', admin_email_account('admin'));
    assert_contains('APP_KEY=', (string) file_get_contents(bh_env()['env_file']), 'the rest of .env is kept');
});

test('admin email: an invalid new address is refused and nothing is saved', function () {
    admin_email_reset();
    $res = admin_email_save('admin', ['admin_email' => 'not-an-address', 'tagline' => 'must not save']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_same('admin@example.test', admin_email_setting());
});

test('admin email: an unchanged older value does not stop the rest of the screen saving', function () {
    admin_email_reset();
    \App\Core\Application::getInstance()->make(\App\Services\SettingService::class)->set('general', 'admin_email', 'admin@localhost');
    $res = admin_email_save('admin', ['admin_email' => 'admin@localhost', 'tagline' => 'Saved with a legacy address']);
    assert_same(200, $res->status, $res->body);
});

test('admin email: your own address changes your account only with your current password', function () {
    admin_email_reset();
    $res = admin_email_save('admin', ['admin_email' => 'owner@example.test', 'admin_email_account' => '1', 'admin_email_password' => 'wrong']);
    assert_error($res, 422, 'VALIDATION_FAILED');
    assert_same('admin@example.test', admin_email_setting(), 'a wrong password saves nothing');
    assert_same('admin@example.test', admin_email_account('admin'));

    $res = admin_email_save('admin', ['admin_email' => 'owner@example.test', 'admin_email_account' => '1', 'admin_email_password' => BH_TEST_PASSWORD]);
    assert_same(200, $res->status, $res->body);
    assert_same('owner@example.test', admin_email_setting());
    assert_same('owner@example.test', admin_email_account('admin'), 'the account changed with it');
    assert_same('owner@example.test', $res->json()['data']['account_email'] ?? null);
    $stored = bh_db()->selectOne("SELECT COUNT(*) AS n FROM {settings} WHERE setting_key IN ('admin_email_account', 'admin_email_password')");
    assert_same(0, (int) $stored['n'], 'the password and the checkbox are never stored');
    $log = bh_db()->selectOne("SELECT COUNT(*) AS n FROM {user_activity_log} WHERE event = 'user.email_changed' AND user_id = :u", ['u' => bh_env()['users']['admin']]);
    assert_true((int) $log['n'] >= 1, 'the account change is in the activity log');
    admin_email_reset();
});

test('admin email: without the checkbox, your account keeps its address', function () {
    admin_email_reset();
    $res = admin_email_save('admin', ['admin_email' => 'notices2@example.test', 'admin_email_password' => BH_TEST_PASSWORD]);
    assert_same(200, $res->status, $res->body);
    assert_same('admin@example.test', admin_email_account('admin'));
});

test('admin email: changing your account email on My Profile moves the administration email with it', function () {
    admin_email_reset();
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/profile');
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $page->body, $m);
    $res = $b->request('POST', '/admin/profile', http_build_query([
        '_csrf' => html_entity_decode($m[1] ?? ''), 'display_name' => 'Admin', 'email' => 'boss@example.test', 'current_password' => BH_TEST_PASSWORD,
    ]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $res->status);
    assert_same('boss@example.test', admin_email_account('admin'));
    assert_same('boss@example.test', admin_email_setting(), 'the setting followed the account');
    assert_same('boss@example.test', admin_email_env());
    admin_email_reset();
});

test('admin email: an account change by someone who may not change settings never moves it', function () {
    admin_email_reset();
    \App\Core\Application::getInstance()->make(\App\Services\SettingService::class)->set('general', 'admin_email', 'editor@example.test');
    $b = bh_browser('editor');
    $page = $b->request('GET', '/admin/profile');
    preg_match('/name="_csrf"\s+value="([^"]+)"/', $page->body, $m);
    $b->request('POST', '/admin/profile', http_build_query([
        '_csrf' => html_entity_decode($m[1] ?? ''), 'display_name' => 'Editor', 'email' => 'ed2@example.test', 'current_password' => BH_TEST_PASSWORD,
    ]), ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same('ed2@example.test', admin_email_account('editor'));
    assert_same('editor@example.test', admin_email_setting(), 'an editor cannot move the administration email');
    assert_error(admin_email_save('editor', ['admin_email' => 'evil@example.test']), 403, 'FORBIDDEN');
    admin_email_reset();
});
