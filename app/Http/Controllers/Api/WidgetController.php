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

        /** @var WidgetAreaService $svc */
        $svc = $this->app->make(WidgetAreaService::class);
        /** @var \App\Core\WidgetRegistry $widgets */
        $widgets = $this->app->make(\App\Core\WidgetRegistry::class);

        $items = [];
        foreach ($svc->assignmentsFor($area) as $inst) {
            $key = (string) ($inst['widget'] ?? '');
            $meta = $widgets->get($key);
            if (!$meta || !in_array('frontend', $meta['surfaces'], true)) continue;
            $settings = is_array($inst['settings'] ?? null) ? $inst['settings'] : [];
            $items[] = [
                'id'       => (string) ($inst['id'] ?? ''),
                'widget'   => $key,
                'title'    => $meta['title'],
                'settings' => $settings,
                'html'     => $widgets->render($key, $settings, 'frontend'),
            ];
        }

        return Response::json([
            'data' => [
                'key'         => $def['key'],
                'name'        => $def['name'],
                'description' => $def['description'],
                'items'       => $items,
                'html'        => $svc->render($area),
            ],
        ]);
    }
}
