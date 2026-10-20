<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\WidgetRegistry;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckCapability;
use App\Services\Dashboard\DashboardService;
use App\Services\SettingService;

class DashboardController extends Controller
{
    /**
     * The dashboard (1.2.68): a grid of widgets, each loaded on its own.
     *
     * The page paints straight away: it carries the user's layout and the
     * Add Widgets catalogue, and dashboard.js asks for every card's data in
     * one batched request (widgets.dashboard_data), so a slow or failing
     * widget holds up nothing but its own card. The only HTML rendered here is
     * that of pre-1.2.68 widgets (render callbacks), so their inline scripts
     * still run as they did on the old dashboard.
     *
     * NOTE: the update check used to run here, which meant the dashboard
     * blocked on a round-trip to the update service before it could render.
     * It runs in the background from the browser once the page has painted
     * (POST /admin/updates/sync.json).
     */
    public function index(Request $request): Response
    {
        $user = $this->user() ?? [];
        /** @var DashboardService $dashboard */
        $dashboard = $this->app->make(DashboardService::class);
        /** @var WidgetRegistry $registry */
        $registry = $this->app->make(WidgetRegistry::class);
        /** @var SettingService $settings */
        $settings = $this->app->make(SettingService::class);

        $resolved = $dashboard->resolve($user);
        $catalog = $dashboard->catalog($user, $resolved);

        // Legacy widgets: render their HTML into the page, once, for the
        // cards that show. Each is isolated by WidgetRegistry::render().
        $legacy = [];
        foreach ($resolved['items'] as $it) {
            if (!$it['visible'] || $it['collapsed']) continue;
            $w = $registry->get($it['widget']);
            if ($w === null || isset($w['dashboard']['data']) || isset($w['dashboard']['endpoint'])) continue;
            $legacy[$it['id']] = $registry->render($w['key'], $it['settings'], 'dashboard');
        }

        return $this->view('dashboard.index', [
            'title'         => 'Dashboard',
            'currentUser'   => $user,
            'siteName'      => $settings->get('general', 'site_title', 'Basehim'),
            'layout'        => ['items' => $resolved['items'], 'customized' => $resolved['customized']],
            'catalog'       => $catalog,
            'legacyHtml'    => $legacy,
            'hasSiteDefault'=> $dashboard->hasSiteDefault(),
            'canSetDefault' => CheckCapability::userCan($user, 'manage_settings'),
            'canWrite'      => CheckCapability::userCan($user, 'edit_posts'),
            'canUpload'     => CheckCapability::userCan($user, 'upload_media'),
        ]);
    }
}
