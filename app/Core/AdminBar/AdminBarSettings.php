<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

use App\Http\Middleware\CheckCapability;
use App\Services\SettingService;

/**
 * The admin bar's settings (Settings → Admin bar) and each user's own
 * choices (1.2.96).
 *
 * Site settings live in the settings table, group "toolbar"; a user's
 * choices in users.meta under "admin_bar". Every value is checked against
 * the lists below when read, so a hand-edited row can't put anything odd
 * into the page. A user's choice only counts for what the site lets users
 * change (user_options).
 */
final class AdminBarSettings
{
    public const GROUP = 'toolbar';
    public const META_KEY = 'admin_bar';

    public const MODES = ['horizontal' => 'Top bar', 'floating' => 'Floating button'];
    public const POSITIONS = ['top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right'];
    public const MOBILE = ['same' => 'The same as on larger screens', 'floating' => 'A floating button', 'hidden' => 'Nothing'];
    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact'];
    public const LABELS = ['labels' => 'Icons and labels', 'icons' => 'Icons only'];
    public const COLORS = ['dark' => 'Dark', 'light' => 'Light', 'auto' => 'Follow the device'];
    public const OPEN_ON = ['click' => 'Click', 'hover' => 'Hover (mouse), click (touch)'];
    public const ALIGN = ['start' => 'Under the item', 'end' => 'Right-aligned'];
    /** Sections a user may hide for themselves; core's own and apps' menus. */
    public const OPTIONAL_SECTIONS = ['new' => 'New', 'search' => 'Search', 'notifications' => 'Notifications'];
    /** What users may change for themselves. */
    public const USER_OPTIONS = ['mode' => 'Display mode (top bar, floating button or off)', 'position' => 'Where the floating button sits',
                                 'sections' => 'Which optional sections are shown'];
    public const HEIGHTS = [32, 36, 40, 44, 48];
    public const FLOAT_SIZES = [40, 44, 48, 52, 56];
    public const PANEL_WIDTHS = [280, 320, 360, 400, 440];
    public const AUTO_HIDE = [0 => 'Never', 5 => 'After 5 seconds', 10 => 'After 10 seconds', 30 => 'After 30 seconds'];

    /** Capabilities that make someone a person who manages the site: the bar's default audience. */
    public const MANAGE_CAPS = ['edit_posts', 'edit_pages', 'upload_media', 'moderate_comments', 'manage_taxonomies',
        'manage_menus', 'manage_users', 'manage_themes', 'manage_apps', 'manage_settings', 'manage_options'];

    public const DEFAULTS = [
        'enabled'        => true,
        'mode'           => 'horizontal',
        'position'       => 'bottom-right',
        'mobile'         => 'same',
        'roles'          => [],            // [] = everyone with a management capability
        'context'        => true,
        'search'         => true,
        'notifications'  => true,
        'shortcuts'      => true,
        'density'        => 'comfortable',
        'branding'       => true,
        'avatar'         => true,
        'labels'         => 'labels',
        'height'         => 40,
        'float_size'     => 48,
        'panel_width'    => 360,
        'align'          => 'start',
        'color'          => 'dark',
        'open_on'        => 'click',
        'remember'       => true,
        'auto_hide'      => 0,
        'user_options'   => ['mode', 'position', 'sections'],
    ];

    private ?array $site = null;

    public function __construct(private SettingService $settings) {}

    /** The site's settings, checked. */
    public function site(): array
    {
        if ($this->site !== null) return $this->site;
        $raw = [];
        try { $raw = (array) $this->settings->getGroup(self::GROUP); } catch (\Throwable) {}
        return $this->site = self::clean($raw);
    }

    /** Forget what was read (after a save). */
    public function flush(): void
    {
        $this->site = null;
    }

