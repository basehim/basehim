<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Core\Application;
use App\Core\WidgetRegistry;

/**
 * Core's frontend widgets (1.2.93): HTML / Text, Search, Recent Posts,
 * Categories and Tags.
 *
 * Each is a data provider plus a template in templates/, so a theme restyles
 * one by copying the template to templates/widgets/{name}.php (for example
 * templates/widgets/recent-posts.php) and editing the copy. The keys, the
 * setting names and the stored values are the ones used before 1.2.93, and
 * the markup is the same, so placed widgets and theme CSS keep working.
 */
final class CoreWidgets
{
    public static function register(WidgetRegistry $reg, Application $app): void
    {
        $tpl = __DIR__ . '/templates/';
        $db = fn() => $app->make(\App\Core\Database::class);

        $reg->register('core.html', [
            'title'       => 'HTML / Text',
            'description' => 'A block of custom HTML or text.',
            'icon'        => 'code-bracket',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                // Printed as written, so only people trusted with raw HTML may change it.
                ['key' => 'html', 'label' => 'Content (HTML allowed)', 'type' => 'html', 'capability' => 'unfiltered_html',
                 'help' => 'Printed exactly as written. Scripts run on every page that shows this widget.'],
            ],
            'frontend'    => ['category' => 'utility', 'version' => '1.1.0', 'template' => $tpl . 'html.php'],
        ]);

