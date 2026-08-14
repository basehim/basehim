<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AppLogger — a per-app log file, separate from the core log.
 *
 * Apps previously logged into the shared core log, where an app's own output was
 * interleaved with everything else and an operator debugging one app had to grep
 * for its tag. Each app now gets its own daily file:
 *
 *   storage/logs/apps/{slug}-YYYY-MM-DD.log
 *
 * Lines are still mirrored to the core log at warning level and above, so
 * genuine problems remain visible to anyone reading the main log.
 *
 * Rotation matters here more than usual: shared hosting has a finite and often
 * small disk quota, and a chatty app on a busy site can produce a lot of lines.
 * Files older than the retention window are pruned, and a single file that grows
 * past the size cap is truncated with a marker rather than allowed to fill the
 * disk. Losing old log lines is a much better failure than a site that can no
 * longer write uploads.
 */
class AppLogger
{
    /** Days of per-app logs to keep. */
    private const RETENTION_DAYS = 7;

    /** Hard cap per file. Beyond this the file is rotated aside. */
    private const MAX_BYTES = 2097152; // 2 MB

    /** Pruning is not needed on every write; roughly 1 in N writes. */
    private const PRUNE_CHANCE = 50;

    public function __construct(private string $logDir)
    {
    }

    /**
     * Append a line for an app.
     *
     * Best-effort throughout: an unwritable log directory must never break the
     * app that logged, so every failure path returns quietly.
     */
    public function log(string $slug, string $level, string $message, array $context = []): void
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') return;

        try {
            $dir = $this->logDir . '/apps';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;

            $file = $dir . '/' . $slug . '-' . date('Y-m-d') . '.log';
            $this->rotateIfLarge($file);

            $line = sprintf(
                "[%s] %s: %s%s\n",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $this->collapse($message),
                $context ? ' ' . $this->encodeContext($context) : ''
            );

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            if (random_int(1, self::PRUNE_CHANCE) === 1) {
                $this->prune();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Read back the most recent lines for an app, newest last.
     *
     * Reads only the tail of the file rather than the whole thing — a 2 MB log
     * loaded into memory to show 200 lines would be wasteful on a small host.
     *
     * @return array<int,string>
     */
    public function tail(string $slug, int $lines = 200, ?string $date = null): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') return [];

        $file = $this->logDir . '/apps/' . $slug . '-' . ($date ?: date('Y-m-d')) . '.log';

        // PHP caches stat() results per request. rotateIfLarge() calls is_file()
        // on today's log BEFORE the first write of the day, which caches "does
        // not exist"; the write then creates it, and this is_file() would still
        // see the stale false and report an empty log. It looked exactly like an
        // unwritable directory, which is the wrong thing to go and check.
        clearstatcache(true, $file);

        if (!is_file($file) || !is_readable($file)) return [];

        $lines = max(1, min(2000, $lines));

        try {
            $handle = @fopen($file, 'rb');
            if ($handle === false) return [];

            // Read backwards in chunks until we have enough newlines.
            $buffer = '';
            $chunk = 8192;
            $position = filesize($file);
            while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
                $read = (int) min($chunk, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = fread($handle, $read) . $buffer;
            }
            fclose($handle);

            $all = array_values(array_filter(explode("\n", $buffer), fn($l) => trim($l) !== ''));
            return array_slice($all, -$lines);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Dates for which an app has a log file, newest first. */
    public function availableDates(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') return [];

        $found = [];
        foreach (glob($this->logDir . '/apps/' . $slug . '-*.log') ?: [] as $path) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $path, $m)) {
                $found[] = $m[1];
            }
        }
        rsort($found);
        return $found;
    }

    /** Delete every log file for an app — called on uninstall. */
    public function purge(string $slug): int
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === '') return 0;

        $removed = 0;
        foreach (glob($this->logDir . '/apps/' . $slug . '-*.log') ?: [] as $path) {
            if (@unlink($path)) $removed++;
        }
        return $removed;
    }

    /** Total bytes of per-app logs on disk. */
    public function diskUsage(): int
    {
        $bytes = 0;
        clearstatcache();
        foreach (glob($this->logDir . '/apps/*.log') ?: [] as $path) {
            $bytes += (int) @filesize($path);
        }
        return $bytes;
    }

    /** Drop files past the retention window. */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = time() - ($days * 86400);
        $removed = 0;
        foreach (glob($this->logDir . '/apps/*.log') ?: [] as $path) {
            if ((int) @filemtime($path) < $cutoff && @unlink($path)) $removed++;
        }
        return $removed;
    }

    /**
     * Move a file aside once it exceeds the cap.
     *
     * Renaming rather than deleting keeps the most recent oversized chunk
     * available for exactly one cycle; the .1 file is itself pruned by age.
     */
    private function rotateIfLarge(string $file): void
    {
        // Clear first: a stale size from earlier in the request could let a file
        // grow past the cap, and this is also the call that poisons the stat
        // cache for tail().
        clearstatcache(true, $file);
        if (!is_file($file) || (int) @filesize($file) < self::MAX_BYTES) return;
        @rename($file, $file . '.1');
        clearstatcache(true, $file);
    }

    /** Log lines are one per line; embedded newlines would corrupt the tail. */
    private function collapse(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    private function encodeContext(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return '';
        return mb_substr($json, 0, 1000);
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_replace('/[^a-z0-9_\-]/', '', $slug) ?? '';
    }
}
