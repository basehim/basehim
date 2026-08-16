<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

final class Cors
{
    public function handle(Request $request, Closure $next): mixed
    {
        $origin = $request->header('origin', '*');

        if ($request->isMethod('OPTIONS')) {
            return Response::make('', 204)
                ->header('Access-Control-Allow-Origin', $origin ?: '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-CSRF-Token, Accept')
                ->header('Access-Control-Max-Age', '3600');
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $response->header('Access-Control-Allow-Origin', $origin ?: '*');
            $response->header('Access-Control-Allow-Credentials', 'true');
            $response->header('Vary', 'Origin');
        }
        return $response;
    }
}