        $reg->register('core.search', [
            'title'       => 'Search',
            'description' => 'A site search form.',
            'icon'        => 'magnifying-glass',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title',       'label' => 'Title', 'type' => 'text'],
                ['key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text', 'maxlength' => 80,
                 'help' => 'Shown in the empty box. Leave empty for "Search…".'],
                ['key' => 'button',      'label' => 'Button text', 'type' => 'text', 'default' => 'Search', 'maxlength' => 40],
            ],
            'frontend'    => ['category' => 'search', 'version' => '1.1.0', 'template' => $tpl . 'search.php'],
        ]);

        $categorySlugs = function () use ($db): array {
            $opts = ['' => 'All categories'];
            try {
                foreach ($db()->select("SELECT t.slug, t.name FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category' ORDER BY t.name LIMIT 500") as $r) {
                    $opts[(string) $r['slug']] = html_entity_decode((string) $r['name'], ENT_QUOTES);
                }
            } catch (\Throwable) {}
            return $opts;
        };
        $tagSlugs = function () use ($db): array {
            $opts = ['' => 'Any tag'];
            try {
                foreach ($db()->select("SELECT t.slug, t.name FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'tag' ORDER BY t.name LIMIT 500") as $r) {
                    $opts[(string) $r['slug']] = html_entity_decode((string) $r['name'], ENT_QUOTES);
                }
            } catch (\Throwable) {}
            return $opts;
        };
        $authors = function () use ($db): array {
            $opts = ['0' => 'Any author'];
            try {
                foreach ($db()->select("SELECT u.id, u.display_name, u.username FROM {users} u
                                         WHERE EXISTS (SELECT 1 FROM {posts} p WHERE p.author_id = u.id AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL)
                                         ORDER BY u.display_name LIMIT 500") as $r) {
                    $opts[(string) $r['id']] = (string) ($r['display_name'] ?: $r['username']);
                }
            } catch (\Throwable) {}
            return $opts;
        };

        $reg->register('core.recent-posts', [
            'title'       => 'Recent Posts',
            'description' => 'Your latest, most viewed or most discussed posts, with optional thumbnails, dates and excerpts.',
            'icon'        => 'newspaper',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20],
                ['key' => 'order', 'label' => 'Show', 'type' => 'select', 'default' => 'latest',
                 'options' => ['latest' => 'Latest posts', 'popular' => 'Most viewed', 'commented' => 'Most commented']],
                // Looked up only when the form is shown, not on every page.
                ['key' => 'category', 'label' => 'From category', 'type' => 'select', 'default' => '', 'options' => $categorySlugs],
                ['key' => 'tag', 'label' => 'With tag', 'type' => 'select', 'default' => '', 'options' => $tagSlugs],
                ['key' => 'author', 'label' => 'By author', 'type' => 'select', 'default' => '0', 'options' => $authors],
                ['key' => 'show_thumbnail', 'label' => 'Show thumbnails', 'type' => 'checkbox', 'default' => '0'],
                ['key' => 'thumbnail_size', 'label' => 'Thumbnail size', 'type' => 'select', 'default' => 'thumbnail',
                 'options' => ['thumbnail' => 'Small — beside the title', 'medium' => 'Medium — above the title'],
                 'show_if' => ['show_thumbnail' => '1']],
                ['key' => 'thumbnail_shape', 'label' => 'Thumbnail shape', 'type' => 'select', 'default' => 'square',
                 'options' => [
                     'square'   => 'Square — cropped to fill',
                     'original' => 'Original shape — the whole image, nothing cropped',
                     'wide'     => 'Wide 16:9 — cropped to fill',
                 ], 'show_if' => ['show_thumbnail' => '1']],
                ['key' => 'show_date', 'label' => 'Show the date', 'type' => 'checkbox', 'default' => '0'],
                ['key' => 'show_excerpt', 'label' => 'Show a short excerpt', 'type' => 'checkbox', 'default' => '0'],
                ['key' => 'excerpt_length', 'label' => 'Excerpt length (characters)', 'type' => 'number', 'default' => 110,
                 'min' => 30, 'max' => 400, 'show_if' => ['show_excerpt' => '1']],
                ['key' => 'hide_current', 'label' => 'Leave out the post being read', 'type' => 'checkbox', 'default' => '1'],
            ],
            'frontend'    => [
                'category' => 'content',
                'version'  => '1.1.0',
                'template' => $tpl . 'recent-posts.php',
                'data'     => fn(array $s, array $ctx) => self::recentPosts($app, $s, $ctx),
                // Leaving out the post being read makes the list depend on the page.
                'cache'    => ['ttl' => 300, 'vary' => fn(array $s, array $ctx) => $s['hide_current'] === '1' ? $ctx['path'] : ''],
                'assets'   => ['inline_css' => self::RECENT_CSS],
            ],
        ]);

        $termFields = function (string $taxonomy, string $noun) use ($app): array {
            $f = [
                ['key' => 'title',       'label' => 'Title', 'type' => 'text'],
                ['key' => 'display',     'label' => 'Display as', 'type' => 'select', 'default' => 'list',
                 'options' => ['list' => 'List', 'dropdown' => 'Dropdown', 'cloud' => 'Cloud — busier ones larger']],
                ['key' => 'count',       'label' => 'How many to show', 'type' => 'number', 'min' => 1, 'max' => 100,
                 'help' => 'Leave empty for 10.'],
                ['key' => 'order',       'label' => 'Order by', 'type' => 'select', 'default' => 'count',
                 'options' => ['count' => 'Most used', 'name' => 'Name']],
                ['key' => 'direction',   'label' => 'Direction', 'type' => 'select', 'default' => 'auto',
                 'options' => ['auto' => 'Most used: busiest first. Name: A to Z', 'reverse' => 'Reversed']],
                ['key' => 'show_counts', 'label' => 'Show post counts', 'type' => 'checkbox'],
                ['key' => 'hide_empty',  'label' => 'Hide ones with no posts', 'type' => 'checkbox'],
                ['key' => 'exclude',     'label' => 'Leave out', 'type' => 'terms', 'taxonomy' => $taxonomy, 'multiple' => true,
                 'help' => 'These ' . $noun . 's are never listed.'],
            ];
            if ($taxonomy === 'category') {
                $f[] = ['key' => 'hierarchical', 'label' => 'Show sub-categories under their parent', 'type' => 'checkbox', 'default' => '0',
                        'show_if' => ['display' => ['list', 'dropdown']]];
                $f[] = ['key' => 'depth', 'label' => 'Levels to show', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 5,
                        'help' => '0 shows every level.', 'show_if' => ['hierarchical' => '1']];
            }
            $f[] = ['key' => 'smallest', 'label' => 'Smallest text (em)', 'type' => 'number', 'default' => 0.8, 'min' => 0.5, 'max' => 3, 'step' => 0.1,
                    'show_if' => ['display' => 'cloud']];
            $f[] = ['key' => 'largest', 'label' => 'Largest text (em)', 'type' => 'number', 'default' => 1.6, 'min' => 0.5, 'max' => 4, 'step' => 0.1,
                    'show_if' => ['display' => 'cloud']];
            return $f;
        };

        foreach ([['core.categories', 'Categories', 'A list, dropdown or cloud of your categories.', 'folder', 'category', '/category/'],
                  ['core.tags', 'Tags', 'A list, dropdown or cloud of your tags.', 'tag', 'tag', '/tag/']] as [$key, $title, $desc, $icon, $tax, $prefix]) {
            $reg->register($key, [
                'title'       => $title,
                'description' => $desc,
                'icon'        => $icon,
                'source'      => 'core',
                'surfaces'    => ['frontend'],
                'fields'      => $termFields($tax, $tax),
                'frontend'    => [
                    'category' => 'navigation',
                    'version'  => '1.1.0',
                    'template' => $tpl . 'terms.php',
                    'data'     => fn(array $s, array $ctx) => self::terms($app, $tax, $prefix, $s, $ctx),
                    'cache'    => 600,
                    'assets'   => ['inline_css' => self::TERMS_CSS, 'inline_js' => self::TERMS_JS],
                ],
            ]);
        }
    }

    /** @internal the Recent Posts data provider */
    public static function recentPosts(Application $app, array $s, array $ctx): array
    {
        $count = (int) $s['count'];
        $thumbs = $s['show_thumbnail'] === '1';
        $size = $s['thumbnail_size'] === 'medium' ? 'medium' : 'thumbnail';
        $shape = in_array($s['thumbnail_shape'], ['square', 'original', 'wide'], true) ? $s['thumbnail_shape'] : 'square';
        // The "thumbnail" file is already cropped square, so it only suits a
        // small square. Every other combination uses the uncropped medium image.
        $file = ($size === 'thumbnail' && $shape === 'square') ? 'thumbnail' : 'medium';
        $date = $s['show_date'] === '1';
        $excerpt = $s['show_excerpt'] === '1';
        $hideCur = $s['hide_current'] === '1';
        $rows = $app->make(\App\Repositories\PostRepository::class)->widgetList([
            'limit'    => $count + 1, // one spare, in case the post being read is among them
            'order'    => $s['order'],
            'category' => $s['category'],
            'tag'      => $s['tag'] ?? '',
            'author'   => (int) ($s['author'] ?? 0),
        ]);
        $base = $ctx['base'];
        $here = $ctx['path'] === '/' ? '' : $ctx['path'];
        $max = (int) ($s['excerpt_length'] ?? 110);
        $items = [];
        foreach ($rows as $p) {
            $url = \App\Core\Helpers::postUrl($p, $base);
            if ($hideCur && $here !== '' && rtrim((string) parse_url($url, PHP_URL_PATH), '/') === $here) continue;
            $item = ['title' => (string) ($p['title'] ?? 'Untitled'), 'url' => $url, 'image' => '', 'date' => '', 'date_iso' => '', 'excerpt' => ''];
            if ($thumbs && function_exists('bh_post_image')) {
                $item['image'] = bh_post_image($p, $file, [
                    'alt'   => '', // decorative: the title beside it says the same
                    'sizes' => $size === 'medium' ? '(max-width: 600px) 100vw, 320px' : '96px',
                ]);
            }
            if ($date && !empty($p['published_at'])) {
                $item['date'] = \App\Core\Time::date($p['published_at'], 'j M Y');
                $item['date_iso'] = \App\Core\Time::iso($p['published_at']);
            }
            if ($excerpt) {
                $text = trim((string) ($p['excerpt'] ?? ''));
                if ($text === '') {
                    $body = ltrim((string) ($p['content'] ?? ''));
                    if ($body !== '' && $body[0] !== '{' && $body[0] !== '[') $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES)));
                }
                if (mb_strlen($text) > $max) $text = rtrim(mb_substr($text, 0, $max), " ,.;:-") . '…';
                $item['excerpt'] = $text;
            }
            $items[] = $item;
            if (count($items) >= $count) break;
        }
        return ['posts' => $items, 'rich' => $thumbs || $date || $excerpt, 'thumbs' => $thumbs, 'size' => $size, 'shape' => $shape];
    }

    /** @internal the Categories / Tags data provider */
    public static function terms(Application $app, string $taxonomy, string $urlPrefix, array $s, array $ctx): array
    {
        $limit = (int) ($s['count'] === '' ? 10 : $s['count']);
        $terms = $app->make(\App\Services\TaxonomyService::class)->termsByTaxonomySlug($taxonomy);

        /*
         * Counts are published posts only — what a visitor finds on the
         * archive page. The term row's own count includes drafts, private and
         * trashed posts. One grouped query for the whole taxonomy.
         */
        try {
            $rows = $app->make(\App\Core\Database::class)->select(
                "SELECT pt.term_id, COUNT(*) AS c
                   FROM {post_term} pt
                   JOIN {posts} p ON p.id = pt.post_id
                                 AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL
                   JOIN {terms} t ON t.id = pt.term_id
                   JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = :tax
                  GROUP BY pt.term_id",
                ['tax' => $taxonomy]
            );
            $published = array_column($rows, 'c', 'term_id');
            foreach ($terms as &$t) $t['count'] = (int) ($published[$t['id']] ?? 0);
            unset($t);
        } catch (\Throwable) {
            // Fall back to the stored count.
        }

        $exclude = array_map('intval', (array) ($s['exclude'] ?? []));
        if ($exclude) $terms = array_values(array_filter($terms, fn($t) => !in_array((int) $t['id'], $exclude, true)));
        if ($s['hide_empty'] === '1') $terms = array_values(array_filter($terms, fn($t) => (int) ($t['count'] ?? 0) > 0));

        $byName = fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']);
        // Busiest first, alphabetical within a tie so the order does not shuffle.
        $byCount = fn($a, $b) => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0)) ?: $byName($a, $b);
        usort($terms, $s['order'] === 'name' ? $byName : $byCount);
        if (($s['direction'] ?? 'auto') === 'reverse') $terms = array_reverse($terms);

        $url = fn(array $t): string => $ctx['base'] . $urlPrefix . rawurlencode((string) ($t['slug'] ?? ''));
        $item = fn(array $t, int $depth) => ['id' => (int) $t['id'], 'name' => (string) ($t['name'] ?? ''), 'url' => $url($t),
                                             'count' => (int) ($t['count'] ?? 0), 'depth' => $depth, 'size' => 1.0];

        $display = in_array($s['display'], ['list', 'dropdown', 'cloud'], true) ? $s['display'] : 'list';
        $items = [];
        if ($taxonomy === 'category' && ($s['hierarchical'] ?? '0') === '1' && $display !== 'cloud') {
            // Parents in the chosen order, each followed by its children
            // (also in that order), down to the chosen depth.
            $ids = array_column($terms, 'id');
            $children = [];
            foreach ($terms as $t) {
                $p = (int) ($t['parent_id'] ?? 0);
                // A child whose parent is hidden (empty, excluded) moves up a level.
                $children[$p > 0 && in_array($p, $ids) ? $p : 0][] = $t;
            }
            $maxDepth = (int) ($s['depth'] ?? 0);
            $walk = function (int $parent, int $depth) use (&$walk, &$items, $children, $item, $maxDepth, $limit) {
                foreach ($children[$parent] ?? [] as $t) {
                    if ($depth === 0 && count(array_filter($items, fn($i) => $i['depth'] === 0)) >= $limit) return;
                    $items[] = $item($t, $depth);
                    if ($maxDepth === 0 || $depth + 1 < $maxDepth) $walk((int) $t['id'], $depth + 1);
                }
            };
            $walk(0, 0);
        } else {
            foreach (array_slice($terms, 0, $limit) as $t) $items[] = $item($t, 0);
        }

        if ($display === 'cloud' && $items) {
            $counts = array_column($items, 'count');
            $lo = min($counts); $hi = max($counts);
            $small = (float) $s['smallest']; $large = max($small, (float) $s['largest']);
            foreach ($items as &$i) {
                $i['size'] = round($hi === $lo ? ($small + $large) / 2 : $small + ($i['count'] - $lo) / ($hi - $lo) * ($large - $small), 2);
            }
            unset($i);
            // A cloud reads alphabetically whichever terms were chosen.
            if ($s['order'] !== 'name') usort($items, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        return ['taxonomy' => $taxonomy, 'noun' => $taxonomy, 'display' => $display, 'terms' => $items,
                'show_counts' => $s['show_counts'] === '1', 'select_id' => 'bh-terms-' . $taxonomy . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $ctx['instance'])];
    }

    /*
     * Default styling. Every rule but the image ones is wrapped in :where(),
     * so it has zero specificity: any theme rule for the same element wins.
     */
    private const RECENT_CSS = ':where(.widget-recent-posts--rich){list-style:none;margin:0;padding:0;display:grid;gap:14px}'
        . ':where(.widget-recent-posts__item){display:flex;gap:12px;align-items:flex-start;margin:0;min-width:0}'
        . ':where(.widget-recent-posts__thumb){flex:0 0 auto;display:block;overflow:hidden;border-radius:8px;background:#e8edf3}'
        . ':where(.widget-recent-posts__noimg){display:block;width:100%;height:100%;min-height:48px}'
        . ':where(.widget-recent-posts__body){min-width:0;display:flex;flex-direction:column;gap:3px}'
        . ':where(.widget-recent-posts__title){font-weight:600;line-height:1.35;text-decoration:none}'
        . ':where(.widget-recent-posts__date){font-size:.8em;opacity:.7}'
        . ':where(.widget-recent-posts__excerpt){margin:0;font-size:.85em;line-height:1.45;opacity:.8}'
        . ':where(.widget-recent-posts--thumbnail.widget-recent-posts--shape-square .widget-recent-posts__thumb){width:64px;height:64px}'
        . ':where(.widget-recent-posts--thumbnail.widget-recent-posts--shape-wide .widget-recent-posts__thumb){width:96px;aspect-ratio:16/9}'
        . ':where(.widget-recent-posts--thumbnail.widget-recent-posts--shape-original .widget-recent-posts__thumb){width:88px}'
        . ':where(.widget-recent-posts--medium .widget-recent-posts__item){flex-direction:column;gap:8px}'
        . ':where(.widget-recent-posts--medium .widget-recent-posts__thumb){width:100%}'
        . ':where(.widget-recent-posts--medium.widget-recent-posts--shape-square .widget-recent-posts__thumb){aspect-ratio:1/1}'
        . ':where(.widget-recent-posts--medium.widget-recent-posts--shape-wide .widget-recent-posts__thumb){aspect-ratio:16/9}'
        // Not in :where(), so a theme reset such as img{height:auto} cannot undo it.
        . '.widget-recent-posts__thumb img{display:block;width:100%;height:100%;max-width:none;object-fit:cover}'
        . '.widget-recent-posts--shape-original .widget-recent-posts__thumb img{height:auto;object-fit:contain}';

    private const TERMS_CSS = ':where(.widget-terms){list-style:none;margin:0;padding:0}'
        . ':where(.widget-terms > li){display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;margin:0;padding:.2rem 0}'
        . ':where(.widget-terms > li > a){min-width:0;overflow-wrap:anywhere}'
        . ':where(.widget-terms > li.widget-terms__child){padding-inline-start:calc(var(--bh-depth,1) * 1rem)}'
        . ':where(.widget-count){flex:0 0 auto;min-width:1.9em;padding:.05em .55em;border-radius:999px;'
        .   'font-size:.8em;line-height:1.6;text-align:center;font-variant-numeric:tabular-nums;background:rgba(127,127,127,.14)}'
        . ':where(.widget-terms-select select){box-sizing:border-box;width:100%;padding:.5em .6em;font:inherit;color:inherit;'
        .   'background-color:transparent;border:1px solid rgba(127,127,127,.4);border-radius:.5rem}'
        . ':where(.widget-term-cloud){display:flex;flex-wrap:wrap;align-items:baseline;gap:.35em .7em;line-height:1.3}'
        . ':where(.widget-term-cloud a){text-decoration:none}'
        . ':where(.bh-visually-hidden){position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}';

    private const TERMS_JS = 'document.addEventListener("change",function(e){'
        . 'var s=e.target;if(!s||!s.matches||!s.matches("select[data-bh-term-nav]"))return;'
        . 'if(s.value)window.location.href=s.value;});';
}
