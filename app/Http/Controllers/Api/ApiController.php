<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

abstract class ApiController extends Controller
{
    /** Return the authenticated user (set by Authenticate middleware), or null. */
    protected function authUser(): ?array
    {
        try { return $this->app->make('auth.user'); }
        catch (\Throwable $e) { return null; }
    }

    protected function safeUser(array $user): array
    {
        unset($user['password_hash'], $user['remember_token']);
        return $user;
    }

    /** Helper: 401 when not authed */
    protected function requireAuth(): ?array
    {
        $u = $this->authUser();
        return $u;
    }

    /** Standard pagination meta from a service result */
    protected function paginated(array $result): array
    {
        return [
            'data' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? [],
        ];
    }
}
