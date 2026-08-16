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
        return Response::json(['data' => $settings->all()]);
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
                $settings->setMany($group, $values);
            }
        }
        return Response::json(['data' => $settings->all()]);
    }
}
