<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\TaxonomyService;
use App\Services\PostService;
use App\Services\SettingService;

class TaxonomyController extends Controller
{
    use RendersTheme;

    public function category(Request $request, string $slug): Response
    {
        return $this->renderArchive('category', $slug, $request);
    }

    public function tag(Request $request, string $slug): Response
    {
        return $this->renderArchive('tag', $slug, $request);
    }

    private function renderArchive(string $taxonomy, string $slug, Request $request): Response
    {
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        $term = $tax->findTermBySlug($taxonomy, $slug);
        if (!$term) return $this->notFound(ucfirst($taxonomy) . ' not found');

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $perPage = (int)$settings->get('reading', 'posts_per_page', 10);
        $page = max(1, (int)$request->query('page', 1));
        $result = $posts->byTermId((int)$term['id'], $page, $perPage);

        return $this->renderTheme('archive', [
            'archive_type' => $taxonomy,
            'term' => $term,
            'posts' => $result['data'],
            'meta' => $result['meta'],
            'title' => ucfirst($taxonomy) . ': ' . $term['name'],
            'seo' => [
                'title' => ucfirst($taxonomy) . ': ' . $term['name'],
                'description' => $term['description'] ?? '',
            ],
        ]);
    }
}
