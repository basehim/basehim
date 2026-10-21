<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Env;
use App\Core\Helpers;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\PostRepository;
use App\Services\SettingService;
use App\Services\TaxonomyService;

/**
 * XML sitemaps (1.2.76, CR-20).
 *
 * /sitemap.xml is a sitemap index. It lists:
 *   /sitemap-content-1.xml, -2, …  published content of every type (and the home
 *                                   page in the first), PAGE_SIZE per file;
 *   /sitemap-terms.xml              categories and tags that have content;
 *   /sitemap-authors.xml            author archives, when they are on.
 *
 * Before 1.2.76 it was one file capped at 2,000 entries, so a larger site
 * silently lost the rest, and it ran one query per post for its category.
 * Content files are read by id ranges (keyset, no OFFSET), each file looks up
 * its posts' primary categories in one query, and every file is cached for
 * CACHE_TTL seconds and rebuilt after any save.
 *
 * Addresses use APP_URL, not the request's Host header, so a request with a
 * forged Host can neither show nor cache foreign URLs.
 */
class SitemapController extends Controller
{
    public const PAGE_SIZE = 10000;
    public const CACHE_TTL = 600;

    public function index(Request $request): Response
    {
        if (!$this->enabled()) return Response::make('Sitemap disabled', 404);
        return $this->cached('index', function (): string {
            $o = $this->origin();
            $files = [];
            foreach ($this->contentStarts() as $n => $_) $files[] = $o . '/sitemap-content-' . ($n + 1) . '.xml';
            $files[] = $o . '/sitemap-terms.xml';
            if ($this->authorsOn()) $files[] = $o . '/sitemap-authors.xml';
            $lastmod = $this->db()->selectOne(
                "SELECT MAX(updated_at) AS m FROM {posts} WHERE status = 'published' AND deleted_at IS NULL"
            )['m'] ?? null;

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($files as $loc) {
                $xml .= "  <sitemap>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
                if ($lastmod && str_contains($loc, '/sitemap-content-')) {
                    $xml .= '    <lastmod>' . self::w3c((string) $lastmod) . "</lastmod>\n";
                }
                $xml .= "  </sitemap>\n";
            }
            return $xml . '</sitemapindex>';
        });
    }

    /** /sitemap-{name}.xml: content-N, terms, authors. */
    public function part(Request $request, string $name): Response
    {
        if (!$this->enabled()) return Response::make('Sitemap disabled', 404);
        if (preg_match('/^content-([1-9]\d{0,5})$/', $name, $m)) {
            $n = (int) $m[1];
            $starts = $this->contentStarts();
            if (!isset($starts[$n - 1])) return Response::make('Not found', 404);
            return $this->cached('content-' . $n, fn() => $this->contentFile($n, $starts));
        }
        if ($name === 'terms') return $this->cached('terms', fn() => $this->termsFile());
        if ($name === 'authors' && $this->authorsOn()) return $this->cached('authors', fn() => $this->authorsFile());
        return Response::make('Not found', 404);
    }

    // ------------------------------------------------------------------

    /**
     * The id each content file starts after: [0, id₁, id₂, …]. File n holds
     * the next PAGE_SIZE published items after starts[n-1]. One indexed step
     * per file, cached with the index.
     *
     * @return int[]
     */
    private function contentStarts(): array
    {
        $key = $this->cacheKey('starts');
        $hit = $this->cache()->get($key);
        if (is_array($hit)) return $hit;
        $starts = [0];
        $after = 0;
        for ($i = 0; $i < 5000; $i++) {
            $row = $this->db()->selectOne(
                "SELECT id FROM {posts}
                  WHERE status = 'published' AND deleted_at IS NULL AND id > :a
                  ORDER BY id LIMIT 1 OFFSET " . (self::PAGE_SIZE - 1),
                ['a' => $after]
            );
            if (!$row) break;
            $after = (int) $row['id'];
            // Another file only if something follows this one's last item.
            $more = $this->db()->selectOne(
                "SELECT id FROM {posts} WHERE status = 'published' AND deleted_at IS NULL AND id > :a LIMIT 1",
                ['a' => $after]
            );
            if (!$more) break;
            $starts[] = $after;
        }
        $this->cache()->set($key, $starts, self::CACHE_TTL);
        return $starts;
    }

