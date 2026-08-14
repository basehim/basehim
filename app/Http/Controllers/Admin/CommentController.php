<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\CommentService;

class CommentController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        $page = max(1, (int)$request->query('page', 1));
        $status = (string)$request->query('status', '');

        $filters = [];
        if ($status !== '') $filters['status'] = $status;

        $result = $comments->paginate($filters, $page, 25);
        $session = $this->app->make(Session::class);

        return $this->view('comments.index', [
            'title' => 'Comments',
            'currentUser' => $this->user(),
            'comments' => $result['data'],
            'meta' => $result['meta'],
            'status' => $status,
            'counts' => $comments->counts(),
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->setStatus($request, $id, 'approved', 'approved');
    }

    public function spam(Request $request, string $id): Response
    {
        return $this->setStatus($request, $id, 'spam', 'marked as spam');
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        $comments->delete((int)$id);
        $this->flash('success', 'Comment deleted.');
        return $this->back();
    }

    private function setStatus(Request $request, string $id, string $status, string $verb): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        $comments->setStatus((int)$id, $status);
        $this->flash('success', "Comment {$verb}.");
        return $this->back();
    }
}
