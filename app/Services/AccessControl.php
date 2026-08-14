<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Config;

/**
 * AccessControl — single source of truth for roles, the role hierarchy, custom
 * roles, and app-access capabilities.
 *
 * Security model:
 *  - Every role has a numeric LEVEL. You may only manage (edit role, set
 *    overrides, suspend, delete) users whose level is strictly below yours,
 *    and you may never assign a role above your own level.
 *  - A user can NEVER edit their own capability overrides or role — that closes
 *    the escalation hole where a denied admin re-grants themselves.
 *  - You cannot grant, via override, a capability you do not yourself hold
 *    (prevents laundering privileges upward through custom roles).
 *
 * Custom roles live in the `roles` settings group as:
 *   { "slug": { "label": "...", "level": 40, "capabilities": ["edit_posts", ...] } }
 * and are merged over the config-defined roles.
 */
class AccessControl
{
    /** Built-in role levels. Custom roles supply their own; default 10. */
    private const LEVELS = [
        'super_admin' => 100,
        'admin'       => 80,
        'editor'      => 50,
        'author'      => 30,
        'contributor' => 20,
        'subscriber'  => 10,
    ];

    private ?array $rolesCache = null;

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    /**
     * All roles (config + custom), as:
     *   slug => ['label'=>..., 'level'=>int, 'capabilities'=>[...], 'custom'=>bool]
     */
    public function roles(): array
    {
        if ($this->rolesCache !== null) return $this->rolesCache;

        $config = Application::getInstance()->make(Config::class);
        $configRoles = (array) $config->get('capabilities.roles', []);

        $roles = [];
        foreach ($configRoles as $slug => $caps) {
            $roles[$slug] = [
                'label'        => ucwords(str_replace('_', ' ', $slug)),
                'level'        => self::LEVELS[$slug] ?? 10,
                'capabilities' => (array) $caps,
                'custom'       => false,
            ];
        }

        foreach ($this->customRoles() as $slug => $def) {
            $slug = $this->normalizeSlug((string) $slug);
            if ($slug === '' || isset($roles[$slug])) continue; // never override built-ins
            $roles[$slug] = [
                'label'        => (string) ($def['label'] ?? ucwords(str_replace('_', ' ', $slug))),
                'level'        => max(1, min(90, (int) ($def['level'] ?? 25))), // custom roles capped below admin
                'capabilities' => array_values(array_filter((array) ($def['capabilities'] ?? []), 'is_string')),
                'custom'       => true,
            ];
        }

        return $this->rolesCache = $roles;
    }

    public function roleExists(string $slug): bool
    {
        return isset($this->roles()[$slug]);
    }

    public function levelOf(string $role): int
    {
        return (int) ($this->roles()[$role]['level'] ?? 0);
    }

    public function userLevel(?array $user): int
    {
        return $user ? $this->levelOf((string) ($user['role'] ?? '')) : 0;
    }

    /** Base capabilities a role grants (custom roles included). */
    public function roleCapabilities(string $role): array
    {
        return (array) ($this->roles()[$role]['capabilities'] ?? []);
    }

    // ------------------------------------------------------------------
    // Management guards
    // ------------------------------------------------------------------

    /** Can $actor manage (modify/suspend/delete) $target? Strictly-higher rule. */
    public function canManage(?array $actor, ?array $target): bool
    {
        if (!$actor || !$target) return false;
        if ((int) ($actor['id'] ?? -1) === (int) ($target['id'] ?? -2)) return false; // never self
        // super_admin can manage anyone below (i.e. everyone else).
        return $this->userLevel($actor) > $this->userLevel($target);
    }

    /** May $actor assign $role to someone? Only at-or-below their own level. */
    public function canAssignRole(?array $actor, string $role): bool
    {
        if (!$actor || !$this->roleExists($role)) return false;
        return $this->levelOf($role) <= $this->userLevel($actor);
    }

    /** Roles $actor is allowed to assign (for building a <select>). */
    public function assignableRoles(?array $actor): array
    {
        $max = $this->userLevel($actor);
        $out = [];
        foreach ($this->roles() as $slug => $def) {
            if ($def['level'] <= $max) $out[$slug] = $def;
        }
        return $out;
    }

