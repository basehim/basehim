<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\UserService;
use App\Services\PostService;
use App\Services\SettingService;

class AuthorController extends Controller
{
    use RendersTheme;

    public function show(Request $request, string $username): Response
    {
        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $user = $users->findByUsername($username);
        if (!$user) return $this->notFound('Author not found');

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $perPage = (int)$settings->get('reading', 'posts_per_page', 10);
        $page = max(1, (int)$request->query('page', 1));
        $result = $posts->paginate([
            'type' => 'post',
            'status' => 'published',
            'author_id' => (int)$user['id'],
        ], $page, $perPage);

        return $this->renderTheme('archive', [
            'archive_type' => 'author',
            'author' => $user,
            'posts' => $result['data'],
            'meta' => $result['meta'],
            'title' => 'Posts by ' . ($user['display_name'] ?? $user['username']),
            'seo' => [
                'title' => 'Posts by ' . ($user['display_name'] ?? $user['username']),
                'description' => $user['bio'] ?? '',
            ],
        ]);
    }
}
