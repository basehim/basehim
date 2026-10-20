<?php

declare(strict_types=1);

namespace Example\DashboardWidgets;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Services\Dashboard\WidgetContext;
use App\Services\Dashboard\WidgetUnavailable;

/**
 * Dashboard widgets from an app (Basehim 1.2.68+). See docs/DASHBOARD-WIDGETS.md.
 *
 * Nothing here touches core's dashboard files: every card is registered from
 * boot(), so when this app is switched off its cards stop running at once,
 * and people's dashboards keep their place for them until it comes back.
 *
 * Keys are namespaced automatically: 'pulse' becomes 'dashboard-example.pulse'.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        // 1. A stat card. The provider returns numbers; the dashboard draws them.
        $this->registerWidget('pulse', [
            'surfaces'    => ['dashboard'],
            'title'       => 'Content pulse',
            'description' => 'Words written and posts touched in the last 7 days.',
            'icon'        => 'bolt',
            'capability'  => 'edit_posts',
            'dashboard'   => [
                'view'  => 'stat',
                'size'  => 'half',
                'sizes' => ['third', 'half', 'full'],
                'cache' => ['ttl' => 120, 'scope' => 'user'],
                'data'  => [$this, 'pulse'],
            ],
        ]);

        // 2. A table with settings. The settings form is drawn from 'fields',
        //    validated by core, stored per card, and handed to the provider.
        $this->registerWidget('top-posts', [
            'surfaces'    => ['dashboard'],
            'title'       => 'Most viewed posts',
            'description' => 'Published posts with the most views.',
            'icon'        => 'table-cells',
            'capability'  => 'edit_posts',
            'fields'      => [
                ['key' => 'limit', 'label' => 'Rows', 'type' => 'number', 'min' => 3, 'max' => 20, 'default' => 5],
                ['key' => 'mine', 'label' => 'Only my posts', 'type' => 'checkbox', 'default' => false],
            ],
            'dashboard'   => [
                'view'  => 'table',
                'size'  => 'half',
                'data'  => [$this, 'topPosts'],
                'empty' => ['title' => 'No views yet', 'text' => 'Views are counted when people read published posts.'],
            ],
        ]);

        // 3. A status card with an action. The action has its own capability,
        //    checked on the server when it runs; the menu only offers it to
        //    people who hold it.
        $this->registerWidget('cache', [
            'surfaces'    => ['dashboard'],
            'title'       => 'Cache',
            'description' => 'How full the file cache is, with a button to clear it.',
            'icon'        => 'archive-box',
            'capability'  => 'manage_settings',
            'dashboard'   => [
                'view'    => 'status',
                'sizes'   => ['third', 'half'],
                'default' => false,                      // offered in Add widgets, not placed
                'data'    => [$this, 'cacheStatus'],
                'actions' => [
                    ['id' => 'clear', 'label' => 'Clear cache', 'icon' => 'trash', 'capability' => 'manage_settings',
                     'confirm' => 'Clear the file cache? Pages will be a little slower until it fills again.',
                     'danger' => true, 'handler' => [$this, 'clearCache']],
                ],
            ],
        ]);

        // 4. A card whose data comes from this app's own AJAX action — useful
        //    when the data already has an endpoint. The browser calls it through
        //    the gateway, with that action's capability, CSRF and rate limit.
        $this->ajax('widget-clock', [
            'handler'    => [$this, 'clock'],
            'methods'    => ['GET'],
            'capability' => 'read',
            'input'      => ['instance' => 'string|max:41', 'settings' => 'string|max:4000'],
        ]);
        $this->registerWidget('clock', [
            'surfaces'    => ['dashboard'],
            'title'       => 'Server clock',
            'description' => 'The time on the server and in the site timezone.',
            'icon'        => 'clock',
            'capability'  => 'read',
            'dashboard'   => [
                'endpoint' => $this->slug() . '.widget-clock',
                'size'     => 'third',
                'refresh'  => 60,                          // seconds; 60 is the minimum
                'default'  => false,
            ],
        ]);

        // 5. A widget written before 1.2.68: HTML from a render callback. Still
        //    works unchanged, on the dashboard and anywhere else it is used.
        $this->registerWidget('hello', [
            'title'     => 'Hello (HTML widget)',
            'icon'      => 'hand-raised',
            'surfaces'  => ['dashboard'],
            'render'    => fn(array $s, string $surface) => '<p>Hello from an app widget rendered as HTML.</p>',
            'dashboard' => ['width' => 'third', 'default' => false],
        ]);
    }

    // ------------------------------------------------------------------

    public function pulse(WidgetContext $ctx): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        // Someone who can't edit others' posts counts only their own.
        $filters = $ctx->can('edit_others_posts') ? [] : ['author_id' => $ctx->userId()];
        $page = $this->api()->posts()->paginate($filters, 1, 100);
        $words = 0;
        $touched = 0;
        foreach ($page['data'] ?? [] as $p) {
            if ((string) ($p['updated_at'] ?? '') < $since) continue;
            $touched++;
            $words += str_word_count(strip_tags((string) ($p['content'] ?? '')));
        }
        return ['items' => [
            ['label' => 'Words written', 'value' => $words, 'tone' => 'blue', 'hint' => 'In posts changed this week'],
            ['label' => 'Posts touched', 'value' => $touched, 'tone' => 'violet', 'href' => $ctx->url('/admin/posts')],
        ]];
    }

    public function topPosts(WidgetContext $ctx): array
    {
        $filters = ['status' => 'published', 'sort' => 'views_desc'];
        if ($ctx->setting('mine')) $filters['author_id'] = $ctx->userId();
        $page = $this->api()->posts()->paginate($filters, 1, (int) $ctx->setting('limit', 5));
        $out = [];
        foreach ($page['data'] ?? [] as $p) {
            $out[] = ['title' => (string) $p['title'], 'views' => (int) ($p['view_count'] ?? 0),
                      '_href' => $ctx->url('/admin/posts/' . (int) $p['id'] . '/edit')];
        }
        return [
            'columns' => [['key' => 'title', 'label' => 'Post'], ['key' => 'views', 'label' => 'Views', 'align' => 'right']],
            'rows'    => $out,
        ];
    }

    public function cacheStatus(WidgetContext $ctx): array
    {
        $dir = BASEHIM_ROOT . '/storage/cache';
        if (!is_dir($dir)) {
            throw new WidgetUnavailable('The cache folder does not exist yet.');
        }
        $files = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { $files++; $bytes += $f->getSize(); }
        return [
            'summary' => $files === 0 ? 'The cache is empty.' : 'The cache is working.',
            'items'   => [
                ['label' => 'Cached items', 'state' => 'ok', 'detail' => (string) $files],
                ['label' => 'Size', 'state' => $bytes > 200 * 1048576 ? 'warn' : 'ok', 'detail' => round($bytes / 1048576, 1) . ' MB'],
                ['label' => 'Writable', 'state' => is_writable($dir) ? 'ok' : 'error', 'detail' => is_writable($dir) ? 'Yes' : 'No'],
            ],
        ];
    }

    public function clearCache(WidgetContext $ctx): array
    {
        $this->cache()->flush();
        $this->log('Cache cleared from the dashboard', ['user' => $ctx->userId()]);
        return ['message' => 'Cache cleared.', 'refresh' => true];
    }

    /** The AJAX action behind the 'clock' card: return a view and its data. */
    public function clock(AjaxContext $ctx): array
    {
        $tz = \App\Core\Time::info()['timezone'] ?? 'UTC';
        try {
            $local = new \DateTimeImmutable('now', new \DateTimeZone((string) $tz));
        } catch (\Throwable) {
            throw new AjaxException('UNAVAILABLE', 'The site timezone is not set.', 503);
        }
        return [
            'view'  => 'stat',
            'items' => [
                ['label' => 'Site time', 'value' => $local->format('H:i'), 'hint' => (string) $tz],
                ['label' => 'Server (UTC)', 'value' => gmdate('H:i'), 'tone' => 'slate'],
            ],
        ];
    }
}
