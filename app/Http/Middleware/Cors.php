<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Services\SettingService;
use Closure;

/**
 * Cors
 *
 * Previously this reflected whatever Origin the caller sent and paired it with
 * Access-Control-Allow-Credentials: true — the combination the spec forbids
 * `*` for in the first place. Any website could then read authenticated API
 * responses using a logged-in admin's cookie.
 *
 * Now: credentials are only ever offered to an explicitly configured origin.
 * Everything else gets an anonymous `*` response, which is correct for a
 * token-authenticated API (the caller sends Authorization, not cookies) and
 * carries no ambient authority.
 *
 * Configure allowed origins in config/cms.php:
 *     'cors' => ['origins' => ['https://app.example.com']],
 * or as a comma/space separated list in Settings → General (`cors_origins`).
 */
final class Cors
{
    public function handle(Request $request, Closure $next): mixed
    {
        $origin = (string) ($request->header('origin', '') ?? '');

        if ($request->isMethod('OPTIONS')) {
            $response = Response::make('', 204)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-CSRF-Token, Accept')
                ->header('Access-Control-Max-Age', '3600');
            return $this->applyOrigin($response, $origin);
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $this->applyOrigin($response, $origin);
        }
        return $response;
    }

    private function applyOrigin(Response $response, string $origin): Response
    {
        // Vary on Origin regardless, so a cache never serves one origin's
        // response to another.
        $response->header('Vary', 'Origin');

        if ($origin === '') {
            return $response;
        }

        if ($this->isAllowed($origin)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Credentials', 'true');
            return $response;
        }

        // Unknown origin: anonymous access only. No credentials, so a browser
        // will not attach cookies and cannot read a session-authenticated
        // response.
        $response->header('Access-Control-Allow-Origin', '*');
        return $response;
    }

    /** Exact-match allowlist — no prefix or suffix matching, no wildcards. */
    private function isAllowed(string $origin): bool
    {
        foreach ($this->allowedOrigins() as $allowed) {
            if (hash_equals($allowed, $origin)) return true;
        }
        return false;
    }

    /** @return string[] */
    private function allowedOrigins(): array
    {
        $app = Application::getInstance();
        $origins = [];

        try {
            $configured = $app->make(\App\Core\Config::class)->get('cms.cors.origins', []);
            foreach ((array) $configured as $o) {
                $o = trim((string) $o);
                if ($o !== '') $origins[] = rtrim($o, '/');
            }
        } catch (\Throwable) {}

        try {
            $raw = (string) $app->make(SettingService::class)->get('general', 'cors_origins', '');
            foreach (preg_split('/[\s,]+/', $raw) ?: [] as $o) {
                $o = trim((string) $o);
                if ($o !== '') $origins[] = rtrim($o, '/');
            }
        } catch (\Throwable) {}

        return array_values(array_unique($origins));
    }
}
