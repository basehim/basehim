<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Application;
use App\Core\Logger;
use App\Core\Router;

/**
 * SiteRoutes — how an app registers public pages and endpoints (1.2.72).
 *
 *     public function boot(): void
 *     {
 *         $site = $this->router()->site();
 *         $site->get('/shop', [$this, 'index'], ['name' => 'shop.index']);
 *         $site->get('/shop/{slug}', [$this, 'show'], ['where' => ['slug' => '[a-z0-9-]+']]);
 *         $site->post('/api/shop/cart', [$this, 'addToCart'], ['rate' => [30, 60]]);
 *         $site->delete('/api/shop/cart/{id}', [$this, 'remove'], ['auth' => true, 'where' => ['id' => '\d+']]);
 *         $site->group(['prefix' => '/account', 'auth' => true], function (SiteRoutes $r) {
 *             $r->get('', [$this, 'account']);
 *             $r->get('/orders', [$this, 'orders']);
 *         });
 *     }
 *
 * The routes go into core's router; nothing here dispatches. What this class
 * adds over Router::get() is the checking: a bad path, handler or option is
 * refused here with a log line naming the app, rather than failing later at
 * request time; reserved paths, core routes and other apps' routes are
 * protected by Router::table().
 *
 * Every method returns true when the route was registered, false when it was
 * refused (the reason is in the core log and the app's log).
 *
 * Options (all optional):
 *   name       string   for Router::url(); unique across the site
 *   where      array    {param: regex} for one segment, e.g. ['id' => '\d+']
 *   auth       bool     a signed-in account is required (401 / sign-in page)
 *   can        string   capabilities required, comma separated (implies auth; 403)
 *   csrf       bool     POST/PUT/PATCH/DELETE: true by default — a request
 *                       carrying the site's session cookie must send the
 *                       session's CSRF token (X-CSRF-Token or _csrf). false
 *                       only for endpoints called by other servers (webhooks);
 *                       not allowed together with auth/can.
 *   rate       [n, s]   at most n requests per s seconds per visitor (429)
 *   middleware string[] extra middleware classes, run after the checks above
 *   replace    bool     take over a replaceable core route (GET / or GET /search)
 *   idempotent bool     POST/PUT/PATCH/DELETE need an Idempotency-Key header (400
 *                       without); a repeat with the same key gets the first
 *                       answer back instead of running again (1.2.75)
 */
