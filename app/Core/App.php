<?php

declare(strict_types=1);

namespace App\Core;

/**
 * App
 *
 * Abstract base class for Basehim apps. Provides a rich
 * set of built-in helpers so app authors don't have to reach into the
 * container for every common task (hooks, routes, settings, db, cache,
 * logging, views, assets, admin menu items, etc.).
 *
 * Apps are NOT required to extend this class — implementing a `boot()` method
 * with the appropriate signature is enough. Extending App is the recommended
 * path because it makes ~90% of the common operations a one-liner.
 *
 * Lifecycle:
 *   - boot()            : called on every request when the app is active
 *   - onActivate()      : called once when the user activates the app
 *   - onDeactivate()    : called once when the user deactivates the app
 *   - onInstall()       : called once on first sync (manifest -> DB row)
 *   - onUninstall()     : called once before the app DB row is removed
 */
abstract class App
{
    protected Application $app;
    protected HookRegistry $hooks;
    protected string $slug;
    protected array $manifest = [];
    protected string $path = '';

    /** Lazily-built core API facade; see api(). */
    private ?\App\Core\Api\AppApi $api = null;

    public function __construct(Application $app, string $slug = '', array $manifest = [], string $path = '')
    {
        $this->app = $app;
        $this->hooks = $app->make(HookRegistry::class);
        $this->slug = $slug ?: $this->guessSlug();
        $this->manifest = $manifest;
        $this->path = $path;
    }

    // ------------------------------------------------------------------
    // Lifecycle methods — override in your app as needed.
    // ------------------------------------------------------------------

    /**
     * Called on every request while the app is active. Register hooks,
     * routes, admin menu items, etc. here.
     */
    abstract public function boot(): void;

    /** Run once when the app is activated by an admin. */
    public function onActivate(): void {}

    /** Run once when the app is deactivated by an admin. */
    public function onDeactivate(): void {}

    /** Run once the first time the app is detected (manifest -> DB sync). */
    public function onInstall(): void {}

    /**
     * Run when the app's version changes on disk.
     *
     * The place to migrate your own tables and settings. Fired for downgrades
     * too — only you know whether rolling back your schema is safe — so compare
     * the numbers rather than assuming forward movement:
     *
     *     public function onUpgrade(string $from, string $to): void
     *     {
     *         if (version_compare($from, '2.0.0', '<')) {
     *             $this->schema("ALTER TABLE {$this->table('items')} ADD COLUMN colour VARCHAR(20)");
     *         }
     *     }
     *
     * Fired at most once per version change per request. A throw is logged and
     * swallowed so a bad handler cannot lock you out of the Apps screen.
     */
    public function onUpgrade(string $from, string $to): void {}

    /** Run once just before the app is removed from the system. */
    public function onUninstall(): void {}

    // ------------------------------------------------------------------
    // Hooks & filters — convenience wrappers around HookRegistry.
    // ------------------------------------------------------------------