    /**
     * Check a settings array: known keys only, each value one of its options.
     * Used both when reading and when saving.
     */
    public static function clean(array $raw): array
    {
        $d = self::DEFAULTS;
        $bool = static fn($v, bool $def): bool => $v === null ? $def : filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $pick = static fn($v, array $opts, string $def): string => is_string($v) && isset($opts[$v]) ? $v : $def;
        $num = static fn($v, array $opts, int $def): int => in_array((int) $v, $opts, true) ? (int) $v : $def;
        $list = static function ($v): array {
            if (is_string($v)) { $j = json_decode($v, true); $v = is_array($j) ? $j : ($v === '' ? [] : explode(',', $v)); }
            return is_array($v) ? array_values(array_unique(array_filter(array_map('strval', $v), fn($s) => preg_match('/^[a-z0-9_-]{1,60}$/', $s)))) : [];
        };

        return [
            'enabled'       => $bool($raw['enabled'] ?? null, $d['enabled']),
            'mode'          => $pick($raw['mode'] ?? null, self::MODES, $d['mode']),
            'position'      => $pick($raw['position'] ?? null, self::POSITIONS, $d['position']),
            'mobile'        => $pick($raw['mobile'] ?? null, self::MOBILE, $d['mobile']),
            'roles'         => $list($raw['roles'] ?? []),
            'context'       => $bool($raw['context'] ?? null, $d['context']),
            'search'        => $bool($raw['search'] ?? null, $d['search']),
            'notifications' => $bool($raw['notifications'] ?? null, $d['notifications']),
            'shortcuts'     => $bool($raw['shortcuts'] ?? null, $d['shortcuts']),
            'density'       => $pick($raw['density'] ?? null, self::DENSITIES, $d['density']),
            'branding'      => $bool($raw['branding'] ?? null, $d['branding']),
            'avatar'        => $bool($raw['avatar'] ?? null, $d['avatar']),
            'labels'        => $pick($raw['labels'] ?? null, self::LABELS, $d['labels']),
            'height'        => $num($raw['height'] ?? null, self::HEIGHTS, $d['height']),
            'float_size'    => $num($raw['float_size'] ?? null, self::FLOAT_SIZES, $d['float_size']),
            'panel_width'   => $num($raw['panel_width'] ?? null, self::PANEL_WIDTHS, $d['panel_width']),
            'align'         => $pick($raw['align'] ?? null, self::ALIGN, $d['align']),
            'color'         => $pick($raw['color'] ?? null, self::COLORS, $d['color']),
            'open_on'       => $pick($raw['open_on'] ?? null, self::OPEN_ON, $d['open_on']),
            'remember'      => $bool($raw['remember'] ?? null, $d['remember']),
            'auto_hide'     => array_key_exists((int) ($raw['auto_hide'] ?? 0), self::AUTO_HIDE) ? (int) ($raw['auto_hide'] ?? 0) : 0,
            'user_options'  => array_key_exists('user_options', $raw)
                ? array_values(array_intersect($list($raw['user_options']), array_keys(self::USER_OPTIONS)))
                : $d['user_options'],
        ];
    }

    /**
     * Whether this user gets the bar at all: it is on, and the user is in
     * one of the chosen roles — or, when none are chosen, can manage
     * something (any of MANAGE_CAPS, through their role, a custom role or a
     * per-user grant).
     */
    public function allows(?array $user): bool
    {
        if ($user === null) return false;
        $s = $this->site();
        if (!$s['enabled']) return false;
        if ($s['roles'] !== []) return in_array((string) ($user['role'] ?? ''), $s['roles'], true);
        foreach (self::MANAGE_CAPS as $cap) {
            if (CheckCapability::userCan($user, $cap)) return true;
        }
        return false;
    }

    /** The user's own choices, from their row (no query). */
    public function userPrefs(array $user): array
    {
        $meta = $user['meta'] ?? null;
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $p = is_array($meta) && is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];
        return self::cleanPrefs($p);
    }

    /** A user's choices, checked: unknown keys and values are dropped. */
    public static function cleanPrefs(array $p): array
    {
        $out = [];
        if (isset($p['mode']) && (isset(self::MODES[$p['mode']]) || $p['mode'] === 'off')) $out['mode'] = (string) $p['mode'];
        if (isset($p['position']) && isset(self::POSITIONS[$p['position']])) $out['position'] = (string) $p['position'];
        if (isset($p['collapsed'])) $out['collapsed'] = (bool) $p['collapsed'];
        if (isset($p['hidden']) && is_array($p['hidden'])) {
            $out['hidden'] = array_values(array_unique(array_filter(array_map('strval', $p['hidden']),
                fn($s) => (bool) preg_match('/^[a-z0-9][a-z0-9_.-]{0,100}$/', $s))));
            $out['hidden'] = array_slice($out['hidden'], 0, 40);
        }
        return $out;
    }

    /**
     * What applies to this user: the site's settings with their choices on
     * top, for the options the site lets them change.
     */
    public function effective(array $user): array
    {
        $s = $this->site();
        $p = $this->userPrefs($user);
        $can = array_flip($s['user_options']);
        $s['user_mode'] = 'default';
        if (isset($can['mode']) && isset($p['mode'])) {
            $s['user_mode'] = $p['mode'];
            if ($p['mode'] !== 'off') $s['mode'] = $p['mode'];
        }
        if (isset($can['position']) && isset($p['position'])) $s['position'] = $p['position'];
        $s['collapsed'] = $s['remember'] && !empty($p['collapsed']);
        $s['hidden'] = isset($can['sections']) ? ($p['hidden'] ?? []) : [];
        $s['off'] = ($s['user_mode'] === 'off');
        return $s;
    }
}
