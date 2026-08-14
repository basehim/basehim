<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Helpers;

class MenuService
{
    public function __construct(private Database $db) {}

    public function all(): array
    {
        return $this->db->select('SELECT * FROM {menus} ORDER BY name');
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {menus} WHERE id = :id', ['id' => $id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM {menus} WHERE slug = :slug', ['slug' => $slug]);
    }

    public function items(int $menuId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM {menu_items} WHERE menu_id = :mid ORDER BY menu_order ASC',
            ['mid' => $menuId]
        );
        return $this->buildTree($rows);
    }

    /**
     * Items for a menu assigned to a theme LOCATION (primary, footer …).
     *
     * RendersTheme asks for 'primary' and 'footer', which are locations, but
     * the only lookup available was by slug — and a default install names the
     * menus 'main-menu' and 'footer-menu'. So the lookup never matched and
     * every theme silently fell back to its hardcoded nav, no matter what was
     * configured in the admin. Falls back to a slug match so a menu whose slug
     * happens to be the location name keeps working.
     */
    public function itemsByLocation(string $location): array
    {
        $menu = $this->db->selectOne('SELECT * FROM {menus} WHERE location = :loc', ['loc' => $location]);
        if (!$menu) return $this->itemsBySlug($location);
        return $this->items((int) $menu['id']);
    }

    public function itemsBySlug(string $slug): array
    {
        $menu = $this->findBySlug($slug);
        if (!$menu) return [];
        return $this->items((int)$menu['id']);
    }

    public function create(array $data): int
    {
        $slug = !empty($data['slug']) ? Helpers::slug($data['slug']) : Helpers::slug($data['name']);
        return (int)$this->db->insert('menus', [
            'name' => $data['name'],
            'slug' => $slug,
            'location' => $data['location'] ?? null,
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $payload = [];
        if (isset($data['name'])) $payload['name'] = $data['name'];
        if (isset($data['slug'])) $payload['slug'] = Helpers::slug($data['slug']);
        if (array_key_exists('location', $data)) $payload['location'] = $data['location'];
        if (empty($payload)) return true;
        return $this->db->update('menus', $payload, ['id' => $id]) >= 0;
    }

    /**
     * Delete a menu and all of its items.
     *
     * The menu_items.menu_id FK is ON DELETE CASCADE, so the child rows would go
     * on their own — but we clear them explicitly first so the delete still works
     * on installs where FK enforcement is disabled, and so the intent is obvious.
     */
    public function delete(int $id): bool
    {
        $this->clearItems($id);
        return $this->db->delete('menus', ['id' => $id]) > 0;
    }

    public function addItem(int $menuId, array $data): int
    {
        $maxRow = $this->db->selectOne(
            'SELECT COALESCE(MAX(menu_order), 0) AS m FROM {menu_items} WHERE menu_id = :mid',
            ['mid' => $menuId]
        );
        $order = (int)($maxRow['m'] ?? 0) + 1;

        return (int)$this->db->insert('menu_items', [
            'menu_id' => $menuId,
            'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            'type' => $data['type'] ?? 'custom',
            'object_id' => !empty($data['object_id']) ? (int)$data['object_id'] : null,
            'title' => $data['title'],
            'url' => $data['url'] ?? null,
            'target' => $data['target'] ?? '_self',
            'icon' => $data['icon'] ?? null,
            'classes' => $data['classes'] ?? null,
            'menu_order' => $order,
        ]);
    }

    /** Edit one item's own fields (label, url, target, css classes). */
    public function updateItem(int $id, array $data): bool
    {
        $fields = [];
        foreach (['title', 'url', 'target', 'classes', 'icon'] as $f) {
            if (array_key_exists($f, $data)) $fields[$f] = $data[$f] === '' ? null : $data[$f];
        }
        if (!$fields) return false;
        return $this->db->update('menu_items', $fields, ['id' => $id]) >= 0;
    }

    /**
     * Persist the whole tree in one go: order and nesting together.
     *
     * The builder sends a flat list of {id, parent_id} in display order, so a
     * drag can move an item and re-parent it in the same action. Only items that
     * belong to this menu are touched — an id from another menu is ignored
     * rather than silently re-homed.
     *
     * @param array<int,array{id:int,parent_id:int|null}> $flat
     */
    public function saveTree(int $menuId, array $flat): int
    {
        // NOTE: items() returns a nested tree, so iterating it would only see
        // top-level rows and silently skip every child. Query flat.
        $own = [];
        foreach ($this->db->select('SELECT id FROM {menu_items} WHERE menu_id = :mid', ['mid' => $menuId]) as $row) {
            $own[(int) $row['id']] = true;
        }

        $order = 0;
        $saved = 0;
        foreach ($flat as $node) {
            $id = (int) ($node['id'] ?? 0);
            if ($id <= 0 || !isset($own[$id])) continue;

            $parent = isset($node['parent_id']) && $node['parent_id'] !== null && $node['parent_id'] !== ''
                ? (int) $node['parent_id'] : null;
            // A parent must be a real sibling in this menu, and never itself.
            if ($parent !== null && ($parent === $id || !isset($own[$parent]))) $parent = null;

            $this->db->update('menu_items', [
                'parent_id'  => $parent,
                'menu_order' => ++$order,
            ], ['id' => $id]);
            $saved++;
        }
        return $saved;
    }

    /** Remove every item in a menu (used by "clear"). */
    public function clearItems(int $menuId): int
    {
        return $this->db->delete('menu_items', ['menu_id' => $menuId]);
    }

    public function deleteItem(int $id): bool
    {
        return $this->db->delete('menu_items', ['id' => $id]) > 0;
    }

    private function buildTree(array $items, ?int $parentId = null): array
    {
        $branch = [];
        foreach ($items as $item) {
            if ((int)($item['parent_id'] ?? 0) === (int)$parentId) {
                $children = $this->buildTree($items, (int)$item['id']);
                if ($children) $item['children'] = $children;
                $branch[] = $item;
            }
        }
        return $branch;
    }
}
