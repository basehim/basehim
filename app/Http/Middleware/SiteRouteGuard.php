<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * SiteRouteGuard — the checks in front of every public app route (1.2.72).
 *
 * Built by SiteRoutes from the route's options; runs before any middleware
 * the app added and before its handler. In order:
 *
 *  1. rate      — RateLimiter, per visitor (signed-in account, else IP): 429.
 *  2. JSON      — a body sent as application/json must parse: 400.
 *  3. csrf      — on POST/PUT/PATCH/DELETE, a request that carries this site's
 *                 session cookie must carry the session's CSRF token too
 *                 (X-CSRF-Token header or _csrf field): 403. A request with
 *                 no session cookie has no session to abuse, so a server or a
 *                 cookie-less script may call a public endpoint.
 *  4. auth/can  — the signed-in account (session), as auth.user for the
 *                 handler: 401 (JSON) or the sign-in page (HTML); 403 when a
 *                 capability is missing.
 *
 * After the handler: X-Content-Type-Options: nosniff, and a Referrer-Policy
 * unless the app set its own. Nothing here weakens a core check: the admin
 * area, the AJAX gateway and the REST API keep their own middleware.
 */
final class SiteRouteGuard
{
    public function __construct(private string $owner, private array $options) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $app = Application::getInstance();
        $unsafe = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        // 1. Rate limit
        if (!empty($this->options['rate'])) {
            [$max, $per] = $this->options['rate'];
            try {
                $limiter = $app->make(\App\Services\RateLimiter::class);
                $route = $app->make(\App\Core\Router::class)->currentRoute();
                $bucket = 'site:' . $this->owner . ':' . implode('|', (array) ($route['methods'] ?? [])) . ' ' . ($route['pattern'] ?? $request->path());
                $hit = $limiter->hit($bucket, 'ip:' . $request->ip(), (int) $max, (int) $per);
                if (!$hit['allowed']) {
                    $r = $this->error($request, 429, 'Too Many Requests', 'Too many requests. Please wait a moment and try again.');
                    $r->header('Retry-After', (string) max(1, (int) $hit['retry_after']));
                    return $r;
                }
            } catch (\Throwable) {
                // The limiter's store is unreachable: carry on (as the AJAX gateway does by default).
            }
        }

        // 2. A JSON body that does not parse
        if ($unsafe && str_contains(strtolower((string) ($request->header('content-type') ?? '')), 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            if (trim($raw) !== '') {
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error($request, 400, 'Bad Request', 'The request body is not valid JSON.');
                }
            }
        }

        // 3. CSRF for browser sessions
        if ($unsafe && !empty($this->options['csrf']) && $this->hasSessionCookie($request)) {
            $token = (string) ($request->header('x-csrf-token') ?? '');
            if ($token === '') {
                $t = $request->input('_csrf', '');
                $token = is_string($t) ? $t : '';
            }
            $ok = false;
            try { $ok = $app->make(Session::class)->verifyCsrf($token); } catch (\Throwable) {}
            if (!$ok) {
                return $this->error($request, 403, 'Forbidden', 'The security token is missing or out of date. Reload the page and try again.');
            }
        }

        // 4. Signed-in account and capabilities
        if (!empty($this->options['auth'])) {
            $user = null;
            if ($this->hasSessionCookie($request)) {
                try { $user = $app->make(\App\Services\AuthService::class)->sessionUser(); } catch (\Throwable) {}
            }
            if (!$user || ($user['status'] ?? '') !== 'active') {
                if ($this->wantsJson($request) || $unsafe) {
                    return $this->error($request, 401, 'Unauthorized', 'Sign in to continue.');
                }
                $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
                return Response::redirect($base . '/admin/login');
            }
            $app->instance('auth.user', $user);
            if (!empty($this->options['can'])) {
                foreach (array_filter(array_map('trim', explode(',', (string) $this->options['can']))) as $cap) {
                    if (!CheckCapability::userCan($user, $cap)) {
                        return $this->error($request, 403, 'Forbidden', 'Your account is not allowed to do this.');
                    }
                }
            }
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $h = array_change_key_case($response->getHeaders(), CASE_LOWER);
            if (!isset($h['x-content-type-options'])) $response->header('X-Content-Type-Options', 'nosniff');
            if (!isset($h['referrer-policy'])) $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        return $response;
    }

    private function hasSessionCookie(Request $request): bool
    {
        $name = session_name() ?: 'PHPSESSID';
        return isset($request->cookies[$name]) && $request->cookies[$name] !== '';
    }

    private function wantsJson(Request $request): bool
    {
        return $request->wantsJson() || $request->isJson() || str_starts_with($request->path(), '/api/');
    }

    private function error(Request $request, int $status, string $title, string $detail): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $status);
        }
        return \App\Core\Http\SitePage::error($status, $title, $detail);
    }
}
