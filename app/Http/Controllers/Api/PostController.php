<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\PostService;
use App\Services\SeoService;

class PostController extends ApiController
{
    protected string $type = 'post';

    public function index(Request $request): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 10)));

        $filters = [
            'type' => $this->type,
            'status' => $request->query('status', 'published'),
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

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $id = $posts->create($this->extractData($request, $this->type), (int)$user['id']);
        return Response::json(['data' => $posts->find($id)], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        if (!$existing || $existing['type'] !== $this->type) return Response::json(['error' => 'Not found'], 404);

        $posts->update((int)$id, $this->extractData($request, $this->type));
        return Response::json(['data' => $posts->find((int)$id)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        if (!$existing) return Response::json(['error' => 'Not found'], 404);
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
