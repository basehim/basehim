<?php

declare(strict_types=1);

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxRegistry;
use App\Core\Request;

test('context: user() drops the password hash and the security record, keeps the rest', function () {
    $config = new \App\Core\Config(sys_get_temp_dir() . '/bh-no-config-' . getmypid());
    $action = (new AjaxRegistry($config))->register('core.ctx-test', ['handler' => fn() => null, 'capability' => 'read']);
    $user = [
        'id' => 7, 'username' => 'u', 'password_hash' => '$2y$secret',
        'meta' => json_encode(['security' => ['epoch' => 3, 'two_factor_at' => 1], 'theme' => 'dark']),
    ];
    $ctx = new AjaxContext($action, [], $user, new Request('POST', '/ajax', [], [], [], [], [], []), 'abc', '127.0.0.1');
    $u = $ctx->user();
    assert_false(isset($u['password_hash']));
    assert_same(['theme' => 'dark'], json_decode((string) $u['meta'], true));
    assert_not_contains('epoch', json_encode($u));
    assert_same(7, $ctx->userId());
});
