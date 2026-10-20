<?php

declare(strict_types=1);

namespace App\Services\AppHealth;

/**
 * HealthStore — where app failure reports and validation results live (1.2.71).
 *
 * Files under storage/app-health/, not the database, on purpose: the store has
 * to work in the request where something is going wrong, including when the
 * database is the thing that is down, and from a shutdown function after an
 * engine fatal. The `apps.status` column is still set to 'error' when the
 * database is reachable; these files are what keeps a broken app switched
 * off until it is, and what the admin reads the report from.
 *
 *   {slug}.json  one app's state, its last failure, earlier failures (5),
 *                and its last full validation report.
 *   index.json   per-app validation cache (fingerprint → ok) and the URL
 *                prefixes each app answered, so its screens can show the
 *                report when the app is off.
 *
 * Writes are atomic (temporary file + rename, under a lock), so a reader never
 * sees half a file and two requests cannot interleave a write.
 */
final class HealthStore
{
    private string $dir;

    /** @var array<string, ?array> per-request cache of {slug}.json */
    private array $records = [];

    private ?array $index = null;

    public function __construct(?string $dir = null)
    {
        $root = defined('BASEHIM_ROOT') ? (string) BASEHIM_ROOT : dirname(__DIR__, 3);
        $this->dir = rtrim($dir ?? ($root . '/storage/app-health'), '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** True when a record can be written here (the folder exists or can be made, and is writable). */
    public function writable(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) return false;
        return is_writable($this->dir);
    }

    // ------------------------------------------------------------------
    // Per-app records
    // ------------------------------------------------------------------

    public function get(string $slug): ?array
    {
        $slug = self::safeSlug($slug);
        if ($slug === '') return null;
        if (array_key_exists($slug, $this->records)) return $this->records[$slug];
        $file = $this->dir . '/' . $slug . '.json';
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return $this->records[$slug] = is_array($data) ? $data : null;
    }

    /** Every app that has a record, keyed by slug. */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            if ($slug === 'index' || self::safeSlug($slug) !== $slug) continue;
            $r = $this->get($slug);
            if ($r !== null) $out[$slug] = $r;
        }
        return $out;
    }

    public function put(string $slug, array $record): bool
    {
        $slug = self::safeSlug($slug);
        if ($slug === '') return false;
        $record['slug'] = $slug;
        $record['updated_at'] = gmdate('c');
        $ok = $this->write($this->dir . '/' . $slug . '.json', $record);
        if ($ok) $this->records[$slug] = $record;
        return $ok;
    }

    public function forget(string $slug): void
    {
        $slug = self::safeSlug($slug);
        if ($slug === '') return;
        @unlink($this->dir . '/' . $slug . '.json');
        $this->records[$slug] = null;
    }

    // ------------------------------------------------------------------
    // Index: validation cache and app URL prefixes
    // ------------------------------------------------------------------

    public function index(): array
    {
        if ($this->index !== null) return $this->index;
        $file = $this->dir . '/index.json';
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        return $this->index = is_array($data) ? $data : [];
    }

    /** Change the index and write it, under one lock so concurrent requests do not undo each other. */
    public function updateIndex(callable $change): bool
    {
        if (!$this->writable()) return false;
        $lock = @fopen($this->dir . '/.index.lock', 'c');
        if ($lock === false) return false;
        try {
            flock($lock, LOCK_EX);
            $file = $this->dir . '/index.json';
            $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
            $data = is_array($data) ? $data : [];
            $new = $change($data);
            if (!is_array($new)) return false;
            if ($new === $data) { $this->index = $data; return true; }
            $ok = $this->write($file, $new, false);
            if ($ok) $this->index = $new;
            return $ok;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Drop this request's cached copies, so the next read sees the files. */
    public function refresh(): void
    {
        $this->records = [];
        $this->index = null;
    }

    // ------------------------------------------------------------------

    private function write(string $file, array $data, bool $lock = true): bool
    {
        if (!$this->writable()) return false;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return false;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", $lock ? LOCK_EX : 0) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** An app slug as a safe file name, or '' when it is not one. */
    public static function safeSlug(string $slug): string
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/i', $slug) ? $slug : '';
    }
}
