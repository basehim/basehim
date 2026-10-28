<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * Counting limits for apps (1.2.90): anything the AJAX gateway's and site
 * routes' own `rate` options don't cover — per target, per account,
 * site-wide.
 *
 *     $r = $this->api()->rateLimit()->hit('exports', 'user:' . $id, 5, 3600);
 *     if (!$r['allowed']) { … try again in $r['retry_after'] seconds … }
 *
 * Counters are core's (atomic, in the database, shared by every PHP worker)
 * and namespaced by app, so two apps' buckets never meet. The client key is
 * the caller's: use Request::visitorIp() or AjaxContext::ip() for visitors,
 * never an address taken from a header.
 *
 * When the store cannot be reached, `error` is true and `allowed` follows
 * $failOpen; an app protecting someone else (outbound requests, mail) should
 * fail closed.
 */
class RateLimitApi extends Resource
{
    /**
     * Count one event against max per window.
     *
     * @return array{allowed:bool, count:int, remaining:int, retry_after:int, error:bool}
     */
    public function hit(string $bucket, string $client, int $max, int $perSeconds, bool $failOpen = false): array
    {
        $bucket = 'app:' . $this->slug . ':' . substr($bucket, 0, 100);
        try {
            $r = $this->make(\App\Services\RateLimiter::class)->hit($bucket, substr($client, 0, 200), max(1, $max), max(1, $perSeconds));
        } catch (\Throwable $e) {
            $this->log('Rate limit store unavailable: ' . $e->getMessage(), [], 'warning');
            $r = ['allowed' => true, 'count' => 0, 'remaining' => 0, 'retry_after' => 0, 'error' => true];
        }
        if (!empty($r['error'])) {
            $r['allowed'] = $failOpen;
            $r['retry_after'] = $failOpen ? 0 : 60;
        }
        return $r;
    }
}
