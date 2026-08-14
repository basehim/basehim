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
        // Redacted: credentials in the email/integration groups must not be
        // readable over the API, whatever the caller's role.
        return Response::json(['data' => $settings->allRedacted()]);
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
        // A redacted value read back and re-submitted must not overwrite the
        // real credential with the placeholder.
        foreach ($body as $group => $values) {
            if (!is_array($values)) continue;
            foreach ($values as $k => $v) {
                if ($v === '[redacted]' && SettingService::isSecretKey((string) $k)) {
                    unset($body[$group][$k]);
                }
            }
        }
        foreach ($body as $group => $values) {
            if (is_array($values)) {
                $settings->setMany($group, $values);
            }
        }
        return Response::json(['data' => $settings->allRedacted()]);
    }
}
