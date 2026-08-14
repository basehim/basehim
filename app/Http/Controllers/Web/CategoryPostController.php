<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\CommentService;
use App\Services\PostService;
use App\Services\SeoService;
use App\Services\SettingService;

/**
 * CategoryPostController
 *
 * Handles the /{category-slug}/{post-slug} URL pattern, which is active when
 * the permalink structure setting is set to 'category'.
 *
 * Resolution logic:
 *   1. Verify the permalink structure is actually 'category'. If not, treat the
 *      two-segment path as a 404 rather than silently serving content under
 *      incorrect URLs.
 *   2. Look up the post by slug and confirm it is a published post (not a page).
 *   3. Verify the category segment matches the post's primary category. If it
 *      doesn't match (stale URL, wrong category), issue a 301 to the canonical URL
 *      so search engines consolidate on one address.
 */
class CategoryPostController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $category, string $slug): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        // Only serve this route when the 'category' structure is active.
        $structure = $settings->get('permalinks', 'structure', 'pretty');
        if ($structure !== 'category') {
            return $this->notFound('Page not found');
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post  = $posts->findBySlug($slug);

        if (!$post || $post['type'] !== 'post' || $post['status'] !== 'published') {
            return $this->notFound('Post not found');
        }

        // Verify (and canonicalise) the category segment.
        $primaryCat = Helpers::primaryCategorySlug($post);

        if ($primaryCat === null) {
            // Post has no category — canonical is /posts/{slug}, redirect there.
            return Response::redirect('/posts/' . $post['slug'], 301);
        }

        if ($category !== $primaryCat) {
            // Wrong category slug in the URL — 301 to the canonical address.
            return Response::redirect('/' . $primaryCat . '/' . $post['slug'], 301);
        }

        // Track view (best-effort).
        try { $posts->incrementViewCount((int)$post['id']); } catch (\Throwable) {}

        /** @var CommentService $comments */
        $comments  = $this->app->make(CommentService::class);
        /** @var SeoService $seo */
        $seo       = $this->app->make(SeoService::class);

        $approvedComments = $comments->forPost((int)$post['id'], 'approved');
        $seoMeta          = $seo->forPost((int)$post['id']);

        /** @var \App\Core\HookRegistry $hooks */
        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $post['content'] = $hooks->applyFilters('post.content', (string)($post['content'] ?? ''), $post);

        return $this->renderTheme('single', [
            'post'           => $post,
            'terms'          => $posts->terms((int)$post['id']),
            'comments'       => $approvedComments,
            'comments_count' => count($approvedComments),
            'comments_open'  => $post['comment_status'] === 'open',
            'seo' => [
                'title'          => !empty($seoMeta['meta_title'])       ? $seoMeta['meta_title']       : $post['title'],
                'description'    => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($post['excerpt'] ?? ''),
                'canonical'      => $seoMeta['canonical_url'] ?? null,
                'robots'         => $seoMeta['robots'] ?? 'index,follow',
                'og_title'       => !empty($seoMeta['og_title'])         ? $seoMeta['og_title']         : $post['title'],
                'og_description' => !empty($seoMeta['og_description'])   ? $seoMeta['og_description']   : ($post['excerpt'] ?? ''),
            ],
            'csrf' => $this->app->make(\App\Core\Session::class)->csrfToken(),
        ]);
    }
}
