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

        $data['site_title'] = $settings->get('general', 'site_title', 'Basehim');
        $data['tagline'] = $settings->get('general', 'tagline', '');
        $data['footer_text'] = $settings->get('appearance', 'footer_text', '');
        $data['logo_url'] = $settings->get('appearance', 'logo_url', '');
        $data['favicon_url'] = $settings->get('appearance', 'favicon_url', '');
        $data['custom_css'] = $settings->get('appearance', 'custom_css', '');
        $data['primary_menu'] = $menus->itemsByLocation('primary') ?? [];
        $data['footer_menu'] = $menus->itemsByLocation('footer') ?? [];
        $data['current_url'] = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        if (empty($data['seo'])) {
            $data['seo'] = [
                'title' => $data['site_title'],
                'description' => $data['tagline'],
            ];
        }

        $html = $themes->render($template, $data);

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
            'primary_menu' => $menus->itemsByLocation('primary') ?? [],
            'footer_menu' => $menus->itemsByLocation('footer') ?? [],
            'current_url' => $_SERVER['REQUEST_URI'] ?? '/',
            'seo' => ['title' => 'Page Not Found - ' . $settings->get('general', 'site_title', 'Basehim')],
        ];

        $html = $themes->render('404', $data);
        $response = Response::html($html);
        $response->status(404);
        return $response;
    }
}
