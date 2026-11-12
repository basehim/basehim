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
        // Unified apps + themes marketplace. Gated on manage_apps (the heavier
        // capability, since the page can install apps); the theme-install
        // endpoint keeps its own manage_themes gate under /admin/themes.
        '/admin/marketplace' => 'manage_apps',
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
        // Until 1.2.47 these three had no rule, so any signed-in user (an
        // author, a contributor) could change the theme settings and custom
        // CSS, rename or delete categories and tags, and delete menu items.
        // tests/Unit/AdminAreaPolicyTest.php now fails for any unmapped route.
        '/admin/customize'  => 'manage_themes',
        '/admin/terms'      => 'manage_taxonomies',
        '/admin/menu-items' => 'manage_menus',
    ];

    /**
     * Paths every signed-in user may open: their own profile and session,
     * the dashboard. Listed so the route audit can tell "open on purpose"
     * from "forgotten".
     */
    public const OPEN = ['/admin', '/admin/dashboard', '/admin/profile', '/admin/logout'];

    /**
     * The capability an admin path requires, or null when none: the core map
     * (after the admin.area_policy filter), longest prefix first, then the
     * menu URLs apps registered. The AJAX gateway asks the same question for
     * actions that stand in for an admin route ('area' => '/admin/media'), so
     * a site that changes a rule with the filter changes it for both.
     */
    public static function requiredFor(string $path): ?string
    {
        $app = Application::getInstance();
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
            if (str_starts_with($path, (string) $prefix) && strlen((string) $prefix) > $bestLen) {
                $required = $cap;
                $bestLen = strlen((string) $prefix);
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

        return is_string($required) && $required !== '' ? $required : null;
    }

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

        $required = self::requiredFor($path);

        // An app's admin route that no rule covers — a path outside the app's
        // menu URL, or an app with no menu — still needs access to that app.
        // Without this fallback such routes were open to every signed-in user.
        if ($required === null && str_starts_with($path, '/admin/') && !in_array($path, self::OPEN, true)) {
            try {
                $owner = $app->make(\App\Core\Router::class)->currentOwner();
                if ($owner !== null) $required = \App\Services\AccessControl::appCap($owner);
            } catch (\Throwable) {}
        }

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
        // Shared admin styles and an icon (1.2.128): this page had inline
        // styles, an emoji for an icon and no viewport, so it was tiny on a phone.
        $lock = \App\Core\Icon::svg('lock-closed', 'w-6 h-6');
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Access denied</title>'
            . '<link rel="stylesheet" href="' . $base . '/admin/assets/css/tailwind.min.css"></head>'
            . '<body class="grid min-h-screen place-items-center bg-slate-50 p-4 font-sans">'
            . '<div class="bh-card w-full max-w-md p-8 text-center">'
            . '<div class="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-xl bg-accent-50 text-accent-600">' . $lock . '</div>'
            . '<h1 class="mb-2 text-lg font-semibold text-slate-900">Access denied</h1>'
            . '<p class="mb-6 text-sm text-slate-500">Your role doesn\'t include this area (it needs '
            . '<code class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">' . htmlspecialchars($required) . '</code>).'
            . ' Ask an administrator if you need it.</p>'
            . '<a href="' . $base . '/admin/dashboard" class="bh-btn bh-btn--primary">Back to dashboard</a>'
            . '</div></body></html>';
        return Response::html($html, 403);
    }
}
