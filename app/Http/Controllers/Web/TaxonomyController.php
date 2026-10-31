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

    /**
     * A term of an app-registered taxonomy (1.2.94): /{prefix}/{term}. The
     * taxonomy comes from the first path segment. Theme: taxonomy-{key}.php,
     * else archive.php.
     */
    public function custom(Request $request, string $slug): Response
    {
        $path = trim($request->path(), '/');
        $base = defined('BASEHIM_BASE') ? trim((string) BASEHIM_BASE, '/') : '';
        if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base) + 1);
        $tax = $this->app->make(\App\Services\TaxonomyRegistry::class)->byPrefix(rawurldecode(explode('/', $path)[0] ?? ''));
        if ($tax === null || empty($tax['public'])) return $this->notFound('Page not found');
        return $this->renderArchive($tax['slug'], $slug, $request, $tax);
    }

    private function renderArchive(string $taxonomy, string $slug, Request $request, ?array $def = null): Response
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

        if ($def !== null) {
            $label = $def['singular'] . ': ' . $term['name'];
            return $this->renderTheme(['taxonomy-' . $taxonomy, 'archive'], [
                'archive_type' => 'taxonomy',
                'taxonomy' => $def,
                'term' => $term,
                'posts' => $result['data'],
                'meta' => $result['meta'],
                'title' => $label,
                'seo' => ['title' => $label, 'description' => $term['description'] ?? ''],
            ]);
        }

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
