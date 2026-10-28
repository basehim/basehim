<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\SecurityHeaders;

/**
 * POST /csp-report — browsers send Content-Security-Policy violation
 * reports here (1.2.90). Grouped and counted in
 * storage/security/csp-reports.json, shown under Settings → Security.
 *
 * Browsers send these without cookies or a CSRF token; nothing here acts
 * for a user. Each visitor may send 30 a minute, a body is at most 16 KB,
 * and only reports in the CSP format are kept.
 */
class CspReportController extends Controller
{
    public const MAX_BODY = 16384;

    public function store(Request $request): Response
    {
        $type = strtolower((string) ($request->server['CONTENT_TYPE'] ?? ''));
        if (!str_contains($type, 'csp-report') && !str_contains($type, 'json')) {
            return Response::make('', 415);
        }
        try {
            $hit = $this->app->make(\App\Services\RateLimiter::class)->hit('csp-report', 'ip:' . $request->visitorIp(), 30, 60);
            if (empty($hit['error']) && !$hit['allowed']) return Response::make('', 429)->header('Retry-After', (string) $hit['retry_after']);
        } catch (\Throwable) {
            // Without the limiter, still accept (the store is capped).
        }
        $raw = (string) @file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($raw === '' || strlen($raw) > self::MAX_BODY) return Response::make('', 413);
        SecurityHeaders::record($raw);
        return Response::make('', 204);
    }
}
