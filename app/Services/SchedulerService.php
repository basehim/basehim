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

    /** slug => [key => callable] */
    private array $handlers = [];

    /** Set once the shutdown hook is installed. */
    private bool $sweepArmed = false;

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
            $now = date('Y-m-d H:i:s');
            $due = $this->db->select(
                'SELECT * FROM {scheduled_tasks}
                  WHERE next_run_at <= :now
               ORDER BY next_run_at ASC
                  LIMIT ' . self::MAX_PER_SWEEP,
                ['now' => $now]
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
                $result['tasks'][] = $outcome;
                $outcome['ok'] ? $result['ran']++ : $result['failed']++;
            }

            $this->settings->set('scheduler', 'last_sweep', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            $this->quietLog('Scheduler runDue failed: ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * Run one task by name.
     *
     * @param bool $force Skip the due check — the "run now" button.
     */
    public function runTask(string $slug, string $key, bool $force = false): array
    {
        $key = $this->sanitizeKey($key);
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
        return $this->execute($task, $this->handlers[$slug][$key]);
    }

    /**
     * Invoke a handler and record the outcome.
     *
     * next_run_at advances whether the task succeeded or threw. A handler that
     * fails every time — a dead remote API, say — would otherwise stay due and
     * be retried on every single sweep forever.
     */
    private function execute(array $task, callable $handler): array
    {
        $id   = (int) $task['id'];
        $slug = (string) $task['app_slug'];
        $key  = (string) $task['task_key'];
        $interval = max(60, (int) $task['interval_seconds']);

        $started = microtime(true);
        $ok = true;
        $message = 'ok';

        try {
            $handler();
        } catch (\Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
            $this->quietLog("[app:{$slug}] scheduled task '{$key}' failed: {$message}", 'error');
        }

        $elapsed = round(microtime(true) - $started, 3);
        if ($elapsed > self::SLOW_TASK_SECONDS) {
            $this->quietLog(
                "[app:{$slug}] scheduled task '{$key}' took {$elapsed}s — consider splitting it up",
                'warning'
            );
        }

        try {
            $this->db->execute(
                'UPDATE {scheduled_tasks}
                    SET last_run_at = :now,
                        next_run_at = :next,
                        last_status = :status,
                        last_output = :output,
                        last_duration = :duration,
                        runs = runs + 1,
                        failures = failures + :failed
                  WHERE id = :id',
                [
                    'now'      => date('Y-m-d H:i:s'),
                    'next'     => date('Y-m-d H:i:s', time() + $interval),
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
        ];
    }

    // ------------------------------------------------------------------
    // Throttle, lock, cron token
    // ------------------------------------------------------------------

    /** True when the last sweep was long enough ago. */
    private function throttlePassed(): bool
    {
        try {
            $last = (string) $this->settings->get('scheduler', 'last_sweep', '');
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
        $dir = BASEHIM_ROOT . '/storage/cache';
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

    public function lastSweep(): string
    {
        try {
            return (string) $this->settings->get('scheduler', 'last_sweep', '');
        } catch (\Throwable) {
            return '';
        }
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
