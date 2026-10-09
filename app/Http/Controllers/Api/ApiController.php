<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
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
        // The profile photo, in the shape GET /users/{id}/avatar returns.
        if (array_key_exists('avatar_media_id', $user)) {
            try {
                $av = $this->app->make(\App\Services\AvatarService::class)->forUser($user);
            } catch (\Throwable) {
                $av = null;
            }
            $user['avatar'] = $av;
            $user['avatar_url'] = $av['medium_url'] ?? null;
        }
        return $user;
    }

    /** Helper: 401 when not authed */
    protected function requireAuth(): ?array
    {
        $u = $this->authUser();
        return $u;
    }

    /**
     * Does the current user hold this capability?
     *
     * Added in 1.2.43. Before it, the post, page, media and term write
     * endpoints checked only that *someone* was signed in, so a subscriber
     * could rewrite or delete any post on the site.
     */
    protected function userCan(string $cap): bool
    {
        $user = $this->authUser();
        return $user !== null && \App\Http\Middleware\CheckCapability::userCan($user, $cap);
    }

    /** 403 naming the missing capability, in the shape other API errors use. */
    protected function forbidden(string $cap): Response
    {
        return Response::json(['error' => 'Requires the ' . $cap . ' capability.'], 403);
    }

    /**
     * A page size from the query string.
     *
     * A value that is not a whole number falls back to the default. Casting
     * it, as before, turned "abc" into 0 and then clamped it to 1 per page.
     */
    protected function perPage(Request $request, int $default, int $max = 100): int
    {
        $raw = $request->query('per_page', null);
        if (is_string($raw) && preg_match('/^\d+$/', $raw)) {
            return max(1, min($max, (int) $raw));
        }
        if (is_int($raw)) return max(1, min($max, $raw));
        return $default;
    }

    /** A 1-based page number from the query string; anything else is page 1. */
    protected function pageNumber(Request $request): int
    {
        $raw = $request->query('page', null);
        return (is_string($raw) && preg_match('/^\d{1,9}$/', $raw)) ? max(1, (int) $raw) : 1;
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
