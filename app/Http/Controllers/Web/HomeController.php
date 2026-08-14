<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\SettingService;

class HomeController extends Controller
{
    use RendersTheme;

    public function index(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);

        // Static page homepage?
        $homepageType = $settings->get('reading', 'homepage_type', 'posts');
        if ($homepageType === 'page') {
            $slug = $settings->get('reading', 'homepage_slug');
            if ($slug) {
                $page = $posts->findBySlug($slug);
                if ($page && $page['type'] === 'page' && $page['status'] === 'published') {
                    return $this->renderTheme('page', [
                        'post' => $page,
                        'page' => $page,
                        'is_home' => true,
                    ]);
                }
            }
        }

        // Latest posts feed
        $perPage = (int)$settings->get('reading', 'posts_per_page', 10);
        $page = max(1, (int)$request->query('page', 1));
        $feed = $posts->feed($page, $perPage);

        return $this->renderTheme('index', [
            'posts' => $feed['data'],
            'meta' => $feed['meta'],
            'is_home' => true,
        ]);
    }
}
