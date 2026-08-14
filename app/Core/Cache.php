<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cache
 *
 * File-based key/value cache with TTL. The spec calls for Redis;
 * on shared cPanel hosting we use files. The interface is the same
 * so it can be swapped later.
 */
final class Cache
{
    public function __construct(private string $cacheDir)
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        // allowed_classes => false: the cache holds scalars and arrays, and
        // refusing to instantiate objects removes an object-injection primitive
        // for anyone who ever gains a write into the cache directory.
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            return $default;
        }
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $file = $this->path($key);
        @mkdir(dirname($file), 0755, true);
        $payload = [
            'expires' => $ttl === 0 ? 0 : time() + $ttl,
            'value'   => $value,
        ];
        return (bool) @file_put_contents($file, serialize($payload), LOCK_EX);
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__miss__') !== '__miss__';
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        return !is_file($file) || @unlink($file);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $hit = $this->get($key, '__miss__');
        if ($hit !== '__miss__') {
            return $hit;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function flush(): bool
    {
        $this->rrmdir($this->cacheDir);
        @mkdir($this->cacheDir, 0755, true);
        return true;
    }

    public function flushTag(string $tag): void
    {
        // Simple tag implementation: tagged keys live in a subdirectory
        $dir = $this->cacheDir . '/tag_' . md5($tag);
        if (is_dir($dir)) {
            $this->rrmdir($dir);
        }
    }

    private function path(string $key): string
    {
        $hash = md5($key);
        return $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
