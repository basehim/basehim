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

    /**
     * Create a menu.
     *
     * Slugs are unique (a database key). Until 1.2.126 a second menu with the
     * same name, such as another "Main Menu" next to the installer's, hit
     * that key and every caller answered with a server error. Now a slug
     * taken from the name gets a number ("main-menu-2"), and a slug asked for
     * explicitly that is already used is refused with a message.
     *
     * @throws \InvalidArgumentException when the name is empty or the slug is taken
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('A menu needs a name.');

        $asked = trim((string) ($data['slug'] ?? ''));
        if ($asked !== '') {
            $slug = Helpers::slug($asked);
            $this->assertSlugFree($slug);
        } else {
            $slug = $this->uniqueSlug(Helpers::slug($name) ?: 'menu');
        }
        return (int)$this->db->insert('menus', [
            'name' => $name,
            'slug' => $slug,
            'location' => $data['location'] ?? null,
        ]);
    }

    /** A slug no other menu uses: $base, then $base-2, $base-3… */
    private function uniqueSlug(string $base, int $exceptId = 0): string
    {
        for ($n = 1; $n < 1000; $n++) {
            $slug = $n === 1 ? $base : $base . '-' . $n;
            $other = $this->findBySlug($slug);
            if (!$other || (int) $other['id'] === $exceptId) return $slug;
        }
        throw new \RuntimeException('No free menu slug for "' . $base . '".');
    }

    /** @throws \InvalidArgumentException when another menu already uses $slug */
    private function assertSlugFree(string $slug, int $exceptId = 0): void
    {
        if ($slug === '') throw new \InvalidArgumentException('Enter a slug with at least one letter or number.');
        $other = $this->findBySlug($slug);
        if ($other && (int) $other['id'] !== $exceptId) {
            throw new \InvalidArgumentException('The slug "' . $slug . '" is already used by the menu "' . ($other['name'] ?? '') . '".');
        }
    }

    /** @throws \InvalidArgumentException when the new slug is taken or the name is empty */
    public function update(int $id, array $data): bool
    {
        $payload = [];
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') throw new \InvalidArgumentException('A menu needs a name.');
            $payload['name'] = $name;
        }
        if (isset($data['slug'])) {
            $payload['slug'] = Helpers::slug((string) $data['slug']);
            $this->assertSlugFree($payload['slug'], $id);
        }
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

    /** Whether $itemId is an item of $menuId, at any depth. */
    public function hasItem(int $menuId, int $itemId): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM {menu_items} WHERE id = :id AND menu_id = :mid',
            ['id' => $itemId, 'mid' => $menuId]
        ) !== null;
    }

    /** The deepest nesting replaceItems() accepts (top level is 1): as the Menus screen. */
    public const MAX_DEPTH = 3;

    /** The most items replaceItems() accepts in one menu. */
    public const MAX_ITEMS = 300;

    /**
     * Replace a menu's items with a tree, in one transaction (1.2.124).
     *
     * Each node: {title, url?, target?, children?}. Nothing changes unless
     * the whole tree is valid, so a menu is never left half rebuilt. Used by
     * the MCP save_menu tool.
     *
     * @param list<array<string,mixed>> $tree
     * @return int the number of items saved
     * @throws \InvalidArgumentException naming the first bad item
     */
    public function replaceItems(int $menuId, array $tree): int
    {
        $flat = [];
        $this->flattenTree($tree, 1, '', $flat);
        if (count($flat) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('A menu can have at most ' . self::MAX_ITEMS . ' items.');
        }

        return (int) $this->db->transaction(function () use ($menuId, $flat): int {
            $this->clearItems($menuId);
            $ids = [];
            $order = 0;
            foreach ($flat as $node) {
                $ids[$node['path']] = (int) $this->db->insert('menu_items', [
                    'menu_id'    => $menuId,
                    'parent_id'  => $node['parent'] !== '' ? $ids[$node['parent']] : null,
                    'type'       => 'custom',
                    'object_id'  => null,
                    'title'      => $node['title'],
                    'url'        => $node['url'],
                    'target'     => $node['target'],
                    'icon'       => null,
                    'classes'    => null,
                    'menu_order' => ++$order,
                ]);
            }
            return count($ids);
        });
    }

    /**
     * Validate and flatten a tree, parents before children.
     *
     * @param array<string,mixed>[] $nodes
     * @param list<array{path:string,parent:string,title:string,url:string,target:string}> $flat
     */
    private function flattenTree(array $nodes, int $depth, string $parent, array &$flat): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Menus can be at most ' . self::MAX_DEPTH . ' levels deep (under "' . $parent . '").');
        }
        foreach (array_values($nodes) as $i => $node) {
            $path = ($parent === '' ? '' : $parent . '.') . ($i + 1);
            if (!is_array($node)) throw new \InvalidArgumentException("Item {$path} must be an object.");
            $title = trim((string) ($node['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                throw new \InvalidArgumentException("Item {$path} needs a title of 1 to 255 characters.");
            }
            $url = trim((string) ($node['url'] ?? '#'));
            if (!self::isSafeUrl($url)) {
                throw new \InvalidArgumentException("Item {$path} (\"{$title}\") has an address that isn't allowed: use a path like /about, a #fragment, or an http(s), mailto: or tel: link.");
            }
            $target = (string) ($node['target'] ?? '_self');
            $flat[] = ['path' => $path, 'parent' => $parent, 'title' => $title, 'url' => $url,
                       'target' => $target === '_blank' ? '_blank' : '_self'];
            $children = $node['children'] ?? [];
            if (!is_array($children)) throw new \InvalidArgumentException("Item {$path}: children must be a list.");
            if ($children !== []) $this->flattenTree($children, $depth + 1, $path, $flat);
        }
    }

    /** A link a menu may hold: no javascript: or data: addresses. */
    public static function isSafeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F\s]/', $url)) return false;
        // //host/path is another site, so it must be a valid address.
        if (str_starts_with($url, '//')) return filter_var('https:' . $url, FILTER_VALIDATE_URL) !== false;
        if (in_array($url[0], ['/', '#', '?'], true)) return true;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return match ($scheme) {
            'http', 'https' => filter_var($url, FILTER_VALIDATE_URL) !== false,
            'mailto', 'tel' => strlen($url) > strlen($scheme) + 1,
            default => false,
        };
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
