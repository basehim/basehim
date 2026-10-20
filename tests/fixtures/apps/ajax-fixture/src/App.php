<?php

declare(strict_types=1);

namespace BasehimTests\AjaxFixture;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Ajax\AjaxRegistry;

/**
 * Test fixture: one AJAX action per behaviour the integration tests check,
 * plus registrations that must be refused (their refusals land in this app's
 * log, which the tests read).
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        // Front-end client, from boot() (before bootstrap defines bh_enqueue_*).
        $this->useAjaxClient();

        // A normal signed-in action with a schema.
        $this->ajax('echo', [
            'handler'    => [$this, 'echo'],
            'capability' => 'read',
            'input'      => [
                'msg'  => 'required|string|max:20',
                'n'    => 'int|min:1|max:10',
                'tags' => ['type' => 'array', 'max' => 3, 'items' => 'string|max:10'],
                'meta' => ['type' => 'object', 'fields' => ['color' => 'string|in:red,blue']],
            ],
        ]);

        $this->ajax('admin-only', [
            'handler'    => fn(AjaxContext $c) => ['ok' => true],
            'capability' => 'manage_options',
        ]);

        // Public, CSRF off: runs as a visitor even when the caller is signed in.
        $this->ajax('public-ping', [
            'handler'    => fn(AjaxContext $c) => ['signed_in' => $c->userId() !== null],
            'auth'       => 'public',
            'csrf'       => false,
            'rate_limit' => ['max' => 50, 'per' => 60, 'by' => 'ip'],
        ]);

        // Public with CSRF: a visitor's session token is required.
        $this->ajax('public-form', [
            'handler' => fn(AjaxContext $c) => ['received' => $c->input('email')],
            'auth'    => 'public',
            'input'   => ['email' => 'required|string|email|max:120'],
        ]);

        // Public with a tiny per-IP limit, for the X-Forwarded-For spoofing test.
        $this->ajax('public-tiny', [
            'handler'    => fn(AjaxContext $c) => ['ip' => $c->ip()],
            'auth'       => 'public',
            'csrf'       => false,
            'rate_limit' => ['max' => 3, 'per' => 60, 'by' => 'ip'],
        ]);

        $this->ajax('read', [
            'handler'    => fn(AjaxContext $c) => ['page' => $c->input('page', 1), 'flag' => $c->input('flag')],
            'methods'    => ['GET'],
            'capability' => 'read',
            'input'      => ['page' => 'int|min:1', 'flag' => 'bool'],
        ]);

        $this->ajax('boom', [
            'handler'    => function (AjaxContext $c) {
                throw new \RuntimeException('SQLSTATE[42S02]: secret_table missing in /var/www/secret/path.php');
            },
            'capability' => 'read',
        ]);

        $this->ajax('safe-error', [
            'handler'    => fn(AjaxContext $c) => throw new AjaxException('QUOTA_EXCEEDED', 'You have used your quota.', 409),
            'capability' => 'read',
        ]);

        $this->ajax('noisy', [
            'handler'    => function (AjaxContext $c) { echo '<b>debug output /var/www</b>'; return ['ok' => true]; },
            'capability' => 'read',
        ]);

        $this->ajax('limited', [
            'handler'    => fn(AjaxContext $c) => ['ok' => true],
            'capability' => 'read',
            'rate_limit' => ['max' => 5, 'per' => 60],
        ]);

        $this->ajax('once', [
            'handler'    => fn(AjaxContext $c) => ['done' => true],
            'capability' => 'read',
            'idempotent' => true,
        ]);

        $this->ajax('once-failing', [
            'handler'    => fn(AjaxContext $c) => throw new AjaxException('TRY_AGAIN', 'Failed, retry.', 503),
            'capability' => 'read',
            'idempotent' => true,
        ]);

        $this->ajax('small', [
            'handler'    => fn(AjaxContext $c) => ['ok' => true],
            'capability' => 'read',
            'max_body'   => 512,
            'input'      => ['blob' => 'string'],
        ]);

        // --- admin pages (AppAdminAccessTest) ------------------------------
        // One under the app's menu URL, one outside it, and a POST.
        $this->addAdminMenu(['url' => '/admin/ajax-fixture', 'label' => 'AJAX Fixture', 'icon' => 'beaker']);
        $this->adminGet('/ajax-fixture', fn() => \App\Core\Response::html('fixture admin page'));
        $this->adminGet('/ajax-fixture-tools', fn() => \App\Core\Response::html('fixture tools page'));
        $this->adminPost('/ajax-fixture/save', function (\App\Core\Request $r) {
            $ok = $this->app->make(\App\Core\Session::class)->verifyCsrf((string) ($r->input('_csrf') ?? ''));
            return \App\Core\Response::json(['saved' => $ok], $ok ? 200 : 419);
        });

        // A REST-style route that fails (ApiHardeningTest: the 500 body).
        $this->get('/api/v1/ajax-fixture/boom', function () {
            throw new \RuntimeException('SQLSTATE[42S02]: secret_table missing in /var/www/secret/path.php');
        });

                // --- registrations that must be refused --------------------------

        // Duplicate of our own action.
        $this->ajax('echo', ['handler' => fn() => null, 'capability' => 'read']);
        // Missing capability on a signed-in action.
        $this->ajax('no-cap', ['handler' => fn() => null]);
        // CSRF off on a signed-in action.
        $this->ajax('no-csrf', ['handler' => fn() => null, 'capability' => 'read', 'csrf' => false]);
        // A string handler (function name).
        $this->ajax('string-handler', ['handler' => 'phpinfo', 'capability' => 'read']);

        // Straight to the registry, claiming a core name / another namespace.
        $registry = $this->app->make(AjaxRegistry::class);
        foreach (['media.update', 'core.csrf', 'other-app.thing'] as $name) {
            try {
                $registry->register($name, ['handler' => fn() => ['hijacked' => true], 'capability' => 'read'], $this->slug);
            } catch (\InvalidArgumentException $e) {
                $this->log('REFUSED ' . $name . ': ' . $e->getMessage(), [], 'warning');
            }
        }

        $this->registerFixtureDashboardCards();
    }

    /** Dashboard widgets (1.2.68) — tests/Integration/DashboardTest.php. */
    private function registerFixtureDashboardCards(): void
    {
        // A native stat card with a setting.
        $this->registerWidget('tally', [
            'surfaces'    => ['dashboard'],
            'title'      => 'Fixture tally',
            'icon'       => 'chart-bar',
            'capability' => 'read',
            'fields'     => [['key' => 'start', 'label' => 'Start at', 'type' => 'number', 'min' => 0, 'max' => 100, 'default' => 7]],
            'dashboard'  => [
                'view' => 'stat',
                'size' => 'third',
                'data' => fn($ctx) => ['items' => [['label' => 'Tally', 'value' => (int) $ctx->setting('start') + 1]]],
            ],
        ]);
        // A card whose provider always fails.
        $this->registerWidget('broken', [
            'surfaces'    => ['dashboard'],
            'title'     => 'Fixture broken',
            'dashboard' => ['view' => 'list', 'data' => function () { throw new \RuntimeException('fixture-secret-detail'); }],
        ]);
        // Administrators only.
        $this->registerWidget('admin-card', [
            'surfaces'    => ['dashboard'],
            'title'      => 'Fixture admin card',
            'capability' => 'manage_options',
            'dashboard'  => ['view' => 'list', 'default' => false, 'data' => fn() => ['items' => [['title' => 'fixture-admin-only-data']]]],
        ]);
        // A pre-1.2.68 widget: HTML from a render callback, every surface.
        $this->registerWidget('legacy-box', [
            'title'  => 'Fixture legacy box',
            'render' => fn(array $s, string $surface) => '<p class="fixture-legacy">legacy on ' . htmlspecialchars($surface) . '</p>',
            'dashboard' => ['width' => 'half', 'priority' => 200],
        ]);
        // Refused: another owner's key, and core's namespace.
        $this->registerWidget('core.hijack', ['title' => 'Nope', 'render' => fn() => 'x']);
    }

    public function echo(AjaxContext $ctx): array
    {
        return [
            'msg'     => $ctx->input('msg'),
            'n'       => $ctx->input('n'),
            'tags'    => $ctx->input('tags'),
            'meta'    => $ctx->input('meta'),
            'keys'    => array_keys($ctx->all()),
            'user_id' => $ctx->userId(),
            'has_hash' => array_key_exists('password_hash', (array) $ctx->user()),
        ];
    }
}
