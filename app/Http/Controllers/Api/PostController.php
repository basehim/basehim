<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Http\Middleware\CheckCapability;
use App\Core\Response;
use App\Services\PostService;
use App\Services\SeoService;

class PostController extends ApiController
{
    use AuthorizesContent;

    protected string $type = 'post';

    public function index(Request $request): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 10)));

        // `status` is caller-supplied, so it must not be a way to read
        // unpublished work: ?status=draft was returning every draft's full
        // content to anonymous callers. Anything other than 'published'
        // requires the capability to edit this content type.
        $status = (string) $request->query('status', 'published');
        if ($status !== 'published') {
            $user = $this->authUser();
            $cap = $this->contentCapability('edit', $this->type);
            if (!CheckCapability::userCan($user, $cap)) {
                $status = 'published';
            }
        }

        $filters = [
            'type' => $this->type,
            'status' => $status,
        ];
        $search = $request->query('q');
        if ($search) $filters['search'] = $search;
        if ($request->query('author_id')) $filters['author_id'] = (int)$request->query('author_id');

        return Response::json($posts->paginate($filters, $page, $perPage));
    }

    public function show(Request $request, string $slug): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post = $posts->findBySlug($slug);
        if (!$post || $post['type'] !== $this->type || $post['status'] !== 'published') {
            return Response::json(['error' => 'Not found'], 404);
        }
        try { $posts->incrementViewCount((int)$post['id']); } catch (\Throwable $e) {}

        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);
        return Response::json([
            'data' => array_merge($post, [
                'terms' => $posts->terms((int)$post['id']),
                'seo' => $seo->forPost((int)$post['id']),
            ]),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('posts:write')) return $denied;

        // Creating always writes a row you own, so the base capability applies.
        $cap = $this->contentCapability('edit', $this->type);
        if (!CheckCapability::userCan($user, $cap)) return $this->forbidden($cap);

        $data = $this->enforcePublishCapability($user, $this->extractData($request, $this->type), $this->type);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $id = $posts->create($data, (int)$user['id']);
        return Response::json(['data' => $posts->find($id)], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('posts:write')) return $denied;

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        if (!$existing || $existing['type'] !== $this->type) return Response::json(['error' => 'Not found'], 404);

        if (!$this->canActOn($user, $existing, 'edit', $this->type)) {
            $own = (int)($existing['author_id'] ?? 0) === (int)$user['id'];
            return $this->forbidden($this->contentCapability('edit' . ($own ? '' : '_others'), $this->type));
        }

        $data = $this->enforcePublishCapability($user, $this->extractData($request, $this->type), $this->type);
        $posts->update((int)$id, $data);
        return Response::json(['data' => $posts->find((int)$id)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('posts:write')) return $denied;

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        // Type-scoped: /posts/{id} must not delete a page, and vice versa.
        if (!$existing || ($existing['type'] ?? '') !== $this->type) {
            return Response::json(['error' => 'Not found'], 404);
        }

        if (!$this->canActOn($user, $existing, 'delete', $this->type)) {
            $own = (int)($existing['author_id'] ?? 0) === (int)$user['id'];
            return $this->forbidden($this->contentCapability('delete' . ($own ? '' : '_others'), $this->type));
        }

        $posts->delete((int)$id);
        return Response::json(['message' => 'Deleted']);
    }

    protected function extractData(Request $request, string $type): array
    {
        $termIds = $request->input('term_ids', []);
        if (is_string($termIds)) $termIds = [$termIds];
        return [
            'title' => $request->input('title', 'Untitled'),
            'slug' => $request->input('slug', ''),
            'content' => $request->input('content', ''),
            'content_format' => $request->input('content_format', 'html'),
            'excerpt' => $request->input('excerpt', ''),
            'status' => $request->input('status', 'draft'),
            'type' => $type,
            'comment_status' => $request->input('comment_status', 'open'),
            'featured_media_id' => $request->input('featured_media_id') ?: null,
            'term_ids' => array_filter(array_map('intval', (array)$termIds)),
        ];
    }
}
