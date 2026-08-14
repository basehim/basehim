<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\CheckCapability;

/**
 * Cache maintenance over the API, so a deploy script can clear caches without
 * driving the admin UI.
 *
 * There is no endpoint to read or write arbitrary cache keys: the cache holds
 * rendered fragments and query results across every app, and exposing it by key
 * would be a cross-app read primitive. Apps get their own namespaced view via
 * $this->api()->cache().
 */
class CacheController extends ApiController
{
    /** POST /cache/flush — clear everything. ?tag=… clears one namespace. */
    public function flush(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var Cache $cache */
        $cache = $this->app->make(Cache::class);
        $tag = trim((string) $request->input('tag', ''));

        if ($tag !== '') {
            $cache->flushTag($tag);
            return Response::json(['flushed' => true, 'tag' => $tag]);
        }

        $ok = $cache->flush();
        if (function_exists('opcache_reset')) @opcache_reset();
        return Response::json(['flushed' => $ok]);
    }

    private function canManage(): bool
    {
        $user = $this->authUser();
        return $user !== null && CheckCapability::userCan($user, 'manage_settings');
    }

    private function denied(): Response
    {
        return Response::json(['error' => 'Requires the manage_settings capability.'], 403);
    }
}
