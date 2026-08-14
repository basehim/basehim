<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\CommentService;
use App\Services\SeoService;

class PostController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $slug): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var \App\Services\SettingService $settings */
        $settings = $this->app->make(\App\Services\SettingService::class);

        $post = $posts->findBySlug($slug);

        if (!$post || $post['type'] !== 'post') {
            return $this->notFound('Post not found');
        }
        // Unpublished posts are visible only to a signed-in user who may edit
        // them (draft preview). Everyone else gets the same 404 as before, so a
        // draft URL reveals nothing about whether it exists.
        $isPreview = false;
        if ($post['status'] !== 'published') {
            if (!$this->canPreview($post)) {
                return $this->notFound('Post not found');
            }
            $isPreview = true;
        }

        // Honor the permalink structure setting. The canonical URL for a post
        // depends on `permalinks.structure`:
        //   pretty   → /posts/{slug}             (no redirect needed)
        //   category → /{category-slug}/{slug}   (must redirect away from /posts/...)
        //   flat     → /{slug}                   (must redirect away from /posts/...)
        // Helpers::postUrl() already encodes this logic and falls back to
        // /posts/{slug} when no primary category exists, so we only issue a
        // redirect when the canonical URL actually differs from the URL the
        // visitor asked for.
        $structure = $settings->get('permalinks', 'structure', 'pretty');
        if ($structure === 'flat' || $structure === 'category') {
            $canonical = \App\Core\Helpers::postUrl($post);
            if ($canonical !== '' && $canonical !== '/posts/' . $post['slug']) {
                return Response::redirect($canonical, 301);
            }
        }

        // Track view (best-effort, non-blocking-ish)
        try { $posts->incrementViewCount((int)$post['id']); } catch (\Throwable $e) {}

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);

        $approvedComments = $comments->forPost((int)$post['id'], 'approved');
        $seoMeta = $seo->forPost((int)$post['id']);

        // Run the post body through the `post.content` filter so apps
        // can wrap, append to, or transform the rendered content.
        /** @var \App\Core\HookRegistry $hooks */
        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $post['content'] = $hooks->applyFilters('post.content', (string)($post['content'] ?? ''), $post);

        return $this->renderTheme('single', [
            'post' => $post,
            'terms' => $posts->terms((int)$post['id']),
            'comments' => $approvedComments,
            'comments_count' => count($approvedComments),
            'is_preview' => $isPreview,
            'comments_open' => $post['comment_status'] === 'open',
            'seo' => [
                'title' => !empty($seoMeta['meta_title']) ? $seoMeta['meta_title'] : $post['title'],
                'description' => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($post['excerpt'] ?? ''),
                'canonical' => $seoMeta['canonical_url'] ?? null,
                // A preview must never be indexed, whatever the post's own setting says.
                'robots' => $isPreview ? 'noindex,nofollow' : ($seoMeta['robots'] ?? 'index,follow'),
                'og_title' => !empty($seoMeta['og_title']) ? $seoMeta['og_title'] : $post['title'],
                'og_description' => !empty($seoMeta['og_description']) ? $seoMeta['og_description'] : ($post['excerpt'] ?? ''),
            ],
            'csrf' => $this->app->make(\App\Core\Session::class)->csrfToken(),
        ]);
    }
}
