<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Response;

/**
 * Idempotency keys with the original answer replayed (1.2.75, CR-27).
 *
 * A client that sends a request with an Idempotency-Key and loses the answer
 * (a timeout after the write, a dropped connection) sends the same request
 * again with the same key. It must get the first answer back, not a second
 * order, payment or post, and not a bare "duplicate" error that leaves it
 * guessing what happened.
 *
 *   begin()     claims the key, atomically (a primary-key insert). Outcomes:
 *                 new       — run the request, then complete() or release();
 *                 replay    — the request ran before: send the stored answer;
 *                 running   — the first request is still in progress: 409;
 *                 conflict  — the key was used for a different request: 422.
 *   complete()  stores the answer (status, headers that matter, body).
 *   release()   gives the key back after a failure, so a retry runs again.
 *
 * Answers of 5xx are never stored: the request failed, a retry should run.
 * Keys live 24 hours. A claim whose request died without completing (killed
 * by the time limit) can be taken over after STALE_SECONDS.
 *
 * Used by the REST API (an Idempotency-Key header on POST/PUT/PATCH/DELETE),
 * public app routes ('idempotent' => true), the AJAX gateway (actions
 * declared 'idempotent'), and by apps for webhook events (once()).
 */
final class IdempotencyStore
{
    public const TTL = 86400;
    public const STALE_SECONDS = 300;
    private const MAX_BODY = 1_000_000;
    /** Response headers worth replaying; the rest are the server's own. */
    private const REPLAY_HEADERS = ['content-type', 'location', 'retry-after', 'cache-control'];

