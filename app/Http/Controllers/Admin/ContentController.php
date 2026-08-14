<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\PostTypeRegistry;

/**
 * ContentController — admin screens for app-registered content types.
 *
 * Serves /admin/content/{type}/... for anything an app registered through
 * PostTypeRegistry. Everything else — listing, filtering, the editor, trash,
 * bulk actions, capability checks — is inherited unchanged from PostController,
 * which was already parameterised by $type. Only three things differ, and all
 * three are the overridable methods PostController now exposes: where the URLs
 * point, which capability family applies, and which views render.
 *
 * `post` and `page` keep their own controllers and URLs. Routing them through
 * here would move URLs that are linked from bookmarks, themes and docs, for no
 * benefit.
 */
class ContentController extends PostController
{
    /** Resolved from the route on each request. */
    protected string $type = '';

    private ?array $definition = null;

    /**
     * Pull {type} out of the path.
     *
     * The router passes route parameters positionally to actions, so the type
     * arrives as the first argument on every action. Rather than override all
     * nine of them, the type is read from the request path once — the routes are
     * fixed, so the segment position is known.
     */
    private function resolveType(Request $request): bool
    {
        if ($this->type !== '') return true;

        $path = trim($request->path(), '/');
        $base = defined('BASEHIM_BASE') ? trim((string) BASEHIM_BASE, '/') : '';
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }

        $segments = explode('/', $path);
        // admin / content / {type} / …
        $slug = $segments[2] ?? '';
        if ($slug === '') return false;

        $registry = $this->app->make(PostTypeRegistry::class);
        $definition = $registry->get($slug);
        if ($definition === null) return false;

        $this->type = $definition['slug'];
        $this->definition = $definition;
        return true;
    }

    protected function basePath(): string
    {
        return '/admin/content/' . $this->type;
    }

    protected function capabilityFor(string $action): string
    {
        $family = $this->definition['capability_type'] ?? 'post';
        return $action . '_' . $family . 's';
    }

    /**
     * Custom types reuse the post screens.
     *
     * An app wanting its own layout should register an admin page of its own
     * rather than have core guess at a template that may not exist — a missing
     * view here would be a 500 on a page the operator needs.
     */
    protected function viewPrefix(): string
    {
        return 'posts';
    }

    // ------------------------------------------------------------------
    // Every action resolves the type first, then defers to PostController.
    // ------------------------------------------------------------------

    public function index(Request $request, string $type = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::index($request);
    }

    public function create(Request $request, string $type = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::create($request);
    }

    public function store(Request $request, string $type = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::store($request);
    }

    public function edit(Request $request, string $type = '', string $id = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::edit($request, $id);
    }

    public function update(Request $request, string $type = '', string $id = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::update($request, $id);
    }

    public function destroy(Request $request, string $type = '', string $id = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::destroy($request, $id);
    }

    public function restore(Request $request, string $type = '', string $id = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::restore($request, $id);
    }

    public function forceDelete(Request $request, string $type = '', string $id = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::forceDelete($request, $id);
    }

    public function bulk(Request $request, string $type = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::bulk($request);
    }

    public function emptyTrash(Request $request, string $type = ''): Response
    {
        if (!$this->resolveType($request)) return $this->unknownType($type);
        return parent::emptyTrash($request);
    }

    /**
     * A type that isn't registered right now.
     *
     * Usually means the app that registered it was deactivated. The rows are
     * still in the database and come back with the app, so this says so rather
     * than implying the content is gone.
     */
    private function unknownType(string $slug): Response
    {
        $slug = $slug !== '' ? $slug : 'that type';
        $this->flash(
            'error',
            "No app currently registers '{$slug}'. If the app that provided it was "
            . 'deactivated, its content is still stored and will reappear when the app is active again.'
        );
        return $this->redirect('/admin');
    }
}
