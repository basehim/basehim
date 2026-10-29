<?php

declare(strict_types=1);

namespace App\Core\Search;

use App\Core\Application;
use App\Core\Helpers;
use App\Services\SettingService;

/**
 * The site's own search: /search, /api/v1/search and api()->posts()->search()
 * (1.2.92). Settings → Search chooses what it covers and the order.
 *
 * Results are ranked by relevance across every content type and app source
 * chosen, with word variants ("pricing" finds "price"), a highlighted
 * snippet, and the same query syntax as everywhere else. Until the content
 * index is built it answers as before (published posts, newest first).
 */
final class SiteSearch
{
    public const GROUP = 'search';
    public const SORTS = ['relevance', 'newest'];

    public function __construct(private Application $app) {}

    private function manager(): SearchManager
    {
        return $this->app->make(SearchManager::class);
    }

    /** @return array{types: list<string>, sources: list<string>, sort: string} */
    public function settings(): array
    {
        $s = $this->app->make(SettingService::class);
        $types = $s->get(self::GROUP, 'site_types', null);
        if (is_string($types)) $types = json_decode($types, true);
        $off = $s->get(self::GROUP, 'site_sources_off', []);
        if (is_string($off)) $off = json_decode($off, true);
        $sort = (string) $s->get(self::GROUP, 'site_sort', 'relevance');
        $available = array_keys($this->availableTypes());
        return [
            'types' => is_array($types) ? array_values(array_intersect(array_map('strval', $types), $available)) : array_values(array_intersect(['post', 'page'], $available)),
            'sources' => array_values(array_diff(array_keys($this->appSources()), is_array($off) ? array_map('strval', $off) : [])),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'relevance',
        ];
    }

    /** Content types that can appear in site search: slug => label. */
    public function availableTypes(): array
    {
        $out = ['post' => 'Posts', 'page' => 'Pages'];
        try {
            foreach ($this->app->make(\App\Services\PostTypeRegistry::class)->custom() as $slug => $def) {
                if (!empty($def['public']) && ($def['searchable'] ?? true) !== false) $out[$slug] = (string) ($def['label'] ?? $slug);
            }
        } catch (\Throwable) {}
        return $out;
    }

    /** Public sources apps registered: id => label. */
    public function appSources(): array
    {
        $out = [];
        foreach ($this->manager()->sources() as $id => $s) {
            if ($s->owner !== 'core' && $s->isPublic()) $out[$id] = $s->label;
        }
        return $out;
    }

    /**
     * Search the site.
     *
     * @param bool $contentOnly only posts, pages and content types (the API and api()->posts()->search())
     * @return array{items: list<array>, posts: list<array>, total:int, page:int, per_page:int, last_page:int, indexed:bool}
     */
    public function search(string $query, int $page = 1, int $perPage = 10, bool $contentOnly = false, ?array $types = null): array
    {
        $cfg = $this->settings();
        $types = $types !== null ? array_values(array_map('strval', $types)) : $cfg['types'];
        $m = $this->manager();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if (!$m->ready('content')) {
            return $this->legacy($query, $page, $perPage);
        }
        $sources = array_merge($types !== [] ? ['content'] : [], $contentOnly ? [] : $cfg['sources']);
        if ($sources === []) {
            return ['items' => [], 'posts' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1, 'indexed' => true];
        }
        $r = $m->query($query, [
            'sources' => $sources,
            'public' => true,
            'source_types' => ['content' => $types],
            'page' => $page, 'per_page' => $perPage,
            'sort' => $cfg['sort'],
        ]);
        $items = [];
        $posts = [];
        foreach ($r['items'] as $it) {
            if ($it['source'] === 'content' && isset($it['item'])) {
                $row = $it['item'] + ['search_snippet' => $it['snippet'], 'search_title' => $it['title_html'], 'search_score' => $it['score']];
                $it['url'] = Helpers::postUrl($row);
                $it['item'] = $row;
                $posts[] = $row;
            }
            $items[] = $it;
        }
        return ['items' => $items, 'posts' => $posts, 'total' => $r['total'], 'page' => $r['page'],
            'per_page' => $r['per_page'], 'last_page' => $r['last_page'], 'indexed' => true];
    }

    /** Before the index is built: published posts matching every word, newest first (the 1.2.91 behaviour). */
    private function legacy(string $query, int $page, int $perPage): array
    {
        $r = $this->app->make(\App\Repositories\PostRepository::class)->paginate([
            'type' => 'post', 'status' => 'published', 'search' => $query,
        ], $page, $perPage);
        $q = SearchQuery::parse($query);
        $posts = [];
        $items = [];
        foreach ($r['data'] as $row) {
            $row['search_snippet'] = $q->snippet(TextExtractor::html((string) ($row['excerpt'] ?? '') ?: TextExtractor::fromContent((string) ($row['content'] ?? ''), (string) ($row['content_format'] ?? 'html'), 2000)));
            $row['search_title'] = $q->highlight((string) ($row['title'] ?? ''));
            $posts[] = $row;
            $items[] = ['source' => 'content', 'id' => (int) $row['id'], 'score' => 1.0, 'title' => (string) $row['title'],
                'title_html' => $row['search_title'], 'snippet' => $row['search_snippet'], 'type' => (string) $row['type'],
                'status' => 'published', 'url' => Helpers::postUrl($row), 'date' => $row['published_at'] ?? null, 'item' => $row];
        }
        $meta = $r['meta'];
        return ['items' => $items, 'posts' => $posts, 'total' => (int) ($meta['total'] ?? count($posts)), 'page' => (int) ($meta['page'] ?? $page),
            'per_page' => $perPage, 'last_page' => (int) ($meta['last_page'] ?? 1), 'indexed' => false];
    }
}
