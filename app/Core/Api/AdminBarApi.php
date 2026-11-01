<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\AdminBar\AdminBar;

/**
 * The frontend admin bar for apps (1.2.96). See docs/ADMIN-BAR.md.
 *
 *     // boot(): a menu of your own, with an item, a live panel and a badge
 *     $bar = $this->api()->adminBar();
 *     $bar->menu(['id' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart-bar',
 *                 'capability' => 'access_app:my-analytics']);
 *     $bar->item(['id' => 'report', 'parent' => 'analytics', 'label' => 'Full report',
 *                 'href' => '/admin/my-analytics']);
 *     $bar->panel(['id' => 'today', 'parent' => 'analytics', 'label' => 'This page today',
 *                  'context' => ['single'],
 *                  'data' => fn($ctx, $user) => ['rows' => [['label' => 'Views', 'value' => $this->views($ctx->contentId())]]]]);
 *     $bar->indicator(['id' => 'live', 'parent' => 'analytics', 'type' => 'count',
 *                      'value' => fn($ctx, $user) => $this->visitorsNow()]);
 *
 *     // an item in one of core's menus: site, context (the page's own), new, user
 *     $bar->item(['id' => 'seo', 'parent' => 'context', 'label' => 'Edit SEO', 'context' => ['post', 'page'],
 *                 'href' => '/admin/my-seo/edit']);
 *
 * Ids are this app's: 'analytics' becomes 'my-analytics.analytics', and a
 * parent without a dot means one of yours unless it is one of core's menus
 * (site, context, new, search, notifications, user, display). Callables get
 * the page's AdminBarContext and the signed-in user, run on the server only
 * for users who may see the component, and a throw hides that component and
 * is written to your log. Everything returns false when a definition is
 * refused; the reason is in your log too.
 */
class AdminBarApi extends Resource
{
    private function bar(): AdminBar
    {
        return $this->make(AdminBar::class);
    }

    /** The full id of one of this app's components. */
    public function id(string $id): string
    {
        $id = strtolower(trim($id));
        return str_starts_with($id, $this->slug . '.') ? $id : $this->slug . '.' . $id;
    }

    /** A top-level entry in the bar. */
    public function menu(array $def): bool
    {
        return $this->register('menu', $def);
    }

    /** An entry in a dropdown: under one of your menus or items, or core's site, context, new or user menu. */
    public function item(array $def): bool
    {
        return $this->register('item', $def);
    }

    /** A box of live information at the top of a dropdown, loaded when it opens. */
    public function panel(array $def): bool
    {
        return $this->register('panel', $def);
    }

    /** A badge on a menu or item: a count, a status or a short text. */
    public function indicator(array $def): bool
    {
        return $this->register('indicator', $def);
    }

    /** A source of notices for the bell. */
    public function notifications(array $def): bool
    {
        return $this->register('notifications', $def);
    }

    /** A source of quick-search results. */
    public function search(array $def): bool
    {
        return $this->register('search', $def);
    }

    /** Remove one of this app's components. */
    public function remove(string $id): bool
    {
        return $this->bar()->remove($this->id($id), $this->slug);
    }

    private function register(string $kind, array $def): bool
    {
        if (!isset($def['id']) || !is_string($def['id']) || trim($def['id']) === '') {
            $this->log("Admin bar: a {$kind} without an 'id' was not registered.", [], 'error');
            return false;
        }
        $def['id'] = $this->id($def['id']);
        if (isset($def['parent']) && is_string($def['parent']) && $def['parent'] !== '') {
            $p = strtolower(trim($def['parent']));
            $def['parent'] = in_array($p, AdminBar::CORE_MENUS, true) || str_contains($p, '.') ? $p : $this->id($p);
        }
        unset($def['attrs']);
        try {
            return match ($kind) {
                'menu'          => $this->bar()->menu($def, $this->slug),
                'item'          => $this->bar()->item($def, $this->slug),
                'panel'         => $this->bar()->panel($def, $this->slug),
                'indicator'     => $this->bar()->indicator($def, $this->slug),
                'notifications' => $this->bar()->notifications($def, $this->slug),
                'search'        => $this->bar()->searchProvider($def, $this->slug),
            };
        } catch (\Throwable $e) {
            $this->log('Admin bar: ' . $kind . " '{$def['id']}' failed to register: " . $e->getMessage(), [], 'error');
            return false;
        }
    }
}