final class SiteRoutes
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const OPTIONS = ['name', 'where', 'auth', 'can', 'csrf', 'rate', 'middleware', 'replace', 'idempotent'];
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<int, array{prefix:string, options:array}> */
    private array $groups = [];

    public function __construct(private Router $router, private ?string $owner) {}

    public function get(string $path, callable|array|string $handler, array $options = []): bool
    {
        return $this->match(['GET'], $path, $handler, $options);
    }

    public function post(string $path, callable|array|string $handler, array $options = []): bool
    {
        return $this->match(['POST'], $path, $handler, $options);
    }

    public function put(string $path, callable|array|string $handler, array $options = []): bool
    {
        return $this->match(['PUT'], $path, $handler, $options);
    }

    public function patch(string $path, callable|array|string $handler, array $options = []): bool
    {
        return $this->match(['PATCH'], $path, $handler, $options);
    }

    public function delete(string $path, callable|array|string $handler, array $options = []): bool
    {
        return $this->match(['DELETE'], $path, $handler, $options);
    }

    /**
     * Routes sharing a path prefix and options. Options of the group apply to
     * every route in it; a route's own options are added (middleware lists
     * are joined).
     */
    public function group(array $attributes, callable $define): void
    {
        $prefix = (string) ($attributes['prefix'] ?? '');
        unset($attributes['prefix']);
        $this->groups[] = ['prefix' => $prefix, 'options' => $attributes];
        try {
            $define($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /**
     * Register a route for several methods. GET routes also answer HEAD.
     *
     * @param string[] $methods
     */
    public function match(array $methods, string $path, callable|array|string $handler, array $options = []): bool
    {
        // Group prefixes and options.
        $prefix = '';
        $merged = [];
        foreach ($this->groups as $g) {
            $prefix .= '/' . trim($g['prefix'], '/');
            $mw = array_merge((array) ($merged['middleware'] ?? []), (array) ($g['options']['middleware'] ?? []));
            $merged = array_merge($merged, $g['options']);
            if ($mw) $merged['middleware'] = $mw;
        }
        if (isset($options['middleware'])) $options['middleware'] = array_merge((array) ($merged['middleware'] ?? []), (array) $options['middleware']);
        $options = array_merge($merged, $options);
        $path = '/' . trim(trim($prefix, '/') . '/' . trim($path, '/'), '/');

        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $label = implode('|', $methods) . ' ' . $path;

        if ($this->owner === null || $this->owner === '') {
            return $this->refuse($label, 'Public routes can only be registered from an app\'s boot().');
        }
        foreach ($methods as $m) {
            if (!in_array($m, self::METHODS, true)) return $this->refuse($label, "Method {$m} is not supported; use " . implode(', ', self::METHODS) . '.');
        }
        if (!$methods) return $this->refuse($label, 'No method given.');

        $problem = self::pathProblem($path);
        if ($problem !== null) return $this->refuse($label, $problem);

        $problem = $this->handlerProblem($handler);
        if ($problem !== null) return $this->refuse($label, $problem);

        [$options, $problem] = $this->normaliseOptions($options, $path, $methods);
        if ($problem !== null) return $this->refuse($label, $problem);

        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) $methods[] = 'HEAD';

        $middleware = array_merge(
            [new \App\Http\Middleware\SiteRouteGuard($this->owner, $options)],
            $options['middleware'] ?? []
        );
        return $this->router->addSiteRoute($this->owner, $methods, $path, $handler, $middleware, $options);
    }

    // ------------------------------------------------------------------

    /** Why a path cannot be a route, or null. */
    public static function pathProblem(string $path): ?string
    {
        if (strlen($path) > 300) return 'The path is longer than 300 characters.';
        if (str_contains($path, '\\') || str_contains($path, '//') || str_contains($path, "\0")) return 'The path contains "\\", "//" or a NUL byte.';
        $names = [];
        $segs = explode('/', trim($path, '/'));
        foreach ($segs as $i => $seg) {
            if ($seg === '') continue;
            if ($seg === '.' || $seg === '..') return 'The path contains a "." or ".." segment.';
            if ($seg[0] === '{') {
                if (!preg_match('/^\{([a-zA-Z_]\w{0,40})(\?|:\*)?\}$/', $seg, $m)) {
                    return "\"{$seg}\" is not a valid parameter; use {name}, {name?} or, last, {name:*}.";
                }
                if (isset($names[$m[1]])) return "The parameter {{$m[1]}} appears twice.";
                $names[$m[1]] = true;
                if (($m[2] ?? '') === ':*' && $i !== count($segs) - 1) return "The wildcard {{$m[1]}:*} must be the last segment.";
                if (($m[2] ?? '') === '?' && $i !== count($segs) - 1) return "The optional parameter {{$m[1]}?} must be the last segment.";
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._~!$&\'()*+,;=:@%-]+$/', $seg)) return "The segment \"{$seg}\" contains characters a URL path cannot.";
        }
        return null;
    }

    private function handlerProblem(callable|array|string $handler): ?string
    {
        if ($handler instanceof \Closure) return null;
        if (is_array($handler)) {
            if (count($handler) !== 2 || !is_string($handler[1] ?? null)) return 'A handler array must be [object or class, "method"].';
            [$target, $method] = $handler;
            if (is_object($target)) {
                return method_exists($target, $method) && (new \ReflectionMethod($target, $method))->isPublic()
                    ? null : 'The handler ' . get_class($target) . "::{$method}() does not exist or is not public.";
            }
            if (!is_string($target) || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $target)) {
                return 'The handler class name is not valid.';
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) return 'The handler method name is not valid.';
            // The controller class is checked when the route is used, so an
            // app's controllers are not loaded on every request.
            return null;
        }
        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                [$cls, $m] = explode('@', $handler, 2);
                return $this->handlerProblem([$cls, $m]);
            }
            return function_exists($handler) ? null : "The function {$handler}() does not exist.";
        }
        return is_callable($handler) ? null : 'The handler is not callable.';
    }

    /** @return array{0: array, 1: ?string} */
    private function normaliseOptions(array $o, string $path, array $methods): array
    {
        foreach (array_keys($o) as $k) {
            if (!in_array($k, self::OPTIONS, true)) return [$o, "Unknown option '{$k}'. Known: " . implode(', ', self::OPTIONS) . '.'];
        }
        $out = [];

        if (isset($o['name'])) {
            if (!is_string($o['name']) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/i', $o['name'])) return [$o, "'name' must be letters, digits, '.', '_' or '-'."];
            if ($this->router->hasName($o['name'])) return [$o, "The route name '{$o['name']}' is already used."];
            $out['name'] = $o['name'];
        }

        if (isset($o['where'])) {
            if (!is_array($o['where'])) return [$o, "'where' must be an array of {parameter: pattern}."];
            preg_match_all('/\{([a-zA-Z_]\w*)/', $path, $m);
            foreach ($o['where'] as $param => $re) {
                if (!in_array($param, $m[1], true)) return [$o, "'where' names {{$param}}, which is not in the path."];
                if (!is_string($re) || $re === '' || strlen($re) > 200 || str_contains($re, '#') || str_contains($re, '/') || preg_match('/\(\?(?!:)/', $re)) {
                    return [$o, "The pattern for {{$param}} is not allowed (no '/', '#', or named/look-around groups)."];
                }
                if (@preg_match('#^(?:' . $re . ')$#', '') === false) return [$o, "The pattern for {{$param}} is not a valid regular expression."];
            }
            $out['where'] = $o['where'];
        }

        if (isset($o['can'])) {
            if (!is_string($o['can']) || !preg_match('/^[a-z0-9_:.-]+(\s*,\s*[a-z0-9_:.-]+)*$/i', $o['can'])) return [$o, "'can' must be capability names, comma separated."];
            $out['can'] = $o['can'];
            $out['auth'] = true;
        }
        if (array_key_exists('auth', $o)) $out['auth'] = (bool) $o['auth'] || !empty($out['auth']);

        $unsafe = (bool) array_intersect($methods, self::UNSAFE);
        $out['csrf'] = $unsafe ? (!array_key_exists('csrf', $o) || (bool) $o['csrf']) : false;
        if ($unsafe && !$out['csrf'] && !empty($out['auth'])) {
            return [$o, "'csrf' => false cannot be combined with 'auth' or 'can': a signed-in browser session would be open to cross-site requests."];
        }

        if (isset($o['rate'])) {
            $r = $o['rate'];
            if (is_string($r) && preg_match('#^(\d+)\s*/\s*(\d+)$#', $r, $mm)) $r = [(int) $mm[1], (int) $mm[2]];
            if (!is_array($r) || count($r) !== 2 || (int) $r[0] < 1 || (int) $r[1] < 1 || (int) $r[1] > 86400) {
                return [$o, "'rate' must be [requests, seconds], e.g. [30, 60]."];
            }
            $out['rate'] = [(int) $r[0], (int) $r[1]];
        }

        if (isset($o['middleware'])) {
            foreach ((array) $o['middleware'] as $mw) {
                $cls = is_string($mw) ? explode(':', $mw, 2)[0] : null;
                if (is_object($mw) && method_exists($mw, 'handle')) continue;
                if ($cls === null || !class_exists($cls) || !method_exists($cls, 'handle')) {
                    return [$o, 'The middleware ' . (is_string($mw) ? $mw : gettype($mw)) . ' does not exist or has no handle() method.'];
                }
                if (str_ends_with(ltrim($cls, '\\'), 'Middleware\\AdminAreaPolicy')) {
                    return [$o, 'AdminAreaPolicy is for /admin routes; use \'auth\' and \'can\' on a public route.'];
                }
            }
            $out['middleware'] = array_values((array) $o['middleware']);
        }

        if (!empty($o['replace'])) $out['replace'] = true;
        if (array_key_exists('idempotent', $o)) {
            if (!is_bool($o['idempotent'])) return [$o, "'idempotent' must be true or false."];
            if ($o['idempotent'] && !$unsafe) return [$o, "'idempotent' applies to POST, PUT, PATCH and DELETE routes."];
            if ($o['idempotent']) $out['idempotent'] = true;
        }
        return [$out, null];
    }

    private function refuse(string $label, string $why): bool
    {
        $msg = "App '" . ($this->owner ?? '?') . "': public route {$label} not registered: {$why}";
        // Boot runs on every request: log each refusal once a day, not each time.
        $fresh = true;
        try {
            $cache = Application::getInstance()->make(\App\Core\Cache::class);
            $key = 'routes.refused.' . sha1($msg);
            $fresh = !$cache->has($key);
            if ($fresh) $cache->set($key, 1, 86400);
        } catch (\Throwable) {}
        if ($fresh) {
            try { Application::getInstance()->make(Logger::class)->warning($msg, ['app' => $this->owner]); } catch (\Throwable) {}
            if ($this->owner) {
                try { Application::getInstance()->make(\App\Services\AppLogger::class)->log($this->owner, 'warning', $msg, []); } catch (\Throwable) {}
            }
        }
        self::$refused[] = ['owner' => $this->owner, 'route' => $label, 'reason' => $why];
        return false;
    }

    /** @var array<int, array{owner:?string, route:string, reason:string}> refused in this request */
    private static array $refused = [];

    /** Routes refused at registration in this request (for the app's report). */
    public static function refused(?string $owner = null): array
    {
        return $owner === null ? self::$refused : array_values(array_filter(self::$refused, static fn($r) => $r['owner'] === $owner));
    }
}
