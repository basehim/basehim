<?php

declare(strict_types=1);

use App\Core\Ajax\AjaxException;
use App\Core\Ajax\AjaxRegistry;
use App\Core\Config;

function fresh_registry(): AjaxRegistry
{
    return new AjaxRegistry(new Config(BASEHIM_ROOT . '/config'));
}

function ok_def(array $extra = []): array
{
    return $extra + ['handler' => fn() => ['ok' => true], 'capability' => 'read'];
}

final class RegistryTestHandler
{
    public function handle(): array { return []; }
    public function __call($n, $a) { return null; }
}

test('registry: a valid core action registers with strict defaults', function () {
    $r = fresh_registry();
    $a = $r->register('posts.autosave', ok_def(['capability' => 'edit_posts']));
    assert_same(['POST'], $a->methods);
    assert_same('user', $a->auth);
    assert_true($a->csrf, 'csrf on by default');
    assert_same(['edit_posts'], $a->capabilities);
    assert_true($a->rateLimit !== null && $a->rateLimit['by'] === 'user', 'rate-limited per user by default');
    assert_true($a->input->isEmpty(), 'no input accepted unless declared');
    assert_true($r->has('posts.autosave'));
});

test('registry: duplicate names are refused, not overwritten', function () {
    $r = fresh_registry();
    $first = $r->register('posts.save', ok_def());
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('posts.save', ok_def()), 'already registered');
    assert_true($r->get('posts.save') === $first, 'the original stays');
});

test('registry: core may only use reserved namespaces', function () {
    assert_throws(\InvalidArgumentException::class, fn() => fresh_registry()->register('my-app.save', ok_def()), 'reserved core namespace');
});

test('registry: an app may only register under its own slug', function () {
    $r = fresh_registry();
    $r->register('my-app.save', ok_def(), 'my-app');
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('media.update', ok_def(), 'my-app'), "only register AJAX actions under 'my-app.'");
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('other-app.save', ok_def(), 'my-app'));
});

test('registry: an app cannot take a core name, whichever registers first', function () {
    $r = fresh_registry();
    // The app tries first (apps boot before core's route files load)…
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('core.csrf', ok_def(), 'my-app'));
    // …and core still gets its name.
    $r->register('core.csrf', ['handler' => fn() => null, 'auth' => 'public']);
    assert_same('core', $r->get('core.csrf')->owner);
    // And after core has it, the app is refused again.
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('core.csrf', ok_def(), 'my-app'));
});

test('registry: an app whose slug is a reserved namespace cannot register at all', function () {
    assert_same(null, AjaxRegistry::namespaceForApp('media'));
    assert_same(null, AjaxRegistry::namespaceForApp('Posts'));
    assert_same('my-app', AjaxRegistry::namespaceForApp('My-App'));
    assert_throws(\InvalidArgumentException::class, fn() => fresh_registry()->register('media.thing', ok_def(), 'media'), 'slug is reserved');
});

test('registry: names must follow namespace.action in lower case', function () {
    $r = fresh_registry();
    foreach (['save', 'Posts.save', 'posts.', '.save', 'posts..save', 'posts.save/../x', 'posts.sa ve', 'posts.save;rm', str_repeat('a', 90) . '.x'] as $bad) {
        assert_throws(\InvalidArgumentException::class, fn() => $r->register($bad, ok_def()), 'Invalid AJAX action name');
    }
});

test('registry: handlers must be closures or [class|object, method] — never strings', function () {
    $r = fresh_registry();
    $bad = [
        'phpinfo',
        'App\\Core\\Env::all',
        'App\\Core\\Env@all',
        ['App\\Core\\Env'],
        ['App\\Core\\Env', 'all', 'extra'],
        ['App\\Core\\Env', '__construct'],
        [new RegistryTestHandler(), '__call'],
        ['../../etc/passwd', 'x'],
        ['App\\Core\\Env', 'a-b'],
        null,
    ];
    foreach ($bad as $i => $h) {
        assert_throws(\InvalidArgumentException::class, fn() => $r->register("posts.h{$i}", ['handler' => $h, 'capability' => 'read']));
    }
    $r->register('posts.ok1', ['handler' => [RegistryTestHandler::class, 'handle'], 'capability' => 'read']);
    $r->register('posts.ok2', ['handler' => [new RegistryTestHandler(), 'handle'], 'capability' => 'read']);
});

