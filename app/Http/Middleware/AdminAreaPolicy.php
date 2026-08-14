<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * AdminAreaPolicy — role-based access control for the admin panel.
 *
 * Runs right after Authenticate on every admin route and maps the request
 * path to a required capability (config/capabilities.php defines which roles
 * hold which capabilities). Longest-prefix wins, so /admin/settings/email
 * matches the /admin/settings rule.
 *
 * This gives area-level enforcement in one auditable place; finer-grained
 * rules (e.g. authors editing only their own posts) live in the controllers.
 * Apps can adjust the map via the `admin.area_policy` filter.
 */
final class AdminAreaPolicy
{
    /** path prefix (after the install base) => required capability */
    private const MAP = [
        '/admin/users'      => 'manage_users',
        '/admin/roles'      => 'manage_users',
        // App-registered content types. They map onto the post capability
        // family by default (see PostTypeRegistry), so this is the right gate
        // for the area; ContentController still enforces per-action caps.
        '/admin/content'    => 'edit_posts',
        '/admin/apps'       => 'manage_apps',
        '/admin/apps/marketplace' => 'manage_apps',
        '/admin/widgets'    => 'manage_apps',
        // Legacy paths still resolve (they redirect to /admin/apps), so they
        // are gated identically rather than left unguarded.
        '/admin/themes'     => 'manage_themes',
        '/admin/themes/marketplace' => 'manage_themes',
        '/admin/settings'   => 'manage_settings',
        '/admin/system'     => 'manage_settings',
        '/admin/updates'    => 'manage_settings',
        '/admin/api'        => 'manage_options',
        '/admin/menus'      => 'manage_menus',
        '/admin/taxonomies' => 'manage_taxonomies',
        '/admin/comments'   => 'moderate_comments',
        '/admin/media'      => 'upload_media',
        '/admin/posts'      => 'edit_posts',
        '/admin/templates'  => 'edit_posts',
        '/admin/pages'      => 'edit_pages',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $app = Application::getInstance();
        $user = $app->has('auth.user') ? $app->make('auth.user') : null;
        if (!$user) {
            return $next($request); // Authenticate handles unauthenticated users.
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (defined('BASEHIM_BASE') && BASEHIM_BASE !== '' && str_starts_with($path, (string) BASEHIM_BASE)) {
            $path = substr($path, strlen((string) BASEHIM_BASE)) ?: '/';
        }

        $map = self::MAP;
        try {
            $hooks = $app->make(\App\Core\HookRegistry::class);
            $filtered = $hooks->applyFilters('admin.area_policy', $map);
            if (is_array($filtered)) $map = $filtered;
        } catch (\Throwable) {}

        // Longest matching prefix decides the required capability.
        $required = null;
        $bestLen = 0;
        foreach ($map as $prefix => $cap) {
            if (str_starts_with($path, $prefix) && strlen($prefix) > $bestLen) {
                $required = $cap;
                $bestLen = strlen($prefix);
            }
        }

        // App admin areas: if the path matches an app's registered menu URL,
        // require that app's access capability (unless a longer core rule won).
        try {
            $ac = $app->make(\App\Services\AccessControl::class);
            foreach ($ac->appMenuUrls() as $slug => $url) {
                if ($url !== '' && str_starts_with($path, $url) && strlen($url) > $bestLen) {
                    $required = \App\Services\AccessControl::appCap((string) $slug);
                    $bestLen = strlen($url);
                }
            }
        } catch (\Throwable) {}

        if ($required === null || CheckCapability::userCan($user, $required)) {
            return $next($request);
        }

        // AJAX/JSON callers get a problem document; humans get a friendly page.
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $xrw = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        if (str_contains($accept, 'application/json') || strtolower($xrw) === 'xmlhttprequest') {
            return Response::json([
                'type'   => 'https://basehim.io/errors/forbidden',
                'title'  => 'Forbidden',
                'status' => 403,
                'detail' => "Your role does not include the '{$required}' capability.",
            ], 403);
        }

        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $html = '<!doctype html><html><head><title>403 — Access denied</title></head>'
            . '<body style="font-family:system-ui,Arial,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">'
            . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:36px 42px;text-align:center;max-width:420px;">'
            . '<div style="font-size:40px;margin-bottom:10px;">&#128274;</div>'
            . '<h1 style="font-size:19px;color:#0f172a;margin:0 0 8px;">Access denied</h1>'
            . '<p style="font-size:14px;color:#64748b;margin:0 0 20px;">Your account role does not have permission for this area'
            . ' (requires <code style="background:#f1f5f9;padding:2px 6px;border-radius:5px;">' . htmlspecialchars($required) . '</code>).'
            . ' Contact an administrator if you believe this is a mistake.</p>'
            . '<a href="' . $base . '/admin/dashboard" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;'
            . 'padding:9px 20px;border-radius:9px;font-size:14px;font-weight:600;">Back to Dashboard</a>'
            . '</div></body></html>';
        return Response::html($html, 403);
    }
}
