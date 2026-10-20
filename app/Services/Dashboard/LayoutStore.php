<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * Where dashboard layouts are kept. The engine only ever talks to this, so
 * the reconciliation rules in DashboardService can be tested without a
 * database (tests/Unit/DashboardWidgetsTest.php uses an in-memory store).
 *
 * A stored layout is:
 *   ['v' => 1, 'items' => [item, …], 'seen' => [widget key, …], 'saved_at' => 'Y-m-d H:i:s']
 * and an item:
 *   ['id' => 'w-…', 'widget' => 'core.dashboard.recent-posts', 'size' => 'half',
 *    'visible' => true, 'collapsed' => false, 'settings' => [...]]
 * Position is the item's place in the list.
 */
interface LayoutStore
{
    /** The user's saved layout, or null when they have never saved one. */
    public function load(int $userId): ?array;

    /** @throws \RuntimeException when it could not be written */
    public function save(int $userId, array $layout): void;

    public function reset(int $userId): void;

    /** The site's default layout an administrator saved, or null. */
    public function siteDefault(): ?array;

    /** @param array|null $layout null clears it (back to the built-in default) */
    public function saveSiteDefault(?array $layout): void;
}