test('registry: signed-in actions must declare a capability', function () {
    assert_throws(\InvalidArgumentException::class,
        fn() => fresh_registry()->register('posts.x', ['handler' => fn() => null]), "must declare a 'capability'");
});

test('registry: public actions are explicit and take no capability', function () {
    $r = fresh_registry();
    $a = $r->register('core.ping', ['handler' => fn() => null, 'auth' => 'public']);
    assert_true($a->isPublic());
    assert_same('ip', $a->rateLimit['by']);
    assert_throws(\InvalidArgumentException::class,
        fn() => $r->register('core.ping2', ['handler' => fn() => null, 'auth' => 'public', 'capability' => 'read']));
    assert_throws(\InvalidArgumentException::class,
        fn() => $r->register('core.ping3', ['handler' => fn() => null, 'auth' => 'guest']));
});

test('registry: CSRF can be switched off only on public actions', function () {
    $r = fresh_registry();
    assert_throws(\InvalidArgumentException::class, fn() => $r->register('posts.x', ok_def(['csrf' => false])), 'cannot switch CSRF');
    $a = $r->register('core.beacon', ['handler' => fn() => null, 'auth' => 'public', 'csrf' => false]);
    assert_false($a->csrf);
});

test('registry: public actions must keep a rate limit; signed-in ones may opt out', function () {
    $r = fresh_registry();
    assert_throws(\InvalidArgumentException::class,
        fn() => $r->register('core.x', ['handler' => fn() => null, 'auth' => 'public', 'rate_limit' => false]), 'must keep a rate limit');
    assert_same(null, $r->register('posts.x', ok_def(['rate_limit' => false]))->rateLimit);
    assert_throws(\InvalidArgumentException::class,
        fn() => $r->register('core.y', ['handler' => fn() => null, 'auth' => 'public', 'rate_limit' => ['by' => 'user']]));
});

test('registry: every option is validated', function () {
    $r = fresh_registry();
    $bad = [
        ['methods' => ['DELETE']],
        ['methods' => []],
        ['csrf' => 'yes'],
        ['max_body' => 10],
        ['max_body' => 5 * 1024 * 1024],
        ['rate_limit' => ['max' => 0]],
        ['rate_limit' => ['per' => 999999]],
        ['rate_limit' => ['by' => 'cookie']],
        ['rate_limit' => ['fail' => 'maybe']],
        ['rate_limit' => ['burst' => 3]],
        ['idempotent' => true, 'methods' => ['GET']],
        ['capability' => 'Edit Posts'],
        ['capability' => []],
        ['input' => ['t' => 'requred|string']],
        ['permission' => 'x'],                 // unknown option
    ];
    foreach ($bad as $i => $opts) {
        assert_throws(\InvalidArgumentException::class, fn() => $r->register("posts.o{$i}", ok_def($opts)));
    }
});

test('registry: GET and POST may both be declared', function () {
    $a = fresh_registry()->register('posts.list', ok_def(['methods' => ['get', 'POST']]));
    assert_same(['GET', 'POST'], $a->methods);
    assert_true($a->allowsMethod('GET'));
    assert_false($a->allowsMethod('PUT'));
});

test('AjaxException: codes and statuses are constrained', function () {
    assert_throws(\InvalidArgumentException::class, fn() => new AjaxException('lower', 'm'));
    assert_throws(\InvalidArgumentException::class, fn() => new AjaxException('OK', 'm', 200));
    $e = new AjaxException('QUOTA_EXCEEDED', 'Quota used.', 409);
    assert_same(409, $e->status);
});
