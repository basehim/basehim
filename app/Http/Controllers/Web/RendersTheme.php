<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Response;
use App\Services\SettingService;
use App\Services\ThemeService;
use App\Services\MenuService;

trait RendersTheme
{
    protected function renderTheme(string $template, array $data, ?string $url = null): Response
    {
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);

        /*
         * Identity values come through the Customizer rather than straight from
         * settings, so a preview shows the title being typed rather than the one
         * on record. On an ordinary request coreValue() reads the stored value
         * and costs nothing extra — a draft is only ever consulted inside a
         * verified preview.
         */
        $cz = $this->app->make(\App\Services\CustomizerService::class);

        $data['site_title']  = $cz->coreValue('identity', 'site_title', 'Basehim');
        $data['tagline']     = $cz->coreValue('identity', 'tagline', '');
        $data['logo_url']    = $cz->coreValue('identity', 'logo_url', '');
        $data['favicon_url'] = $cz->coreValue('identity', 'favicon_url', '');
        $data['footer_text'] = $cz->coreValue('footer', 'footer_text', '');
        $data['custom_css']  = $settings->get('appearance', 'custom_css', '');

        /*
         * Everything the Customizer needs in <head>: the theme's options as CSS
         * custom properties, the site's custom CSS, and — inside a preview only
         * — the script that applies pending changes live.
         *
         * Themes echo this in one place instead of assembling it themselves.
         * A theme that does not is simply not customisable, rather than broken.
         */
        $data['customizer_head'] = $cz->headMarkup();
        $data['primary_menu'] = $menus->itemsByLocation('primary') ?? [];
        $data['footer_menu'] = $menus->itemsByLocation('footer') ?? [];
        $data['current_url'] = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        if (empty($data['seo'])) {
            $data['seo'] = [
                'title' => $data['site_title'],
                'description' => $data['tagline'],
            ];
        }

        /*
         * A broken theme must not take the site with it.
         *
         * render() deliberately rethrows so the caller can decide, and until now
         * nothing did — a typo in single.php was a 500 on every post, with the
         * only clue in a log the site owner has no reason to look at. A theme is
         * the part of an installation most likely to be edited by hand, so it is
         * the part most likely to be broken at three in the morning.
         *
         * The failure is caught, logged with the file and line, and answered
         * with a plain page that says which template failed. The site stays up,
         * the admin stays reachable, and the message names the file to fix.
         */
        try {
            $html = $themes->render($template, $data);
        } catch (\Throwable $e) {
            return $this->themeFailure($template, $e, $data);
        }

