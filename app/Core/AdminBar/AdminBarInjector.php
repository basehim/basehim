<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

use App\Core\Application;
use App\Core\HookRegistry;
use App\Core\Request;
use App\Core\Response;

/**
 * Puts the admin bar into public pages (1.2.96).
 *
 * Called by Router::emit() for every response, before the security headers
 * (so its scripts get the page's CSP nonce). It works with every theme,
 * because it adds the bar to the finished page rather than asking themes to
 * print it, the same way the preview badge and SEO tags are added.
 *
 * Visitors pay nothing: a request without a session cookie is passed on
 * after one isset(), with no session started, no query and no asset. For a
 * signed-in user it costs one query for the user (the same one the admin
 * makes) and the bar's own work; the page is then marked private, so no
 * cache keeps a copy with someone's bar in it.
 *
 * A page can opt out with the X-Basehim-Admin-Bar: off header (removed
 * before sending), and apps and themes with the admin_bar.show filter.
 */
final class AdminBarInjector
{
    public const OPT_OUT_HEADER = 'X-Basehim-Admin-Bar';

    public static function apply(Response $response, Request $request, bool $private, string $path, ?array $route): void
    {
        // Answer the opt-out header in every case, so it never reaches the browser.
        $optOut = self::takeOptOut($response);
        if ($private || $optOut) return;
        if ($request->method !== 'GET') return;
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_name()])) return;

        try {
            self::inject($response, $request, $path, $route);
        } catch (\Throwable $e) {
            try {
                Application::getInstance()->make(\App\Core\Logger::class)->error('Admin bar could not be added to ' . $path . ': ' . $e->getMessage()
                    . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            } catch (\Throwable) {}
        }
    }

    private static function inject(Response $response, Request $request, string $path, ?array $route): void
    {
        $status = $response->getStatus();
        if (!(($status >= 200 && $status < 300) || $status === 404 || $status === 410)) return;
        if (str_starts_with($path, '/api/') || $path === Request::AJAX_PATH || str_starts_with($path, '/mcp') || str_starts_with($path, '/oauth')
            || str_starts_with($path, '/.well-known/')) return;
        if (!self::isHtmlPage($response)) return;
        if (isset($_GET['bh_admin_bar']) && $_GET['bh_admin_bar'] === '0') return;

        $app = Application::getInstance();
        // The Customizer's preview frame shows the site as visitors see it.
        try { if ($app->make(\App\Services\CustomizerService::class)->isPreview()) return; } catch (\Throwable) {}

        $user = $app->make(\App\Services\AuthService::class)->sessionUser();
        if ($user === null) return;
        /** @var AdminBarSettings $settings */
        $settings = $app->make(AdminBarSettings::class);
        if (!$settings->allows($user)) return;
        $eff = $settings->effective($user);
        if ($eff['off']) return;

        $q = is_string($_GET['q'] ?? null) ? (string) $_GET['q'] : '';
        $ctx = AdminBarContext::current($path, $status, $route, $q);
        try {
            $show = $app->make(HookRegistry::class)->applyFilters('admin_bar.show', true, $ctx, $user);
            if ($show === false) return;
        } catch (\Throwable) {}

        /** @var AdminBar $bar */
        $bar = $app->make(AdminBar::class);
        $tree = $bar->tree($ctx, $user, $eff);
        if ($tree === []) return;

        $session = $app->make(\App\Core\Session::class);
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $renderer = new AdminBarRenderer($base);
        $markup = $renderer->render($tree, $eff, $user, $ctx, $ctx->descriptor($session->csrfToken()));

        $html = $response->getBody();
        $early = $renderer->earlyStyle($eff);
        if ($early !== '') {
            $head = stripos($html, '</head>');
            $html = $head === false ? $early . $html : substr($html, 0, $head) . $early . substr($html, $head);
        }
        $pos = strripos($html, '</body>');
        $html = $pos === false ? $html . $markup : substr($html, 0, $pos) . $markup . substr($html, $pos);
        $response->body($html);

        // Someone's own bar is in this page: no shared cache may keep it.
        $response->removeHeader('Cache-Control')->header('Cache-Control', 'private, no-store');
        $vary = '';
        foreach ($response->getHeaders() as $k => $v) if (strcasecmp((string) $k, 'Vary') === 0) $vary = (string) $v;
        if (stripos($vary, 'cookie') === false) $response->removeHeader('Vary')->header('Vary', trim($vary . ($vary !== '' ? ', ' : '') . 'Cookie'));
    }

    private static function isHtmlPage(Response $response): bool
    {
        foreach ($response->getHeaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0 && stripos((string) $value, 'text/html') === false) return false;
            if (strcasecmp((string) $name, 'Content-Disposition') === 0 && stripos((string) $value, 'attachment') !== false) return false;
        }
        $body = $response->getBody();
        if (stripos($body, '</body>') === false) return false;
        $start = ltrim(substr($body, 0, 300));
        return stripos($start, '<!doctype html') === 0 || stripos($start, '<html') === 0;
    }

    private static function takeOptOut(Response $response): bool
    {
        foreach ($response->getHeaders() as $name => $value) {
            if (strcasecmp((string) $name, self::OPT_OUT_HEADER) === 0) {
                $response->removeHeader((string) $name);
                return strtolower(trim((string) $value)) === 'off';
            }
        }
        return false;
    }
}
