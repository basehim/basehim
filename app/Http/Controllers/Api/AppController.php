<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\CheckCapability;
use App\Services\AppService;

/**
 * Read-only REST view of installed apps.
 *
 * Deliberately read-only: activating, installing or deleting an app runs
 * arbitrary third-party lifecycle code, and that belongs behind an admin
 * session with CSRF, not a bearer token. Those actions stay at /admin/apps.
 */
class AppController extends ApiController
{
    /** GET /apps — installed apps. ?status=active|inactive */
    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $status = (string) $request->query('status', '');

        $rows = $status === 'active' ? $apps->active() : $apps->installed();
        if ($status === 'inactive') {
            $rows = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') !== 'active'));
        }

        $onDisk = $apps->scan();
        $data = array_map(function (array $row) use ($onDisk): array {
            $slug = (string) ($row['slug'] ?? '');
            $manifest = $onDisk[$slug] ?? null;
            $permissions = json_decode((string) ($row['permissions'] ?? ''), true);

            return [
                'slug'        => $slug,
                'name'        => $row['name'] ?? $slug,
                'version'     => $row['version'] ?? null,
                'author'      => $row['author'] ?? null,
                'vendor'      => $row['vendor'] ?? null,
                'description' => $row['description'] ?? null,
                'status'      => $row['status'] ?? 'inactive',
                'icon'        => $row['icon'] ?? null,
                'permissions' => is_array($permissions) ? $permissions : [],
                'files_present' => $manifest !== null,
                'legacy_folder' => (bool) ($manifest['_legacy'] ?? false),
            ];
        }, $rows);

        return Response::json(['data' => array_values($data)]);
    }

    /** GET /apps/{slug} */
    public function show(Request $request, string $slug): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var AppService $apps */
        $apps = $this->app->make(AppService::class);
        $row = $apps->find($slug);
        if (!$row) return Response::json(['error' => 'Not found'], 404);

        $permissions = json_decode((string) ($row['permissions'] ?? ''), true);
        $manifest = $apps->scan()[$slug] ?? null;

        return Response::json(['data' => array_merge($row, [
            'permissions'   => is_array($permissions) ? $permissions : [],
            'files_present' => $manifest !== null,
            'legacy_folder' => (bool) ($manifest['_legacy'] ?? false),
        ])]);
    }

    private function canManage(): bool
    {
        $user = $this->authUser();
        return $user !== null && CheckCapability::userCan($user, 'manage_apps');
    }

    private function denied(): Response
    {
        return Response::json(['error' => 'Requires the manage_apps capability.'], 403);
    }
}
