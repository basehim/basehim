<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Daily cleanup of rows and files that only grow (1.2.76, CR-24).
 *
 * Run by the core scheduled task "cleanup" once a day. Each table is cleared
 * in batches, so a site that has never been cleaned catches up over a few
 * days instead of locking a table for minutes.
 *
 * Kept for good: content, comments, users, media and settings. What goes:
 *   - sign-in material that can no longer be used: refresh tokens, remember
 *     tokens, MCP OAuth codes and tokens, password resets — 30 days after
 *     they expired;
 *   - sign-in attempt records untouched for 30 days and not locking anyone;
 *   - expired idempotency keys and rate-limit counters;
 *   - activity logs older than retention.activity_days (default 180);
 *   - notifications older than retention.notification_days (default 90);
 *   - log files in storage/logs older than retention.log_days (default 30);
 *   - session files nobody has used for a day (PHP's own collector is off
 *     on many Linux builds, gc_probability = 0).
 * A retention setting of 0 keeps that kind forever.
 */
final class RetentionService
{
    private const BATCH = 5000;
    private const MAX_BATCHES = 20;
    private const DEFAULT_DAYS = ['activity_days' => 180, 'notification_days' => 90, 'log_days' => 30];

    public function __construct(private Database $db, private SettingService $settings) {}

    /** @return array<string,int> what was removed, by kind */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $at = static fn(int $daysAgo): string => gmdate('Y-m-d H:i:s', $now - $daysAgo * 86400);
        $out = [];

        $expired30 = $at(30);
        $out['refresh_tokens'] = $this->purge('refresh_tokens', 'expires_at < :t', ['t' => $expired30]);
        $out['remember_tokens'] = $this->purge('auth_remember_tokens', 'expires_at < :t', ['t' => $expired30]);
        $out['password_resets'] = $this->purge('password_resets', 'expires_at < :t', ['t' => $expired30]);
        $out['oauth_codes'] = $this->purge('mcp_oauth_codes', 'expires_at < :t', ['t' => $expired30]);
        $out['oauth_tokens'] = $this->purge('mcp_oauth_tokens',
            'expires_at < :t AND (refresh_expires_at IS NULL OR refresh_expires_at < :t2)', ['t' => $expired30, 't2' => $expired30]);
        $out['login_attempts'] = $this->purge('auth_login_attempts',
            'updated_at < :t AND (locked_until IS NULL OR locked_until < :n)', ['t' => $expired30, 'n' => gmdate('Y-m-d H:i:s', $now)]);
        $out['idempotency_keys'] = $this->purge('idempotency_keys', 'expires_at <= :n', ['n' => $now]);
        $out['rate_limits'] = $this->purge('rate_limits', 'expires_at <= :n', ['n' => $now]);

        if (($d = $this->days('activity_days')) > 0) {
            $out['activity_log'] = $this->purge('activity_log', 'created_at < :t', ['t' => $at($d)]);
            $out['user_activity_log'] = $this->purge('user_activity_log', 'created_at < :t', ['t' => $at($d)]);
        }
        if (($d = $this->days('notification_days')) > 0) {
            $out['notifications'] = $this->purge('notifications', 'created_at < :t', ['t' => $at($d)]);
        }
        if (($d = $this->days('log_days')) > 0) {
            $out['log_files'] = $this->removeOldFiles(BASEHIM_ROOT . '/storage/logs', '/^basehim-\d{4}-\d{2}-\d{2}\.log$/', $now - $d * 86400);
        }
        $idle = max(86400, (int) ini_get('session.gc_maxlifetime'));
        $out['session_files'] = $this->removeOldFiles(BASEHIM_ROOT . '/storage/sessions', '/^sess_[A-Za-z0-9,-]+$/', $now - $idle);

        return array_filter($out);
    }

    private function days(string $key): int
    {
        $v = $this->settings->get('retention', $key, self::DEFAULT_DAYS[$key]);
        return max(0, (int) $v);
    }

    /** Delete matching rows in batches; a table that does not exist counts as clean. */
    private function purge(string $table, string $where, array $params): int
    {
        $total = 0;
        try {
            for ($i = 0; $i < self::MAX_BATCHES; $i++) {
                $n = $this->db->execute('DELETE FROM {' . $table . '} WHERE ' . $where . ' LIMIT ' . self::BATCH, $params);
                $total += $n;
                if ($n < self::BATCH) break;
            }
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code !== 1146 && $code !== 1054 && $e->getCode() !== '42S02') throw $e;   // missing table or column
        }
        return $total;
    }

    private function removeOldFiles(string $dir, string $namePattern, int $olderThan): int
    {
        if (!is_dir($dir)) return 0;
        $n = 0;
        $h = @opendir($dir);
        if ($h === false) return 0;
        while (($f = readdir($h)) !== false && $n < 100000) {
            if (!preg_match($namePattern, $f)) continue;
            $path = $dir . '/' . $f;
            $m = @filemtime($path);
            if ($m !== false && $m < $olderThan && is_file($path) && @unlink($path)) $n++;
        }
        closedir($h);
        return $n;
    }
}
