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
                // Markdown stored under the default 'html' format (common for
                // posts written through the API, MCP or AI tools) printed its
                // ** and * on the public site. Same self-correction (1.2.48).
                if ($format === 'markdown'
                    || (($format === 'html' || $format === '') && \App\Services\Markdown::looksLikeMarkdown($content))) {
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

        // The dashboard engine (1.2.68): layouts per user in users.meta, the
        // site default in settings, data and actions behind each widget's
        // capability. See docs/DASHBOARD-WIDGETS.md.
        $this->singleton(\App\Services\Dashboard\DashboardService::class, fn($app) =>
            new \App\Services\Dashboard\DashboardService(
                $app->make(WidgetRegistry::class),
                new \App\Services\Dashboard\UserMetaLayoutStore(
                    $app->make(\App\Services\UserService::class),
                    $app->make(\App\Services\SettingService::class)
                ),
                $app->make(Cache::class),
                static function (string $owner) use ($app): string {
                    static $memo = [];
                    if (isset($memo[$owner])) return $memo[$owner];
                    if ($owner === 'core') return $memo[$owner] = 'Basehim';
                    [$kind, $slug] = array_pad(explode(':', $owner, 2), 2, '');
                    if ($kind === 'app') {
                        try {
                            $row = $app->make(\App\Services\AppService::class)->find($slug);
                            if (!empty($row['name'])) return $memo[$owner] = (string) $row['name'];
                        } catch (\Throwable) {}
                    }
                    $label = ucwords(str_replace(['-', '_'], ' ', $slug ?: $owner));
                    return $memo[$owner] = ($kind === 'theme' ? $label . ' theme' : $label);
                },
                static function (string $level, string $message, string $owner) use ($app): void {
                    try {
                        if (str_starts_with($owner, 'app:')) {
                            $app->make(\App\Services\AppLogger::class)->log(substr($owner, 4), $level, $message, []);
                        }
                        $app->make(Logger::class)->log($level, '[dashboard] ' . $message, ['owner' => $owner]);
                    } catch (\Throwable) {}
                }
            ));

        // Widget-area registry — themes/apps declare the regions ("sidebars")
        // where frontend widgets can be placed.
        $this->singleton(\App\Core\WidgetAreaRegistry::class, fn() => new \App\Core\WidgetAreaRegistry());

        // Bridges area definitions with the admin's saved widget placements.
        $this->singleton(\App\Services\WidgetAreaService::class, fn($app) =>
            new \App\Services\WidgetAreaService(
                $app->make(\App\Services\SettingService::class),
                $app->make(\App\Core\WidgetAreaRegistry::class),
                $app->make(WidgetRegistry::class),
                null,
                $app->make(\App\Core\Widgets\FrontendWidgets::class)
            ));

        // The public site's widget framework (1.2.93): placements, rendering,
        // templates, caching. The admin dashboard's widgets are separate.
        $this->singleton(\App\Core\Widgets\Placements::class, fn($app) =>
            new \App\Core\Widgets\Placements($app->make(\App\Services\SettingService::class)));
        $this->singleton(\App\Core\Widgets\FrontendWidgets::class, fn($app) =>
            new \App\Core\Widgets\FrontendWidgets(
                $app->make(WidgetRegistry::class),
                $app->make(\App\Core\WidgetAreaRegistry::class),
                $app->make(\App\Core\Widgets\Placements::class),
                $app->make(Cache::class),
                $app->make(Logger::class),
                $app->make(HookRegistry::class),
                [
                    'user' => fn() => $app->make(\App\Services\AuthService::class)->currentUser(),
                    'theme' => fn() => $app->make(\App\Services\ThemeService::class)->activeSlug(),
                    'theme_path' => fn() => $app->make(\App\Services\ThemeService::class)->activePath(),
                    'app_active' => fn(string $slug) => $app->make(\App\Services\AppService::class)->isBooted($slug),
                    'preview' => fn() => $app->make(\App\Services\CustomizerService::class)->isPreview(),
                ]
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

        // Taxonomies and custom fields for content types (1.2.94).
        $this->singleton(\App\Services\TaxonomyRegistry::class, fn($app) => new \App\Services\TaxonomyRegistry(
            $app->make(Config::class),
            $app->make(\App\Services\PostTypeRegistry::class),
            $app->make(Database::class),
            $app->make(Cache::class),
            $app->make(HookRegistry::class),
        ));
        $this->singleton(\App\Services\Fields\FieldRegistry::class, fn() => new \App\Services\Fields\FieldRegistry());
        $this->singleton(\App\Services\Fields\FieldService::class, fn($app) => new \App\Services\Fields\FieldService(
            $app->make(Database::class),
            $app->make(\App\Services\Fields\FieldRegistry::class),
        ));

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

        // Fault isolation for apps (1.2.71): validation before an app's code
        // is loaded, quarantine when it fails. Database and loggers are
        // resolved lazily, so the guard works while either is unavailable.
        $this->singleton(\App\Services\AppHealth\AppGuard::class, function ($app) {
            return new \App\Services\AppHealth\AppGuard(
                // APP_HEALTH_DIR moves the reports (the test suite uses its own).
                new \App\Services\AppHealth\HealthStore((string) (Env::get('APP_HEALTH_DIR', '') ?: BASEHIM_ROOT . '/storage/app-health')),
                new \App\Services\AppHealth\Preflight(BASEHIM_ROOT),
                static fn() => $app->make(Database::class),
                static fn() => $app->make(Logger::class),
                static fn() => $app->make(\App\Services\AppLogger::class),
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
                // So a failing template or partial can be reported rather than
                // disappearing into a blank page.
                $app->make(Logger::class),
            );
        });

        // API Key service
        $this->singleton(\App\Services\ApiKeyService::class, function ($app) {
            return new \App\Services\ApiKeyService(
                $app->make(Database::class),
            );
        });

        // AJAX gateway: the action registry (filled by routes/ajax.php and by
        // apps' boot()) and the counters behind its rate limits.
        $this->singleton(\App\Core\Ajax\AjaxRegistry::class, fn($app) =>
            new \App\Core\Ajax\AjaxRegistry($app->make(Config::class)));
        $this->singleton(\App\Services\RateLimiter::class, fn($app) =>
            new \App\Services\RateLimiter($app->make(Database::class), $app->make(Logger::class)));
        // Post editor rules shared by the form and the AJAX actions (1.2.47).
        $this->singleton(\App\Services\PostEditorService::class, fn($app) =>
            new \App\Services\PostEditorService($app->make(Database::class)));
        // Takes scheduled posts live when their time comes (1.2.60).
        $this->singleton(\App\Services\ScheduledPublisher::class, fn($app) =>
            new \App\Services\ScheduledPublisher(
                $app->make(\App\Repositories\PostRepository::class),
                $app->make(\App\Services\PostService::class),
                $app->make(Cache::class),
                $app->make(Logger::class),
            ));

        // Repositories
        $this->singleton(\App\Repositories\PostRepository::class, fn($app) =>
            new \App\Repositories\PostRepository(
                $app->make(Database::class),
                $app->make(Cache::class),
                fn() => (string) $app->make(\App\Services\SettingService::class)->get('reading', 'search_mode', 'auto'),
            ));
        $this->singleton(\App\Repositories\UserRepository::class, fn($app) =>
            new \App\Repositories\UserRepository($app->make(Database::class)));
        $this->singleton(\App\Repositories\MediaRepository::class, fn($app) =>
            new \App\Repositories\MediaRepository($app->make(Database::class)));
        $this->singleton(\App\Repositories\TaxonomyRepository::class, fn($app) =>
            new \App\Repositories\TaxonomyRepository($app->make(Database::class)));

        // BH Search (1.2.92): one search framework for core and apps.
        $this->singleton(\App\Core\Search\SearchManager::class, fn($app) =>
            new \App\Core\Search\SearchManager(
                $app->make(Database::class),
                $app->make(\App\Services\SettingService::class),
                $app->make(Cache::class),
                $app->make(HookRegistry::class),
                fn(\App\Core\Search\SearchManager $m) => \App\Core\Search\CoreSources::register($m, $app),
                (defined('BASEHIM_ROOT') ? BASEHIM_ROOT : dirname(__DIR__, 2)) . '/storage/locks',
            ));

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
        $this->alias('ajax', \App\Core\Ajax\AjaxRegistry::class);

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

        // Cached widget output (1.2.93) is cleared when what it shows changes.
        try {
            $hooks = $this->make(HookRegistry::class);
            $flush = fn() => $this->make(\App\Core\Widgets\FrontendWidgets::class)->flush();
            foreach (['post.created', 'post.updated', 'post.published', 'post.deleted', 'post.restored',
                      'term.created', 'term.updated', 'term.deleted',
                      'comment.created', 'comment.status_changed', 'comment.deleted',
                      'media.updated', 'media.deleted', 'media.file_replaced', 'user.updated', 'user.deleted'] as $tag) {
                $hooks->addAction($tag, $flush, 90, 0);
            }
        } catch (\Throwable) {}

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

        // …and its boot.php, where a theme registers filters (seo.*, …).
        try {
            $this->make(\App\Services\ThemeService::class)->bootTheme($this);
        } catch (\Throwable $e) {
            try { $this->make(Logger::class)->error('Theme boot.php failure: ' . $e->getMessage()); } catch (\Throwable) {}
        }

        // Scheduled posts whose time has come go live now, before this request
        // is routed, so it already sees them (1.2.60). After apps and the theme
        // have booted, so their post.published / post.updated listeners run.
        // Costs one cache-file read when nothing is due; never throws.
        try {
            $this->make(\App\Services\ScheduledPublisher::class)->maybeRun();
        } catch (\Throwable) {}

        // Core maintenance (1.2.76): page views folded in every minute
        // (CR-23), old rows and files cleaned up daily (CR-24). Registered
        // without a query; the task rows are made when a sweep runs.
        try {
            $scheduler = $this->make(\App\Services\SchedulerService::class);
            $scheduler->core('post-views', 60, fn() => $this->make(\App\Services\PostViewCounter::class)->fold());
            $scheduler->core('cleanup', 86400, fn() => $this->make(\App\Services\RetentionService::class)->run());
            // BH Search (1.2.92): build the search index in the background, a few
            // seconds per sweep, and retry anything that failed to index.
            $scheduler->core('search-index', 60, fn() => $this->make(\App\Core\Search\SearchManager::class)->build(8.0));
        } catch (\Throwable) {}
    }

    /**
     * A small set of always-available frontend widgets so a fresh install can
     * populate its sidebars immediately. Apps/themes add more via the registry.
     */
    private function registerCoreWidgets(): void
    {
        /** @var WidgetRegistry $reg */
        $reg = $this->make(WidgetRegistry::class);

        // HTML / Text, Search, Recent Posts, Categories, Tags: data providers
        // and templates since 1.2.93 (app/Core/Widgets/templates).
        \App\Core\Widgets\CoreWidgets::register($reg, $this);

        // The admin dashboard's own cards (1.2.68), registered like any app's.
        \App\Services\Dashboard\CoreDashboardWidgets::register($reg);
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
