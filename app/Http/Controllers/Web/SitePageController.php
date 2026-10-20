<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * Renders an app's page in the active theme for App\Core\Http\SitePage
 * (1.2.72). Not a route: it reuses RendersTheme, so an app page gets the same
 * header, footer, menus, customizer styles and SEO tags as a core page.
 */
final class SitePageController extends Controller
{
    use RendersTheme;

    public function page(string $title, string $html, array $page = []): Response
    {
        $themes = $this->app->make(\App\Services\ThemeService::class);
        $site = '';
        try { $site = (string) $this->app->make(\App\Services\SettingService::class)->get('general', 'site_title', ''); } catch (\Throwable) {}

        $seo = ['title' => $title !== '' ? ($site !== '' ? "{$title} – {$site}" : $title) : $site];
        if (!empty($page['description'])) $seo['description'] = (string) $page['description'];
        if (!empty($page['robots'])) $seo['robots'] = (string) $page['robots'];

        $data = [
            'seo' => $seo,
            'title' => $title,
            'content' => $html,
            'app_page' => true,
        ];

        // A theme may lay app pages out its own way (templates/app.php gets
        // $content and $title); every theme has page.php.
        if ($themes->templatePath('app') !== null) {
            return $this->renderTheme('app', $data);
        }
        $data['post'] = [
            'id' => 0, 'title' => $title, 'slug' => '', 'content' => $html, 'content_format' => 'html',
            'type' => 'page', 'status' => 'published', 'excerpt' => (string) ($page['description'] ?? ''),
            'featured_url' => null, 'author_name' => '', 'published_at' => null, 'comment_status' => 'closed',
        ];
        return $this->renderTheme('page', $data);
    }

    public function missing(string $message): Response
    {
        return $this->notFound($message);
    }

    /** An app page is never a post or page of the site, whatever template lays it out. */
    protected function seoContext(string $template, array $data): array
    {
        $ctx = [
            'type' => 'other', 'template' => $template, 'post' => null, 'terms' => null, 'term' => null,
            'author' => null, 'query' => null, 'seo' => is_array($data['seo'] ?? null) ? $data['seo'] : [],
            'page' => 1, 'is_preview' => false,
        ];
        return $ctx;
    }
}
