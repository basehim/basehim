<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Request;

/**
 * A per-visitor limit on public searches (1.2.76, CR-16).
 *
 * Search is the one public page whose cost the visitor chooses, so it is
 * limited: reading.search_rate_limit searches a minute per IP address
 * (default 30, 0 for no limit), shared by /search and /api/v1/search. If the
 * limiter's store cannot be reached the search goes ahead.
 */
final class SearchThrottle
{
    public const DEFAULT_PER_MINUTE = 30;

    /** @return int|null seconds to wait when over the limit, null when allowed */
    public static function check(Request $request, ?Application $app = null): ?int
    {
        $app ??= Application::getInstance();
        try {
            $max = (int) $app->make(SettingService::class)->get('reading', 'search_rate_limit', self::DEFAULT_PER_MINUTE);
            if ($max <= 0) return null;
            $hit = $app->make(RateLimiter::class)->hit('search', 'ip:' . $request->ip(), $max, 60);
            return $hit['allowed'] ? null : max(1, (int) $hit['retry_after']);
        } catch (\Throwable) {
            return null;
        }
    }
}
