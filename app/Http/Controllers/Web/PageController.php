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
        $page = $posts->findBySlug($slug, 'page');

        if (!$page) {
            // An old /page/{slug} link to what is now a post: send it on.
            $post = $posts->findBySlug($slug, 'post');
            if ($post && $post['status'] === 'published') {
                return Response::redirect(Helpers::postUrl($post), 301);
            }
            return $this->notFound('Page not found');
        }

        // Visibility first: to anyone who may not preview it, an unpublished
        // page is a 404, the same as a missing one.
        $isPreview = false;
        if ($page['status'] !== 'published') {
            if (!$this->canPreview($page)) {
                return $this->notFound('Page not found');
            }
            $isPreview = true;
        }

        /*
         * Pages live at the site root, /{slug}. This address is kept only so
         * links to it keep working: menus, bookmarks and search results from
         * before 1.2.7. It answers with a permanent redirect for a published
         * page, and a temporary one for a preview, whose address must not be
         * cached.
         *
         * It renders here only for a page whose slug a route has claimed
         * ("search", "feed"), which cannot be served at /{slug}.
         */
        $canonical = Helpers::postUrl($page);
        if ($canonical !== '/page/' . $page['slug']) {
            $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
            return Response::redirect($canonical . ($qs !== '' ? '?' . $qs : ''), $isPreview ? 302 : 301);
        }

        if (!$isPreview) {
            try { $posts->incrementViewCount((int)$page['id']); } catch (\Throwable $e) {}
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
                /*
                 * Derived from postUrl() when no manual override is set, so
                 * the tag can never disagree with the redirect rules that
                 * govern this row. Absolute, with the scheme and host the
                 * request actually arrived on — a relative href is technically
                 * legal but not the form search engines are built around, and
                 * canonical is exactly the tag where an implicit base should
                 * not be relied on.
                 */
                'canonical' => !empty($seoMeta['canonical_url'])
                    ? $seoMeta['canonical_url']
                    : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                        . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                        . rtrim((defined('BASEHIM_BASE') ? BASEHIM_BASE : ''), '/')
                        . \App\Core\Helpers::postUrl($page),
                // A preview must never be indexed, whatever the page's own setting says.
                'robots' => $isPreview ? 'noindex,nofollow' : ($seoMeta['robots'] ?? 'index,follow'),
            ],
        ]);
    }
}
