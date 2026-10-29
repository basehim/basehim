<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\SettingService;

class SearchController extends Controller
{
    use RendersTheme;

    public function index(Request $request): Response
    {
        // ?q[]=x arrives as an array; treat it as no query rather than warn.
        $q = $request->query('q', '');
        $query = is_scalar($q) ? trim((string) $q) : '';
        $p = $request->query('page', 1);
        $page = is_scalar($p) ? max(1, (int) $p) : 1;

        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        $perPage = (int)$settings->get('reading', 'posts_per_page', 10);

        $result = ['data' => [], 'results' => [], 'meta' => ['total' => 0, 'page' => 1, 'last_page' => 1, 'per_page' => $perPage]];
        if ($query !== '' && ($wait = \App\Services\SearchThrottle::check($request, $this->app)) !== null) {
            return \App\Core\Http\SitePage::error(429, 'Too Many Requests', 'You have searched a lot in a short time. Please wait a minute and try again.')
                ->header('Retry-After', (string) $wait);
        }
        if ($query !== '') {
            // BH Search (1.2.92): best match first, across the content types
            // and app sources chosen in Settings → Search.
            $r = (new \App\Core\Search\SiteSearch($this->app))->search($query, $page, $perPage);
            $result = ['data' => $r['posts'], 'results' => $r['items'],
                'meta' => ['total' => $r['total'], 'page' => $r['page'], 'last_page' => $r['last_page'], 'per_page' => $r['per_page']]];
        }

        return $this->renderTheme('search', [
            'query' => $query,
            // Post rows, as themes have always had them, each with
            // search_snippet / search_title (HTML, matches in <mark>).
            'posts' => $result['data'],
            // Every result, apps' included: source, title, title_html, url,
            // snippet (HTML), date, item (the source's row).
            'results' => $result['results'],
            'meta' => $result['meta'],
            'seo' => [
                'title' => $query !== '' ? "Search: {$query}" : 'Search',
                'description' => 'Search results for: ' . $query,
                'robots' => 'noindex,follow',
            ],
        ]);
    }
}
