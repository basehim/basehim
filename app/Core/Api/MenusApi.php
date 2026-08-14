<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\MenuService;

/**
 * MenusApi — navigation menus and their items.
 *
 *     $api->menus()->addItem($menuId, ['label' => 'Docs', 'url' => '/docs']);
 */
class MenusApi extends Resource
{
    private function service(): MenuService
    {
        return $this->make(MenuService::class);
    }

    public function all(): array
    {
        return (array) $this->attempt(fn() => $this->service()->all(), [], 'all');
    }

    public function find(int $id): ?array
    {
        return $this->attempt(fn() => $this->service()->find($id), null, 'find');
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->attempt(fn() => $this->service()->findBySlug($slug), null, 'findBySlug');
    }

    /** Items of a menu, as a tree. */
    public function items(int $menuId): array
    {
        return (array) $this->attempt(fn() => $this->service()->items($menuId), [], 'items');
    }

    /** Items by menu slug — what a theme or widget usually wants. */
    public function itemsBySlug(string $slug): array
    {
        return (array) $this->attempt(fn() => $this->service()->itemsBySlug($slug), [], 'itemsBySlug');
    }

    public function create(array $data): int
    {
        $id = (int) $this->attempt(fn() => $this->service()->create($data), 0, 'create');
        if ($id > 0) $this->log("Created menu #{$id}");
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->update($id, $data), false, 'update');
    }

    public function delete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->delete($id), false, 'delete');
        if ($ok) $this->log("Deleted menu #{$id}");
        return $ok;
    }

    /** Append an item. Returns the new item id, or 0. */
    public function addItem(int $menuId, array $data): int
    {
        return (int) $this->attempt(fn() => $this->service()->addItem($menuId, $data), 0, 'addItem');
    }

    public function updateItem(int $itemId, array $data): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->updateItem($itemId, $data), false, 'updateItem');
    }

    public function deleteItem(int $itemId): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->deleteItem($itemId), false, 'deleteItem');
    }

    /** Replace a menu's whole structure from a flat, ordered array. */
    public function saveTree(int $menuId, array $flat): int
    {
        return (int) $this->attempt(fn() => $this->service()->saveTree($menuId, $flat), 0, 'saveTree');
    }

    /** Remove every item, keeping the menu itself. */
    public function clearItems(int $menuId): int
    {
        return (int) $this->attempt(fn() => $this->service()->clearItems($menuId), 0, 'clearItems');
    }
}
