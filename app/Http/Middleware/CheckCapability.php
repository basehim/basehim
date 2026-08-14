<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Application;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * CheckCapability
 *
 * Verifies the authenticated user has the listed capability.
 * The list of caps to check is encoded into the middleware string,
 * e.g. 'capability:manage_options'. Multiple are comma-separated.
 */
final class CheckCapability
{
    public function __construct(private string $required = '') {}

    public function handle(Request $request, Closure $next): mixed
    {
        $app = Application::getInstance();
        $user = $app->has('auth.user') ? $app->make('auth.user') : null;

        if (!$user) {
            return Response::json([
                'type' => 'https://basehim.io/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'Authentication required.',
            ], 401);
        }

        $required = array_filter(array_map('trim', explode(',', $this->required)));

        foreach ($required as $cap) {
            if (!self::userCan($user, $cap)) {
                return self::forbidden($cap);
            }
        }

        return $next($request);
    }

    public static function userCan(?array $user, string $cap): bool
    {
        if (!$user) return false;
        $roleCaps = self::capabilitiesFor($user['role'] ?? 'subscriber');
        // Wildcard roles (super_admin) bypass per-user overrides entirely, so
        // a bad override can never lock the super admin out.
        if (in_array('*', $roleCaps, true)) return true;

        $overrides = self::overridesFor($user);

        // App-access capabilities use DEFAULT-ALLOW / EXPLICIT-DENY: an app is
        // accessible to anyone who can reach the app area unless the user is
        // specifically denied it. (App caps are never in a role's base list, so
        // treating them like normal caps would hide every app.)
        //
        // A deny always beats a grant, and is checked first. There is only one
        // spelling on a clean install; appCapAliases() still returns a list so
        // the loop shape is unchanged and re-adding an alias later is a
        // one-line change rather than a restructure.
        if (\App\Services\AccessControl::isAppCap($cap)) {
            $slug = \App\Services\AccessControl::appCapSlug($cap);
            $aliases = \App\Services\AccessControl::appCapAliases($slug);

            foreach ($aliases as $alias) {
                if (in_array($alias, $overrides['deny'], true)) return false;
            }
            foreach ($aliases as $alias) {
                if (in_array($alias, $overrides['grant'], true)) return true;
            }
            // Default: visible to users who actually manage apps or options.
            // Read-only roles (e.g. subscriber) are intentionally excluded — a
            // subscriber should not reach app admin areas by default.
            return in_array('manage_apps', $roleCaps, true)
                || in_array('manage_options', $roleCaps, true);
        }

        // aliasesFor() returns [$cap] for everything on a clean install. It is
        // kept because capability names have been renamed once already, and the
        // machinery for honouring an old grant is worth more than the handful
        // of lines it costs.
        $capAliases = self::aliasesFor($cap);

        foreach ($capAliases as $alias) {
            if (in_array($alias, $overrides['deny'], true)) return false;
        }
        foreach ($capAliases as $alias) {
            if (in_array($alias, $overrides['grant'], true)) return true;
        }
        foreach ($capAliases as $alias) {
            if (in_array($alias, $roleCaps, true)) return true;
        }
        return false;
    }

    /**
     * Every spelling of a capability, so a check under one name honours a grant
     * recorded under another. Returns [$cap] for everything unaliased.
     *
     * @return array<int,string>
     */
    public static function aliasesFor(string $cap): array
    {
        static $groups = [
            // ['old_name', 'new_name'] pairs go here when a capability is
            // renamed. Empty on a clean install.
        ];
        foreach ($groups as $group) {
            if (in_array($cap, $group, true)) return $group;
        }
        return [$cap];
    }

    /**
     * Per-user capability overrides stored in users.meta JSON:
     *   {"caps_grant": ["manage_menus"], "caps_deny": ["publish_posts"]}
     * Deny always wins over grant and over the role's own capabilities.
     */
    public static function overridesFor(array $user): array
    {
        $meta = $user['meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $meta = json_decode($meta, true);
        }
        if (!is_array($meta)) $meta = [];
        return [
            'grant' => self::expandAliases((array) ($meta['caps_grant'] ?? [])),
            'deny'  => self::expandAliases((array) ($meta['caps_deny'] ?? [])),
        ];
    }

    /**
     * Expand a stored capability list so every entry appears under all of its
     * spellings.
     *
     * Overrides are stored per user as JSON; core asks
     * about "access_app:foo". Expanding here means a single stored entry
     * satisfies a lookup under either name — not just in userCan(), but in the
     * user-edit screen, which derives its Default/Grant/Deny radio state by
     * testing membership of these very lists. Without this, a legacy deny would
     * render as "Default" while still silently denying.
     *
     * @param array $caps Raw list from {users}.meta.
     * @return array<int,string>
     */
    private static function expandAliases(array $caps): array
    {
        $out = [];
        foreach ($caps as $cap) {
            if (!is_string($cap) || $cap === '') continue;
            $out[] = $cap;
            if (\App\Services\AccessControl::isAppCap($cap)) {
                $slug = \App\Services\AccessControl::appCapSlug($cap);
                foreach (\App\Services\AccessControl::appCapAliases($slug) as $alias) {
                    $out[] = $alias;
                }
            } else {
                foreach (self::aliasesFor($cap) as $alias) {
                    $out[] = $alias;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Effective capability list (role + grants − denies), for display. */
    public static function effectiveCaps(array $user): array
    {
        $roleCaps = self::capabilitiesFor($user['role'] ?? 'subscriber');
        if (in_array('*', $roleCaps, true)) return ['*'];
        $o = self::overridesFor($user);
        return array_values(array_diff(array_unique(array_merge($roleCaps, $o['grant'])), $o['deny']));
    }

    public static function capabilitiesFor(string $role): array
    {
        // Prefer AccessControl (config roles + custom roles); fall back to raw
        // config if the container isn't available yet.
        try {
            $caps = Application::getInstance()->make(\App\Services\AccessControl::class)->roleCapabilities($role);
            if ($caps !== []) return $caps;
        } catch (\Throwable) {}
        try {
            $config = Application::getInstance()->make(\App\Core\Config::class);
            return (array) $config->get('capabilities.roles.' . $role, []);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function capabilitiesForLegacy(string $role): array
    {
        $config = Application::getInstance()->make(Config::class);
        return $config->get("capabilities.roles.{$role}", []);
    }

    private static function forbidden(string $cap): Response
    {
        return Response::json([
            'type' => 'https://basehim.io/errors/forbidden',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => "Missing capability: {$cap}",
        ], 403);
    }
}
