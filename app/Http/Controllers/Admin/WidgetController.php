<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\WidgetRegistry;
use App\Http\Controllers\Controller;
use App\Services\WidgetAreaService;

class WidgetController extends Controller
{
    /** GET /admin/widgets — management/overview page. */
    public function index(Request $request): Response
    {
        /** @var WidgetRegistry $reg */
        $reg = $this->app->make(WidgetRegistry::class);
        return $this->view('widgets.index', [
            'title'       => 'Widgets',
            'currentUser' => $this->user(),
            'widgets'     => $reg->all(),
        ]);
    }

    /** GET /admin/widgets/list.json?surface=editor|frontend|dashboard */
    public function list(Request $request): Response
    {
        /** @var WidgetRegistry $reg */
        $reg = $this->app->make(WidgetRegistry::class);
        $surface = (string) $request->input('surface', '');
        $surface = in_array($surface, ['editor', 'frontend', 'dashboard'], true) ? $surface : null;
        return Response::json(['ok' => true, 'widgets' => $reg->all($surface)]);
    }

    /**
     * POST /admin/widgets/render — render one widget to HTML.
     * Body: { widget: key, settings: {...}, surface: 'editor'|'frontend'|'dashboard' }
     * Used by the block editor for live previews and by any AJAX surface.
     */
    public function render(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var WidgetRegistry $reg */
        $reg = $this->app->make(WidgetRegistry::class);
        $key = (string) $request->input('widget', '');
        if (!$reg->has($key)) {
            return Response::json(['ok' => false, 'error' => 'Unknown widget: ' . $key], 404);
        }
        $settings = $request->input('settings', []);
        if (!is_array($settings)) $settings = [];
        $surface = (string) $request->input('surface', 'editor');
        if (!in_array($surface, ['editor', 'frontend', 'dashboard'], true)) $surface = 'editor';

        return Response::json([
            'ok'   => true,
            'html' => $reg->render($key, $settings, $surface),
        ]);
    }

    // ── Widget areas (sidebars) ──────────────────────────────────────────────

    /** GET /admin/widgets/areas — assign & order widgets within theme areas. */
    public function areas(Request $request): Response
    {
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        /** @var WidgetRegistry $reg */
        $reg = $this->app->make(WidgetRegistry::class);

        return $this->view('widgets.areas', [
            'title'        => 'Widget Areas',
            'currentUser'  => $this->user(),
            'areas'        => $svc->definitions(),
            'assignments'  => $svc->assignments(),
            'available'    => $reg->all('frontend'),
            'registry'     => $reg,
            'csrf'         => $this->app->make(Session::class)->csrfToken(),
        ]);
    }

    /** POST /admin/widgets/areas/{area}/add — Body: {widget}. */
    public function areaAdd(Request $request, string $area): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        $widget = (string) $request->input('widget', '');
        $added = $svc->addWidget($area, $widget);
        $this->flash($added ? 'success' : 'error', $added
            ? 'Widget added.'
            : 'Could not add that widget — it may not exist or support the frontend.');
        return $this->back();
    }

    /** POST /admin/widgets/areas/{area}/{itemId} — save one widget's settings. */
    public function areaUpdate(Request $request, string $area, string $itemId): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        $settings = $request->input('settings', []);
        if (!is_array($settings)) $settings = [];
        $ok = $svc->updateWidget($area, $itemId, $settings);
        $this->flash($ok ? 'success' : 'error', $ok ? 'Widget saved.' : 'Widget not found.');
        return $this->back();
    }

    /** POST /admin/widgets/areas/{area}/{itemId}/remove */
    public function areaRemove(Request $request, string $area, string $itemId): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        $ok = $svc->removeWidget($area, $itemId);
        $this->flash($ok ? 'success' : 'error', $ok ? 'Widget removed.' : 'Widget not found.');
        return $this->back();
    }

    /** POST /admin/widgets/areas/{area}/{itemId}/move — Body: {dir: up|down}. */
    public function areaMove(Request $request, string $area, string $itemId): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        $dir = $request->input('dir') === 'up' ? 'up' : 'down';
        $svc->moveWidget($area, $itemId, $dir);
        return $this->back();
    }

    /**
     * POST /admin/widgets/areas/{area}/reorder — Body: {order: [id,...]}.
     * JSON so an optional drag-and-drop UI can persist an arbitrary order.
     */
    public function areaReorder(Request $request, string $area): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        $order = $request->input('order', []);
        if (!is_array($order)) $order = [];
        $ok = $svc->reorder($area, $order);
        return Response::json(['ok' => $ok]);
    }
}
