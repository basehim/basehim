<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Application;
use App\Core\Session;

/**
 * core.csrf — the current session's CSRF token.
 *
 * The token rotates when someone signs in or out, so a page left open from
 * before that holds a stale one. The JavaScript client asks here once and
 * retries, instead of making the user reload. The token is only of use to
 * script on this site: the gateway sends no CORS headers, so another origin
 * can make the request but never read the answer, and cross-site requests
 * are refused outright (Sec-Fetch-Site / Origin).
 */
final class SessionActions
{
    public function csrf(AjaxContext $ctx): array
    {
        return [
            'token'     => Application::getInstance()->make(Session::class)->csrfToken(),
            'signed_in' => $ctx->userId() !== null,
        ];
    }
}
