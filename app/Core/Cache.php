<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cache
 *
 * File-based key/value cache with TTL. The spec calls for Redis;
 * on shared cPanel hosting we use files. The interface is the same
 * so it can be swapped later.
 *
 * Tags (1.2.73, BH-025). Every key belongs to one tag, read from the key
 * itself, and lives in that tag's folder, so flushTag() removes exactly the
 * keys it names:
 *
 *     app.{slug}.anything  → tag "app.{slug}"   (what CacheApi::flush() clears)
 *     settings.all         → tag "settings"
 *     posts.recent         → tag "posts"
 *     plain                → tag "_" (no dot: cleared only by flush())
 *
 * Until 1.2.73 flushTag() deleted a folder nothing was ever written to: an
 * app's cache()->flush() answered true and removed nothing.
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
        $data = @unserialize($raw, ['allowed_classes' => true]);
        // array_key_exists, not isset: a cached null is a hit (1.2.73).
        if (!is_array($data) || !isset($data['expires']) || !array_key_exists('value', $data)) {
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
        // Written to a temporary name and renamed, so a reader never sees a
        // half-written file.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($payload)) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public function has(string $key): bool
    {
        $miss = new \stdClass();
        return $this->get($key, $miss) !== $miss;
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        return !is_file($file) || @unlink($file);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $miss = new \stdClass();
        $hit = $this->get($key, $miss);
        if ($hit !== $miss) {
            return $hit;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /** Remove every entry. */
    public function flush(): bool
    {
        $ok = true;
        foreach (scandir($this->cacheDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;   // .gitkeep, .htaccess
            $path = $this->cacheDir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
                $ok = $ok && !is_dir($path);
            } elseif (str_ends_with($entry, '.cache') || str_ends_with($entry, '.tmp')) {
                $ok = @unlink($path) && $ok;
            }
        }
        return $ok;
    }

    /**
     * Remove every entry of one tag (see the class comment). Returns false
     * only when entries were there and could not all be removed.
     */
    public function flushTag(string $tag): bool
    {
        $tag = trim($tag);
        if ($tag === '') return false;
        $dir = $this->cacheDir . '/' . self::tagDir($tag);
        if (!is_dir($dir)) return true;
        $this->rrmdir($dir);
        return !is_dir($dir);
    }

    /** The tag a key belongs to. */
    public static function tagOf(string $key): string
    {
        $parts = explode('.', $key);
        if (count($parts) < 2) return '_';
        if ($parts[0] === 'app' && count($parts) >= 3) return 'app.' . $parts[1];
        return $parts[0];
    }

    private static function tagDir(string $tag): string
    {
        return 'tag_' . md5($tag);
    }

    private function path(string $key): string
    {
        $hash = md5($key);
        return $this->cacheDir . '/' . self::tagDir(self::tagOf($key)) . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
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
