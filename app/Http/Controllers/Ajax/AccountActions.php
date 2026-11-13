<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Application;
use App\Services\AppearanceService;

/**
 * profile.appearance (1.3.0) — save the signed-in person's light/dark mode
 * and accent colour on their account. The page changes at once, before this
 * answers; the answer is what was stored.
 */
final class AccountActions
{
    public function appearance(AjaxContext $ctx): array
    {
        $saved = Application::getInstance()->make(AppearanceService::class)
            ->save((int) $ctx->userId(), [
                'mode'   => $ctx->input('mode'),
                'accent' => $ctx->input('accent'),
            ]);
        return ['appearance' => $saved];
    }
}
