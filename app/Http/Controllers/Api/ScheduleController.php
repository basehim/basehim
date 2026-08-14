<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\CheckCapability;
use App\Services\SchedulerService;

/**
 * Scheduled task inspection, plus the cron entry point.
 *
 * /schedule/run is intentionally OUTSIDE the authenticated group: a crontab
 * sends no cookies and holds no JWT. It is guarded by an unguessable token
 * instead, compared in constant time. Everything else here needs a session.
 */
class ScheduleController extends ApiController
{
    /**
     * GET /schedule/run?token=… — run everything due.
     *
     * Point a crontab at this for punctual tasks:
     *   * * * * * curl -s "https://example.com/api/v1/schedule/run?token=…"
     *
     * The throttle that paces pseudo-cron is bypassed here, since the crontab
     * is already the pacing mechanism.
     */
    public function run(Request $request): Response
    {
        /** @var SchedulerService $scheduler */
        $scheduler = $this->app->make(SchedulerService::class);

        $token = (string) ($request->query('token', '') ?: $request->input('token', ''));
        if (!$scheduler->verifyCronToken($token)) {
            // 404 rather than 403: an unauthenticated caller learns nothing
            // about whether this endpoint exists.
            return Response::json(['error' => 'Not found'], 404);
        }

        $result = $scheduler->runDue(ignoreThrottle: true);
        return Response::json(['ok' => true] + $result);
    }

    /** GET /schedule — every task and its run history. */
    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var SchedulerService $scheduler */
        $scheduler = $this->app->make(SchedulerService::class);

        return Response::json([
            'data' => $scheduler->allTasks(),
            'meta' => [
                'last_sweep' => $scheduler->lastSweep(),
                'cron_url'   => $this->cronUrl($scheduler),
            ],
        ]);
    }

    /**
     * POST /schedule/{app}/{key}/run — run one task immediately.
     *
     * Only works when the owning app is active: handlers are registered during
     * boot(), so a deactivated app has nothing to invoke.
     */
    public function runTask(Request $request, string $app, string $key): Response
    {
        if (!$this->canManage()) return $this->denied();

        $result = $this->app->make(SchedulerService::class)->runTask($app, $key, force: true);
        return Response::json($result, !empty($result['ok']) ? 200 : 422);
    }

    private function cronUrl(SchedulerService $scheduler): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return $scheme . '://' . $host . $base
             . '/api/v1/schedule/run?token=' . $scheduler->cronToken();
    }

    private function canManage(): bool
    {
        $user = $this->authUser();
        return $user !== null && CheckCapability::userCan($user, 'manage_settings');
    }

    private function denied(): Response
    {
        return Response::json(['error' => 'Requires the manage_settings capability.'], 403);
    }
}
