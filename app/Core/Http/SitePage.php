<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Application;
use App\Core\Response;

/**
 * SitePage — an app's public page inside the active theme (1.2.72).
 *
 *     use App\Core\Http\SitePage;
 *
 *     public function show(Request $request, string $slug): Response
 *     {
 *         $item = $this->find($slug);
 *         if (!$item) return SitePage::notFound();
 *         return SitePage::view($this, 'item', ['item' => $item], [
 *             'title' => $item['name'],
 *             'description' => $item['summary'],
 *         ]);
 *     }
 *
 * view() renders {app}/views/{template}.php — or, when the theme ships one,
 * {theme}/templates/apps/{app-slug}/{template}.php, so a theme can restyle an
 * app's page — and puts the result in the theme's layout: the theme's
 * templates/app.php if it has one (it receives $content and $title), else its
 * page.php, which every theme has. Header, footer, menus, the customizer's
 * styles and the SEO tags come with it, exactly as for a core page.
 *
 * Templates follow core's convention: escape output with htmlspecialchars()
 * (the template gets $e as a shortcut: <?= $e($item['name']) ?>). Whatever a
 * template echoes unescaped is the app's responsibility.
 *
 * A page that should not use the theme returns its own Response: JSON with
 * Response::json(), a redirect with Response::redirect(), plain text with
 * Response::make($text)->header('Content-Type', 'text/plain; charset=utf-8'),
 * or 'layout' => false here for the template's HTML alone.
 *
 * Page options: title, description, status (default 200), layout (default
 * true), robots (e.g. 'noindex').
 */
final class SitePage
{
    /**
     * Render an app template in the theme.
     *
     * @param object $app the app (its path() and slug() locate the template)
     */
    public static function view(object $app, string $template, array $data = [], array $page = []): Response
    {
        $slug = method_exists($app, 'slug') ? (string) $app->slug() : '';
        $dir = method_exists($app, 'path') ? (string) $app->path() : '';
        if (!preg_match('/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/', $template) || $dir === '') {
            return self::failure($slug, "Template name '{$template}' is not valid (letters, digits, '-', '_' and '/').");
        }
        $file = self::locate($slug, $dir, $template);
        if ($file === null) {
            return self::failure($slug, "Template '{$template}' was not found in views/ of the app (nor in the theme's templates/apps/{$slug}/).");
        }
        $html = self::include($file, $data + ['app' => $app]);
        return self::html((string) ($page['title'] ?? ''), $html, $page + ['app' => $slug]);
    }

    /**
     * Put ready-made HTML in the theme. The HTML is used as given: build it
     * with escaped values.
     */
    public static function html(string $title, string $html, array $page = []): Response
    {
        $status = (int) ($page['status'] ?? 200);
        if ($status < 200 || $status > 599) $status = 200;
        if (array_key_exists('layout', $page) && !$page['layout']) {
            return Response::html($html, $status);
        }
        try {
            $response = (new \App\Http\Controllers\Web\SitePageController())->page($title, $html, $page);
        } catch (\Throwable $e) {
            self::log('', 'error', 'The theme could not render an app page: ' . $e->getMessage());
            return Response::html($html, $status);
        }
        return $response->status($status);
    }

    /** The theme's own 404 page. */
    public static function notFound(string $message = 'Page not found'): Response
    {
        try {
            return (new \App\Http\Controllers\Web\SitePageController())->missing($message);
        } catch (\Throwable) {
            return self::error(404, 'Not Found', $message);
        }
    }

    /**
     * A short error page in the theme (falls back to plain HTML). For 4xx/5xx
     * answers to visitors; the detail is shown, so keep internals out of it.
     */
    public static function error(int $status, string $title, string $detail): Response
    {
        $body = '<div class="bh-site-error" style="padding:3rem 1rem;text-align:center"><h1>' . htmlspecialchars("{$status} {$title}")
            . '</h1><p>' . htmlspecialchars($detail) . '</p></div>';
        try {
            $r = (new \App\Http\Controllers\Web\SitePageController())->page("{$status} {$title}", $body, ['robots' => 'noindex']);
            return $r->status($status);
        } catch (\Throwable) {
            return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars("{$status} {$title}") . '</title></head><body>' . $body . '</body></html>', $status);
        }
    }

    // ------------------------------------------------------------------

    private static function locate(string $slug, string $dir, string $template): ?string
    {
        try {
            $theme = Application::getInstance()->make(\App\Services\ThemeService::class)->activePath();
            if ($slug !== '' && preg_match('/^[a-z0-9_-]+$/i', $slug)) {
                $override = $theme . '/templates/apps/' . $slug . '/' . $template . '.php';
                if (is_file($override)) return $override;
            }
        } catch (\Throwable) {}
        $own = rtrim($dir, '/') . '/views/' . $template . '.php';
        return is_file($own) ? $own : null;
    }

    private static function include(string $__file, array $__data): string
    {
        $__data['e'] = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $__data['base'] = $__data['base'] ?? (defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '');
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private static function failure(string $slug, string $why): Response
    {
        self::log($slug, 'error', $why);
        return self::error(500, 'Server Error', 'This page could not be shown. The error has been logged.');
    }

    private static function log(string $slug, string $level, string $msg): void
    {
        try { Application::getInstance()->make(\App\Core\Logger::class)->{$level}(($slug !== '' ? "App '{$slug}': " : '') . $msg); } catch (\Throwable) {}
        if ($slug !== '') {
            try { Application::getInstance()->make(\App\Services\AppLogger::class)->log($slug, $level, $msg, []); } catch (\Throwable) {}
        }
    }
}
