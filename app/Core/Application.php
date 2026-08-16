<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Application
 *
 * Minimal DI container + service registry. PSR-11 inspired.
 * Captures the spirit of the spec's "PSR-11 container" without
 * pulling in the full PSR ecosystem.
 */
final class Application
{
    private static ?self $instance = null;
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function boot(): void
    {
        $app = self::getInstance();
        $app->registerCoreServices();
    }

    private function registerCoreServices(): void
    {
        // Config
        $this->singleton(Config::class, fn() => new Config(BASEHIM_ROOT . '/config'));

        // Database
        $this->singleton(Database::class, function ($app) {
            $cfg = $app->make(Config::class)->get('database');
            return new Database($cfg);
        });

        // Hook registry — central event/filter system
        $this->singleton(HookRegistry::class, function () {
            $hooks = new HookRegistry();
            // Core filter (priority 5, before app filters at default 10):
            // when a post is stored in block format, render its JSON to HTML
            // on output. Apps can still transform the result afterwards.
            $hooks->addFilter('post.content', function ($content, $post = null) use ($hooks) {
                $content = (string) $content;
                $format  = is_array($post) ? (string) ($post['content_format'] ?? '') : '';

                // Trust the *content*, not the metadata. content_format and the
                // stored body drift apart easily (format switched in the editor,
                // written via REST/MCP, imported, set by an app) — and when
                // they did, raw block JSON was printed on the public site.
                // Sniffing the shape makes this self-correcting.
                if ($format === 'blocks' || \App\Services\BlockRenderer::looksLikeBlocks($content)) {
                    return \App\Services\BlockRenderer::render($content, $hooks);
                }
                if ($format === 'markdown') {
                    return \App\Services\Markdown::toHtml($content);
                }
                return $content;
            }, 5, 2);
            // Widget block: `{"type":"widget","data":{"widget":"key","settings":{}}}`
            // renders on the public site by delegating to the WidgetRegistry.
            $hooks->addFilter('blocks.render.widget', function ($html, array $data) {
                $key = (string) ($data['widget'] ?? $data['key'] ?? '');
                if ($key === '') return $html;
                $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
                $reg = \App\Core\Application::getInstance()->make(\App\Core\WidgetRegistry::class);
                return '<div class="bh-widget bh-widget--' . htmlspecialchars($key, ENT_QUOTES)
                    . '">' . $reg->render($key, $settings, 'frontend') . '</div>';
            }, 10, 3);
            return $hooks;
        });

        // Widget registry — apps & themes register widgets here during boot.
        $this->singleton(WidgetRegistry::class, fn() => new WidgetRegistry());

        // Widget-area registry — themes/apps declare the regions ("sidebars")
        // where frontend widgets can be placed.
        $this->singleton(\App\Core\WidgetAreaRegistry::class, fn() => new \App\Core\WidgetAreaRegistry());

        // Bridges area definitions with the admin's saved widget placements.
        $this->singleton(\App\Services\WidgetAreaService::class, fn($app) =>
            new \App\Services\WidgetAreaService(
                $app->make(\App\Services\SettingService::class),
                $app->make(\App\Core\WidgetAreaRegistry::class),
                $app->make(WidgetRegistry::class)
            ));


        // Logger
        $this->singleton(Logger::class, fn() => new Logger(BASEHIM_ROOT . '/storage/logs'));

        // Cache (file-based by default)
        $this->singleton(Cache::class, fn() => new Cache(BASEHIM_ROOT . '/storage/cache'));

        // Router
        $this->singleton(Router::class, fn($app) => new Router($app));

        // View renderer — share the install base path so templates can prepend
        // it to all internal URLs. Empty for root installs, '/basehim' for subdirs.
        $this->singleton(View::class, function () {
            $view = new View(BASEHIM_ROOT . '/admin/views');
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            $view->share('base', $base);
            return $view;
        });

        // Session helper
        $this->singleton(Session::class, fn() => new Session());

        // Request
        $this->singleton(Request::class, fn() => Request::capture());

        // Auth service
        $this->singleton(\App\Services\AuthService::class, function ($app) {
            return new \App\Services\AuthService(
                $app->make(Database::class),
                $app->make(Config::class),
                $app->make(Session::class),
            );
        });

        // Post service
        $this->singleton(\App\Services\PostService::class, function ($app) {
            return new \App\Services\PostService(
                $app->make(\App\Repositories\PostRepository::class),
                $app->make(HookRegistry::class),
                $app->make(Cache::class),
            );
        });

        // User service
        $this->singleton(\App\Services\UserService::class, function ($app) {
            return new \App\Services\UserService(
                $app->make(\App\Repositories\UserRepository::class),
                $app->make(HookRegistry::class),
            );
        });

        // Media service
        $this->singleton(\App\Services\MediaService::class, function ($app) {
            return new \App\Services\MediaService(
                $app->make(\App\Repositories\MediaRepository::class),
                $app->make(HookRegistry::class),
                BASEHIM_ROOT . '/storage/uploads',
                $app->make(\App\Services\SettingService::class),
                $app->make(\App\Services\ImageProcessor::class),
            );
        });

        $this->singleton(\App\Services\ImageProcessor::class, fn() => new \App\Services\ImageProcessor());

        // Settings service
        $this->singleton(\App\Services\SettingService::class, function ($app) {
            return new \App\Services\SettingService(
                $app->make(Database::class),
                $app->make(Cache::class),
            );
        });

        // Taxonomy service
        $this->singleton(\App\Services\TaxonomyService::class, function ($app) {
            return new \App\Services\TaxonomyService(
                $app->make(\App\Repositories\TaxonomyRepository::class),
                $app->make(HookRegistry::class),
            );
        });

        // Comment service
        $this->singleton(\App\Services\CommentNotifier::class, fn($app) =>
            new \App\Services\CommentNotifier(
                $app->make(\App\Services\Mailer::class),
                $app->make(\App\Services\SettingService::class),
                $app->make(Config::class),
            ));

        $this->singleton(\App\Services\CommentService::class, function ($app) {
            return new \App\Services\CommentService(
                $app->make(Database::class),
                $app->make(HookRegistry::class),
                $app->make(\App\Services\SettingService::class),
                $app->make(\App\Services\CommentNotifier::class),
            );
        });

        // Menu service
        $this->singleton(\App\Services\MenuService::class, function ($app) {
            return new \App\Services\MenuService($app->make(Database::class));
        });

        // SEO service
        $this->singleton(\App\Services\SeoService::class, function ($app) {
            return new \App\Services\SeoService($app->make(Database::class));
        });

        // Content type registry — built-in types plus anything apps register.
        $this->singleton(\App\Services\PostTypeRegistry::class, function ($app) {
            return new \App\Services\PostTypeRegistry(
                $app->make(Config::class),
                $app->make(HookRegistry::class),
            );
        });

        // App permission broker, per-app logger, and static scanner.
        $this->singleton(\App\Services\PermissionBroker::class, function ($app) {
            return new \App\Services\PermissionBroker($app->make(Database::class));
        });
        $this->singleton(\App\Services\AppLogger::class, function ($app) {
            return new \App\Services\AppLogger(BASEHIM_ROOT . '/storage/logs');
        });
        $this->singleton(\App\Services\AppScanner::class, function ($app) {
            return new \App\Services\AppScanner();
        });

        // Scheduler — recurring app work, driven post-response or by real cron.
        $this->singleton(\App\Services\SchedulerService::class, function ($app) {
            return new \App\Services\SchedulerService(
                $app->make(Database::class),
                $app->make(\App\Services\SettingService::class),
                $app->make(Logger::class),
            );
        });

        // Apps live in content/apps/. Nothing else is scanned.
        $this->singleton(\App\Services\AppService::class, function ($app) {
            return new \App\Services\AppService(
                $app->make(Database::class),
                $app->make(HookRegistry::class),
                BASEHIM_ROOT . '/content/apps',
            );
        });

        // Theme service
        $this->singleton(\App\Services\ThemeService::class, function ($app) {
            return new \App\Services\ThemeService(
                $app->make(\App\Services\SettingService::class),
                BASEHIM_ROOT . '/content/themes',
            );
        });

        // API Key service
        $this->singleton(\App\Services\ApiKeyService::class, function ($app) {
            return new \App\Services\ApiKeyService(
                $app->make(Database::class),
            );
        });

        // Repositories
        $this->singleton(\App\Repositories\PostRepository::class, fn($app) =>
            new \App\Repositories\PostRepository($app->make(Database::class)));
        $this->singleton(\App\Repositories\UserRepository::class, fn($app) =>
            new \App\Repositories\UserRepository($app->make(Database::class)));
        $this->singleton(\App\Repositories\MediaRepository::class, fn($app) =>
            new \App\Repositories\MediaRepository($app->make(Database::class)));
        $this->singleton(\App\Repositories\TaxonomyRepository::class, fn($app) =>
            new \App\Repositories\TaxonomyRepository($app->make(Database::class)));

        // Short aliases
        $this->alias('config', Config::class);
        $this->alias('db', Database::class);
        $this->alias('hooks', HookRegistry::class);
        $this->alias('logger', Logger::class);
        $this->alias('cache', Cache::class);
        $this->alias('router', Router::class);
        $this->alias('view', View::class);
        $this->alias('session', Session::class);
        $this->alias('request', Request::class);
        $this->alias('auth', \App\Services\AuthService::class);
        $this->alias('posts', \App\Services\PostService::class);
        $this->alias('users', \App\Services\UserService::class);
        $this->alias('media', \App\Services\MediaService::class);
        $this->alias('settings', \App\Services\SettingService::class);
        $this->alias('taxonomies', \App\Services\TaxonomyService::class);
        $this->alias('comments', \App\Services\CommentService::class);
        $this->alias('menus', \App\Services\MenuService::class);
        $this->alias('seo', \App\Services\SeoService::class);
        $this->alias('apps', \App\Services\AppService::class);
        $this->alias('scheduler', \App\Services\SchedulerService::class);
        $this->alias('permissions', \App\Services\PermissionBroker::class);
        $this->alias('post_types', \App\Services\PostTypeRegistry::class);
        $this->alias('themes', \App\Services\ThemeService::class);

        // Boot apps (after services are ready)
        $this->bootApps();
    }

