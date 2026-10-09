<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\OriginPolicy;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * CORS for the REST API, the MCP endpoint and OAuth discovery.
 *
 * Any origin may call the API with a bearer token or API key: those aren't
 * ambient credentials, so allowing the origin exposes nothing it couldn't
 * already reach. Cookies are a different matter. Since 1.2.43,
 * Access-Control-Allow-Credentials goes only to trusted origins (this site,
 * plus CORS_ALLOWED_ORIGINS in .env); see OriginPolicy. Before that every
 * Origin was echoed back with credentials allowed, so any web page could read
 * the API as whoever was signed in to the admin.
 */
final class Cors
{
    public function handle(Request $request, Closure $next): mixed
    {
        $origin = (string) ($request->header('origin', '') ?? '');
        $trusted = $origin !== '' && OriginPolicy::isTrusted($origin);

        if ($request->isMethod('OPTIONS')) {
            $res = Response::make('', 204)
                ->header('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-CSRF-Token, Accept, Mcp-Session-Id, Mcp-Protocol-Version')
                ->header('Access-Control-Max-Age', '3600')
                ->header('Vary', 'Origin');
            if ($trusted) $res->header('Access-Control-Allow-Credentials', 'true');
            return $res;
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $response->header('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
            if ($trusted) $response->header('Access-Control-Allow-Credentials', 'true');
            $response->header('Vary', 'Origin');
        }
        return $response;
    }
}
