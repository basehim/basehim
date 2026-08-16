<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Services\MenuService;
use Basehim\WpMigrator\Sources\MysqlSource;

/**
 * MenuImporter
 *
 * Builds Basehim menus from WordPress nav_menu data. Menus only exist in
 * a MySQL source — WXR exports don't include menu structure — so this
 * importer is a no-op for WXR jobs.
 *
 * Each WP menu becomes a Basehim `menus` row; each WP nav_menu_item
 * becomes a `menu_items` row, with parent_id reconstructed in a second
 * pass.
 */
class MenuImporter extends Importer
{
    public function entityType(): string { return 'menus'; }

    public function total(): int
    {
        if (!$this->source instanceof MysqlSource) return 0;
        return count($this->source->fetchMenuItems());
    }

    public function runBatch(int $offset, int $limit): int
    {
        if (!$this->source instanceof MysqlSource) return 0;
        $items = $this->source->fetchMenuItems();
        $slice = array_slice($items, $offset, $limit);
        if (!$slice) return 0;

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);

        // Ensure menu rows exist (one per unique menu_slug).
        $seen = [];
        foreach ($slice as $it) {
            $slug = (string)$it['menu_slug'];
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $existing = $menus->findBySlug($slug);
            if ($existing) {
                $this->idMap->put('menu', $slug, (int)$existing['id']);
            } else {
                $newId = $menus->create([
                    'name' => (string)$it['menu_name'],
                    'slug' => $slug,
                ]);
                $this->idMap->put('menu', $slug, $newId);
            }
        }

        // Insert menu items.
        foreach ($slice as $it) {
            $menuNewId = $this->idMap->get('menu', (string)$it['menu_slug']);
            if (!$menuNewId) continue;

            // Skip if already imported.
            if ($this->idMap->get('menu_item', (int)$it['item_id'])) continue;

            // Resolve target.
            $type = 'custom'; $objectId = null; $url = $it['url'] ?: '#';
            switch ((string)$it['object_type']) {
                case 'post':
                case 'page':
                    $mapped = $this->idMap->get('post', (int)$it['object_id']);
                    if ($mapped) { $type = (string)$it['object_type']; $objectId = $mapped; $url = null; }
                    break;
                case 'category':
                case 'post_tag':
                    $mapped = $this->idMap->get('term', (int)$it['object_id']);
                    if ($mapped) { $type = 'taxonomy'; $objectId = $mapped; $url = null; }
                    break;
            }

            try {
                $newId = $menus->addItem($menuNewId, [
                    'title'      => (string)$it['post_title'],
                    'type'       => $type,
                    'object_id'  => $objectId,
                    'url'        => $url,
                    'target'     => (string)$it['target'] ?: '_self',
                    'classes'    => (string)$it['css_classes'] ?: null,
                    'menu_order' => (int)$it['menu_order'],
                ]);
                $this->idMap->put('menu_item', (int)$it['item_id'], $newId);
                $this->state->bumpCount($this->jobId, 'menus');
            } catch (\Throwable $e) {
                $this->log('menu item failed: ' . $e->getMessage());
            }
        }

        // After last batch, fix parents.
        if ($offset + count($slice) >= $this->total()) {
            foreach ($items as $it) {
                $newId = $this->idMap->get('menu_item', (int)$it['item_id']);
                $parentNewId = $it['post_parent'] ? $this->idMap->get('menu_item', (int)$it['post_parent']) : null;
                if ($newId && $parentNewId) {
                    try { $this->db->update('menu_items', ['parent_id' => $parentNewId], ['id' => $newId]); }
                    catch (\Throwable) {}
                }
            }
        }

        return count($slice);
    }
}
