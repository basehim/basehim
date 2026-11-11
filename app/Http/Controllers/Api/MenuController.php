<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\MenuService;

class MenuController extends ApiController
{
    public function show(Request $request, string $slug): Response
    {
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $menu = $menus->findBySlug($slug);
        if (!$menu) return Response::json(['error' => 'Not found'], 404);
        return Response::json([
            'data' => array_merge($menu, ['items' => $menus->items((int)$menu['id'])]),
        ]);
    }

    // ==================================================================
    // Menu management (authenticated — requires manage_menus)
    // ==================================================================

    /** GET /menus */
    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();
        return Response::json(['data' => $this->app->make(MenuService::class)->all()]);
    }

    /** POST /menus — body: {name, slug, location?} */
    public function store(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $name = trim((string) $request->input('name', ''));
        if ($name === '') return Response::json(['error' => 'name is required'], 422);

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        try {
            $id = $menus->create([
                'name'     => $name,
                'slug'     => (string) $request->input('slug', ''),
                'location' => $request->input('location'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        if ($id <= 0) return Response::json(['error' => 'Could not create the menu.'], 500);
        return Response::json(['data' => $menus->find($id)], 201);
    }

    /** PUT /menus/{id} */
    public function update(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        if (!$menus->find((int) $id)) return Response::json(['error' => 'Not found'], 404);

        $data = [];
        foreach (['name', 'slug', 'location'] as $field) {
            if ($request->input($field) !== null) $data[$field] = $request->input($field);
        }
        if (!$data) return Response::json(['error' => 'Nothing to update.'], 422);

        try {
            $menus->update((int) $id, $data);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return Response::json(['data' => $menus->find((int) $id)]);
    }

    /** DELETE /menus/{id} */
    public function destroy(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        if (!$menus->find((int) $id)) return Response::json(['error' => 'Not found'], 404);
        return Response::json(['deleted' => $menus->delete((int) $id)]);
    }

    /** GET /menus/{id}/items */
    public function items(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();
        return Response::json(['data' => $this->app->make(MenuService::class)->items((int) $id)]);
    }

    /**
     * POST /menus/{id}/items — body: {title (or label), url, parent_id?, target?}
     *
     * Until 1.2.124 this passed `label` to MenuService, which stores `title`,
     * so every call failed. Both names are accepted now; items are added at
     * the end (the Menus screen or the MCP save_menu tool sets the whole order).
     */
    public function addItem(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        if (!$menus->find((int) $id)) return Response::json(['error' => 'Menu not found'], 404);

        $title = trim((string) ($request->input('title') ?? $request->input('label', '')));
        if ($title === '') return Response::json(['error' => 'title is required'], 422);

        $parent = (int) ($request->input('parent_id') ?: 0);
        if ($parent > 0 && !$menus->hasItem((int) $id, $parent)) {
            return Response::json(['error' => 'parent_id is not an item of this menu.'], 422);
        }
        $target = (string) ($request->input('target') ?? '_self');

        $itemId = $menus->addItem((int) $id, [
            'title'     => $title,
            'url'       => (string) $request->input('url', '#'),
            'parent_id' => $parent ?: null,
            'target'    => $target === '_blank' ? '_blank' : '_self',
        ]);
        if ($itemId <= 0) return Response::json(['error' => 'Could not add the item.'], 500);
        return Response::json(['data' => ['id' => $itemId]], 201);
    }

    /** PUT /menu-items/{id} */
    public function updateItem(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        // `label` is accepted for `title` (the name this endpoint documented).
        $data = [];
        foreach (['title', 'url', 'target'] as $field) {
            if ($request->input($field) !== null) $data[$field] = (string) $request->input($field);
        }
        if (!isset($data['title']) && $request->input('label') !== null) $data['title'] = (string) $request->input('label');
        if (isset($data['target'])) $data['target'] = $data['target'] === '_blank' ? '_blank' : '_self';
        if (!$data) return Response::json(['error' => 'Nothing to update. Send title, url or target.'], 422);

        $ok = $this->app->make(MenuService::class)->updateItem((int) $id, $data);
        return Response::json(['updated' => $ok], $ok ? 200 : 404);
    }

    /** DELETE /menu-items/{id} */
    public function destroyItem(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();
        $ok = $this->app->make(MenuService::class)->deleteItem((int) $id);
        return Response::json(['deleted' => $ok], $ok ? 200 : 404);
    }

    private function canManage(): bool
    {
        $user = $this->authUser();
        return $user !== null
            && \App\Http\Middleware\CheckCapability::userCan($user, 'manage_menus');
    }

    private function denied(): Response
    {
        return Response::json(['error' => 'Requires the manage_menus capability.'], 403);
    }
}