    private function bootApps(): void
    {
        // Built-in frontend widgets, available before any app/theme loads so
        // the widget areas are useful out of the box.
        try {
            $this->registerCoreWidgets();
        } catch (\Throwable $e) {
            try { $this->make(Logger::class)->error('Core widget boot failure: ' . $e->getMessage()); } catch (\Throwable) {}
        }

        try {
            $this->make(\App\Services\AppService::class)->bootActive($this);
        } catch (\Throwable $e) {
            // Don't let app errors take down the boot sequence
            try {
                $this->make(Logger::class)->error('App boot failure: ' . $e->getMessage());
            } catch (\Throwable) {}
        }

        // Let the active theme register its widgets (from its widgets.php).
        try {
            $this->make(\App\Services\ThemeService::class)->bootWidgets($this);
        } catch (\Throwable $e) {
            try { $this->make(Logger::class)->error('Theme widget boot failure: ' . $e->getMessage()); } catch (\Throwable) {}
        }

        // …and the widget areas it declares (theme.json "widget_areas").
        try {
            $this->make(\App\Services\ThemeService::class)->bootAreas($this);
        } catch (\Throwable $e) {
            try { $this->make(Logger::class)->error('Theme widget-area boot failure: ' . $e->getMessage()); } catch (\Throwable) {}
        }
    }