    private const TABLE_DDL = 'CREATE TABLE IF NOT EXISTS {idempotency_keys} (
        `k`             CHAR(64) NOT NULL PRIMARY KEY,
        `request_hash`  CHAR(64) NOT NULL,
        `status`        VARCHAR(10) NOT NULL DEFAULT \'running\',
        `response_code` SMALLINT UNSIGNED NULL,
        `response_headers` TEXT NULL,
        `response_body` MEDIUMTEXT NULL,
        `created_at`    INT UNSIGNED NOT NULL,
        `expires_at`    INT UNSIGNED NOT NULL,
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private Database $db) {}

    /** A key a client may send: 16–128 of A–Z a–z 0–9 _ - (a UUID fits). */
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key);
    }

    /** The fingerprint of a request, so a reused key with another body is caught. */
    public static function requestHash(string $method, string $path, string $body): string
    {
        return hash('sha256', strtoupper($method) . ' ' . $path . "\n" . $body);
    }

    /**
     * @param string $scope who and where: 'api:user:7', 'site:shop:POST /cart:session:…'
     * @return array{state:string, response?:Response}
     */
    public function begin(string $scope, string $key, string $requestHash, ?int $now = null): array
    {
        $now ??= time();
        $k = hash('sha256', $scope . '|' . $key);
        $this->maybeSweep($now);
        $insert = fn() => $this->db->execute(
            'INSERT INTO {idempotency_keys} (k, request_hash, status, created_at, expires_at)
             VALUES (:k, :h, \'running\', :c, :e)',
            ['k' => $k, 'h' => $requestHash, 'c' => $now, 'e' => $now + self::TTL]
        );
        try {
            $this->withTable($insert);
            return ['state' => 'new', 'k' => $k];
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) throw $e;
        }

        $row = $this->db->selectOne('SELECT * FROM {idempotency_keys} WHERE k = :k', ['k' => $k]);
        if ($row === null) {
            // Released between our insert and this read: claim it again.
            try { $this->withTable($insert); return ['state' => 'new', 'k' => $k]; }
            catch (\PDOException $e) { if (!self::isDuplicate($e)) throw $e; return ['state' => 'running', 'k' => $k]; }
        }

        $expired = (int) $row['expires_at'] <= $now;
        $stale = $row['status'] === 'running' && (int) $row['created_at'] + self::STALE_SECONDS <= $now;
        if ($expired || $stale) {
            // Take it over, conditionally: of two takers only one wins.
            $won = $this->db->execute(
                'UPDATE {idempotency_keys}
                    SET request_hash = :h, status = \'running\', response_code = NULL, response_headers = NULL,
                        response_body = NULL, created_at = :c, expires_at = :e
                  WHERE k = :k AND created_at = :old AND status = :st',
                ['h' => $requestHash, 'c' => $now, 'e' => $now + self::TTL, 'k' => $k,
                 'old' => (int) $row['created_at'], 'st' => (string) $row['status']]
            ) === 1;
            return ['state' => $won ? 'new' : 'running', 'k' => $k];
        }
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            return ['state' => 'conflict', 'k' => $k];
        }
        if ($row['status'] !== 'done') {
            return ['state' => 'running', 'k' => $k];
        }
        $headers = json_decode((string) ($row['response_headers'] ?? ''), true);
        $res = Response::make((string) ($row['response_body'] ?? ''), (int) $row['response_code']);
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            $res->header((string) $name, (string) $value);
        }
        $res->header('Idempotent-Replayed', 'true');
        return ['state' => 'replay', 'k' => $k, 'response' => $res];
    }

    /** Store the answer of a request begun with begin(); a 5xx releases the key instead. */
    public function complete(string $k, Response $response): void
    {
        $code = $response->getStatus();
        $body = $response->getBody();
        if ($code >= 500 || strlen($body) > self::MAX_BODY) {
            $this->releaseHash($k);
            return;
        }
        $keep = [];
        foreach ($response->getHeaders() as $name => $value) {
            if (in_array(strtolower((string) $name), self::REPLAY_HEADERS, true)) $keep[(string) $name] = (string) $value;
        }
        try {
            $this->db->execute(
                'UPDATE {idempotency_keys}
                    SET status = \'done\', response_code = :c, response_headers = :h, response_body = :b
                  WHERE k = :k',
                ['c' => $code, 'h' => json_encode($keep, JSON_UNESCAPED_SLASHES), 'b' => $body, 'k' => $k]
            );
        } catch (\Throwable) {
            // Not stored: a retry runs again, which is what happens without a key.
        }
    }

    /** Give a key back after a failure, so the same key can be retried. */
    public function releaseHash(string $k): void
    {
        try { $this->db->execute('DELETE FROM {idempotency_keys} WHERE k = :k', ['k' => $k]); } catch (\Throwable) {}
    }

    /**
     * Do $work at most once for this (scope, key) — for events that arrive
     * more than once, such as payment webhooks:
     *
     *     $done = $store->once('stripe', $event['id'], fn() => $orders->markPaid($id));
     *
     * Returns true when $work ran now, false when it had already run (or is
     * running). If $work throws, the key is released and the exception
     * rethrown, so the sender's retry is processed.
     */
    public function once(string $scope, string $key, callable $work): bool
    {
        $begin = $this->begin('once:' . $scope, $key, hash('sha256', 'once'));
        if ($begin['state'] !== 'new') return false;
        try {
            $work();
        } catch (\Throwable $e) {
            $this->releaseHash($begin['k']);
            throw $e;
        }
        $this->complete($begin['k'], Response::make('', 204));
        return true;
    }

    // ------------------------------------------------------------------

    private static function isDuplicate(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || $e->getCode() === '23000';
    }

    /** Create the table on first use where migration 017 has not run yet. */
    private function withTable(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1146 && $e->getCode() !== '42S02') throw $e;
            $this->db->execute(self::TABLE_DDL);
            $fn();
        }
    }

    /** About once in 200 claims, drop expired keys. */
    private function maybeSweep(int $now): void
    {
        if (random_int(1, 200) !== 1) return;
        try {
            $this->db->execute('DELETE FROM {idempotency_keys} WHERE expires_at <= :n LIMIT 500', ['n' => $now]);
        } catch (\Throwable) {}
    }
}
