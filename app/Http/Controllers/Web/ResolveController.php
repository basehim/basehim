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

        // Pages first. Slugs are unique across posts and pages since 1.2.7, but
        // a site may still hold an older clash; a page is what /{slug} means.
        $row = $posts->findBySlug($slug, 'page') ?? $posts->findBySlug($slug, 'post');

        // Visibility first. An unpublished row is shown only to someone who may
        // preview it; to everyone else it is a 404 like any missing slug. This
        // used to come after the redirects below, so a draft post answered a
        // public visitor with a redirect instead of a 404 — enough to confirm
        // that an unpublished slug existed.
        $isPreview = false;
        if ($row && $row['status'] !== 'published') {
            if (!$this->canPreview($row)) {
                return $this->notFound('Page not found');
            }
            $isPreview = true;
        }

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

        if (!$row || !in_array($row['type'] ?? '', ['post', 'page'], true)) {
            return $this->notFound('Page not found');
        }

        if (!$isPreview) {
            try { $posts->incrementViewCount((int)$row['id']); } catch (\Throwable) {}
        }

        return $this->renderPost($row, $posts, $isPreview);
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

        // Visibility first, as in show(). Previews are served here as well as
        // at /posts/{slug}: this is the canonical address of a categorised
        // post, and a browser that followed the old 301 from /posts/{slug} for
        // a draft has cached that redirect and will keep coming here.
        $isPreview = false;
        if ($row && $row['status'] !== 'published') {
            if (!$this->canPreview($row)) {
                return $this->notFound('Post not found');
            }
            $isPreview = true;
        }

        // A preview's address is not permanent, so it is never answered with a
        // 301 that the browser would cache.
        $moved = $isPreview ? 302 : 301;

        // If the structure changed away from 'category', redirect to the canonical URL.
        if ($structure !== 'category') {
            if ($row) {
                return Response::redirect(Helpers::postUrl($row), $moved);
            }
            return $this->notFound('Post not found');
        }

        if (!$row) {
            return $this->notFound('Post not found');
        }

        // Validate the category segment. If wrong, redirect to the canonical URL
        // (e.g. post was re-categorised).
        $primaryCat = Helpers::lookupPrimaryCategory((int)$row['id']);
        if ($primaryCat !== '' && $primaryCat !== $category) {
            return Response::redirect(Helpers::postUrl($row), $moved);
        }

        if (!$isPreview) {
            try { $posts->incrementViewCount((int)$row['id']); } catch (\Throwable) {}
        }

        return $this->renderPost($row, $posts, $isPreview);
    }

    /** Shared render logic for a resolved post/page row. */
    private function renderPost(array $row, PostService $posts, bool $isPreview = false): Response
    {
        /** @var SeoService $seo */
        $seo     = $this->app->make(SeoService::class);

        /*
         * Run the body through the `post.content` filter, as PostController and
         * PageController already do.
         *
         * This controller serves /{slug} and /{cat}/{slug} — the pretty
         * permalinks most posts actually use — so an app filtering post.content
         * worked on /posts/{slug} and silently did nothing on the canonical URL
         * of the same post. An ad-insertion app shipped an entire output-buffer
         * fallback to work around it: buffering the whole page, re-querying the
         * post by slug, and substituting the body by string search. That is the
         * kind of workaround a missing filter call invites, and it only ever
         * worked for HTML-format content on the stock theme.
         */
        /** @var \App\Core\HookRegistry $hooks */
        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $row['content'] = $hooks->applyFilters('post.content', (string) ($row['content'] ?? ''), $row);
        $seoMeta = $seo->forPost((int)$row['id']);
        $template = $row['type'] === 'post' ? 'single' : 'page';

        /*
         * The approved comments, exactly as PostController loads them.
         *
         * This used to pass an empty list and a count of 0. This controller
         * serves the canonical URL of every post under category and flat
         * permalinks, so on those sites no post ever showed its comments or
         * its comment count — a comment could be approved and still never
         * appear, and a theme's "Comments (N)" heading stayed at zero.
         */
        $approvedComments = $row['type'] === 'post'
            ? $this->app->make(\App\Services\CommentService::class)->forPost((int)$row['id'], 'approved')
            : [];

        return $this->renderTheme($template, [
            'post'           => $row,
            'page'           => $row,
            'terms'          => $row['type'] === 'post' ? $posts->terms((int)$row['id']) : [],
            'comments'       => $approvedComments,
            'comments_count' => count($approvedComments),
            'comments_open'  => ($row['comment_status'] ?? 'closed') === 'open',
            'is_preview'     => $isPreview,
            'csrf'           => $this->app->make(\App\Core\Session::class)->csrfToken(),
            'seo'            => [
                'title'       => !empty($seoMeta['meta_title']) ? $seoMeta['meta_title'] : $row['title'],
                'description' => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($row['excerpt'] ?? ''),
/*
                 * This is the controller that actually served the category-
                 * style URL on cloudhim.com, and it never emitted a canonical
                 * tag unless an editor set one by hand. A manual override still
                 * wins; otherwise the canonical is derived from the same
                 * postUrl() logic this method already uses above to validate
                 * the category segment, so the tag can never disagree with the
                 * redirect rules that govern this same row.
                 */
                'canonical'   => !empty($seoMeta['canonical_url'])
                    ? $seoMeta['canonical_url']
                    : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                        . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                        . rtrim((defined('BASEHIM_BASE') ? BASEHIM_BASE : ''), '/') . Helpers::postUrl($row),
                // A preview must never be indexed, whatever the post's own setting says.
                'robots'      => $isPreview ? 'noindex,nofollow' : ($seoMeta['robots'] ?? 'index,follow'),
            ],
        ]);
    }
}
