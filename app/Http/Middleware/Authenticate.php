<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Config;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\ApiKeyService;
use Closure;

/**
 * Authenticate
 *
 * Resolves the current user from either:
 *   - Authorization: Bearer <jwt>      (API clients)
 *   - PHP session ($_SESSION['uid'])   (admin SPA / browser)
 *
 * Captures the spec's "dual-mode auth" without forcing one over the other.
 */
final class Authenticate
{
    public function __construct(private string $guard = 'web') {}

    public function handle(Request $request, Closure $next): mixed
    {
        $app = Application::getInstance();
        $user = null;
        $via = null;            // 'key' | 'jwt' | 'cookie'
        $guard = $this->guard;

        // Bearer credentials (API keys, JWTs) only on API requests. The admin
        // area is for browser sessions: a key accepted there worked with all
        // of its owner's admin rights, whatever scopes the key was limited to.
        $path = $request->path();
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $rel = ($base !== '' && str_starts_with($path, $base)) ? (string) substr($path, strlen($base)) : $path;
        $isApi = $guard === 'api' || str_starts_with($rel, '/api/');

        // Try JWT first (API requests)
        $token = $isApi ? $request->bearerToken() : null;
        if ($token) {
            // 1. Try as a Basehim API key. Always ask the service which
            //    prefixes it accepts rather than hard-coding one here — a stray
            //    literal silently rejects otherwise valid keys.
            if (ApiKeyService::looksLikeKey($token)) {
                try {
                    /** @var ApiKeyService $apiKeySvc */
                    $apiKeySvc = $app->make(ApiKeyService::class);
                    $keyRecord = $apiKeySvc->validate($token);
                    if ($keyRecord) {
                        $repo = $app->make(UserRepository::class);
                        $user = $repo->find((int)$keyRecord['user_id']);
                        // Attach scopes to the request context
                        if ($user) {
                            $via = 'key';
                            $app->instance('auth.api_key', $keyRecord);
                            $app->instance('auth.scopes', $keyRecord['scopes']);
                        }
                    }
                } catch (\Throwable) {
                    // ApiKeyService not available (e.g. table not yet migrated) — fall through to JWT
                }
            }

            // 2. Try as a JWT token
            if (!$user) {
                $secret = $app->make(Config::class)->get('auth.jwt.secret');
                $payload = Jwt::decode($token, $secret);
                if ($payload && isset($payload['sub'])) {
                    $repo = $app->make(UserRepository::class);
                    $user = $repo->find((int) $payload['sub']);
                    if ($user) $via = 'jwt';
                }
            }
        }

        // Try session next (admin). sessionUser() also ends a session whose
        // account was suspended or deleted, whose password changed, or that
        // was signed out everywhere — and clears it, so the login page does
        // not bounce a dead session back to the dashboard.
        // On the API, cookies only count when the request comes from a page of
        // this site (or an origin listed in CORS_ALLOWED_ORIGINS). Another
        // site's script gets treated as signed out, whatever the browser sends.
        $cookieAllowed = !$isApi || !$this->isForeignOrigin($request);

        if (!$user && $cookieAllowed) {
            try {
                $user = $app->make(\App\Services\AuthService::class)->sessionUser();
                if ($user) $via = 'cookie';
            } catch (\Throwable) {
                $user = null;
            }
        }

        // Try a "remember me" cookie last — if valid, restore the session so
        // the user stays logged in across browser restarts.
        if (!$user && $cookieAllowed) {
            // Read the current cookie, falling back to the pre-rename name so an
            // upgrade from Basehim doesn't sign everyone out.
            $cookie = (string) ($_COOKIE[\App\Services\AuthSecurityService::REMEMBER_COOKIE] ?? '');
            if ($cookie !== '') {
                try {
                    /** @var \App\Services\AuthSecurityService $sec */
                    $sec = $app->make(\App\Services\AuthSecurityService::class);
                    $rid = $sec->resolveRemember($cookie);
                    if ($rid) {
                        $repo = $app->make(UserRepository::class);
                        $candidate = $repo->find($rid);
                        if ($candidate && ($candidate['status'] ?? 'inactive') === 'active') {
                            $user = $candidate;
                            $via = 'cookie';
                            // Restore a full session (new id, fingerprint) for the
                            // rest of the request lifecycle.
                            $app->make(\App\Services\AuthService::class)->loginSession($candidate);
                        } else {
                            $sec->revokeRememberCookie($cookie);
                        }
                    }
                } catch (\Throwable) {
                    // AuthSecurityService/table not ready — ignore, fall through.
                }
            }
        }

        if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
            if ($guard === 'api' || str_starts_with($request->path(), '/api/')) {
                return Response::json([
                    'type' => 'https://basehim.io/errors/unauthorized',
                    'title' => 'Unauthorized',
                    'status' => 401,
                    'detail' => 'Authentication required.',
                ], 401);
            }
            // Remember where the user was heading so we can send them back
            // after they sign in. Only for safe GET navigations to admin pages,
            // and never for the auth pages themselves (avoids redirect loops).
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
                $path = $request->path();
                $relative = ($base !== '' && str_starts_with($path, $base)) ? substr($path, strlen($base)) : $path;
                if ($relative === '' || $relative === false) $relative = '/';
                $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
                if ($qs !== '') $relative .= '?' . $qs;

                $skip = ['/admin/login', '/admin/logout', '/admin/register', '/admin/login/otp', '/admin/login/verify',
                         '/admin/forgot-password', '/admin/reset-password'];
                $bare = explode('?', $relative)[0];
                $isSkippable = false;
                foreach ($skip as $s) {
                    if ($bare === $s || str_starts_with($bare, $s . '/')) { $isSkippable = true; break; }
                }
                if (str_starts_with($bare, '/admin') && !$isSkippable) {
                    try { $app->make(Session::class)->set('intended_url', $relative); } catch (\Throwable) {}
                }
            }
            // Redirect to login for browser sessions
            return Response::redirect('/admin/login');
        }

        if ($isApi) {
            // A browser session changing something must prove the request came
            // from our own page: the same CSRF token the admin forms carry, in
            // an X-CSRF-Token header (or a _csrf field). Until 1.2.43 a plain
            // form post with the session cookie was enough to create a post.
            if ($via === 'cookie' && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $token = (string) ($request->header('X-CSRF-Token') ?? $request->input('_csrf', '') ?? '');
                $ok = false;
                try { $ok = $app->make(Session::class)->verifyCsrf($token); } catch (\Throwable) {}
                if (!$ok) {
                    return Response::json([
                        'type' => 'https://basehim.io/errors/csrf',
                        'title' => 'CSRF token missing or invalid',
                        'status' => 403,
                        'detail' => 'Requests signed in with a browser session must send the X-CSRF-Token header. API clients should use an API key or a bearer token.',
                    ], 403);
                }
            }

            // An API key reaches only what its scopes name. The scopes used to
            // be recorded here and then never checked on the REST API, so a
            // posts:read key could do anything its owner's role allowed.
            if ($via === 'key') {
                $needed = self::scopeFor($rel, $request->method);
                if ($needed !== null) {
                    $scopes = (array) ($app->make('auth.scopes') ?? []);
                    if (!in_array($needed, $scopes, true)) {
                        return Response::json([
                            'type' => 'https://basehim.io/errors/insufficient-scope',
                            'title' => 'Insufficient scope',
                            'status' => 403,
                            'detail' => 'This API key does not have the ' . $needed . ' scope.',
                            'required_scope' => $needed,
                        ], 403);
                    }
                }
            }
        }

        // Store user in request-scoped state via the container
        $app->instance('auth.user', $user);

        return $next($request);
    }

    /**
     * The scope an API-key request needs, from its path under /api/v1 and
     * its method: GET/HEAD read, anything else writes. Null for paths core
     * doesn't own (an app's own routes check the scopes they contribute).
     */
    public static function scopeFor(string $relPath, string $method): ?string
    {
        $path = preg_replace('#^/api/v1#', '', $relPath) ?? $relPath;
        $first = explode('/', trim($path, '/'))[0] ?? '';
        $family = match ($first) {
            'posts', 'pages'                 => 'posts',
            'media'                          => 'media',
            'users', 'me'                    => 'users',
            'comments'                       => 'comments',
            'taxonomies', 'terms'            => 'taxonomies',
            'settings', 'apps', 'cache', 'schedule' => 'settings',
            'menus', 'menu-items'            => 'menus',
            default                          => null,
        };
        if ($family === null) return null;
        // Reading your own profile is harmless; changing it (email, password,
        // photo) is account takeover from a leaked read-only key.
        if ($first === 'me' && in_array(strtoupper($method), ['GET', 'HEAD'], true)) return null;
        return $family . (in_array(strtoupper($method), ['GET', 'HEAD'], true) ? ':read' : ':write');
    }

    private function isForeignOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '') {
            // No Origin: a same-origin GET, or a non-browser client. Browsers
            // send Origin on every cross-origin request.
            return false;
        }
        return !\App\Core\OriginPolicy::isTrusted($origin);
    }
}