    /** Register an action callback. */
    protected function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->hooks->addAction($tag, $callback, $priority, $acceptedArgs);
    }

    /** Register a filter callback. */
    protected function addFilter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->hooks->addFilter($tag, $callback, $priority, $acceptedArgs);
    }

    /** Fire an action — useful for app-defined events. */
    protected function doAction(string $tag, mixed ...$args): void
    {
        $this->hooks->doAction($tag, ...$args);
    }

    /** Run a value through registered filters. */
    protected function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $this->hooks->applyFilters($tag, $value, ...$args);
    }

    // ------------------------------------------------------------------
    // Block editor — convenience wrappers around the editor.* hooks.
    // ------------------------------------------------------------------

    /** Load a JS file on the post editor page (use $this->asset('js/x.js')). */
    protected function addEditorScript(string $url): void
    {
        $this->hooks->addFilter('editor.scripts', function (array $scripts) use ($url) {
            $scripts[] = $url;
            return $scripts;
        });
    }

    /** Load a CSS file on the post editor page. */
    protected function addEditorStyle(string $url): void
    {
        $this->hooks->addFilter('editor.styles', function (array $styles) use ($url) {
            $styles[] = $url;
            return $styles;
        });
    }

    /** Merge values into window.BasehimEditorConfig on the editor page. */
    protected function addEditorConfig(array $values): void
    {
        $this->hooks->addFilter('editor.config', function (array $cfg) use ($values) {
            return array_merge($cfg, $values);
        });
    }

    /**
     * Register a PHP renderer for a block type: fn(array $data, array $block): string.
     * The returned HTML is used on the public site for blocks of this type.
     */
    protected function registerBlockRenderer(string $type, callable $renderer): void
    {
        $this->hooks->addFilter('blocks.render.' . $type, function ($html, array $data, array $block) use ($renderer) {
            return (string) $renderer($data, $block);
        }, 10, 3);
    }

    /**
     * Register a stylesheet for every admin page (rendered in <head>).
     * Pass an absolute URL, or use asset() for a file inside the app.
     */
    protected function addAdminStyle(string $url): void
    {
        $this->addFilter('admin.styles', function (array $styles) use ($url) {
            $styles[] = $url;
            return $styles;
        });
    }

    /**
     * Register a script for every admin page (rendered just before </body>,
     * after the core admin scripts — so the DOM is ready to enhance).
     */
    protected function addAdminScript(string $url): void
    {
        $this->addFilter('admin.scripts', function (array $scripts) use ($url) {
            $scripts[] = $url;
            return $scripts;
        });
    }

    /**
     * Register a widget contributed by this app. Widgets can appear in the
     * block editor, on the public site, and/or on the admin dashboard.
     *
     * @param string $key Unique key; auto-namespaced to '{app-slug}.{key}'
     *                     unless it already contains a dot.
     * @param array  $def title, render (fn(array $settings, string $surface): string),
     *                     description, icon, surfaces, fields, dashboard — see
     *                     WidgetRegistry::register().
     */
    protected function registerWidget(string $key, array $def): void
    {
        if (!str_contains($key, '.') && $this->slug !== '') {
            $key = $this->slug . '.' . $key;
        }
        $def['source'] = 'app:' . $this->slug;
        $this->widgets()->register($key, $def);
    }

    /** The shared widget registry singleton. */
    protected function widgets(): \App\Core\WidgetRegistry
    {
        return $this->app->make(\App\Core\WidgetRegistry::class);
    }

    // ------------------------------------------------------------------
    // Routes — register custom front-end / admin routes from an app.
    // ------------------------------------------------------------------

    /** Register a GET route. */
    protected function get(string $pattern, callable|array|string $handler): void
    {
        $this->router()->get($pattern, $handler);
    }

    /** Register a POST route. */
    protected function post(string $pattern, callable|array|string $handler): void
    {
        $this->router()->post($pattern, $handler);
    }

    /** Register any-method route. */
    protected function route(array $methods, string $pattern, callable|array|string $handler): void
    {
        $this->router()->add($methods, $pattern, $handler);
    }

    /** Register routes inside a group (prefix + middleware). */
    protected function routeGroup(array $attributes, callable $callback): void
    {
        $this->router()->group($attributes, $callback);
    }

    /**
     * Register a GET route inside the authenticated /admin area.
     * The path is relative to /admin and the route is automatically
     * protected by the Authenticate middleware — matching how
     * routes/admin.php registers built-in admin routes.
     *
     * Example: $this->adminGet('/greeter', [$this, 'show'])
     *          -> GET /admin/greeter, behind auth.
     */
    protected function adminGet(string $pattern, callable|array|string $handler): void
    {
        $this->router()->group(
            ['prefix' => '/admin', 'middleware' => ['App\\Http\\Middleware\\Authenticate']],
            function ($router) use ($pattern, $handler) {
                $router->get($pattern, $handler);
            }
        );
    }

    /** POST companion to adminGet(). */
    protected function adminPost(string $pattern, callable|array|string $handler): void
    {
        $this->router()->group(
            ['prefix' => '/admin', 'middleware' => ['App\\Http\\Middleware\\Authenticate']],
            function ($router) use ($pattern, $handler) {
                $router->post($pattern, $handler);
            }
        );
    }

    protected function router(): Router
    {
        return $this->app->make(Router::class);
    }

    // ------------------------------------------------------------------
    // Admin menu — add a sidebar item to /admin.
    // ------------------------------------------------------------------

    /**
     * Register a custom admin sidebar item.
     *
     * @param array $item ['url' => '/admin/...', 'label' => '...', 'icon' => 'fa-...']
     */
    protected function addAdminMenu(array $item): void
    {
        // Tag the item with this app's slug so the core can (a) hide it in
        // the sidebar and (b) enforce per-app access (access_app:{slug}) at
        // the request level — without every app having to opt in.
        if (empty($item['app'])) {
            $item['app'] = $this->slug;
        }
        if (empty($item['icon'])) {
            $item['icon'] = $this->icon();
        }
        $this->addFilter('admin.menu', function (array $items) use ($item) {
            $items[] = $item;
            return $items;
        });
    }

    // ------------------------------------------------------------------
    // Settings — per-app key/value storage in the `settings` table.
    // Each app gets its own setting_group: "app:{slug}" so app settings
    // never collide with core groups (general, reading, …).
    // ------------------------------------------------------------------

    /** Get an app setting (returns $default if missing). */
    protected function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings()->get($this->settingGroup(), $key, $default);
    }

    /** Save an app setting. */
    protected function setSetting(string $key, mixed $value): void
    {
        $this->settings()->set($this->settingGroup(), $key, $value);
    }

    /** Delete an app setting. */
    protected function deleteSetting(string $key): void
    {
        // Through the service, not raw SQL. A raw DELETE leaves the cached
        // `settings.all` snapshot intact, so the value kept being served for up
        // to an hour after it was deleted.
        $this->settings()->delete($this->settingGroup(), $key);
        $this->cache()->flushTag('settings');
    }

    /** Get all settings owned by this app. */
    protected function allSettings(): array
    {
        return $this->settings()->getGroup($this->settingGroup());
    }

    /** Delete every setting owned by this app (used on uninstall). */
    protected function dropAllSettings(): void
    {
        // Runs on uninstall. Bypassing the service here meant a reinstalled app
        // could read its predecessor's settings out of the stale cache.
        $this->settings()->deleteGroups([
            $this->settingGroup(),
        ]);
        $this->cache()->flushTag('settings');
    }

    /** The settings group used for this app: "app:{slug}". */
    /**
     * Add a stylesheet to the front end.
     *
     * The theme does not need to know the app exists — it calls bh_head(), and
     * whatever has been registered arrives. That is the whole point: an
     * analytics or consent app should never ask anyone to edit a template.
     */
    protected function enqueueStyle(string $handle, string $url, int $priority = 10, array $attrs = []): void
    {
        if (function_exists('bh_enqueue_style')) {
            bh_enqueue_style($this->slug . '-' . $handle, $url, $priority, $attrs);
        }
    }

    /** Add a script to the front end. Before </body> unless $inHead. */
    protected function enqueueScript(string $handle, string $url, int $priority = 10, bool $inHead = false, array $attrs = []): void
    {
        if (function_exists('bh_enqueue_script')) {
            bh_enqueue_script($this->slug . '-' . $handle, $url, $priority, $inHead, $attrs);
        }
    }

    /**
     * Add markup to every front-end page.
     *
     *     $this->addHead(fn() => '<meta name="…" content="…">');
     *     $this->addFooter(fn() => '<script>…</script>');
     *
     * The callback may echo or return a string. A failure is logged and costs
     * this app's output only.
     */
    protected function addHead(callable $fn, int $priority = 10): void
    {
        // Registered as both, so a callback that echoes and one that returns
        // are equally correct. bh_head() collects the echo from the action and
        // the value from the filter, and a callback only ever does one.
        $this->addAction('bh.head', $fn, $priority, 0);
        $this->addFilter('bh.head', function ($carry) use ($fn) {
            $v = $fn();
            return $carry . (is_string($v) ? $v : '');
        }, $priority, 1);
    }

    protected function addFooter(callable $fn, int $priority = 10): void
    {
        $this->addAction('bh.footer', $fn, $priority, 0);
        $this->addFilter('bh.footer', function ($carry) use ($fn) {
            $v = $fn();
            return $carry . (is_string($v) ? $v : '');
        }, $priority, 1);
    }

    protected function settingGroup(): string
    {
        return 'app:' . $this->slug;
    }


    protected function settings(): \App\Services\SettingService
    {
        return $this->app->make(\App\Services\SettingService::class);
    }

    // ------------------------------------------------------------------
    // Database & cache shortcuts.
    // ------------------------------------------------------------------

    /**
     * The database, WITHOUT the db.raw check.
     *
     * For core's own use inside this base class only. An app's settings helpers
     * operate on storage the app already owns, so they must not require the
     * permission that governs arbitrary SQL — otherwise
     * declaring any permission at all would break deleteSetting() for every app
     * that didn't also ask for db.raw.
     */
    private function rawDb(): Database
    {
        return $this->app->make(Database::class);
    }

    /**
     * The PDO-wrapper Database instance.
     *
     * Gated behind 'db.raw', because raw SQL bypasses every other permission an
     * app holds — an app granted only posts.read could otherwise UPDATE the
     * users table. Apps that declare no permissions are unaffected.
     *
     * Denial throws rather than returning null: every caller expects a
     * Database and would fatal on a null anyway, so a clear exception naming
     * the missing permission is the more useful failure. The API resources
     * behave differently on purpose — they degrade rather than throw, since
     * they run on page-render paths.
     */
    protected function db(): Database
    {
        $this->requirePermission('db.raw', 'db()');
        return $this->app->make(Database::class);
    }

    /** Get the file cache. */
    protected function cache(): Cache
    {
        return $this->app->make(Cache::class);
    }

    /** Get the global logger; messages are auto-tagged with the app slug. */
    protected function logger(): Logger
    {
        return $this->app->make(Logger::class);
    }

    /** Log an info message tagged with the app slug. */
    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        // Per-app file first — this is what Admin > Apps > Logs reads.
        try {
            $this->app->make(\App\Services\AppLogger::class)
                 ->log($this->slug, $level, $message, $context);
        } catch (\Throwable) {
        }

        // Warnings and above are mirrored into the core log, so an operator
        // reading the main log still sees an app in trouble without having to
        // know a per-app file exists. Info and debug stay out of it — that is
        // the noise the split was meant to remove.
        if ($level === 'info' || $level === 'debug') return;

        try {
            $context['app'] = $this->slug;
            $this->logger()->log($level, "[app:{$this->slug}] {$message}", $context);
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    /**
     * True when this app may use $permission.
     *
     * Apps can call this to degrade gracefully rather than trip a denial:
     *
     *     if ($this->allowed('mail.send')) { … }
     */
    protected function allowed(string $permission): bool
    {
        try {
            return $this->app->make(\App\Services\PermissionBroker::class)
                        ->allows($this->slug, $permission);
        } catch (\Throwable) {
            // Broker unavailable (mid-migration): fail open, matching the
            // pre-1.36 behaviour of allowing everything.
            return true;
        }
    }

    /** Throw unless the permission is held. */
    private function requirePermission(string $permission, string $what): void
    {
        if ($this->allowed($permission)) return;

        $this->log(
            "Permission denied: '{$permission}' is required for {$what}.",
            ['permission' => $permission],
            'warning'
        );
        throw new \RuntimeException(sprintf(
            "App '%s' called %s without the '%s' permission. Declare it in the "
            . 'manifest and approve the app under Admin > Apps.',
            $this->slug, $what, $permission
        ));
    }

    // ------------------------------------------------------------------
    // Views & assets — render templates that ship with the app, and
    // generate URLs for app-bundled assets.
    // ------------------------------------------------------------------

    /**
     * Render a PHP template that lives inside the app folder.
     * Looks under `{app}/views/{name}.php` by default.
     */
    protected function view(string $template, array $data = []): string
    {
        $file = $this->path . '/views/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("App view not found: {$template} ({$file})");
        }

        // Make $app available in templates as a convenience handle.
        $data['app']    = $data['app'] ?? $this;
        $data['base']   = $data['base'] ?? (defined('BASEHIM_BASE') ? BASEHIM_BASE : '');

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /**
     * Render an app-owned template wrapped in the admin layout (sidebar,
     * topbar, flash bar — the full /admin chrome).
     *
     * The app template only needs to produce the page *body*; this method
     * supplies the title, the current user, and the flash message that the
     * layout expects.
     *
     * @param string $template  App template name, e.g. "admin" -> {app}/views/admin.php
     * @param string $title     Title shown in the topbar and browser tab.
     * @param array  $data      Variables exposed inside the template.
     */
    protected function adminView(string $template, string $title, array $data = []): string
    {
        // Render the app template to a string.
        $body = $this->view($template, $data);

        // Render the admin layout with our body pre-set as the 'content' section.
        $session = $this->session();
        $layoutData = [
            'title'       => $title,
            'currentUser' => $this->app->has('auth.user') ? $this->app->make('auth.user') : null,
            'flash'       => $data['flash'] ?? $session->getFlash('_flash'),
        ];

        return $this->make(View::class)->renderWithSections(
            'layouts.app',
            ['content' => $body],
            $layoutData
        );
    }

    /**
     * URL for an app-bundled asset (e.g. css, js, image).
     * Files in `{app}/assets/...` are served via the app asset route.
     *
     * Example: $this->asset('css/style.css') ->
     *   /content/apps/{slug}/assets/css/style.css
     */
    protected function asset(string $relativePath): string
    {
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        return $base . '/content/' . $this->containerDir() . '/' . $this->slug
             . '/assets/' . ltrim($relativePath, '/');
    }

    // ------------------------------------------------------------------
    // Container & config & request access.
    // ------------------------------------------------------------------

    /** Resolve any service from the container. */
    protected function make(string $abstract): mixed
    {
        return $this->app->make($abstract);
    }

    /** Read a config value (dot notation). */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make(Config::class)->get($key, $default);
    }

    /** Current HTTP request. */
    protected function request(): Request
    {
        return $this->app->make(Request::class);
    }

    /** Current session. */
    protected function session(): Session
    {
        return $this->app->make(Session::class);
    }

    // ------------------------------------------------------------------
    // Schema helpers — create an app-owned database table.
    // Idempotent: safe to call on every activation.
    // ------------------------------------------------------------------

    /**
     * Run an arbitrary CREATE TABLE / ALTER TABLE statement.
     * Use sparingly; prefer self-contained tables named via table(), which
     * applies the `app_{slug}_*` convention (e.g. `app_myapp_things`).
     */
    protected function schema(string $sql): void
    {
        $this->requirePermission('db.raw', 'schema()');
        $this->app->make(Database::class)->execute($sql);
    }

    // ------------------------------------------------------------------
    // Content types
    // ------------------------------------------------------------------

    /**
     * Register a custom content type, with full admin screens.
     *
     * Call from boot(). The type gets a sidebar entry and the standard list /
     * create / edit / trash screens at /admin/content/{slug}, and its rows are
     * reachable through $this->api()->content('slug').
     *
     *     $this->registerPostType('event', [
     *         'label'      => 'Events',
     *         'singular'   => 'Event',
     *         'icon'       => 'calendar',
     *         'supports'   => ['title', 'content', 'thumbnail'],
     *         'taxonomies' => ['category'],
     *     ]);
     *
     * Registration is per-request and nothing is persisted. Deactivating the app
     * removes the screens; the content stays in the database and comes back when
     * the app does.
     *
     * By default the type uses the `post` capability family — no role in an
     * existing install holds edit_events, so a type demanding its own
     * capabilities would be invisible to everyone. Override with
     * 'capability_type' only if you have created matching capabilities.
     *
     * @return bool False for `post` and `page`, which core owns.
     */
    protected function registerPostType(string $slug, array $args = []): bool
    {
        $args['app'] = $args['app'] ?? $this->slug;
        return $this->app->make(\App\Services\PostTypeRegistry::class)
                    ->register($slug, $args);
    }

    // ------------------------------------------------------------------
    // Core API — everything an app needs from core, in one place.
    // ------------------------------------------------------------------

    /**
     * The core services facade, scoped to this app.
     *
     *     $this->api()->posts()->create([...]);
     *     $this->api()->cache()->remember('k', 300, fn() => …);
     *     $this->api()->schedule()->hourly('sync', [$this, 'sync']);
     *
     * Everything reachable here is in-process: no HTTP round trip to your own
     * site, no container lookups, and one consistent CRUD shape across every
     * resource. See App\Core\Api\AppApi for the full surface.
     *
     * The instance is cached per app, so the resource objects (and their own
     * caches) survive across calls within a request.
     */
    protected function api(): \App\Core\Api\AppApi
    {
        return $this->api ??= new \App\Core\Api\AppApi($this->app, $this->slug);
    }

    /** Shorthand for $this->api()->schedule(). */
    protected function schedule(): \App\Core\Api\ScheduleApi
    {
        return $this->api()->schedule();
    }

    // ------------------------------------------------------------------
    // Table naming — app-owned tables use the `app_{slug}_*` convention.
    // ------------------------------------------------------------------

    /**
     * Build a fully-qualified table name owned by this app.
     *
     * Example: $this->table('sites') -> `app_myapp_sites`
     *
     * The slug's dashes become underscores so the result is always a legal
     * MySQL identifier.
     */
    protected function table(string $name): string
    {
        $slug = str_replace('-', '_', $this->slug);
        // Carries the site's DB_PREFIX, so an app's own tables sit in the same
        // namespace as core's. Without this, a prefixed install would end up
        // with `bh_posts` next to a bare `app_myapp_things`, and two Basehim
        // sites sharing one database would collide on the app tables even
        // though core's were kept apart.
        return $this->rawDb()->table('app_' . $slug . '_' . ltrim($name, '_'));
    }

    // ------------------------------------------------------------------
    // Identity — icon, permissions, and where the app lives on disk.
    // ------------------------------------------------------------------

    /**
     * The app's icon, taken from the manifest's "icon" key.
     *
     * Three forms are understood by the admin UI:
     *   "fa-rocket"            — a Font Awesome class
     *   "heroicon:puzzle-piece" — a core Icon.php glyph
     *   "assets/icon.svg"      — a file bundled inside the app
     *
     * Falls back to a generic app glyph so every app renders with something.
     */
    public function icon(): string
    {
        $icon = trim((string) ($this->manifest['icon'] ?? ''));
        return $icon !== '' ? $icon : 'heroicon:puzzle-piece';
    }

    /**
     * A resolved URL for the app's icon when it is a bundled file; otherwise
     * the raw icon string (the view decides how to render a class/glyph).
     */
    public function iconUrl(): string
    {
        $icon = $this->icon();
        if (preg_match('#\.(svg|png|jpe?g|webp|gif)$#i', $icon)) {
            return $this->asset(preg_replace('#^assets/#', '', $icon) ?? $icon);
        }
        return $icon;
    }

    /**
     * Permissions this app declares in its manifest.
     *
     * Declaring them has no enforcement effect in 1.34.0 — the permission
     * broker lands in 1.35.0 — but apps can (and should) declare them now so
     * the admin UI can show what an app intends to touch.
     *
     * @return array<int,string>
     */
    public function permissions(): array
    {
        $perms = $this->manifest['permissions'] ?? [];
        if (!is_array($perms)) return [];
        return array_values(array_filter(array_map(
            fn($p) => is_string($p) ? trim($p) : '',
            $perms
        ), fn($p) => $p !== ''));
    }

    /** The content directory apps live in. */
    protected function containerDir(): string
    {
        return 'apps';
    }

    // ------------------------------------------------------------------
    // Meta accessors.
    // ------------------------------------------------------------------

    public function slug(): string    { return $this->slug; }
    public function path(): string    { return $this->path; }
    public function manifest(): array { return $this->manifest; }

    private function guessSlug(): string
    {
        // Fallback: derive from class name (e.g. MyApp -> my-app)
        $short = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }
}
