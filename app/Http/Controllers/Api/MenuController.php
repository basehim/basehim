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
        $id = $menus->create([
            'name'     => $name,
            'slug'     => (string) $request->input('slug', ''),
            'location' => $request->input('location'),
        ]);
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

        $menus->update((int) $id, $data);
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

    /** POST /menus/{id}/items — body: {label, url, parent_id?, sort_order?} */
    public function addItem(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        if (!$menus->find((int) $id)) return Response::json(['error' => 'Menu not found'], 404);

        $label = trim((string) $request->input('label', ''));
        if ($label === '') return Response::json(['error' => 'label is required'], 422);

        $itemId = $menus->addItem((int) $id, [
            'label'      => $label,
            'url'        => (string) $request->input('url', '#'),
            'parent_id'  => $request->input('parent_id') ?: null,
            'sort_order' => (int) $request->input('sort_order', 0),
            'target'     => $request->input('target'),
        ]);
        if ($itemId <= 0) return Response::json(['error' => 'Could not add the item.'], 500);
        return Response::json(['data' => ['id' => $itemId]], 201);
    }

    /** PUT /menu-items/{id} */
    public function updateItem(Request $request, string $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $data = [];
        foreach (['label', 'url', 'parent_id', 'sort_order', 'target'] as $field) {
            if ($request->input($field) !== null) $data[$field] = $request->input($field);
        }
        if (!$data) return Response::json(['error' => 'Nothing to update.'], 422);

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
