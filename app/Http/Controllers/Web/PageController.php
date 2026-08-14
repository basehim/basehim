<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\SeoService;

class PageController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $slug): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $page = $posts->findBySlug($slug);

        $isPreview = false;
        if ($page && $page['status'] !== 'published' && $this->canPreview($page)) {
            $isPreview = true;
        } elseif (!$page || $page['status'] !== 'published') {
            return $this->notFound('Page not found');
        }

        // Bump views
        try { $posts->incrementViewCount((int)$page['id']); } catch (\Throwable $e) {}

        // For 'post' types, redirect to canonical URL (so /{slug} doesn't compete with /posts/{slug})
        if ($page['type'] === 'post') {
            return Response::redirect(Helpers::postUrl($page), 301);
        }

        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);
        $seoMeta = $seo->forPost((int)$page['id']);

        // Run the page body through the `post.content` filter so apps
        // can wrap, append to, or transform the rendered content.
        /** @var \App\Core\HookRegistry $hooks */
        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $page['content'] = $hooks->applyFilters('post.content', (string)($page['content'] ?? ''), $page);

        return $this->renderTheme('page', [
            'post' => $page,
            'page' => $page,
            'is_preview' => $isPreview,
            'seo' => [
                'title' => !empty($seoMeta['meta_title']) ? $seoMeta['meta_title'] : $page['title'],
                'description' => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($page['excerpt'] ?? ''),
                'canonical' => $seoMeta['canonical_url'] ?? null,
                // A preview must never be indexed, whatever the page's own setting says.
                'robots' => $isPreview ? 'noindex,nofollow' : ($seoMeta['robots'] ?? 'index,follow'),
            ],
        ]);
    }
}