    /**
     * May $actor grant $cap as an override? Wildcard holders may grant anything.
     * App-access capabilities (access_app:*) are a delegation
     * namespace — an admin who can manage apps/users may grant them to others without
     * personally "holding" each one. All other caps follow the strict rule:
     * you can only grant what you effectively hold, preventing upward laundering.
     */
    public function canGrantCapability(?array $actor, string $cap): bool
    {
        if (!$actor) return false;
        $caps = \App\Http\Middleware\CheckCapability::effectiveCaps($actor);
        if (in_array('*', $caps, true)) return true;

        if (self::isAppCap($cap)) {
            return in_array('manage_apps', $caps, true)

                || in_array('manage_users', $caps, true)
                || in_array('manage_options', $caps, true);
        }

        // Single spelling: a clean install has no pre-1.34 role rows.
        // but not 'manage_apps'. Without this, such an admin could no longer
        // delegate the capability they demonstrably have.
        foreach (\App\Http\Middleware\CheckCapability::aliasesFor($cap) as $alias) {
            if (in_array($alias, $caps, true)) return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Custom roles CRUD (persisted in the `roles` settings group)
    // ------------------------------------------------------------------

    public function customRoles(): array
    {
        try {
            $settings = Application::getInstance()->make(SettingService::class);
            $raw = $settings->get('roles', 'custom', []);
            if (is_string($raw)) $raw = json_decode($raw, true);
            return is_array($raw) ? $raw : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function saveCustomRole(string $slug, string $label, int $level, array $capabilities): string
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') $slug = $this->normalizeSlug($label);
        if ($slug === '') return '';
        // Never allow shadowing a built-in role.
        if (in_array($slug, array_keys(self::LEVELS), true)) return '';

        $roles = $this->customRoles();
        $roles[$slug] = [
            'label'        => $label !== '' ? $label : ucwords(str_replace('_', ' ', $slug)),
            'level'        => max(1, min(90, $level)),
            'capabilities' => array_values(array_unique(array_filter($capabilities, 'is_string'))),
        ];
        $this->persist($roles);
        return $slug;
    }

    public function deleteCustomRole(string $slug): bool
    {
        $roles = $this->customRoles();
        if (!isset($roles[$slug])) return false;
        unset($roles[$slug]);
        $this->persist($roles);
        return true;
    }

    private function persist(array $roles): void
    {
        $settings = Application::getInstance()->make(SettingService::class);
        $settings->set('roles', 'custom', $roles);
        $this->rolesCache = null;
    }

    // ------------------------------------------------------------------
    // App access capabilities
    //
    // Capabilities are stored as strings inside JSON blobs on role and user
    // rows. A clean 1.43.0 install has no rows predating the 1.34.0 rename, so
    // there is only ever one spelling here: access_app:. Upgraded sites keep
    // the dual-spelling build, which is why this differs between the full
    // release and the compatibility patch.
    // ------------------------------------------------------------------

    /** Capability string gating access to an app's admin area. */
    public static function appCap(string $slug): string
    {
        return 'access_app:' . $slug;
    }

    /** True when $cap is an app-access capability in either spelling. */
    public static function isAppCap(string $cap): bool
    {
        return str_starts_with($cap, 'access_app:');
    }

    /** The slug an app-access capability refers to ('' when not one). */
    public static function appCapSlug(string $cap): string
    {
        return str_starts_with($cap, 'access_app:')
            ? substr($cap, strlen('access_app:'))
            : '';
    }

    /**
     * Both spellings of an app-access capability, for membership tests against
     * a stored capability list.
     *
     * @return array{0:string,1:string}
     */
    public static function appCapAliases(string $slug): array
    {
        return ['access_app:' . $slug];
    }

    /**
     * Active apps as [ ['slug'=>, 'name'=>, 'cap'=>, 'menu_url'=>] ].
     * menu_url is resolved from the app's registered admin.menu entry when
     * available, so the app-area policy knows which paths to guard.
     */
    public function appList(): array
    {
        $out = [];
        try {
            $apps = Application::getInstance()->make(\App\Services\AppService::class);
            $menuUrls = $this->appMenuUrls();
            foreach ($apps->active() as $p) {
                $slug = (string) $p['slug'];
                $out[] = [
                    'slug'     => $slug,
                    'name'     => (string) ($p['name'] ?? $slug),
                    'cap'      => self::appCap($slug),
                    'icon'     => (string) ($p['icon'] ?? ''),
                    'menu_url' => $menuUrls[$slug] ?? null,
                ];
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * Best-effort slug => admin menu URL, derived from the admin.menu filter.
     * Menu items are tagged with 'app' by App::addAdminMenu().
     */
    public function appMenuUrls(): array
    {
        $map = [];
        try {
            $hooks = Application::getInstance()->make(\App\Core\HookRegistry::class);
            $items = $hooks->applyFilters('admin.menu', []);
            foreach ((array) $items as $it) {
                $slug = (string) ($it['app'] ?? '');
                if (!empty($it['url']) && $slug !== '') {
                    $map[$slug] = (string) $it['url'];
                }
            }
        } catch (\Throwable) {}
        return $map;
    }

    // ------------------------------------------------------------------

    private function normalizeSlug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        return trim((string) $s, '_');
    }
}
