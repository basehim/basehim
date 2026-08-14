<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\UserService;
use App\Services\MediaService;
use App\Services\CommentService;
use App\Services\SettingService;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // NOTE: the update check used to run here, which meant the dashboard
        // blocked on a round-trip to the update service before it could render.
        // It now runs in the background from the browser once the page has
        // painted (POST /admin/updates/sync.json), so the dashboard is always
        // instant and the check can run far more often.

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        /** @var CommentService $comments */
        $comments = $this->app->make(CommentService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $postCounts = $posts->counts();
        $commentCounts = $comments->counts();

        $stats = [
            'posts' => $postCounts['post']['total'] ?? 0,
            'posts_published' => $postCounts['post']['published'] ?? 0,
            'posts_draft' => $postCounts['post']['draft'] ?? 0,
            'pages' => $postCounts['page']['total'] ?? 0,
            'media' => $media->totalCount(),
            'media_size' => $media->totalSize(),
            'users' => $users->totalCount(),
            'comments' => $commentCounts['total'] ?? 0,
            'comments_pending' => $commentCounts['pending'] ?? 0,
        ];

        return $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'currentUser' => $this->user(),
            'stats' => $stats,
            'recentPosts' => $posts->recent(5, 'post'),
            'recentComments' => $comments->recent(5),
            'siteName' => $settings->get('general', 'site_title', 'Basehim'),
        ]);
    }
}
