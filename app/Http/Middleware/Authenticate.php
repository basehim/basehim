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

    /**
     * Is this request under /api/?
     *
     * The install base has to come off first — on a subdirectory install the
     * path is '/basehim/api/v1/posts', and a bare str_starts_with('/api/')
     * check silently answers no, which would hand API routes back their cookie
     * authentication on exactly the installs least likely to be tested.
     */
    private static function isApiPath(Request $request): bool
    {
        $path = $request->path();
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return str_starts_with($path, '/api/');
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $app = Application::getInstance();
        $user = null;
        $guard = $this->guard;

        // Try JWT first (API requests)
        $token = $request->bearerToken();
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
                $cfg = $app->make(Config::class);
                $secret = $cfg->get('auth.jwt.secret');
                $payload = Jwt::decode($token, $secret, (string) $cfg->get('auth.jwt.algorithm', 'HS256'));
                if ($payload && isset($payload['sub'])) {
                    $repo = $app->make(UserRepository::class);
                    $user = $repo->find((int) $payload['sub']);
                }
            }
        }

        // Cookie-based auth is for the browser admin panel only.
        //
        // The API used to accept $_SESSION too, which meant every /api/v1 route
        // carried ambient authority from an admin's browser cookie while having
        // no CSRF token requirement of its own. Any page the admin visited could
        // then drive the API as them. Bearer credentials are explicit and are
        // never attached by the browser automatically, so the API takes those
        // only.
        $cookieAuthAllowed = $guard !== 'api' && !self::isApiPath($request);

        // Try session next (admin)
        if (!$user && $cookieAuthAllowed) {
            $session = $app->make(Session::class);
            $uid = $session->get('user_id');
            if ($uid) {
                $repo = $app->make(UserRepository::class);
                $user = $repo->find((int) $uid);
            }
        }

        // Try a "remember me" cookie last — if valid, restore the session so
        // the user stays logged in across browser restarts.
        if (!$user && $cookieAuthAllowed) {
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
                            // Restore the session for the rest of the request lifecycle.
                            $session = $app->make(Session::class);
                            $session->set('user_id', (int) $candidate['id']);
                            $session->set('user_role', $candidate['role'] ?? null);
                            $session->set('logged_in_at', time());
                        }
                    }
                } catch (\Throwable) {
                    // AuthSecurityService/table not ready — ignore, fall through.
                }
            }
        }

        if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
            if ($guard === 'api' || self::isApiPath($request)) {
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

                $skip = ['/admin/login', '/admin/logout', '/admin/register', '/admin/login/otp',
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

        // Store user in request-scoped state via the container
        $app->instance('auth.user', $user);

        return $next($request);
    }
}
