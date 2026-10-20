<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Services\SettingService;
use App\Services\UserService;

/**
 * Dashboard layouts in the database (1.2.68):
 *
 *   - a user's layout in `users.meta` under the key `dashboard`, next to the
 *     Data Table preferences (`datatable`) — the per-user store core already
 *     has, so no new table and no migration. The users table belongs to this
 *     installation (its DB_PREFIX), so layouts are per site as well as per
 *     user, and follow the user to every browser and device.
 *   - the site default in `settings` (group `dashboard`, key `default_layout`).
 *
 * Nothing is kept in the browser: the database is the source of truth.
 */
final class UserMetaLayoutStore implements LayoutStore
{
    public const META_KEY = 'dashboard';

    public function __construct(
        private UserService $users,
        private SettingService $settings,
    ) {}

    public function load(int $userId): ?array
    {
        $raw = $this->users->meta($userId, self::META_KEY);
        return is_array($raw) && isset($raw['items']) && is_array($raw['items']) ? $raw : null;
    }

    public function save(int $userId, array $layout): void
    {
        if (!$this->users->setMeta($userId, self::META_KEY, $layout)) {
            throw new \RuntimeException('The dashboard layout could not be saved.');
        }
    }

    public function reset(int $userId): void
    {
        if ($this->users->meta($userId, self::META_KEY) !== null) {
            $this->users->setMeta($userId, self::META_KEY, null);
        }
    }

    public function siteDefault(): ?array
    {
        $raw = $this->settings->get('dashboard', 'default_layout');
        if (is_string($raw)) $raw = json_decode($raw, true);
        return is_array($raw) && isset($raw['items']) && is_array($raw['items']) ? $raw : null;
    }

    public function saveSiteDefault(?array $layout): void
    {
        if ($layout === null) {
            $this->settings->delete('dashboard', 'default_layout');
            return;
        }
        // Not autoloaded: only the dashboard reads it.
        $this->settings->set('dashboard', 'default_layout', $layout, false);
    }
}
