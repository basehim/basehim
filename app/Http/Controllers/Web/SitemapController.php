<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\TaxonomyService;
use App\Services\SettingService;
use App\Core\Database;

class SitemapController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        if (!$settings->get('seo', 'generate_sitemap', true)) {
            return Response::make('Sitemap disabled', 404);
        }

        $base = $this->baseUrl($request);

        /** @var Database $db */
        $db = $this->app->make(Database::class);
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);

        /*
         * id is required, not cosmetic: Helpers::postUrl() resolves the
         * primary category through lookupPrimaryCategory((int) $post['id']).
         * Without it every row evaluates as id 0, no category is ever found,
         * and every post silently falls back to /posts/{slug} — which the
         * sitemap then reasserts to every crawler on every fetch, regardless
         * of the site's permalink structure. This was confirmed live: all 19
         * published posts on cloudhim.com listed as /posts/{slug} despite the
         * category permalink structure being active, which is what let
         * Google index both the old and new URL for the same post.
         */
        $posts = $db->select(
            "SELECT id, slug, type, updated_at FROM {posts} WHERE status='published' AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 2000"
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Homepage
        $xml .= $this->urlNode($base . '/', null, 'daily', '1.0');

        foreach ($posts as $p) {
            $path = Helpers::postUrl($p);
            $xml .= $this->urlNode($base . $path, $p['updated_at'], 'weekly', '0.8');
        }

        // Taxonomy terms
        foreach (['category', 'tag'] as $taxName) {
            $terms = $tax->termsByTaxonomySlug($taxName);
            foreach ($terms as $term) {
                $xml .= $this->urlNode($base . "/{$taxName}/{$term['slug']}", null, 'weekly', '0.6');
            }
        }

        // Author archives, when switched on: one per author with a published
        // post (the only authors that have a page).
        try {
            /** @var \App\Services\AuthorService $authors */
            $authors = $this->app->make(\App\Services\AuthorService::class);
            if ($authors->archivesEnabled()) {
                foreach ($authors->authors() as $u) {
                    $path = $authors->url($u);
                    if ($path !== '') $xml .= $this->urlNode($base . $path, null, 'weekly', '0.5');
                }
            }
        } catch (\Throwable $e) {
            // A sitemap without author pages beats no sitemap.
        }

        $xml .= '</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function urlNode(string $loc, ?string $lastmod, string $freq, string $priority): string
    {
        $node = "  <url>\n    <loc>" . htmlspecialchars($loc) . "</loc>\n";
        if ($lastmod) {
            $node .= "    <lastmod>" . date('c', strtotime($lastmod)) . "</lastmod>\n";
        }
        $node .= "    <changefreq>{$freq}</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
        return $node;
    }

    private function baseUrl(Request $request): string
    {
        $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        return $scheme . '://' . $host . $base;
    }
}
