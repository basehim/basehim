<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router
 *
 * Pattern-based router with middleware groups. Captures the spec's
 * route registration, middleware pipeline, and middleware groups.
 */
final class Router
{
    /** @var array<int, array> */
    private array $routes = [];

    /**
     * Routes tried only after every normal route has missed.
     *
     * Apps boot inside bootstrap.php, before index.php loads the route files,
     * so a route an app registers with get() lands ahead of all of core's. The
     * router returns the first match, which means an app catch-all such as
     * '/{path:*}' shadows the entire site — /admin included. That is not a
     * mistake an app author can avoid by being careful; registration order is
     * simply not theirs to control.
     *
     * fallback() puts a route here instead, and this list is consulted only
     * when nothing else matched. It is the right place for the thing apps
     * actually want: "handle URLs this site would otherwise 404 on."
     *
     * @var array<int, array>
     */
    private array $fallbacks = [];

    private array $groupStack = [];

    public function __construct(private Application $app) {}

    public function get(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    public function patch(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    public function any(string $pattern, callable|array|string $handler): self
    {
        return $this->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $pattern, $handler);
    }

    /**
     * Register a route that is only tried after every other route has missed.
     *
     * For apps that need to claim URLs the site does not otherwise serve —
     * imported redirects, a legacy permalink scheme, a custom 404. Registering
     * the same pattern with get() would shadow the whole site, because apps
     * boot before core's routes are loaded.
     *
     * Fallbacks are tried in registration order.
     */
    public function fallback(string $pattern, callable|array|string $handler, array $methods = ['GET', 'HEAD']): self
    {
        $this->fallbacks[] = [
            'methods'    => $methods,
            'pattern'    => '/' . ltrim($pattern, '/'),
            'handler'    => $handler,
            'middleware' => [],
            'owner'      => $this->owner,
        ];
        return $this;
    }

    /** The app whose boot() is running (AppService sets it), credited with the routes it adds. */
    private ?string $owner = null;

    /** Credit the routes added from now on to an app (null: core). Returns the previous owner. */
    public function setOwner(?string $slug): ?string
    {
        $prev = $this->owner;
        $this->owner = $slug;
        return $prev;
    }

    /**
     * Remove every route (and fallback) credited to an app, so an app whose
     * boot() failed part-way answers no URL (1.2.71, AppGuard). Returns how
     * many were removed.
     */
    public function forgetOwner(string $slug): int
    {
        $before = count($this->routes) + count($this->fallbacks);
        $this->routes = array_values(array_filter($this->routes, static fn($r) => ($r['owner'] ?? null) !== $slug));
        $this->fallbacks = array_values(array_filter($this->fallbacks, static fn($r) => ($r['owner'] ?? null) !== $slug));
        $this->reservedCache = null;
        $this->table = null;
        return $before - count($this->routes) - count($this->fallbacks);
    }

    /**
     * Every registered route, for documentation (the API reference screen):
     * [['methods' => [...], 'pattern' => '/api/v1/…', 'owner' => app slug|null,
     *   'fallback' => bool, 'protected' => bool], …]. 'protected' is true when
     * the route sits behind the Authenticate middleware.
     */
    public function routes(): array
    {
        $out = [];
        foreach ([[$this->routes, false], [$this->fallbacks, true]] as [$list, $fallback]) {
            foreach ($list as $r) {
                $mw = array_map('strval', (array) ($r['middleware'] ?? []));
                $out[] = [
                    'methods'   => array_values((array) $r['methods']),
                    'pattern'   => (string) $r['pattern'],
                    'owner'     => $r['owner'] ?? null,
                    'fallback'  => $fallback,
                    'protected' => (bool) array_filter($mw, static fn($m) => str_contains($m, 'Authenticate')),
                ];
            }
        }
        return $out;
    }

    /** @var string[]|null Cached reservedSegments(); cleared whenever a route is added. */
    private ?array $reservedCache = null;

    /** Whether the request being answered is in the admin or the AJAX gateway (set by dispatch). */
    private bool $privateArea = false;

    /** The route being dispatched, while its middleware and handler run. */
    private ?array $current = null;

    /**
     * The app that registered the route being dispatched (its slug), or null
     * for core's routes and outside a dispatch. AdminAreaPolicy uses it to
     * guard an app's admin routes that its menu URL does not cover.
     */
    public function currentOwner(): ?string
    {
        $owner = $this->current['owner'] ?? null;
        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * The literal first path segment of every registered route: "admin",
     * "search", "feed", and those of installed apps. A page or post whose slug
     * is one of these could never be reached at /{slug} — the route answers
     * first — so PostService never hands one out, and Helpers::postUrl() keeps
     * such a legacy page at /page/{slug}.
     *
     * @return string[]
     */
    public function reservedSegments(): array
    {
        if ($this->reservedCache !== null) return $this->reservedCache;
        $out = [];
        foreach ($this->routes as $r) {
            $seg = explode('/', trim((string) ($r['pattern'] ?? ''), '/'))[0] ?? '';
            if ($seg !== '' && $seg[0] !== '{') $out[strtolower($seg)] = true;
        }
        return $this->reservedCache = array_keys($out);
    }

    public function add(array $methods, string $pattern, callable|array|string $handler): self
    {
        // Apply group stack
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . trim($group['prefix'] ?? '', '/');
            $groupMw = array_merge($groupMw, $group['middleware'] ?? []);
        }
        $prefix = $prefix === '' ? '' : '/' . trim($prefix, '/');
        $fullPattern = rtrim($prefix, '/') . '/' . ltrim($pattern, '/');
        $fullPattern = '/' . trim($fullPattern, '/');
        if ($fullPattern !== '/' && str_ends_with($fullPattern, '/')) {
            $fullPattern = rtrim($fullPattern, '/');
        }

        // Which area the route serves (1.2.72). Core's own routes are 'core'.
        // An app's route is 'admin' when it sits under /admin behind the
        // admin middleware (adminGet/adminPost), else it is a public route
        // added with the original helpers ('legacy').
        $area = 'core';
        if ($this->owner !== null) {
            $area = (str_starts_with($fullPattern . '/', '/admin/') && self::hasAdminAuth($groupMw)) ? 'admin' : 'legacy';
            // The same app registering the same route again (boot code run
            // twice) is not a second route.
            if ($this->isRepeat($this->owner, $area, $methods, $fullPattern)) return $this;
        }

        $this->reservedCache = null;
        $this->table = null;
        $this->routes[] = [
            'methods'    => $methods,
            'pattern'    => $fullPattern,
            'handler'    => $handler,
            'middleware' => $groupMw,
            'owner'      => $this->owner,
            'area'       => $area,
            'options'    => [],
            'seq'        => ++$this->seq,
        ];
        return $this;
    }

    // ------------------------------------------------------------------
    // Public site routes for apps (1.2.72) — see docs/PUBLIC-ROUTES.md
    // ------------------------------------------------------------------

    /**
     * The registrar an app uses for its public pages and endpoints:
     *
     *     $site = $this->router()->site();     // in the app's boot()
     *     $site->get('/shop', [$this, 'index']);
     *     $site->get('/shop/{slug}', [$this, 'show'], ['where' => ['slug' => '[a-z0-9-]+']]);
     *     $site->post('/api/shop/cart', [$this, 'add'], ['rate' => [30, 60]]);
     *
     * Routes are credited to the app whose boot() is running. Outside an
     * app's boot nothing can be registered here.
     */
    public function site(): \App\Core\Routing\SiteRoutes
    {
        return new \App\Core\Routing\SiteRoutes($this, $this->owner);
    }

    /**
     * Store a route checked by SiteRoutes. Not for direct use.
     *
     * @internal
     */
    public function addSiteRoute(string $owner, array $methods, string $pattern, callable|array|string $handler, array $middleware, array $options): bool
    {
        if ($this->isRepeat($owner, 'site', $methods, $pattern)) return true;
        $this->reservedCache = null;
        $this->table = null;
        $this->routes[] = [
            'methods'    => $methods,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => $middleware,
            'owner'      => $owner,
            'area'       => 'site',
            'options'    => $options,
            'seq'        => ++$this->seq,
        ];
        return true;
    }

    /** Whether a route name is taken already (by any app). */
    public function hasName(string $name): bool
    {
        foreach ($this->routes as $r) {
            if (($r['options']['name'] ?? null) === $name) return true;
        }
        return false;
    }

    private function isRepeat(string $owner, string $area, array $methods, string $pattern): bool
    {
        foreach ($this->routes as $r) {
            if (($r['owner'] ?? null) === $owner && ($r['area'] ?? '') === $area
                && $r['pattern'] === $pattern && $r['methods'] === $methods) {
                return true;
            }
        }
        return false;
    }

    private static function hasAdminAuth(array $middleware): bool
    {
        foreach ($middleware as $mw) {
            if (is_string($mw) && str_contains($mw, 'Middleware\\Authenticate') && !str_contains($mw, ':api')) return true;
        }
        return false;
    }

    /** Monotonic registration counter, for a stable order. */
    private int $seq = 0;

    /** @var array{routes: array, rejected: array}|null the effective route table, built on first dispatch */
    private ?array $table = null;

    /**
     * Paths only core may serve. A public app route under one of these is
     * refused: /admin needs the admin middleware (adminGet/adminPost), the
     * rest belong to core's gateway, API, OAuth and file serving.
     */
    public const RESERVED_PREFIXES = ['/admin', '/ajax', '/api/v1', '/mcp', '/oauth', '/.well-known', '/uploads', '/content', '/storage', '/install.php'];

    /**
     * Core routes an app may take over, and only by asking for it with the
     * 'replace' option: the home page and search. Every other core route
     * always wins over an app's.
     */
    public const REPLACEABLE = ['GET /', 'GET /search'];

    /**
     * The routes in the order they are tried, and the app routes that were
     * refused. Precedence (deterministic, whatever order apps boot in):
     *
     *  1. app routes that replace a replaceable core route ('replace' => true);
     *  2. core's routes whose first segment is literal (/admin/…, /api/v1/…,
     *     /search, /feed, /posts/{slug} …);
     *  3. app routes, admin and public, most specific first: more literal
     *     segments, then fewer parameters, then a wildcard last, then
     *     registration order;
     *  4. core's content catch-alls (/{slug}, /{category}/{slug}), so an app's
     *     /shop is reached before a page lookup, as before.
     *
     * Refused, with the reason logged once and shown in the app's report:
     * a public route under a reserved prefix or under /admin, one whose first
     * segment is a parameter (it would swallow every page), one a core route
     * already answers (same method and path shape), and one another app
     * registered first.
     *
     * @return array{routes: array<int, array>, rejected: array<int, array>}
     */
    public function table(): array
    {
        if ($this->table !== null) return $this->table;

        $core = [];
        $coreCatch = [];
        $apps = [];
        foreach ($this->routes as $r) {
            if (($r['owner'] ?? null) === null) {
                $first = explode('/', trim($r['pattern'], '/'))[0] ?? '';
                if ($first !== '' && $first[0] === '{') $coreCatch[] = $r; else $core[] = $r;
            } else {
                $apps[] = $r;
            }
        }

        $accepted = [];
        $replacing = [];
        $rejected = [];
        $reject = static function (array $r, string $reason, string $detail) use (&$rejected): void {
            $rejected[] = [
                'owner' => $r['owner'], 'area' => $r['area'], 'methods' => array_values(array_diff($r['methods'], ['HEAD'])) ?: $r['methods'],
                'pattern' => $r['pattern'], 'reason' => $reason, 'detail' => $detail,
            ];
        };

        foreach ($apps as $r) {
            $pattern = $r['pattern'];
            $public = $r['area'] !== 'admin';
            $first = explode('/', trim($pattern, '/'))[0] ?? '';

            if ($first !== '' && $first[0] === '{') {
                $reject($r, 'catch-all', 'Its first segment is a parameter, so it would answer every page of the site. Start it with a fixed word (/shop/{slug}), or use fallback() for URLs nothing else serves.');
                continue;
            }
            if ($public && ($pattern === '/admin' || str_starts_with($pattern, '/admin/'))) {
                $reject($r, 'admin-path', 'A public route cannot live under /admin, where it would skip the admin sign-in and permission checks. Register it with adminGet()/adminPost().');
                continue;
            }
            if ($pattern === Request::AJAX_PATH) {
                $reject($r, 'reserved', 'The AJAX gateway is core\'s. Register AJAX actions with ajax().');
                continue;
            }
            if ($r['area'] === 'site') {
                foreach (self::RESERVED_PREFIXES as $prefix) {
                    if ($pattern === $prefix || str_starts_with($pattern, $prefix . '/')) {
                        $reject($r, 'reserved', "Paths under {$prefix} are reserved for core.");
                        continue 2;
                    }
                }
            }

            // A core route answering the same method and path shape (or, for
            // a fixed path, any core route that matches it).
            $hit = null;
            foreach ($core as $c) {
                if (!array_intersect(array_diff($r['methods'], ['HEAD']) ?: $r['methods'], $c['methods'])) continue;
                if (self::shape($c['pattern']) === self::shape($pattern)
                    || (!str_contains($pattern, '{') && $this->match($c['pattern'], $pattern) !== null)) {
                    $hit = $c;
                    break;
                }
            }
            if ($hit !== null) {
                $key = ($r['methods'][0] ?? 'GET') . ' ' . $hit['pattern'];
                if ($r['area'] === 'site' && !empty($r['options']['replace']) && in_array($key, self::REPLACEABLE, true) && !isset($replacing[$key])) {
                    $replacing[$key] = $r;
                    continue;
                }
                $reject($r, 'core-conflict', "Core already serves {$key}" . ($hit['pattern'] !== $pattern ? " (matches {$pattern})" : '') . '. Core routes always win' . (in_array($key, self::REPLACEABLE, true) ? "; to take this one over, register it with ['replace' => true]." : '.'));
                continue;
            }

            // An earlier app route with the same method and shape.
            foreach ($accepted as $a) {
                if (!array_intersect(array_diff($r['methods'], ['HEAD']) ?: $r['methods'], $a['methods'])) continue;
                if (self::shape($a['pattern']) === self::shape($pattern)) {
                    $by = $a['owner'] === $r['owner'] ? 'this app, earlier' : "the app '{$a['owner']}'";
                    $reject($r, 'duplicate', "{$a['pattern']} is already registered by {$by}; the first registration is kept.");
                    continue 2;
                }
            }
            $accepted[] = $r;
        }

        usort($accepted, static function (array $a, array $b): int {
            return self::specificity($b['pattern']) <=> self::specificity($a['pattern']) ?: $a['seq'] <=> $b['seq'];
        });

        return $this->table = [
            'routes' => array_merge(array_values($replacing), $core, $accepted, $coreCatch),
            'rejected' => $rejected,
        ];
    }

    /** App routes that were refused, with the reason. */
    public function rejected(?string $owner = null): array
    {
        $all = $this->table()['rejected'];
        return $owner === null ? $all : array_values(array_filter($all, static fn($r) => $r['owner'] === $owner));
    }

    /** "/shop/{slug}" and "/shop/{id}" have the same shape: "/shop/{}". */
    private static function shape(string $pattern): string
    {
        // A wildcard ({p:*}) or optional ({p?}) segment is a different shape.
        return (string) preg_replace_callback('/\{[a-zA-Z_]\w*(\?|:\*)?\}/', static fn($m) => '{' . ($m[1] ?? '') . '}', $pattern);
    }

    /** Sort key: literal segments first, then fewer parameters, wildcards last. */
    private static function specificity(string $pattern): array
    {
        $lit = 0; $params = 0; $wild = 0;
        foreach (explode('/', trim($pattern, '/')) as $seg) {
            if ($seg === '') continue;
            if ($seg[0] !== '{') { $lit++; continue; }
            if (str_ends_with($seg, ':*}')) $wild++; else $params++;
        }
        return [$lit, -$wild, -$params];
    }

    /**
     * The URL of a named route, with its parameters filled in and the install
     * base in front: $router->url('shop.item', ['slug' => 'blue-mug']).
     * Null when no accepted route has that name or a parameter is missing.
     */
    public function url(string $name, array $params = []): ?string
    {
        foreach ($this->table()['routes'] as $r) {
            if (($r['options']['name'] ?? null) !== $name) continue;
            $missing = false;
            $path = preg_replace_callback('/\{([a-zA-Z_]\w*)(\?|:\*)?\}/', function ($m) use ($params, &$missing) {
                $v = $params[$m[1]] ?? null;
                if ($v === null || $v === '') {
                    if (($m[2] ?? '') !== '?') $missing = true;
                    return '';
                }
                return ($m[2] ?? '') === ':*'
                    ? implode('/', array_map('rawurlencode', explode('/', (string) $v)))
                    : rawurlencode((string) $v);
            }, $r['pattern']);
            if ($missing) return null;
            $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
            return $base . '/' . ltrim(rtrim((string) $path, '/'), '/');
        }
        return null;
    }

    /** The route being dispatched (methods, pattern, owner, area, options), or null. */
    public function currentRoute(): ?array
    {
        if ($this->current === null) return null;
        $r = $this->current;
        unset($r['handler']);
        return $r;
    }

    private function logAppRouteFailure(array $route, Request $request, \Throwable $e): void
    {
        $root = defined('BASEHIM_ROOT') ? (string) BASEHIM_ROOT : '';
        $where = str_replace($root . '/', '', $e->getFile()) . ':' . $e->getLine();
        $msg = sprintf("Route %s %s failed: %s: %s (%s)", $request->method, $route['pattern'], get_class($e),
            str_replace($root . '/', '', $e->getMessage()), $where);
        try { $this->app->make(\App\Services\AppLogger::class)->log((string) $route['owner'], 'error', $msg, []); } catch (\Throwable) {}
    }

    /** Write each refused route to the core log and its app's log, once a day. */
    private function logRejected(): void
    {
        $rejected = $this->table()['rejected'];
        if (!$rejected) return;
        try {
            $cache = $this->app->make(Cache::class);
            foreach ($rejected as $r) {
                $key = 'routes.rejected.' . sha1(json_encode($r));
                if ($cache->has($key)) continue;
                $cache->set($key, 1, 86400);
                $msg = sprintf("App '%s': route %s %s not registered (%s). %s", (string) $r['owner'], implode('|', $r['methods']), $r['pattern'], $r['reason'], $r['detail']);
                try { $this->app->make(Logger::class)->warning($msg, ['app' => $r['owner'], 'reason' => $r['reason']]); } catch (\Throwable) {}
                try { $this->app->make(\App\Services\AppLogger::class)->log((string) $r['owner'], 'warning', $msg, []); } catch (\Throwable) {}
            }
        } catch (\Throwable) {}
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    public function dispatch(): void
    {
        $request = Request::capture();
        $this->app->instance(Request::class, $request);

        $method = $request->method;
        $path = $request->path();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Strip the install base prefix (set in index.php) so routes registered
        // as '/admin/login' work whether the install is at '/' or '/basehim/'.
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
            if ($path === '' || $path === false) {
                $path = '/';
            }
        }

        // The admin and the AJAX gateway are never for search engines (1.2.50).
        $this->privateArea = $path === '/admin' || str_starts_with($path, '/admin/') || $path === Request::AJAX_PATH;

        // A path with "." or ".." segments or a NUL byte, encoded or not, is
        // never a page: refuse it before any handler sees it (1.2.72).
        if (self::malformedPath($path)) {
            $this->emit($this->errorResponse($request, 400, 'Bad Request', 'The address is not valid.'));
            return;
        }

        $table = $this->table()['routes'];
        $this->logRejected();

        foreach ($table as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $params = $this->match($route['pattern'], $path, $route['options']['where'] ?? []);
            if ($params === null) {
                continue;
            }

            // Parameters are decoded and checked: a parameter may not smuggle
            // in a slash or a NUL byte. Public app routes since 1.2.72; core's
            // routes too since 1.2.76, so a Unicode slug (/اردو-خبریں, sent as
            // %D8%A7…) reaches its handler as the slug that is stored.
            $decoded = self::decodeParams($route['pattern'], $params);
            if ($decoded === null) {
                $this->emit($this->errorResponse($request, 400, 'Bad Request', 'A part of the address is not valid.'));
                return;
            }
            $params = $decoded;

            $this->current = $route;
            try {
                $response = $this->runMiddleware($route['middleware'], $request, function ($req) use ($route, $params) {
                    return $this->callHandler($route['handler'], $req, $params);
                });
            } catch (\Throwable $e) {
                // An app's route failed: say which app, route and kind of
                // failure in its own log, then let the error page answer
                // (core's ErrorHandler: no details for visitors).
                if (($route['owner'] ?? null) !== null) $this->logAppRouteFailure($route, $request, $e);
                throw $e;
            }

            /*
             * A matched route that answers 404 is still a miss as far as the
             * visitor is concerned, and core's '/{slug}' catch-all matches
             * every single-segment path — so without this, `route.miss` would
             * never fire for the ordinary case of an old permalink. Only a 404
             * is offered around; any other status means the route handled it.
             */
            // The AJAX gateway's 404 is a JSON answer ("no such action"), not a
            // missing page: a redirect or custom-404 app must not replace it.
            if ($response->getStatus() === 404 && $path !== Request::AJAX_PATH) {
                $handled = $this->offerMiss($request, $path);
                if ($handled instanceof Response) {
                    $this->emit($handled);
                    return;
                }
            }

            $this->emit($response);
            return;
        }

        // Nothing matched. Give fallback routes their turn before 404ing —
        // these were registered by apps that want the URLs this site does not
        // otherwise serve.
        foreach ($this->fallbacks as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            $this->current = $route;
            try {
                $response = $this->runMiddleware($route['middleware'], $request, function ($req) use ($route, $params) {
                    return $this->callHandler($route['handler'], $req, $params);
                });
            } catch (\Throwable $e) {
                // A broken fallback must not turn a missing page into a 500.
                try {
                    $this->app->make(Logger::class)->error('Fallback route failed: ' . $e->getMessage());
                } catch (\Throwable) {}
                continue;
            }
            // A fallback may decline by returning null, letting the next one —
            // or the 404 — have the request.
            if ($response !== null) {
                $this->emit($this->normalizeResponse($response));
                return;
            }
        }

        /*
         * Last chance before the 404: let listeners handle the miss.
         *
         * A listener returns a Response to take the request, or null to pass.
         * This is what an app needs to redirect an old URL without registering
         * a route, and it runs here — after every route has had its say — so
         * it cannot shadow a page the site can genuinely serve.
         */
        $handled = $this->offerMiss($request, $path);
        if ($handled instanceof Response) {
            $this->emit($handled);
            return;
        }

        // The path exists, but not for this method: 405 with the methods it
        // does take. Core's content catch-alls are left out, or every POST to
        // an unknown one-segment path would claim to be a 405 (1.2.72).
        $allowed = $this->allowedMethods($table, $path);
        if ($allowed) {
            $r = $this->errorResponse($request, 405, 'Method Not Allowed', "This address accepts " . implode(', ', $allowed) . '.');
            $r->header('Allow', implode(', ', $allowed));
            $this->emit($r);
            return;
        }

        // 404 fallback
        $response = $this->notFound($request);
        $this->emit($response);
    }

    /**
     * Last chance before a 404 is shown: let listeners claim the request.
     *
     * A listener returns a Response to take it, or null to pass. Called both
     * when no route matched and when a matched route answered 404, because
     * core's '/{slug}' catch-all means the second case is the common one.
     */
    private function offerMiss(Request $request, string $path): ?Response
    {
        try {
            $hooks = $this->app->make(HookRegistry::class);
            $handled = $hooks->applyFilters('route.miss', null, $request, $path);
            return $handled instanceof Response ? $handled : null;
        } catch (\Throwable $e) {
            try {
                $this->app->make(Logger::class)->error('route.miss listener failed: ' . $e->getMessage());
            } catch (\Throwable) {}
        }
        return null;
    }

    /** @return string[] the methods other routes accept for this path (none: it is unknown) */
    private function allowedMethods(array $table, string $path): array
    {
        $out = [];
        foreach ($table as $r) {
            if (($r['owner'] ?? null) === null) {
                $first = explode('/', trim($r['pattern'], '/'))[0] ?? '';
                if ($first !== '' && $first[0] === '{') continue;
            }
            if ($this->match($r['pattern'], $path, $r['options']['where'] ?? []) !== null) {
                foreach ($r['methods'] as $m) $out[$m] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    private static function malformedPath(string $path): bool
    {
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0") || str_contains($path, "\0")) return true;
        foreach (explode('/', $decoded) as $seg) {
            if ($seg === '.' || $seg === '..') return true;
        }
        return false;
    }

    /** @return array<string,string>|null decoded parameters, or null when one is not allowed */
    private static function decodeParams(string $pattern, array $params): ?array
    {
        $wild = [];
        if (preg_match_all('/\{([a-zA-Z_]\w*):\*\}/', $pattern, $m)) $wild = array_flip($m[1]);
        foreach ($params as $k => $v) {
            $d = rawurldecode((string) $v);
            if (str_contains($d, "\0") || (!isset($wild[$k]) && str_contains($d, '/'))) return null;
            if (!mb_check_encoding($d, 'UTF-8')) return null;
            $params[$k] = $d;
        }
        return $params;
    }

    /** A plain error answer, JSON for API-style requests, with no internals. */
    private function errorResponse(Request $request, int $status, string $title, string $detail): Response
    {
        if (str_starts_with($request->path(), '/api/') || $request->wantsJson()) {
            return Response::json(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $status);
        }
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $status . ' ' . htmlspecialchars($title) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#f8fafc;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}main{text-align:center;padding:2rem}p{color:#475569}</style></head>'
            . '<body><main><h1>' . $status . ' ' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($detail) . '</p></main></body></html>';
        return Response::html($html, $status);
    }

    private function match(string $pattern, string $path, array $where = []): ?array
    {
        // Convert {param} to a named group.
        // {param}    - matches one path segment ([^/]+)
        // {param:*}  - matches anything including slashes (.+) — for catch-all paths like uploads
        // {param?}   - optional segment
        $regex = preg_replace_callback('/\{([a-zA-Z_]\w*)(\?|:\*)?\}/', function ($m) use ($where) {
            $name = $m[1];
            $mod = $m[2] ?? '';
            // A 'where' constraint (validated by SiteRoutes) narrows one segment.
            $seg = isset($where[$name]) ? '(?:' . $where[$name] . ')' : '[^/]+';
            if ($mod === '?') {
                return '(?:/(?P<' . $name . '>' . $seg . '))?';
            }
            if ($mod === ':*') {
                return '(?P<' . $name . '>.+)';
            }
            return '(?P<' . $name . '>' . $seg . ')';
        }, $pattern);

        // Handle optional segments cleanly
        $regex = '#^' . str_replace('/(?:', '(?:', $regex) . '$#';

        if (preg_match($regex, $path, $matches)) {
            $params = [];
            foreach ($matches as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            return $params;
        }

        return null;
    }

    private function callHandler(callable|array|string $handler, Request $request, array $params): Response
    {
        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = $handler;
            // [$object, 'method']    -> already-bound callable (e.g. from apps).
            // [$className, 'method'] -> resolve the controller via the container.
            $controller = is_object($target) ? $target : $this->app->make($target);
            $result = $controller->{$method}($request, ...array_values($params));
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            $controller = $this->app->make($class);
            $result = $controller->{$method}($request, ...array_values($params));
        } elseif (is_callable($handler)) {
            $result = $handler($request, ...array_values($params));
        } else {
            throw new \RuntimeException('Invalid route handler');
        }

        return $this->normalizeResponse($result);
    }

    private function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) return $result;
        if (is_string($result)) return new Response($result);
        if (is_array($result) || is_object($result)) return Response::json($result);
        if ($result === null) return new Response('', 204);
        return new Response((string) $result);
    }

    private function runMiddleware(array $middleware, Request $request, \Closure $core): Response
    {
        $stack = array_reverse($middleware);
        $next = $core;
        foreach ($stack as $mw) {
            if (is_string($mw)) {
                // Support "ClassName:arg1,arg2" syntax
                if (str_contains($mw, ':')) {
                    [$cls, $argString] = explode(':', $mw, 2);
                    $args = explode(',', $argString);
                    $mwInstance = new $cls(...$args);
                } else {
                    $mwInstance = $this->app->make($mw);
                }
            } else {
                $mwInstance = $mw;
            }
            $currentNext = $next;
            $next = function (Request $req) use ($mwInstance, $currentNext) {
                return $this->normalizeResponse($mwInstance->handle($req, $currentNext));
            };
        }
        return $this->normalizeResponse($next($request));
    }

    private function emit(Response $response): void
    {
        /*
         * X-Robots-Tag on every admin and AJAX response — pages, JSON,
         * downloads and redirects alike. The meta tag in the admin layouts
         * only covers HTML, and robots.txt's "Disallow: /admin/" only asks
         * crawlers not to fetch; neither keeps an address that was linked
         * from somewhere out of an index the way this header does.
         */
        if ($this->privateArea && !isset($response->getHeaders()['X-Robots-Tag'])) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }
        $response->send();
    }

    private function notFound(Request $request): Response
    {
        if (str_starts_with($request->path(), '/api/') || $request->wantsJson()) {
            return Response::json([
                'type'   => 'https://basehim.io/errors/not-found',
                'title'  => 'Not Found',
                'status' => 404,
                'detail' => "No route matches {$request->method} {$request->path()}",
            ], 404);
        }
        return Response::view('errors.404', ['path' => $request->path()], 404);
    }
}