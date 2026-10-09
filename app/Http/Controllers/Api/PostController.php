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
        $page = $this->pageNumber($request);
        $perPage = $this->perPage($request, 10);

        // This endpoint is public, so it lists published content only. It used
        // to pass ?status= straight through, which let anyone read drafts and
        // pending posts with GET /api/v1/posts?status=draft (fixed in 1.2.43).
        $filters = [
            'type' => $this->type,
            'status' => 'published',
        ];
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') $filters['search'] = $search;
        $author = $request->query('author_id');
        if (is_string($author) && ctype_digit($author)) $filters['author_id'] = (int) $author;

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

    /** Statuses a client may set. Anything else is refused rather than stored. */
    private const STATUSES = ['draft', 'pending', 'published'];

    /** Capability for an action on this type: edit_posts, delete_others_pages, ... */
    protected function cap(string $action): string
    {
        return $action . '_' . $this->type . 's';
    }

    public function store(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if (!$this->userCan($this->cap('edit'))) return $this->forbidden($this->cap('edit'));

        $data = $this->extractData($request, $this->type, false);

        // A create with nothing recognisable in it (an unparsed body, a wrong
        // content type) used to make an empty "Untitled" draft. Refuse it.
        if (!isset($data['title']) && !isset($data['content'])) {
            return Response::json(['error' => 'Provide at least a title or content (as JSON or form fields).'], 422);
        }
        if ($err = $this->checkStatus($data)) return $err;
        $data = $this->gatePublishing($data);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $id = $posts->create($data + ['status' => 'draft'], (int)$user['id']);
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

        if (!$this->userCan($this->cap('edit'))) return $this->forbidden($this->cap('edit'));
        $own = (int) ($existing['author_id'] ?? 0) === (int) $user['id'];
        if (!$own && !$this->userCan($this->cap('edit_others'))) return $this->forbidden($this->cap('edit_others'));

        // Only the fields the request actually sends. Filling the rest with
        // defaults, as before, meant PATCH {"title": "x"} also emptied the
        // content, reset the slug and unpublished the post.
        $data = $this->extractData($request, $this->type, true);
        if ($err = $this->checkStatus($data)) return $err;

        // Without publish rights a post that is already live cannot be edited
        // through the API: it would either go live unreviewed or be pulled.
        if (($existing['status'] ?? '') === 'published' && !$this->userCan($this->cap('publish'))) {
            return $this->forbidden($this->cap('publish'));
        }
        $data = $this->gatePublishing($data);

        $posts->update((int)$id, $data);
        return Response::json(['data' => $posts->find((int)$id)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        // The type check matters: DELETE /pages/{id} used to delete posts too.
        if (!$existing || $existing['type'] !== $this->type) return Response::json(['error' => 'Not found'], 404);

        if (!$this->userCan($this->cap('delete'))) return $this->forbidden($this->cap('delete'));
        $own = (int) ($existing['author_id'] ?? 0) === (int) $user['id'];
        if (!$own && !$this->userCan($this->cap('delete_others'))) return $this->forbidden($this->cap('delete_others'));

        $posts->delete((int)$id);
        return Response::json(['message' => 'Deleted']);
    }

    private function checkStatus(array $data): ?Response
    {
        if (isset($data['status']) && !in_array($data['status'], self::STATUSES, true)) {
            return Response::json(['error' => 'status must be one of: ' . implode(', ', self::STATUSES)], 422);
        }
        return null;
    }

    /** Roles without publish rights submit for review instead, as in the admin. */
    private function gatePublishing(array $data): array
    {
        if (($data['status'] ?? '') === 'published' && !$this->userCan($this->cap('publish'))) {
            $data['status'] = 'pending';
        }
        return $data;
    }

    /**
     * Writable fields from the request.
     *
     * $partial = true returns only the fields present (for updates); false is
     * the same list, with absent fields simply left out so PostService applies
     * its own defaults.
     */
    protected function extractData(Request $request, string $type, bool $partial = false): array
    {
        $body = $request->all();
        $data = ['type' => $type];
        foreach (['title', 'slug', 'content', 'content_format', 'excerpt', 'status', 'comment_status'] as $f) {
            if (array_key_exists($f, $body) && is_scalar($body[$f])) $data[$f] = (string) $body[$f];
        }
        if (isset($data['title']) && trim($data['title']) === '') unset($data['title']);
        if (isset($data['comment_status']) && !in_array($data['comment_status'], ['open', 'closed'], true)) {
            unset($data['comment_status']);
        }
        if (array_key_exists('featured_media_id', $body)) {
            $data['featured_media_id'] = $body['featured_media_id'] ?: null;
        }
        if (array_key_exists('term_ids', $body)) {
            $termIds = $body['term_ids'];
            if (is_string($termIds) || is_int($termIds)) $termIds = [$termIds];
            $data['term_ids'] = array_values(array_filter(array_map('intval', (array) $termIds)));
        }
        if (!$partial && !isset($data['title']) && isset($data['content'])) $data['title'] = 'Untitled';
        return $data;
    }
}
