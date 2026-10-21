<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * SchedulerService — recurring background work without a daemon.
 *
 * Basehim targets cPanel shared hosting: no queue worker, no supervisor, and
 * often no reliable crontab. So tasks are driven two ways, and both funnel
 * through runDue():
 *
 *   1. Pseudo-cron (default). After a normal page response has been sent to
 *      the browser, due tasks run in the same PHP process. The visitor has
 *      already got their bytes, so this adds nothing to perceived load time.
 *   2. Real cron (optional, better). Hit /api/v1/schedule/run?token=… from a
 *      crontab. Tasks then fire on time even with no traffic, which pseudo-cron
 *      cannot promise.
 *
 * Pseudo-cron's honest limitation: a site with no visitors runs nothing. A task
 * asking for hourly on a quiet site gets "hourly, whenever someone next shows
 * up". Anything needing punctuality wants the real cron URL.
 *
 * Concurrency: an exclusive flock over one file. Two simultaneous requests
 * cannot both run the queue, so a slow task can't be started twice and stack
 * up. The lock is non-blocking — a request that can't get it just moves on
 * rather than waiting.
 *
 * Handlers live in memory only. An app registers them in boot(), which runs on
 * every request, so the runner always has them. A task row whose handler is not
 * registered this request (the app was deactivated, say) is skipped, not
 * failed — the row stays so the task resumes if the app comes back.
 */
class SchedulerService
{
    /** Minimum gap between pseudo-cron sweeps, seconds. */
    private const SWEEP_INTERVAL = 60;

    /** Tasks run in a single sweep, so one request can't run away. */
    private const MAX_PER_SWEEP = 5;

    /** A task exceeding this is logged as a slow task. */
    private const SLOW_TASK_SECONDS = 10;

    /**
     * How long a started run holds its task (1.2.75). A run killed by the
     * time or memory limit never records its end; once the lease expires the
     * task is due again, and that is counted as a failed attempt.
     */
    public const LEASE_SECONDS = 900;

    /** Longest wait between retries of a failing task. */
    public const MAX_BACKOFF_SECONDS = 86400;

    /** slug => [key => callable] */
    private array $handlers = [];

    /** Set once the shutdown hook is installed. */
    private bool $sweepArmed = false;

    /** @var array<string, array{0:int,1:callable}> core tasks, registered when a sweep runs */
    private array $coreTasks = [];

