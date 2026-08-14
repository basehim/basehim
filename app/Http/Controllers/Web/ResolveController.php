<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\SettingService;
use App\Services\SeoService;

/**
 * Handles the bare /{slug} URL and (for category permalinks) /{cat}/{slug}.
 *
 * Resolution order:
 *   pretty   → /{slug} resolves pages only; posts redirect to /posts/{slug}
 *   category → /{slug} resolves pages only; posts redirect to /{cat}/{slug}
 *   flat     → /{slug} tries post then page
 */
class ResolveController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $slug): Response
    {
        /** @var SettingService $settings */
        $settings  = $this->app->make(SettingService::class);
        /** @var PostService $posts */
        $posts     = $this->app->make(PostService::class);
        $structure = $settings->get('permalinks', 'structure', 'pretty');

        $row = $posts->findBySlug($slug);

        if ($structure === 'flat') {
            // posts AND pages live at /{slug} — accept either
        } elseif ($structure === 'category') {
            // Pages live at /{slug}; posts must use /{cat}/{post-slug}.
            if ($row && $row['type'] === 'post') {
                return Response::redirect(Helpers::postUrl($row), 302);
            }
        } else {
            // 'pretty': only pages live at /{slug}
            if ($row && $row['type'] === 'post') {
                return Response::redirect(Helpers::postUrl($row), 302);
            }
        }

        if (!$row || $row['status'] !== 'published' || !in_array($row['type'] ?? '', ['post', 'page'], true)) {
            return $this->notFound('Page not found');
        }

        try { $posts->incrementViewCount((int)$row['id']); } catch (\Throwable) {}

        return $this->renderPost($row, $posts);
    }

    /**
     * Handles /{category-slug}/{post-slug} for the 'category' permalink structure.
     * If the structure has changed, redirects to the canonical URL.
     */
    public function showCategoryPost(Request $request, string $category, string $slug): Response
    {
        /** @var SettingService $settings */
        $settings  = $this->app->make(SettingService::class);
        /** @var PostService $posts */
        $posts     = $this->app->make(PostService::class);
        $structure = $settings->get('permalinks', 'structure', 'pretty');

        $row = $posts->findBySlug($slug, 'post');

        // If the structure changed away from 'category', redirect to the canonical URL.
        if ($structure !== 'category') {
            if ($row) {
                return Response::redirect(Helpers::postUrl($row), 301);
            }
            return $this->notFound('Post not found');
        }

        if (!$row || $row['status'] !== 'published') {
            return $this->notFound('Post not found');
        }

        // Validate the category segment. If wrong, redirect to the canonical URL
        // (e.g. post was re-categorised).
        $primaryCat = Helpers::lookupPrimaryCategory((int)$row['id']);
        if ($primaryCat !== '' && $primaryCat !== $category) {
            return Response::redirect(Helpers::postUrl($row), 301);
        }

        try { $posts->incrementViewCount((int)$row['id']); } catch (\Throwable) {}

        return $this->renderPost($row, $posts);
    }

    /** Shared render logic for a resolved post/page row. */
    private function renderPost(array $row, PostService $posts): Response
    {
        /** @var SeoService $seo */
        $seo     = $this->app->make(SeoService::class);
        $seoMeta = $seo->forPost((int)$row['id']);
        $template = $row['type'] === 'post' ? 'single' : 'page';

        // Run the body through the `post.content` filter, exactly as
        // PostController and PageController do.
        //
        // This controller serves every bare /{slug} URL and, under the
        // 'category' permalink structure, every /{cat}/{slug} post URL — which
        // is most of the site. Skipping the filter meant a post stored in block
        // format was handed to the theme as raw JSON and printed verbatim. It
        // went unnoticed because content written through the old editor is
        // stored as HTML and passes through unchanged either way.
        /** @var \App\Core\HookRegistry $hooks */
        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $row['content'] = $hooks->applyFilters('post.content', (string)($row['content'] ?? ''), $row);

        return $this->renderTheme($template, [
            'post'           => $row,
            'page'           => $row,
            'terms'          => $row['type'] === 'post' ? $posts->terms((int)$row['id']) : [],
            'comments'       => [],
            'comments_count' => 0,
            'comments_open'  => ($row['comment_status'] ?? 'closed') === 'open',
            'csrf'           => $this->app->make(\App\Core\Session::class)->csrfToken(),
            'seo'            => [
                'title'       => !empty($seoMeta['meta_title']) ? $seoMeta['meta_title'] : $row['title'],
                'description' => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($row['excerpt'] ?? ''),
                'canonical'   => $seoMeta['canonical_url'] ?? null,
                'robots'      => $seoMeta['robots'] ?? 'index,follow',
            ],
        ]);
    }
}
