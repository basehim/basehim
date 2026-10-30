<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\WidgetAreaRegistry;
use App\Services\WidgetAreaService;

/**
 * Public, read-only view of the site's widget areas so a decoupled/headless
 * front end can render the same sidebars the theme would. Each area's widgets
 * are returned server-rendered to HTML (the same output the PHP theme emits).
 */
class WidgetController extends ApiController
{
    /** GET /api/v1/widget-areas — list registered areas with their widget counts. */
    public function areas(Request $request): Response
    {
        /** @var WidgetAreaRegistry $reg */
        $reg = $this->app->make(WidgetAreaRegistry::class);
        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);

        $data = [];
        foreach ($reg->all() as $area) {
            $data[] = [
                'key'          => $area['key'],
                'name'         => $area['name'],
                'description'  => $area['description'],
                'source'       => $area['source'],
                'widget_count' => count($svc->assignmentsFor($area['key'])),
            ];
        }
        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/v1/widget-areas/{area} — one area with its ordered widgets, each
     * rendered to HTML. 404 when the area isn't registered by the active theme.
     */
    public function area(Request $request, string $area): Response
    {
        /** @var WidgetAreaRegistry $reg */
        $reg = $this->app->make(WidgetAreaRegistry::class);
        $def = $reg->get($area);
        if (!$def) return Response::json(['error' => 'Not found'], 404);

        /** @var \App\Core\Widgets\FrontendWidgets $front */
        $front = $this->app->make(\App\Core\Widgets\FrontendWidgets::class);
        /** @var \App\Core\WidgetRegistry $widgets */
        $widgets = $this->app->make(\App\Core\WidgetRegistry::class);

        // Only what visitors see (1.2.93): hidden and unavailable widgets are
        // left out, and so are settings a widget marks private and raw HTML
        // fields, which until 1.2.93 this public endpoint returned as saved.
        $items = [];
        foreach ($front->placements()->area($def['key']) as $inst) {
            if ($inst['disabled'] || $front->unavailable($inst['widget']) !== null) continue;
            $meta = $widgets->get($inst['widget']);
            $items[] = [
                'id'       => $inst['id'],
                'widget'   => $meta['key'],
                'title'    => $meta['title'],
                'settings' => $front->publicSettings($inst),
                'html'     => $front->renderInstance($def, $inst),
            ];
        }

        return Response::json([
            'data' => [
                'key'         => $def['key'],
                'name'        => $def['name'],
                'description' => $def['description'],
                'items'       => $items,
                'html'        => $front->renderArea($def['key']),
            ],
        ]);
    }
}
