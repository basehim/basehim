<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Services\IdempotencyStore;
use Closure;

/**
 * Idempotency-Key on the REST API and on public app routes (1.2.75, CR-27).
 *
 * A POST, PUT, PATCH or DELETE that carries an Idempotency-Key runs once per
 * key and caller. Sending the same request again with the same key returns
 * the first answer (with Idempotent-Replayed: true), so a client whose
 * connection dropped after a create can safely retry it:
 *
 *   - same key, same request, finished       → the stored answer
 *   - same key while the first is running    → 409, Retry-After: 2
 *   - same key, different method/path/body   → 422
 *   - an answer of 500 or above is not kept  → a retry runs again
 *
 * On the REST API the key is optional (clients opt in by sending it). A public
 * app route declared with 'idempotent' => true requires it (400 without), via
 * SiteRouteGuard. Keys are scoped to the caller: the signed-in account, else
 * the session, else the client address. They last 24 hours.
 */
final class Idempotency
{
    /** @param string $required '1' when the route requires a key */
    public function __construct(private string $required = '0') {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        $key = trim((string) ($request->header('idempotency-key') ?? ''));
        if ($key === '') {
            if ($this->required === '1') {
                return self::problem(400, 'Idempotency-Key required',
                    'This endpoint needs an Idempotency-Key header (16–128 characters: letters, digits, "_" or "-"; a UUID works).');
            }
            return $next($request);
        }
        if (!IdempotencyStore::validKey($key)) {
            return self::problem(400, 'Invalid Idempotency-Key',
                'An Idempotency-Key is 16–128 characters: letters, digits, "_" or "-". A UUID works.');
        }

        $app = Application::getInstance();
        $scope = 'http:' . self::caller($request);
        $path = $request->path() . (($q = (string) ($_SERVER['QUERY_STRING'] ?? '')) !== '' ? '?' . $q : '');
        $body = (string) @file_get_contents('php://input');
        try {
            /** @var IdempotencyStore $store */
            $store = $app->make(IdempotencyStore::class);
            $begin = $store->begin($scope, $key, IdempotencyStore::requestHash($request->method, $path, $body));
        } catch (\Throwable) {
            return self::problem(503, 'Service unavailable', 'The request could not be checked for duplicates. Try again shortly.');
        }

        if ($begin['state'] === 'replay') return $begin['response'];
        if ($begin['state'] === 'running') {
            return self::problem(409, 'Request in progress',
                'A request with this Idempotency-Key is still being processed. Retry shortly to get its result.')
                ->header('Retry-After', '2');
        }
        if ($begin['state'] === 'conflict') {
            return self::problem(422, 'Idempotency-Key reused',
                'This Idempotency-Key was used for a different request. Use a new key for each new request.');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $store->releaseHash($begin['k']);
            throw $e;
        }
        if ($response instanceof Response) {
            $store->complete($begin['k'], $response);
        } else {
            $store->releaseHash($begin['k']);
        }
        return $response;
    }

    /** Who is calling: the account, else the session, else the address. */
    private static function caller(Request $request): string
    {
        try {
            $user = Application::getInstance()->make('auth.user');
            if (is_array($user) && !empty($user['id'])) return 'user:' . (int) $user['id'];
        } catch (\Throwable) {}
        $name = session_name() ?: 'PHPSESSID';
        if (!empty($request->cookies[$name])) return 'session:' . hash('sha256', (string) $request->cookies[$name]);
        return 'ip:' . $request->visitorIp();
    }

    private static function problem(int $status, string $title, string $detail): Response
    {
        return Response::json(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $status);
    }
}