    private function contentFile(int $n, array $starts): string
    {
        $after = (int) $starts[$n - 1];
        $upTo = $starts[$n] ?? null;
        $sql = "SELECT id, slug, type, updated_at FROM {posts}
                 WHERE status = 'published' AND deleted_at IS NULL AND id > :a";
        $params = ['a' => $after];
        if ($upTo !== null) { $sql .= ' AND id <= :b'; $params['b'] = (int) $upTo; }
        $rows = $this->db()->select($sql . ' ORDER BY id LIMIT ' . self::PAGE_SIZE, $params);

        $cats = Helpers::permalinkStructure() === 'category' ? $this->primaryCategories($rows) : [];
        $o = $this->origin();
        $xml = self::open();
        if ($n === 1) $xml .= self::url($o . '/', null, 'daily', '1.0');
        foreach ($rows as $p) {
            $cat = $p['type'] === 'post' ? ($cats[(int) $p['id']] ?? '') : '';
            $xml .= self::url($o . Helpers::postUrl($p, '', $cat), $p['updated_at'], 'weekly', '0.8');
        }
        return $xml . '</urlset>';
    }

    /**
     * Each post's first category slug, for all of $rows in one query. A post
     * with none is left out, and postUrl() looks it up (a post not re-saved
     * since 1.2.7).
     *
     * @return array<int,string>
     */
    private function primaryCategories(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) if ($r['type'] === 'post') $ids[] = (int) $r['id'];
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', $chunk);   // integers only
            foreach ($this->db()->select(
                "SELECT pt.post_id, t.slug
                   FROM {post_term} pt
                   JOIN {terms} t ON t.id = pt.term_id
                   JOIN {taxonomies} tax ON tax.id = t.taxonomy_id AND tax.slug = 'category'
                  WHERE pt.post_id IN ({$in})
                  ORDER BY pt.post_id, pt.term_order, pt.term_id"
            ) as $r) {
                $out[(int) $r['post_id']] ??= (string) $r['slug'];
            }
        }
        return $out;
    }

    private function termsFile(): string
    {
        $o = $this->origin();
        $xml = self::open();
        $rows = $this->db()->select(
            "SELECT t.slug, tax.slug AS taxonomy FROM {terms} t
               JOIN {taxonomies} tax ON tax.id = t.taxonomy_id AND tax.slug IN ('category', 'tag')
              WHERE t.count > 0
              ORDER BY t.count DESC, t.id
              LIMIT " . self::PAGE_SIZE
        );
        foreach ($rows as $t) {
            $xml .= self::url($o . '/' . $t['taxonomy'] . '/' . rawurlencode((string) $t['slug']), null, 'weekly', '0.6');
        }
        return $xml . '</urlset>';
    }

    private function authorsFile(): string
    {
        $o = $this->origin();
        $xml = self::open();
        try {
            /** @var \App\Services\AuthorService $authors */
            $authors = $this->app->make(\App\Services\AuthorService::class);
            foreach ($authors->authors() as $u) {
                $path = $authors->url($u);
                if ($path !== '') $xml .= self::url($o . $path, null, 'weekly', '0.5');
            }
        } catch (\Throwable) {
            // An empty file beats an error.
        }
        return $xml . '</urlset>';
    }

    // ------------------------------------------------------------------

    private function cached(string $file, \Closure $build): Response
    {
        $key = $this->cacheKey($file);
        $xml = $this->cache()->get($key);
        if (!is_string($xml)) {
            $xml = $build();
            $this->cache()->set($key, $xml, self::CACHE_TTL);
        }
        return Response::make($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** Per origin, and new after any post is saved (PostRepository's list generation). */
    private function cacheKey(string $file): string
    {
        $gen = $this->app->make(PostRepository::class)->listGeneration();
        return 'sitemap.' . $gen . '.' . md5($this->origin()) . '.' . $file;
    }

    /**
     * scheme://host[:port] plus the install's base path, from APP_URL. The
     * request's host is used only when APP_URL is missing or still points at
     * localhost while the site is served elsewhere.
     */
    private function origin(): string
    {
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $app = trim((string) (Env::get('APP_URL', '') ?? ''));
        if (preg_match('#^(https?://[A-Za-z0-9.\-]+(?::\d+)?)#i', $app, $m)) {
            $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
            $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            $reqHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            if (!$local || $reqHost === '' || in_array($reqHost, ['localhost', '127.0.0.1'], true)) {
                return rtrim($m[1], '/') . $base;
            }
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . $base;
    }

    private function enabled(): bool
    {
        return (bool) $this->app->make(SettingService::class)->get('seo', 'generate_sitemap', true);
    }

    private function authorsOn(): bool
    {
        try {
            return $this->app->make(\App\Services\AuthorService::class)->archivesEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    private function db(): Database
    {
        return $this->app->make(Database::class);
    }

    private function cache(): Cache
    {
        return $this->app->make(Cache::class);
    }

    private static function open(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    }

    private static function url(string $loc, ?string $lastmod, string $freq, string $priority): string
    {
        $node = "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
        if ($lastmod) $node .= '    <lastmod>' . self::w3c($lastmod) . "</lastmod>\n";
        return $node . "    <changefreq>{$freq}</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
    }

    /** A stored UTC time as W3C datetime. */
    private static function w3c(string $utc): string
    {
        $t = strtotime($utc . ' UTC');
        return $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : '';
    }
}
