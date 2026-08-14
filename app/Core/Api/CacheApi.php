<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Cache;

/**
 * CacheApi — an app-scoped view of the file cache.
 *
 * Every key is namespaced with the owning app's slug, so two apps can both
 * cache under "results" without colliding, and flush() clears only the calling
 * app's entries rather than nuking the site's cache.
 */
class CacheApi extends Resource
{
    private function cache(): Cache
    {
        return $this->make(Cache::class);
    }

    /** Namespace a caller's key to this app. */
    private function key(string $key): string
    {
        return 'app.' . $this->slug . '.' . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attempt(fn() => $this->cache()->get($this->key($key), $default), $default, 'get');
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return (bool) $this->attempt(fn() => $this->cache()->set($this->key($key), $value, $ttl), false, 'set');
    }

    public function has(string $key): bool
    {
        return (bool) $this->attempt(fn() => $this->cache()->has($this->key($key)), false, 'has');
    }

    public function delete(string $key): bool
    {
        return (bool) $this->attempt(fn() => $this->cache()->delete($this->key($key)), false, 'delete');
    }

    /**
     * Return a cached value, computing and storing it on a miss.
     *
     * The one method most apps need:
     *
     *     $rates = $api->cache()->remember('fx', 3600, fn() => $api->http()->getJson($url));
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->attempt(
            fn() => $this->cache()->remember($this->key($key), $ttl, $callback),
            null,
            'remember'
        );
    }

    /** Clear every entry belonging to this app. */
    public function flush(): bool
    {
        return (bool) $this->attempt(fn() => $this->cache()->flushTag('app.' . $this->slug), false, 'flush');
    }
}