        // A preview is only ever shown to a signed-in user who may edit the
        // post, so unpublished work never reaches the public. The badge is
        // injected here rather than in a template, so it works with every
        // theme — including third-party ones that know nothing about previews.
        if (!empty($data['is_preview'])) {
            $html = $this->injectPreviewBadge($html, (string) ($data['post']['status'] ?? 'draft'));
        }
        return Response::html($html);
    }

    /**
     * May the current visitor preview this unpublished post?
     *
     * Authors may preview their own drafts; editors/admins may preview any.
     * Everyone else gets the normal 404, so a draft URL leaks nothing.
     */
    protected function canPreview(array $post): bool
    {
        try {
            $session = $this->app->make(\App\Core\Session::class);
            $uid = (int) ($session->get('user_id') ?? 0);
            if ($uid <= 0) return false;
            $user = $this->app->make(\App\Repositories\UserRepository::class)->find($uid);
            if (!$user || ($user['status'] ?? '') !== 'active') return false;

            $cap = ($post['type'] ?? 'post') === 'page' ? 'edit_pages' : 'edit_posts';
            if (!\App\Http\Middleware\CheckCapability::userCan($user, $cap)) return false;

            // Everyone who can edit may preview their OWN unpublished work.
            $isOwn = (int) ($post['author_id'] ?? 0) === $uid;
            if ($isOwn) return true;

            // Someone else's draft needs rights over other people's content.
            // Note: publish_posts is deliberately NOT enough — an author can
            // publish their own work without being able to read a colleague's
            // unpublished draft.
            return \App\Http\Middleware\CheckCapability::userCan($user, 'edit_others_posts')
                || \App\Http\Middleware\CheckCapability::userCan($user, 'manage_options');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Floating "you are previewing" badge, appended before </body>. */
    private function injectPreviewBadge(string $html, string $status): string
    {
        $label = htmlspecialchars(ucfirst($status));
        $badge = '<div style="position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:2147483000;'
            . 'display:flex;align-items:center;gap:10px;padding:9px 16px;border-radius:999px;'
            . 'background:rgba(15,23,42,.94);color:#fff;font:600 13px/1 system-ui,-apple-system,sans-serif;'
            . 'box-shadow:0 8px 30px rgba(2,6,23,.35);backdrop-filter:blur(6px);">'
            . '<span style="width:8px;height:8px;border-radius:50%;background:#fbbf24;box-shadow:0 0 0 3px rgba(251,191,36,.25)"></span>'
            . 'Preview &middot; ' . $label
            . '<span style="opacity:.55;font-weight:400">Only you can see this</span>'
            . '</div>';
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $badge : substr($html, 0, $pos) . $badge . substr($html, $pos);
    }

    /**
     * Answer a request whose theme template threw.
     *
     * Deliberately does not use the theme to render this: the theme is what
     * just failed. Plain markup, no template lookup, nothing that can throw
     * again.
     *
     * Signed-in administrators see the file and line, because they are the ones
     * who can fix it. Everyone else sees a generic message — a stack trace on a
     * public page tells an attacker the directory layout and the framework
     * version.
     */
    private function themeFailure(string $template, \Throwable $e, array $data): Response
    {
        try {
            $this->app->make(\App\Core\Logger::class)->error(sprintf(
                'Theme template "%s" failed: %s in %s:%d',
                $template, $e->getMessage(), $e->getFile(), $e->getLine()
            ));
        } catch (\Throwable) {}

        $detail = '';
        if ($this->viewerIsAdmin()) {
            $file = str_replace(BASEHIM_ROOT . '/', '', $e->getFile());
            $detail =
                '<p style="margin:18px 0 6px;font-weight:600">' . htmlspecialchars($template) . ' could not be rendered</p>'
              . '<pre style="margin:0;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;'
              . 'border-radius:8px;font:12px/1.6 ui-monospace,monospace;color:#334155;'
              . 'white-space:pre-wrap;word-break:break-word">'
              . htmlspecialchars($e->getMessage()) . "\n\n"
              . htmlspecialchars($file) . ':' . (int) $e->getLine()
              . '</pre>'
              . '<p style="margin:14px 0 0;font-size:13px;color:#64748b">'
              . 'Only administrators see this. Visitors are shown a short message instead.</p>';
        }

        $title = htmlspecialchars((string) ($data['site_title'] ?? 'Basehim'));
        $body =
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<title>' . $title . '</title></head>'
          . '<body style="margin:0;font:15px/1.6 system-ui,-apple-system,sans-serif;color:#0f172a;background:#fff">'
          . '<div style="max-width:640px;margin:12vh auto;padding:0 24px">'
          . '<h1 style="margin:0 0 10px;font-size:22px">This page could not be displayed</h1>'
          . '<p style="margin:0;color:#475569">Something in the site\'s theme went wrong. '
          . 'The rest of the site is unaffected.</p>'
          . $detail
          . '</div></body></html>';

        $response = Response::html($body);
        // 500, because something genuinely is broken and a search engine should
        // not treat this as the page's real content.
        $response->status(500);
        return $response;
    }

    /** Is the viewer signed in and allowed to see theme internals? */
    private function viewerIsAdmin(): bool
    {
        try {
            $session = $this->app->make(\App\Core\Session::class);
            $uid = (int) ($session->get('user_id') ?? 0);
            if ($uid <= 0) return false;
            $user = $this->app->make(\App\Repositories\UserRepository::class)->find($uid);
            if (!$user || ($user['status'] ?? '') !== 'active') return false;
            return \App\Http\Middleware\CheckCapability::userCan($user, 'manage_options');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function notFound(string $message = 'Page not found'): Response
    {
        /** @var ThemeService $themes */
        $themes = $this->app->make(ThemeService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);

        $data = [
            'message' => $message,
            'site_title' => $settings->get('general', 'site_title', 'Basehim'),
            'tagline' => $settings->get('general', 'tagline', ''),
            'footer_text' => $settings->get('appearance', 'footer_text', ''),
            'logo_url' => $settings->get('appearance', 'logo_url', ''),
            'favicon_url' => $settings->get('appearance', 'favicon_url', ''),
            'custom_css' => $settings->get('appearance', 'custom_css', ''),
            // The 404 page is styled by the theme like any other, so it needs
            // the same variables — otherwise a preview of it loses its colours.
            'customizer_head' => $this->app->make(\App\Services\CustomizerService::class)->headMarkup(),
            'primary_menu' => $menus->itemsByLocation('primary') ?? [],
            'footer_menu' => $menus->itemsByLocation('footer') ?? [],
            'current_url' => $_SERVER['REQUEST_URI'] ?? '/',
            'seo' => ['title' => 'Page Not Found - ' . $settings->get('general', 'site_title', 'Basehim')],
        ];

        /*
         * The 404 template can throw too, and that is the worst case: the page
         * shown when something is already wrong. Failing here would turn every
         * missing page into a 500, so a plain fallback is used instead.
         */
        try {
            $html = $themes->render('404', $data);
        } catch (\Throwable $e) {
            try {
                $this->app->make(\App\Core\Logger::class)->error(
                    'Theme 404 template failed: ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
            } catch (\Throwable) {}

            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                  . '<title>Page not found</title></head>'
                  . '<body style="margin:0;font:15px/1.6 system-ui,-apple-system,sans-serif;color:#0f172a">'
                  . '<div style="max-width:640px;margin:12vh auto;padding:0 24px">'
                  . '<h1 style="margin:0 0 10px;font-size:22px">Page not found</h1>'
                  . '<p style="margin:0;color:#475569">'
                  . htmlspecialchars((string) ($data['message'] ?? 'That page does not exist.'))
                  . '</p></div></body></html>';
        }

        $response = Response::html($html);
        $response->status(404);
        return $response;
    }
}
