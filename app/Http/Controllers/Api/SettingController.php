<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\SettingService;

class SettingController extends ApiController
{
    public function index(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user || !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        // Credentials (SMTP password, the update-service key, the site's
        // signing secret…) are never sent, as on MCP's get_settings. Until
        // 1.2.51 a settings:read API key read them all in plain text.
        return Response::json(['data' => SettingService::redact($settings->all())]);
    }

    public function publicSettings(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        return Response::json(['data' => $settings->publicSettings()]);
    }

    public function update(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user || !in_array($user['role'], ['super_admin', 'admin'], true)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);
        $body = $request->all();
        foreach ($body as $group => $values) {
            if (is_array($values)) {
                // GET returns credentials as "[redacted]"; sending one back
                // unchanged leaves the stored value alone.
                $settings->setMany($group, SettingService::withoutRedacted($values));
            }
        }
        return Response::json(['data' => SettingService::redact($settings->all())]);
    }
}
