<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\SchedulerService;

/**
 * ScheduleApi — recurring background work for apps.
 *
 * Register handlers inside boot(), which runs on every request, so the runner
 * always knows what a task key means:
 *
 *     public function boot(): void
 *     {
 *         $this->api()->schedule()->hourly('sync-feed', [$this, 'syncFeed']);
 *         $this->api()->schedule()->daily('cleanup', [$this, 'cleanup']);
 *     }
 *
 * Registration is cheap — it records the handler in memory and upserts one row
 * — so calling it on every request is fine and intended.
 *
 * Read SchedulerService for how tasks actually fire; the short version is that
 * they run after a response is sent, so nothing here blocks a page render.
 */
class ScheduleApi extends Resource
{
    /** Registering, running and inspecting tasks all need 'schedule'. */
    protected function permissionFor(string $operation): ?string
    {
        return 'schedule';
    }

    private function scheduler(): SchedulerService
    {
        return $this->make(SchedulerService::class);
    }

    /**
     * Run $handler no more often than every $seconds.
     *
     * @param int      $seconds Minimum gap between runs (60 is the floor).
     * @param string   $key     Unique within your app. Stable across requests.
     * @param callable $handler Receives no arguments; return value is ignored.
     *                          Throwing marks the run failed and is recorded.
     */
    public function every(int $seconds, string $key, callable $handler): bool
    {
        // The funnel for hourly/daily/weekly/everyMinutes, so one check covers
        // all of them. ScheduleApi builds no attempt() call of its own.
        if (!$this->permitted('every')) return false;
        return $this->scheduler()->register($this->slug, $key, max(60, $seconds), $handler);
    }

    public function hourly(string $key, callable $handler): bool
    {
        return $this->every(3600, $key, $handler);
    }

    public function daily(string $key, callable $handler): bool
    {
        return $this->every(86400, $key, $handler);
    }

    public function weekly(string $key, callable $handler): bool
    {
        return $this->every(604800, $key, $handler);
    }

    /** Every N minutes (minimum 1). */
    public function everyMinutes(int $minutes, string $key, callable $handler): bool
    {
        return $this->every(max(1, $minutes) * 60, $key, $handler);
    }

    /** Stop running a task and drop its row. */
    public function forget(string $key): bool
    {
        return $this->scheduler()->forget($this->slug, $key);
    }

    /** This app's tasks with their run history. */
    public function tasks(): array
    {
        return $this->scheduler()->tasksFor($this->slug);
    }

    /**
     * Run a task now, ignoring its schedule.
     *
     * Only works once the handler is registered, so call it after registration
     * in the same request — typically from an admin "run now" button.
     */
    public function runNow(string $key): array
    {
        if (!$this->permitted('runNow')) {
            return ['ok' => false, 'task' => $key, 'error' => "The 'schedule' permission is required."];
        }
        return $this->scheduler()->runTask($this->slug, $key, force: true);
    }
}
