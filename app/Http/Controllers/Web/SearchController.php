<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\SettingService;

class SearchController extends Controller
{
    use RendersTheme;

    public function index(Request $request): Response
    {
        $query = trim((string)$request->query('q', ''));
        $page = max(1, (int)$request->query('page', 1));

        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        $perPage = (int)$settings->get('reading', 'posts_per_page', 10);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);

        $result = ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'last_page' => 1, 'per_page' => $perPage]];
        if ($query !== '') {
            $result = $posts->search($query, $page, $perPage);
        }

        return $this->renderTheme('search', [
            'query' => $query,
            'posts' => $result['data'],
            'meta' => $result['meta'],
            'seo' => [
                'title' => $query !== '' ? "Search: {$query}" : 'Search',
                'description' => 'Search results for: ' . $query,
                'robots' => 'noindex,follow',
            ],
        ]);
    }
}
