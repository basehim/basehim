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
        ];
        return $this;
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

        $this->routes[] = [
            'methods'    => $methods,
            'pattern'    => $fullPattern,
            'handler'    => $handler,
            'middleware' => $groupMw,
        ];
        return $this;
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

        foreach ($this->routes as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            $response = $this->runMiddleware($route['middleware'], $request, function ($req) use ($route, $params) {
                return $this->callHandler($route['handler'], $req, $params);
            });

            /*
             * A matched route that answers 404 is still a miss as far as the
             * visitor is concerned, and core's '/{slug}' catch-all matches
             * every single-segment path — so without this, `route.miss` would
             * never fire for the ordinary case of an old permalink. Only a 404
             * is offered around; any other status means the route handled it.
             */
            if ($response->getStatus() === 404) {
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

    private function match(string $pattern, string $path): ?array
    {
        // Convert {param} to a named group.
        // {param}    - matches one path segment ([^/]+)
        // {param:*}  - matches anything including slashes (.+) — for catch-all paths like uploads
        // {param?}   - optional segment
        $regex = preg_replace_callback('/\{([a-zA-Z_]\w*)(\?|:\*)?\}/', function ($m) {
            $name = $m[1];
            $mod = $m[2] ?? '';
            if ($mod === '?') {
                return '(?:/(?P<' . $name . '>[^/]+))?';
            }
            if ($mod === ':*') {
                return '(?P<' . $name . '>.+)';
            }
            return '(?P<' . $name . '>[^/]+)';
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