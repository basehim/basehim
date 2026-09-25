<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\CommentService;
use App\Services\PostService;
use App\Services\SettingService;
use App\Services\AuthService;

class CommentController extends Controller
{
    public function store(Request $request): Response
    {
        $session = $this->app->make(Session::class);
        $isAjax = $this->isAjax($request);

        if (!$this->verifyCsrf($request)) {
            return $this->respond($isAjax, 'error', 'Security check failed.', $request->input('redirect_to', '/'), 419);
        }

        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        if (!$settings->get('discussion', 'allow_comments', true)) {
            return $this->respond($isAjax, 'error', 'Comments are disabled.', $request->input('redirect_to', '/'), 403);
        }

        $postId = (int)$request->input('post_id');
        if ($postId <= 0) {
            return $this->respond($isAjax, 'error', 'Invalid post.', '/', 422);
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post = $posts->find($postId);
        if (!$post || $post['comment_status'] !== 'open' || $post['status'] !== 'published') {
            return $this->respond($isAjax, 'error', 'Comments are closed on this post.', '/', 403);
        }

        $content = trim((string)$request->input('content', ''));

        if ($content === '') {
            return $this->respond($isAjax, 'error', 'Please write a comment.', Helpers::postUrl($post) . '#comment-form', 422);
        }

        /*
         * Who is commenting. Resolved before any validation, because it
         * decides what needs validating.
         *
         * A signed-in, active account comments as itself: its display name
         * (or username) and its email, whatever the form sent. Nothing typed
         * into a name or email field is used, so a theme still showing those
         * fields cannot let a member post under someone else's name.
         *
         * The required-fields check used to run first, before the user was
         * even looked up — so a signed-in member was refused with "Name and
         * email are required" unless they typed both again, and a theme
         * could not hide those fields without breaking commenting for them.
         */
        /** @var AuthService $auth */
        $auth = $this->app->make(AuthService::class);
        $currentUser = $auth->currentUser();
        if ($currentUser && ($currentUser['status'] ?? '') !== 'active') {
            $currentUser = null;
        }

        if ($currentUser) {
            $authorName  = trim((string) ($currentUser['display_name'] ?? '')) ?: (string) ($currentUser['username'] ?? '');
            $authorEmail = (string) ($currentUser['email'] ?? '');
            $authorUrl   = null;
        } else {
            $authorName  = trim((string)$request->input('author_name', ''));
            $authorEmail = trim((string)$request->input('author_email', ''));
            $authorUrl   = $request->input('author_url');

            if ($settings->get('discussion', 'require_email', true)) {
                if ($authorName === '' || $authorEmail === '') {
                    return $this->respond($isAjax, 'error', 'Name and email are required.', Helpers::postUrl($post) . '#comment-form', 422);
                }
                if (!filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
                    return $this->respond($isAjax, 'error', 'Please enter a valid email.', Helpers::postUrl($post) . '#comment-form', 422);
                }
            }
        }

        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);

        /*
         * Someone who can approve comments is not held for approval. Their
         * comment went into the moderation queue like a stranger's, where
         * they then had to approve it themselves. "Can approve" is the
         * moderate_comments capability (by default: super admin, admin,
         * editor), so a site that gives it to more roles trusts them too.
         */
        $trusted = $currentUser !== null && $auth->userCan($currentUser, 'moderate_comments');
        $defaultStatus = ($trusted || !$settings->get('discussion', 'moderate_first', true)) ? 'approved' : 'pending';

        // Anti-spam gate (honeypot, flood, duplicates, blocklist/moderation words).
        $decision = $comments->guard([
            'content'      => $content,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'author_url'   => $authorUrl,
            'honeypot'     => $request->input('hp_comment_field', ''),
            'post_id'      => $postId,
            'status'       => $defaultStatus,
            'trusted'      => $trusted,
        ]);

        if ($decision['action'] === 'reject') {
            return $this->respond($isAjax, 'error', $decision['message'] ?? 'Your comment could not be posted.', Helpers::postUrl($post) . '#comment-form', 429);
        }
        if ($decision['action'] === 'drop') {
            // Honeypot tripped: behave exactly like a held comment, store nothing.
            $held = 'Thanks! Your comment has been submitted and is awaiting moderation.';
            if ($isAjax) {
                return Response::json(['success' => true, 'status' => 'pending', 'pending' => true, 'message' => $held, 'comment' => null], 201);
            }
            $session->flash('success', $held);
            return Response::redirect(Helpers::postUrl($post) . '#comments');
        }
        $status = $decision['status'] ?? $defaultStatus;

        $id = $comments->create([
            'post_id' => $postId,
            'author_id' => $currentUser['id'] ?? null,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'author_url' => $authorUrl,
            'content' => $content,
            'parent_id' => $request->input('parent_id') ?: null,
            'status' => $status,
        ]);

        // Spam is stored (for admin review) but treated like "held" to the user.
        $msg = $status === 'approved'
            ? 'Comment posted successfully.'
            : 'Thanks! Your comment has been submitted and is awaiting moderation.';

        if ($isAjax) {
            return Response::json([
                'success' => true,
                'status' => $status,
                'pending' => $status !== 'approved',
                'message' => $msg,
                'comment' => $status === 'approved' ? $comments->find($id) : null,
                // The post's approved total after this comment, so a page can
                // update its count without reloading.
                'count' => $comments->approvedCount($postId),
            ], 201);
        }

        $session->flash('success', $msg);
        return Response::redirect(Helpers::postUrl($post) . '#comments');
    }

    private function isAjax(Request $request): bool
    {
        $h = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($h, 'XMLHttpRequest') === 0) return true;
        $a = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($a, 'application/json');
    }

    private function respond(bool $isAjax, string $type, string $message, string $redirectTo, int $status): Response
    {
        if ($isAjax) {
            return Response::json(['success' => $type === 'success', 'error' => $type === 'error' ? $message : null, 'message' => $message], $status);
        }
        $this->app->make(Session::class)->flash($type, $message);
        return Response::redirect($redirectTo);
    }
}