    /**
     * A small set of always-available frontend widgets so a fresh install can
     * populate its sidebars immediately. Apps/themes add more via the registry.
     */
    private function registerCoreWidgets(): void
    {
        /** @var WidgetRegistry $reg */
        $reg = $this->make(WidgetRegistry::class);

        $title = static function (array $s): string {
            $t = trim((string) ($s['title'] ?? ''));
            if ($t === '') return '';
            $before = (string) ($s['__before_title'] ?? '<h3 class="widget-title">');
            $after  = (string) ($s['__after_title'] ?? '</h3>');
            return $before . htmlspecialchars($t, ENT_QUOTES) . $after;
        };

        // Custom HTML / text block. Frontend-only on purpose: the block editor
        // already has a native 'html' block for raw markup, so we don't add a
        // second raw-HTML path here. Placement into areas is manage_apps-gated.
        $reg->register('core.html', [
            'title'       => 'HTML / Text',
            'description' => 'A block of custom HTML or text.',
            'icon'        => 'code-bracket',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'html',  'label' => 'Content (HTML allowed)', 'type' => 'textarea'],
            ],
            'render' => static function (array $s) use ($title): string {
                return $title($s) . '<div class="widget-html">' . ((string) ($s['html'] ?? '')) . '</div>';
            },
        ]);

        // Search form.
        $reg->register('core.search', [
            'title'       => 'Search',
            'description' => 'A site search form.',
            'icon'        => 'magnifying-glass',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title',       'label' => 'Title', 'type' => 'text'],
                ['key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
            ],
            'render' => static function (array $s) use ($title): string {
                $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
                $ph = htmlspecialchars((string) ($s['placeholder'] ?? 'Search…'), ENT_QUOTES);
                return $title($s)
                    . '<form class="widget-search" method="get" action="' . htmlspecialchars($base . '/search', ENT_QUOTES) . '">'
                    . '<input type="search" name="q" placeholder="' . $ph . '" aria-label="Search">'
                    . '<button type="submit">Search</button>'
                    . '</form>';
            },
        ]);

        // Recent posts.
        $reg->register('core.recent-posts', [
            'title'       => 'Recent Posts',
            'description' => 'A list of your most recent published posts.',
            'icon'        => 'newspaper',
            'source'      => 'core',
            'surfaces'    => ['frontend'],
            'fields'      => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'count', 'label' => 'Number of posts', 'type' => 'number'],
            ],
            'render' => function (array $s) use ($title): string {
                $count = (int) ($s['count'] ?? 5);
                if ($count < 1) $count = 5;
                if ($count > 20) $count = 20;
                try {
                    /** @var \App\Repositories\PostRepository $posts */
                    $posts = $this->make(\App\Repositories\PostRepository::class);
                    $rows = $posts->recent($count + 10, 'post');
                } catch (\Throwable) {
                    $rows = [];
                }
                $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
                $items = '';
                $shown = 0;
                foreach ($rows as $p) {
                    if (($p['status'] ?? '') !== 'published') continue;
                    $url = \App\Core\Helpers::postUrl($p, $base);
                    $items .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                        . htmlspecialchars((string) ($p['title'] ?? 'Untitled')) . '</a></li>';
                    if (++$shown >= $count) break;
                }
                if ($items === '') $items = '<li class="widget-empty">No posts yet.</li>';
                return $title($s) . '<ul class="widget-recent-posts">' . $items . '</ul>';
            },
        ]);
    }


    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function make(string $abstract): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $resolver = $this->bindings[$abstract];
            $instance = $resolver instanceof Closure ? $resolver($this) : new $resolver();
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        if (class_exists($abstract)) {
            $instance = $this->build($abstract);
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        throw new \RuntimeException("Cannot resolve service: {$abstract}");
    }

    /**
     * Autowire a class via reflection — resolve constructor type hints from the container.
     */
    private function build(string $class): object
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if (!$ctor) {
            return new $class();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->make($type->getName());
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new \RuntimeException(
                "Cannot resolve parameter \${$param->getName()} for {$class}"
            );
        }

        return $ref->newInstanceArgs($args);
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
}
