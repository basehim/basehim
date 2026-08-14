<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\MenuService;

class MenuController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $all = $menus->all();
        $session = $this->app->make(Session::class);
        return $this->view('menus.index', [
            'title' => 'Menus',
            'currentUser' => $this->user(),
            'menus' => $all,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        /** @var MenuService $menusSvc */
        $menusSvc = $this->app->make(MenuService::class);
        $menu = $menusSvc->find((int)$id);
        if (!$menu) return $this->abort(404);
        $items = $menusSvc->items((int)$id);
        $session = $this->app->make(Session::class);

        return $this->view('menus.edit', [
            'title' => 'Menu: ' . $menu['name'],
            'currentUser' => $this->user(),
            'sources' => $this->linkSources(),
            'menu' => $menu,
            'items' => $items,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $id = $menus->create([
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'location' => $request->input('location'),
        ]);
        $this->flash('success', 'Menu created.');
        return $this->redirect("/admin/menus/{$id}/edit");
    }

    public function update(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $menus->update((int)$id, [
            'name' => $request->input('name'),
            'location' => $request->input('location'),
        ]);
        $this->flash('success', 'Menu updated.');
        return $this->redirect("/admin/menus/{$id}/edit");
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $menu = $menus->find((int)$id);
        if (!$menu) return $this->abort(404);
        $menus->delete((int)$id);
        $this->flash('success', 'Menu “' . ($menu['name'] ?? '') . '” deleted.');
        return $this->redirect('/admin/menus');
    }

    /**
     * Everything that can be linked to, grouped for the builder's pickers.
     * Published content only — linking a menu at a draft would 404 for visitors.
     */
    private function linkSources(): array
    {
        $out = ['pages' => [], 'posts' => [], 'taxonomies' => []];
        try {
            /** @var \App\Services\PostService $posts */
            $posts = $this->app->make(\App\Services\PostService::class);
            foreach (($posts->paginate(['type' => 'page', 'status' => 'published'], 1, 100)['data'] ?? []) as $p) {
                $out['pages'][] = ['id' => (int) $p['id'], 'title' => $p['title'], 'url' => '/page/' . $p['slug']];
            }
            foreach (($posts->paginate(['type' => 'post', 'status' => 'published'], 1, 100)['data'] ?? []) as $p) {
                $out['posts'][] = ['id' => (int) $p['id'], 'title' => $p['title'], 'url' => '/posts/' . $p['slug']];
            }
        } catch (\Throwable) {}
        try {
            /** @var \App\Services\TaxonomyService $tax */
            $tax = $this->app->make(\App\Services\TaxonomyService::class);
            foreach ($tax->allTaxonomies() as $t) {
                $slug = (string) ($t['slug'] ?? '');
                if ($slug === '') continue;
                // Match the public routes: /category/{slug} and /tag/{slug}.
                $prefix = $slug === 'category' ? '/category/' : ($slug === 'tag' ? '/tag/' : '/' . $slug . '/');
                $terms = [];
                foreach ($tax->termsByTaxonomySlug($slug) as $term) {
                    $terms[] = [
                        'id'    => (int) ($term['id'] ?? 0),
                        'title' => $term['name'] ?? '',
                        'url'   => $prefix . ($term['slug'] ?? ''),
                        'count' => (int) ($term['count'] ?? 0),
                    ];
                }
                if ($terms) {
                    $out['taxonomies'][] = ['slug' => $slug, 'label' => $t['label'] ?? ucfirst($slug), 'terms' => $terms];
                }
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * POST /admin/menus/{id}/items/bulk — add several items at once.
     * Body: items[] = [{type, object_id, title, url}]
     */
    public function addItems(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var MenuService $svc */
        $svc = $this->app->make(MenuService::class);
        $menu = $svc->find((int) $id);
        if (!$menu) return \App\Core\Response::json(['ok' => false, 'error' => 'Menu not found.'], 404);

        $raw = json_decode((string) file_get_contents('php://input'), true);
        $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        if (!$items) return \App\Core\Response::json(['ok' => false, 'error' => 'Nothing selected.']);

        $added = 0;
        foreach ($items as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            $url   = trim((string) ($it['url'] ?? ''));
            if ($title === '' || $url === '') continue;
            $type = (string) ($it['type'] ?? 'custom');
            if (!in_array($type, ['post', 'page', 'taxonomy', 'custom', 'archive'], true)) $type = 'custom';
            $svc->addItem((int) $id, [
                'type'      => $type,
                'object_id' => !empty($it['object_id']) ? (int) $it['object_id'] : null,
                'title'     => mb_substr($title, 0, 200),
                'url'       => mb_substr($url, 0, 500),
                'target'    => ($it['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            ]);
            $added++;
        }
        return \App\Core\Response::json(['ok' => true, 'added' => $added, 'items' => $svc->items((int) $id)]);
    }

    /** POST /admin/menus/{id}/items/reorder — save order + nesting together. */
    public function reorderItems(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var MenuService $svc */
        $svc = $this->app->make(MenuService::class);
        if (!$svc->find((int) $id)) return \App\Core\Response::json(['ok' => false, 'error' => 'Menu not found.'], 404);

        $raw = json_decode((string) file_get_contents('php://input'), true);
        $flat = is_array($raw['tree'] ?? null) ? $raw['tree'] : [];
        $saved = $svc->saveTree((int) $id, $flat);
        return \App\Core\Response::json(['ok' => true, 'saved' => $saved]);
    }

    /** POST /admin/menus/{id}/items/{itemId} — edit one item inline. */
    public function updateItem(Request $request, string $id, string $itemId): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var MenuService $svc */
        $svc = $this->app->make(MenuService::class);
        if (!$svc->find((int) $id)) return \App\Core\Response::json(['ok' => false, 'error' => 'Menu not found.'], 404);

        $raw = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $ok = $svc->updateItem((int) $itemId, [
            'title'   => mb_substr(trim((string) ($raw['title'] ?? '')), 0, 200),
            'url'     => mb_substr(trim((string) ($raw['url'] ?? '')), 0, 500),
            'target'  => ($raw['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'classes' => mb_substr(trim((string) ($raw['classes'] ?? '')), 0, 200),
        ]);
        return \App\Core\Response::json(['ok' => $ok]);
    }

    /** POST /admin/menus/{id}/items/{itemId}/delete */
    public function removeItem(Request $request, string $id, string $itemId): Response
    {
        if (!$this->verifyCsrf($request)) {
            return \App\Core\Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var MenuService $svc */
        $svc = $this->app->make(MenuService::class);
        $ok = $svc->deleteItem((int) $itemId);
        return \App\Core\Response::json(['ok' => $ok, 'items' => $svc->items((int) $id)]);
    }

    public function addItem(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $menus->addItem((int)$id, [
            'title' => $request->input('title'),
            'url' => $request->input('url'),
            'type' => $request->input('type', 'custom'),
            'target' => $request->input('target', '_self'),
            'icon' => $request->input('icon'),
        ]);
        $this->flash('success', 'Item added.');
        return $this->redirect("/admin/menus/{$id}/edit");
    }

    public function destroyItem(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var MenuService $menus */
        $menus = $this->app->make(MenuService::class);
        $menus->deleteItem((int)$id);
        $this->flash('success', 'Item removed.');
        return $this->back();
    }
}
