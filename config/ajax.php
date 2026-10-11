<?php

/**
 * AJAX gateway (POST /ajax) — see docs/APP-AJAX.md.
 *
 * Every value can be set in .env; the defaults suit a typical shared host.
 */

use App\Core\Env;

return [
    // Largest request body accepted, in bytes, before anything is parsed.
    // An action may ask for less or more ('max_body'), up to 1 MB.
    'max_body' => (int) (Env::get('AJAX_MAX_BODY', 65536) ?: 65536),

    // Requests per client (signed-in user, else IP address) across all
    // actions, per minute. Checked before the body is read.
    'global_limit' => (int) (Env::get('AJAX_RATE_LIMIT', 300) ?: 300),

    // Per-action limits used when an action does not declare its own:
    // [requests, seconds]. Public actions are counted per IP address.
    'default_limit_user'   => [120, 60],
    'default_limit_public' => [30, 60],

    // When the rate-limit table cannot be reached: 'open' lets the request
    // through (and logs a warning), 'closed' answers 503. An action can
    // insist on 'closed' for itself with 'rate_limit' => [..., 'fail' => 'closed'].
    'rate_limit_fail' => (string) (Env::get('AJAX_RATE_LIMIT_FAIL', 'open') ?: 'open'),

    // Proxies whose X-Forwarded-For header is believed, comma-separated IPs
    // or CIDR ranges (e.g. "10.0.0.0/8, 173.245.48.0/20"). Empty: the
    // connecting address is the client, and forwarded headers are ignored —
    // otherwise any visitor could pick their own IP and dodge a limit.
    'trusted_proxies' => (string) (Env::get('TRUSTED_PROXIES', '') ?? ''),
];