    /** Whether scheduled_tasks has the lease columns (migration 016); null until checked. */
    private ?bool $leases = null;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private Database $db,
        private SettingService $settings,
        private Logger $logger
    ) {
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    /**
     * Record a handler and make sure the task has a row.
     *
     * Called on every request from an app's boot(), so it must stay cheap: the
     * row is only touched when the interval actually changed.
     */
    public function register(string $slug, string $key, int $intervalSeconds, callable $handler): bool
    {
        $key = $this->sanitizeKey($key);
        if ($key === '') return false;

        $this->handlers[$slug][$key] = $handler;

        try {
            $row = $this->find($slug, $key);
            if ($row === null) {
                $this->db->insert('scheduled_tasks', [
                    'app_slug'         => $slug,
                    'task_key'         => $key,
                    'interval_seconds' => $intervalSeconds,
                    // Stagger first runs by one interval so activating an app
                    // doesn't fire everything it owns on the very next request.
                    'next_run_at'      => date('Y-m-d H:i:s', time() + $intervalSeconds),
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
            } elseif ((int) $row['interval_seconds'] !== $intervalSeconds) {
                $this->db->update('scheduled_tasks', [
                    'interval_seconds' => $intervalSeconds,
                ], ['id' => (int) $row['id']]);
            }
            $this->armSweep();
            return true;
        } catch (\Throwable $e) {
            // A missing table (migration not yet run) must not break boot().
            $this->quietLog('Scheduler register failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * A core maintenance task (1.2.76): view counts, retention cleanup.
     *
     * Unlike register(), nothing is read or written now. The task row is
     * created when a sweep actually runs, so core's tasks cost ordinary
     * requests nothing; they are listed on Admin → System under "core" and
     * get the same leases, backoff and failure reports as app tasks.
     */
    public function core(string $key, int $intervalSeconds, callable $handler): void
    {
        $key = $this->sanitizeKey($key);
        if ($key === '') return;
        $this->coreTasks[$key] = [$intervalSeconds, $handler];
        $this->armSweep();
    }

    private function registerCoreTasks(): void
    {
        foreach ($this->coreTasks as $key => [$interval, $handler]) {
            if (!isset($this->handlers['core'][$key])) $this->register('core', $key, $interval, $handler);
        }
    }

    /** Drop a task and stop running it. */
    public function forget(string $slug, string $key): bool
    {
        $key = $this->sanitizeKey($key);
        unset($this->handlers[$slug][$key]);
        try {
            $this->db->delete('scheduled_tasks', ['app_slug' => $slug, 'task_key' => $key]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Every task belonging to one app. */
    public function tasksFor(string $slug): array
    {
        try {
            return $this->db->select(
                'SELECT * FROM {scheduled_tasks} WHERE app_slug = :s ORDER BY task_key',
                ['s' => $slug]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Every task on the site. */
    public function allTasks(): array
    {
        try {
            return $this->db->select('SELECT * FROM {scheduled_tasks} ORDER BY app_slug, task_key');
        } catch (\Throwable) {
            return [];
        }
    }

    private function find(string $slug, string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM {scheduled_tasks} WHERE app_slug = :s AND task_key = :k',
            ['s' => $slug, 'k' => $key]
        );
    }

    // ------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------

    /**
     * Install the post-response sweep, at most once per request.
     *
     * fastcgi_finish_request() flushes the response and lets PHP keep working,
     * which is what makes this invisible to the visitor. Where it doesn't exist
     * (mod_php, CLI) the shutdown function still runs, just before the
     * connection closes — correct either way, only less elegant.
     */
    private function armSweep(): void
    {
        if ($this->sweepArmed) return;
        $this->sweepArmed = true;

        register_shutdown_function(function (): void {
            try {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                $this->runDue();
            } catch (\Throwable $e) {
                $this->quietLog('Scheduler sweep failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Run every task that is due.
     *
     * @param bool $ignoreThrottle True for the real-cron endpoint, which is
     *                             already externally paced and shouldn't be
     *                             skipped because a page view swept recently.
     * @return array{ran:int, skipped:int, failed:int, tasks:array}
     */
    public function runDue(bool $ignoreThrottle = false): array
    {
        $result = ['ran' => 0, 'skipped' => 0, 'failed' => 0, 'tasks' => []];

        if (!$ignoreThrottle && !$this->throttlePassed()) {
            return $result;
        }
        if (!$this->acquireLock()) {
            return $result;
        }

        try {
            $this->registerCoreTasks();
            $now = date('Y-m-d H:i:s');
            // Only tasks whose handler is registered this request (1.2.73,
            // BH-023). Rows of an app that is switched off, quarantined or
            // uninstalled stay due and untouched; selected first by age, five
            // of them used to fill every sweep and no other task ever ran.
            [$owned, $params] = $this->registeredFilter();
            if ($owned === null) {
                return $result;
            }
            $params['now'] = $now;
            $leaseFree = '';
            if ($this->hasLeases()) {
                // A run still holding its lease is not due again (1.2.75).
                $leaseFree = ' AND (locked_until IS NULL OR locked_until <= :lnow)';
                $params['lnow'] = $now;
            }
            $due = $this->db->select(
                'SELECT * FROM {scheduled_tasks}
                  WHERE next_run_at <= :now' . $leaseFree . ' AND (' . $owned . ')
               ORDER BY next_run_at ASC
                  LIMIT ' . self::MAX_PER_SWEEP,
                $params
            );

            foreach ($due as $task) {
                $slug = (string) $task['app_slug'];
                $key  = (string) $task['task_key'];

                if (!isset($this->handlers[$slug][$key])) {
                    // The owning app isn't booted this request. Leave the row
                    // untouched so it runs when the app is next active.
                    $result['skipped']++;
                    continue;
                }

                $outcome = $this->execute($task, $this->handlers[$slug][$key]);
                if (!empty($outcome['claimed_elsewhere'])) {
                    $result['skipped']++;
                    continue;
                }
                $result['tasks'][] = $outcome;
                $outcome['ok'] ? $result['ran']++ : $result['failed']++;
            }

            $this->recordSweep();
        } catch (\Throwable $e) {
            $this->quietLog('Scheduler runDue failed: ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * SQL matching the (app, task) pairs that have a handler this request,
     * with its parameters; [null, []] when there are none.
     *
     * @return array{0: ?string, 1: array<string,string>}
     */
    private function registeredFilter(): array
    {
        $clauses = [];
        $params = [];
        $i = 0;
        foreach ($this->handlers as $slug => $tasks) {
            if (!is_array($tasks) || $tasks === []) continue;
            $keys = [];
            foreach (array_keys($tasks) as $key) {
                $keys[] = ':k' . $i;
                $params['k' . $i++] = (string) $key;
            }
            $params['s' . $i] = (string) $slug;
            $clauses[] = '(app_slug = :s' . $i++ . ' AND task_key IN (' . implode(', ', $keys) . '))';
        }
        return $clauses === [] ? [null, []] : [implode(' OR ', $clauses), $params];
    }

    /** Remove every task row of an app (uninstall). Returns rows removed. */
    public function forgetApp(string $slug): int
    {
        unset($this->handlers[$slug]);
        try {
            return $this->db->execute('DELETE FROM {scheduled_tasks} WHERE app_slug = :s', ['s' => $slug]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Run one task by name.
     *
     * @param bool $force Skip the due check — the "run now" button.
     */
    public function runTask(string $slug, string $key, bool $force = false): array
    {
        $key = $this->sanitizeKey($key);
        if ($slug === 'core') $this->registerCoreTasks();
        $task = $this->find($slug, $key);
        if ($task === null) {
            return ['ok' => false, 'task' => $key, 'error' => 'No such task.'];
        }
        if (!isset($this->handlers[$slug][$key])) {
            return ['ok' => false, 'task' => $key, 'error' => 'The task handler is not registered — is the app active?'];
        }
        if (!$force && strtotime((string) $task['next_run_at']) > time()) {
            return ['ok' => false, 'task' => $key, 'error' => 'Not due yet.'];
        }
        $out = $this->execute($task, $this->handlers[$slug][$key]);
        if (!empty($out['claimed_elsewhere'])) {
            return ['ok' => false, 'task' => $key, 'error' => 'This task is running right now. Try again when it has finished.'];
        }
        return $out;
    }

    /**
     * Invoke a handler and record the outcome.
     *
     * Since 1.2.75 (CR-12) a run first claims its task with one conditional
     * UPDATE that takes a lease (locked_until) and counts the attempt, so two
     * runners (two servers, or a cron call during a page's sweep) cannot both
     * start it. The outcome then decides the next run:
     *
     *  - success: next_run_at = now + interval, attempts back to 0;
     *  - a throw: retried after interval × 2^(failures-1), at most a day,
     *    never sooner than the interval. A dead remote API is not hammered
     *    on every sweep, and is never given up on silently;
     *  - no outcome at all (the run was killed by the time or memory limit):
     *    the lease expires, the task is due again, and the next claim records
     *    the lost run as a failure.
     *
     * A task does its work at least once, possibly more after a killed run:
     * handlers must be safe to repeat (check what is already done first).
     */
    private function execute(array $task, callable $handler): array
    {
        $id   = (int) $task['id'];
        $slug = (string) $task['app_slug'];
        $key  = (string) $task['task_key'];
        $interval = max(60, (int) $task['interval_seconds']);
        $leases = $this->hasLeases();

        if ($leases) {
            $now = date('Y-m-d H:i:s');
            try {
                $claimed = $this->db->execute(
                    'UPDATE {scheduled_tasks}
                        SET locked_until = :until, attempts = attempts + 1
                      WHERE id = :id AND (locked_until IS NULL OR locked_until <= :now)',
                    ['until' => date('Y-m-d H:i:s', time() + self::LEASE_SECONDS), 'id' => $id, 'now' => $now]
                ) === 1;
            } catch (\Throwable $e) {
                $this->quietLog('Scheduler could not claim a task: ' . $e->getMessage());
                $claimed = false;
            }
            if (!$claimed) {
                return ['ok' => false, 'app' => $slug, 'task' => $key, 'duration' => 0.0,
                        'message' => 'Already running elsewhere.', 'claimed_elsewhere' => true];
            }
            // The previous claim never recorded an end: that run was killed.
            if (!empty($task['locked_until'])) {
                $this->quietLog("[app:{$slug}] scheduled task '{$key}' did not finish last time "
                    . '(stopped by the time or memory limit?); running it again.', 'error');
            }
        }

        $started = microtime(true);
        $ok = true;
        $message = 'ok';

        try {
            $handler();
        } catch (\Throwable $e) {
            $ok = false;
            $message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $this->quietLog("[app:{$slug}] scheduled task '{$key}' failed: {$message}", 'error');
        }

        $elapsed = round(microtime(true) - $started, 3);
        if ($elapsed > self::SLOW_TASK_SECONDS) {
            $this->quietLog(
                "[app:{$slug}] scheduled task '{$key}' took {$elapsed}s — consider splitting it up",
                'warning'
            );
        }

        $failures = $ok ? 0 : max(1, (int) ($task['attempts'] ?? 0) + 1);
        $delay = $ok ? $interval : self::backoff($interval, $failures);

        try {
            $sets = 'last_run_at = :now, next_run_at = :next, last_status = :status, last_output = :output,
                     last_duration = :duration, runs = runs + 1, failures = failures + :failed';
            if ($leases) {
                $sets .= ', locked_until = NULL' . ($ok ? ', attempts = 0' : '');
            }
            $this->db->execute(
                "UPDATE {scheduled_tasks} SET {$sets} WHERE id = :id",
                [
                    'now'      => date('Y-m-d H:i:s'),
                    'next'     => date('Y-m-d H:i:s', time() + $delay),
                    'status'   => $ok ? 'ok' : 'error',
                    'output'   => mb_substr($message, 0, 500),
                    'duration' => $elapsed,
                    'failed'   => $ok ? 0 : 1,
                    'id'       => $id,
                ]
            );
        } catch (\Throwable $e) {
            $this->quietLog('Scheduler could not record a run: ' . $e->getMessage());
        }

        return [
            'ok'       => $ok,
            'app'      => $slug,
            'task'     => $key,
            'duration' => $elapsed,
            'message'  => $ok ? 'ok' : $message,
            'retry_in' => $ok ? null : $delay,
        ];
    }

    /** Seconds before retrying a task that has failed $failures times in a row. */
    public static function backoff(int $interval, int $failures): int
    {
        $interval = max(60, $interval);
        $exp = min(20, max(0, $failures - 1));
        return (int) min(max($interval, self::MAX_BACKOFF_SECONDS), $interval * (2 ** $exp));
    }

    /** Whether migration 016's lease columns exist (checked once per request). */
    private function hasLeases(): bool
    {
        if ($this->leases !== null) return $this->leases;
        try {
            $this->leases = $this->db->selectOne("SHOW COLUMNS FROM {scheduled_tasks} LIKE 'locked_until'") !== null;
        } catch (\Throwable) {
            $this->leases = false;
        }
        return $this->leases;
    }

    /**
     * Tasks that are failing now: their last run threw, or a run did not
     * finish. For the System page (1.2.75).
     *
     * @return array<int, array<string, mixed>>
     */
    public function failingTasks(): array
    {
        try {
            $leases = $this->hasLeases();
            $sql = "SELECT * FROM {scheduled_tasks} WHERE last_status = 'error'"
                 . ($leases ? " OR (locked_until IS NOT NULL AND locked_until <= :now)" : '')
                 . ' ORDER BY next_run_at LIMIT 50';
            return $this->db->select($sql, $leases ? ['now' => date('Y-m-d H:i:s')] : []);
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Throttle, lock, cron token
    // ------------------------------------------------------------------

    /** True when the last sweep was long enough ago. */
    private function throttlePassed(): bool
    {
        try {
            $last = $this->lastSweep();
            if ($last === '') return true;
            return (time() - (int) strtotime($last)) >= self::SWEEP_INTERVAL;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Take an exclusive, non-blocking lock.
     *
     * LOCK_NB matters: a blocking wait would pile requests up behind a slow
     * task, which on shared hosting exhausts the process pool and takes the
     * site down. Losing the race is fine — the winner is already doing the work.
     */
    private function acquireLock(): bool
    {
        // storage/locks, not storage/cache (1.2.73): clearing the cache
        // deleted a held lock file, and a second run could then start.
        $dir = BASEHIM_ROOT . '/storage/locks';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

        $handle = @fopen($dir . '/scheduler.lock', 'c');
        if ($handle === false) return false;

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle === null) return;
        @flock($this->lockHandle, LOCK_UN);
        @fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    /**
     * The token guarding the real-cron endpoint, generated on first read.
     *
     * The endpoint must be reachable without a session — a crontab has no
     * cookies — so an unguessable token is what stands between the URL and
     * anyone who can send an HTTP request.
     */
    public function cronToken(): string
    {
        try {
            $token = (string) $this->settings->get('scheduler', 'cron_token', '');
            if ($token === '') {
                $token = bin2hex(random_bytes(16));
                $this->settings->set('scheduler', 'cron_token', $token);
            }
            return $token;
        } catch (\Throwable) {
            return '';
        }
    }

    /** Constant-time comparison, so the token can't be discovered by timing. */
    public function verifyCronToken(string $candidate): bool
    {
        $expected = $this->cronToken();
        return $expected !== '' && hash_equals($expected, $candidate);
    }

    /**
     * When the last sweep finished. Kept in storage/locks/scheduler-last-sweep
     * since 1.2.76 (CR-22): as a setting, writing it once a minute threw away
     * the whole settings cache every minute. The setting is still read once,
     * for the first sweep after updating.
     */
    public function lastSweep(): string
    {
        $file = self::sweepFile();
        $v = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if ($v !== '') return $v;
        try {
            return (string) $this->settings->get('scheduler', 'last_sweep', '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function recordSweep(): void
    {
        $file = self::sweepFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $first = !is_file($file);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, date('Y-m-d H:i:s')) !== false && @rename($tmp, $file)) {
            // The old setting is no longer read once the file exists.
            if ($first) { try { $this->settings->delete('scheduler', 'last_sweep'); } catch (\Throwable) {} }
            return;
        }
        @unlink($tmp);
    }

    private static function sweepFile(): string
    {
        return BASEHIM_ROOT . '/storage/locks/scheduler-last-sweep';
    }

    private function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9._\-]/', '-', $key) ?? '';
        return mb_substr(trim($key, '-'), 0, 100);
    }

    private function quietLog(string $message, string $level = 'warning'): void
    {
        try {
            $this->logger->log($level, $message);
        } catch (\Throwable) {
        }
    }
}
