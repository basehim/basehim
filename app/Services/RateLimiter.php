<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Fixed-window request counters and one-time claims, kept in the database.
 *
 * Why the database: on shared hosting it is the one store every PHP worker
 * shares, it needs no extension, and MySQL can increment a counter
 * atomically. PHP memory is per request, and the file cache has no atomic
 * increment, so two simultaneous requests could both read 9 and both write 10.
 *
 * Each hit is a single statement:
 *
 *     INSERT … VALUES (k, LAST_INSERT_ID(1), …)
 *     ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)
 *
 * The row lock serialises concurrent hits on the same key, and
 * LAST_INSERT_ID(expr) hands each connection the count *its own* statement
 * produced, so no second read can see someone else's increment.
 *
 * The window is part of the key (floor(now / per)), so a new window is a new
 * row and no reset races exist. Fixed windows can let up to twice the limit
 * through across a window boundary; that is the accepted trade for one
 * statement per check. Expired rows are swept occasionally.
 *
 * AuthSecurityService::throttle() predates this and keeps its own table for
 * sign-in limits; it is left alone.
 */
final class RateLimiter
{
    private const TABLE_DDL = 'CREATE TABLE IF NOT EXISTS {rate_limits} (
        `k`          CHAR(64) NOT NULL PRIMARY KEY,
        `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at` INT UNSIGNED NOT NULL,
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin';

    /** One sweep in this many hits. */
    private const SWEEP_ONE_IN = 200;

    private bool $warned = false;

    public function __construct(private Database $db, private Logger $logger) {}

    /**
     * Count one request against a limit.
     *
     * @param string $bucket what is limited, e.g. "ajax:media.update"
     * @param string $client who is counted, e.g. "user:12" or "ip:203.0.113.9"
     * @return array{allowed: bool, count: int, remaining: int, retry_after: int, error: bool}
     *         error = the store could not be reached; the caller decides
     *         whether that means open or closed.
     */
    public function hit(string $bucket, string $client, int $max, int $perSeconds, ?int $now = null): array
    {
        $now ??= time();
        $perSeconds = max(1, $perSeconds);
        $windowStart = intdiv($now, $perSeconds) * $perSeconds;
        $expires = $windowStart + $perSeconds;
        $key = hash('sha256', "hit|{$bucket}|{$client}|{$perSeconds}|{$windowStart}");

        $count = $this->increment($key, $expires);
        if ($count === null) {
            return ['allowed' => true, 'count' => 0, 'remaining' => 0, 'retry_after' => 0, 'error' => true];
        }
        $this->maybeSweep($now);

        return [
            'allowed'     => $count <= $max,
            'count'       => $count,
            'remaining'   => max(0, $max - $count),
            'retry_after' => max(1, $expires - $now),
            'error'       => false,
        ];
    }

    /**
     * Claim a key once within $ttl seconds — for idempotency keys.
     *
     * @return bool|null true = claimed now, false = already claimed and not
     *         yet expired, null = the store could not be reached
     */
    public function claim(string $scope, string $key, int $ttl, ?int $now = null): ?bool
    {
        $now ??= time();
        $k = hash('sha256', "claim|{$scope}|{$key}");
        try {
            $this->withTable(function () use ($k, $now, $ttl) {
                // An expired claim starts over at 1; a live one counts up.
                // `hits` is assigned before `expires_at`, so both IF()s read
                // the old expires_at.
                $this->db->execute(
                    'INSERT INTO {rate_limits} (`k`, `hits`, `expires_at`) VALUES (:k, LAST_INSERT_ID(1), :e)
                     ON DUPLICATE KEY UPDATE
                        `hits` = LAST_INSERT_ID(IF(`expires_at` <= :now1, 1, `hits` + 1)),
                        `expires_at` = IF(`expires_at` <= :now2, :e2, `expires_at`)',
                    ['k' => $k, 'e' => $now + $ttl, 'now1' => $now, 'now2' => $now, 'e2' => $now + $ttl]
                );
            });
            return $this->lastValue() === 1;
        } catch (\Throwable $e) {
            $this->warn($e);
            return null;
        }
    }

    /** Give a claim back, so a request that failed can be retried with the same key. */
    public function release(string $scope, string $key): void
    {
        try {
            $this->db->execute('DELETE FROM {rate_limits} WHERE `k` = :k', ['k' => hash('sha256', "claim|{$scope}|{$key}")]);
        } catch (\Throwable) {}
    }

    // ------------------------------------------------------------------

    /** The count after this hit, or null when the store is unavailable. */
    private function increment(string $key, int $expires): ?int
    {
        try {
            $this->withTable(function () use ($key, $expires) {
                $this->db->execute(
                    'INSERT INTO {rate_limits} (`k`, `hits`, `expires_at`) VALUES (:k, LAST_INSERT_ID(1), :e)
                     ON DUPLICATE KEY UPDATE `hits` = LAST_INSERT_ID(`hits` + 1)',
                    ['k' => $key, 'e' => $expires]
                );
            });
            return $this->lastValue();
        } catch (\Throwable $e) {
            $this->warn($e);
            return null;
        }
    }

    /**
     * The value our own statement passed to LAST_INSERT_ID(expr). It is
     * per connection, so a concurrent request's increment never shows here.
     * Read with SELECT rather than PDO::lastInsertId(), whose answer for
     * LAST_INSERT_ID(expr) inside ON DUPLICATE KEY UPDATE varies by driver.
     */
    private function lastValue(): int
    {
        return (int) ($this->db->selectOne('SELECT LAST_INSERT_ID() AS n')['n'] ?? 0);
    }

    /**
     * Run $fn; if the table is missing (an install that has not run migration
     * 014 yet), create it and run $fn once more.
     */
    private function withTable(\Closure $fn): void
    {
        try {
            $this->retrying($fn);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1146 && $e->getCode() !== '42S02') throw $e;
            $this->db->execute(self::TABLE_DDL);
            $this->retrying($fn);
        }
    }

    /**
     * Run one statement, retrying a few times on a deadlock or serialization
     * failure (1213 / SQLSTATE 40001) or a lock-wait timeout (1205).
     *
     * Several clients inserting the same new key at the same instant is
     * exactly the case InnoDB can resolve with a deadlock, and a statement
     * that loses one is safe to repeat: it either inserts the row or
     * increments it, once. Giving up after a few tries hands the error to
     * the caller, which applies the open/closed policy.
     */
    private function retrying(\Closure $fn): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $fn();
                return;
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                $retryable = $code === 1213 || $code === 1205 || $e->getCode() === '40001';
                if (!$retryable || $attempt >= 5) throw $e;
                usleep(random_int(1000, 5000) * $attempt);
            }
        }
    }

    private function maybeSweep(int $now): void
    {
        if (random_int(1, self::SWEEP_ONE_IN) !== 1) return;
        try {
            $this->db->execute('DELETE FROM {rate_limits} WHERE `expires_at` < :now LIMIT 1000', ['now' => $now]);
        } catch (\Throwable) {}
    }

    private function warn(\Throwable $e): void
    {
        if ($this->warned) return;      // once per request is enough
        $this->warned = true;
        try {
            $this->logger->warning('Rate limit store unavailable: ' . $e->getMessage());
        } catch (\Throwable) {}
    }
}
