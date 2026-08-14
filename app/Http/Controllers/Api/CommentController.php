<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\CommentService;
use App\Services\PostService;
use App\Services\SettingService;

class CommentController extends ApiController
{
    public function index(Request $request, string $slug): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post = $posts->findBySlug($slug);
        if (!$post) return Response::json(['error' => 'Not found'], 404);

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        return Response::json(['data' => $comments->forPost((int)$post['id'], 'approved')]);
    }

    public function store(Request $request, string $slug): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        if (!$settings->get('discussion', 'allow_comments', true)) {
            return Response::json(['error' => 'Comments disabled'], 403);
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post = $posts->findBySlug($slug);
        if (!$post) return Response::json(['error' => 'Not found'], 404);
        if ($post['comment_status'] !== 'open') return Response::json(['error' => 'Comments closed'], 403);

        $content = trim((string)$request->input('content', ''));
        if ($content === '') return Response::json(['error' => 'Content required'], 422);

        $authUser = $this->authUser();
        $authorName = $authUser['display_name'] ?? trim((string)$request->input('author_name', ''));
        $authorEmail = $authUser['email'] ?? trim((string)$request->input('author_email', ''));

        if ($settings->get('discussion', 'require_email', true)) {
            if ($authorName === '' || !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
                return Response::json(['error' => 'Valid name and email required'], 422);
            }
        }

        $defaultStatus = $settings->get('discussion', 'moderate_first', true) ? 'pending' : 'approved';

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);

        // Same anti-spam gate as the web form.
        $decision = $comments->guard([
            'content'      => $content,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'author_url'   => $request->input('author_url'),
            'honeypot'     => $request->input('hp_comment_field', ''),
            'post_id'      => (int)$post['id'],
            'status'       => $defaultStatus,
        ]);
        if ($decision['action'] === 'reject') {
            return Response::json(['error' => $decision['message'] ?? 'Rejected'], 429);
        }
        if ($decision['action'] === 'drop') {
            return Response::json(['data' => null, 'pending' => true], 201);
        }
        $status = $decision['status'] ?? $defaultStatus;

        $id = $comments->create([
            'post_id' => (int)$post['id'],
            'author_id' => $authUser['id'] ?? null,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'author_url' => $request->input('author_url'),
            'content' => $content,
            'parent_id' => $request->input('parent_id') ?: null,
            'status' => $status,
        ]);
        return Response::json([
            'data' => $status === 'approved' ? $comments->find($id) : null,
            'pending' => $status !== 'approved',
        ], 201);
    }

    // ==================================================================
    // Moderation (authenticated — requires moderate_comments)
    //
    // Previously reachable only through the admin UI, which meant an app
    // wanting to auto-approve trusted authors had no supported route.
    // ==================================================================

    /** GET /comments — every comment, filterable. */
    public function all(Request $request): Response
    {
        if (!$this->canModerate()) return $this->denied();

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        $filters = [];
        foreach (['status', 'search'] as $key) {
            $value = (string) $request->query($key, '');
            if ($value !== '') $filters[$key] = $value;
        }
        if ($request->query('post_id')) $filters['post_id'] = (int) $request->query('post_id');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        return Response::json($comments->paginate($filters, $page, $perPage));
    }

    /** GET /comments/{id} */
    public function find(Request $request, string $id): Response
    {
        if (!$this->canModerate()) return $this->denied();

        $comment = $this->app->make(CommentService::class)->find((int) $id);
        if (!$comment) return Response::json(['error' => 'Not found'], 404);
        return Response::json(['data' => $comment]);
    }

    /** PATCH /comments/{id}/status — body: {status: approved|pending|spam|trash} */
    public function setStatus(Request $request, string $id): Response
    {
        if (!$this->canModerate()) return $this->denied();

        $status = (string) $request->input('status', '');
        $allowed = ['approved', 'pending', 'spam', 'trash'];
        if (!in_array($status, $allowed, true)) {
            return Response::json([
                'error' => 'status must be one of: ' . implode(', ', $allowed),
            ], 422);
        }

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        if (!$comments->find((int) $id)) return Response::json(['error' => 'Not found'], 404);
        if (!$comments->setStatus((int) $id, $status)) {
            return Response::json(['error' => 'Could not update the comment.'], 500);
        }
        return Response::json(['data' => $comments->find((int) $id)]);
    }

    /** DELETE /comments/{id} — permanent. */
    public function destroy(Request $request, string $id): Response
    {
        if (!$this->canModerate()) return $this->denied();

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        if (!$comments->find((int) $id)) return Response::json(['error' => 'Not found'], 404);
        if (!$comments->delete((int) $id)) {
            return Response::json(['error' => 'Could not delete the comment.'], 500);
        }
        return Response::json(['deleted' => true]);
    }

    /** GET /comments/counts — totals per status, for a moderation badge. */
    public function counts(Request $request): Response
    {
        if (!$this->canModerate()) return $this->denied();
        return Response::json(['data' => $this->app->make(CommentService::class)->counts()]);
    }

    private function canModerate(): bool
    {
        $user = $this->authUser();
        return $user !== null
            && \App\Http\Middleware\CheckCapability::userCan($user, 'moderate_comments');
    }

    private function denied(): Response
    {
        return Response::json(['error' => 'Requires the moderate_comments capability.'], 403);
    }
}
