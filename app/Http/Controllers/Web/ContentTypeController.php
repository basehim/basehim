<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\PostTypeRegistry;
use App\Services\SettingService;

/**
 * Public pages of app-registered content types (1.2.94).
 *
 *   /{prefix}/{slug}   one item       theme: single-{type}.php, else single.php
 *   /{prefix}          the archive    theme: archive-{type}.php, else archive.php
 *
 * The prefix is the type's `rewrite` (default: its slug plus "s"), so an
 * "event" lives at /events/summer-meetup. Routes are registered in
 * routes/web.php for every public type with a prefix; the type is found from
 * the first path segment, so one controller serves them all.
 */
class ContentTypeController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $slug = ''): Response
    {
        $type = $this->typeFor($request);
        if ($type === null) return $this->notFound('Page not found');

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $row = $posts->findBySlug($slug, $type['slug']);
        if (!$row || ($row['type'] ?? '') !== $type['slug']) return $this->notFound($type['singular'] . ' not found');

        $isPreview = false;
        if (($row['status'] ?? '') !== 'published') {
            if (!$this->canPreview($row)) return $this->notFound($type['singular'] . ' not found');
            $isPreview = true;
        }
        if (!$isPreview) {
            try { $posts->incrementViewCount((int) $row['id']); } catch (\Throwable) {}
        }

        $hooks = $this->app->make(\App\Core\HookRegistry::class);
        $row['content'] = $hooks->applyFilters('post.content', (string) ($row['content'] ?? ''), $row);

        $registry = $this->app->make(PostTypeRegistry::class);
        $comments = [];
        $commentsOn = $registry->supports($type['slug'], 'comments');
        if ($commentsOn) {
            try { $comments = $this->app->make(\App\Services\CommentService::class)->forPost((int) $row['id'], 'approved'); } catch (\Throwable) {}
        }
        $seoMeta = [];
        try { $seoMeta = $this->app->make(\App\Services\SeoService::class)->forPost((int) $row['id']); } catch (\Throwable) {}
        $fields = $this->app->make(\App\Services\Fields\FieldService::class)->values($row, true);

        return $this->renderTheme(['single-' . $type['slug'], 'single'], [
            'post'           => $row,
            'page'           => $row,
            'post_type'      => $type,
            'fields'         => $fields,
            'terms'          => $posts->terms((int) $row['id']),
            'comments'       => $comments,
            'comments_count' => count($comments),
            'comments_open'  => $commentsOn && ($row['comment_status'] ?? 'closed') === 'open',
            'is_preview'     => $isPreview,
            'csrf'           => $this->app->make(\App\Core\Session::class)->csrfToken(),
            'seo'            => [
                'title'       => !empty($seoMeta['meta_title']) ? $seoMeta['meta_title'] : $row['title'],
                'description' => !empty($seoMeta['meta_description']) ? $seoMeta['meta_description'] : ($row['excerpt'] ?? ''),
                'robots'      => $isPreview ? 'noindex,nofollow' : ($seoMeta['robots'] ?? 'index,follow'),
            ],
        ]);
    }

    public function archive(Request $request): Response
    {
        $type = $this->typeFor($request);
        if ($type === null || empty($type['has_archive'])) return $this->notFound('Page not found');

        $perPage = (int) ($type['per_page'] ?: $this->app->make(SettingService::class)->get('reading', 'posts_per_page', 10));
        $perPage = max(1, min(100, $perPage));
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->app->make(PostService::class)->paginate(
            ['type' => $type['slug'], 'status' => 'published'], $page, $perPage
        );
        $this->app->make(\App\Services\Fields\FieldService::class)->prime(array_map(fn($r) => (int) $r['id'], $result['data'] ?? []));

        return $this->renderTheme(['archive-' . $type['slug'], 'archive'], [
            'archive_type' => 'post_type',
            'post_type'    => $type,
            'posts'        => $result['data'] ?? [],
            'meta'         => $result['meta'] ?? [],
            'title'        => $type['label'],
            'seo'          => ['title' => $type['label'], 'description' => $type['description']],
        ]);
    }

    /** The type whose URL prefix is this request's first path segment. */
    private function typeFor(Request $request): ?array
    {
        $path = trim($request->path(), '/');
        $base = defined('BASEHIM_BASE') ? trim((string) BASEHIM_BASE, '/') : '';
        if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base) + 1);
        $first = explode('/', $path)[0] ?? '';
        $type = $this->app->make(PostTypeRegistry::class)->byPrefix(rawurldecode($first));
        return $type !== null && !empty($type['public']) ? $type : null;
    }
}
