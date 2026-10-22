<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Application;
use App\Core\AppPermissionMissing;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\CheckCapability;
use App\Services\AppMeta;
use App\Services\AppService;
use App\Services\AuthService;
use App\Services\PermissionBroker;

/**
 * What an app's screen shows when the app needs a permission it hasn't been
 * allowed (1.2.85), instead of an error page.
 *
 *  - An admin screen (/admin/…): the app's name and what it needs, with the
 *    permission window open. Someone who manages apps allows it and the
 *    screen reloads, working. Anyone else is told to ask an administrator.
 *  - A JSON or AJAX request: 403 APP_PERMISSION_MISSING with the same words.
 *  - A public page: null — core's error page answers, as before; visitors
 *    can't allow anything.
 *
 * Used by the Router (an app's route threw AppPermissionMissing), by
 * AppService when an app couldn't start for the same reason, and its words by
 * the AJAX gateway.
 */
final class AppPermissionPage
{
    public static function answer(Request $request, AppPermissionMissing $pm): ?Response
    {
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $path = $request->path();
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base)) ?: '/';

        if ($request->wantsJson() || str_starts_with($path, '/api/')) {
            return Response::json(['success' => false, 'error' => [
                'code' => 'APP_PERMISSION_MISSING', 'message' => self::sentence($pm),
                'app' => $pm->slug, 'permission' => $pm->permission,
            ]], 403);
        }
        if (!str_starts_with($path, '/admin/')) return null;

        try {
            $app = Application::getInstance();
            $user = $app->make(AuthService::class)->sessionUser();
            $apps = $app->make(AppService::class);
            $row = $apps->find($pm->slug) ?? ['name' => $pm->slug];
            $manifest = $apps->scan()[$pm->slug] ?? null;
            $meta = $manifest !== null ? AppMeta::from($manifest, $pm->slug, (string) ($manifest['_path'] ?? '')) : null;
            $perm = $app->make(PermissionBroker::class)->describe($pm->permission);
            return Response::view('apps.permission-needed', [
                'title'       => (string) ($meta['name'] ?? $row['name'] ?? $pm->slug),
                'currentUser' => $user,
                'slug'        => $pm->slug,
                'appName'     => (string) ($meta['name'] ?? $row['name'] ?? $pm->slug),
                'icon'        => $meta['icon'] ?? ['type' => 'none', 'value' => ''],
                'permission'  => $perm,
                'reason'      => $apps->consentContext($pm->slug)['reasons'][$pm->permission] ?? '',
                'canAllow'    => $user !== null && CheckCapability::userCan($user, 'manage_apps'),
            ], 403);
        } catch (\Throwable) {
            return null;   // the ordinary error page, rather than a second failure
        }
    }

    /** One sentence for a person: which app, what it needs, what to do. */
    public static function sentence(AppPermissionMissing $pm): string
    {
        $label = $pm->permission;
        $name = $pm->slug;
        try {
            $app = Application::getInstance();
            $label = (string) ($app->make(PermissionBroker::class)->describe($pm->permission)['label'] ?? $label);
            $name = (string) ($app->make(AppService::class)->find($pm->slug)['name'] ?? $name);
        } catch (\Throwable) {}
        return "“{$name}” needs the “{$label}” permission for this. Allow it under Apps → Permissions, then try again.";
    }
}
