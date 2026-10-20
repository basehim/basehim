<?php
declare(strict_types=1);

namespace Example\SystemMonitor;

use App\Core\Request;
use App\Core\Response;
use App\Services\Dashboard\WidgetContext;
use App\Services\Dashboard\WidgetUnavailable;

/**
 * Example app: what the server this site runs on is doing.
 *
 * Copy this folder to content/apps/system-monitor and activate it. It shows:
 *
 *   - an admin page (adminGet) behind the app's access capability, rendered in
 *     the admin layout with adminView() and an app template;
 *   - a dashboard card (registerWidget with 'surfaces' => ['dashboard']) fed
 *     by a data provider, with a setting, a cache and an action;
 *   - the "unavailable" state, for hosts that hide load and memory figures.
 *
 * Everything comes from PHP itself (sys_getloadavg, disk_free_space,
 * /proc/meminfo where readable) — no agents, no extensions, nothing to install.
 *
 * Until 1.2.70 this example called $this->agents(), a desktop-agent API core
 * never shipped, and fataled when copied in (issues.md BH-004).
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->addAdminMenu([
            'url'   => '/admin/system-monitor',
            'label' => 'System Monitor',
            'icon'  => 'cpu-chip',
        ]);

        // GET /admin/system-monitor — signed in, with access_app:system-monitor.
        $this->adminGet('/system-monitor', [$this, 'page']);

        // A dashboard card. Keys are namespaced: this is 'system-monitor.server'.
        $this->registerWidget('server', [
            'title'       => 'Server',
            'description' => 'Load, memory and free disk space on the server this site runs on.',
            'icon'        => 'cpu-chip',
            'surfaces'    => ['dashboard'],
            'capability'  => 'access_app:' . $this->slug(),
            'fields'      => [
                ['key' => 'warn_disk', 'label' => 'Warn when free disk is below (%)', 'type' => 'number',
                 'min' => 1, 'max' => 50, 'default' => 10],
            ],
            'dashboard'   => [
                'view'    => 'status',
                'sizes'   => ['third', 'half'],
                'size'    => 'third',
                'cache'   => ['ttl' => 60, 'scope' => 'global'],
                'refresh' => 300,
                'data'    => [$this, 'card'],
                'actions' => [
                    ['id' => 'open', 'label' => 'Open System Monitor', 'url' => (defined('BASEHIM_BASE') ? BASEHIM_BASE : '') . '/admin/system-monitor', 'icon' => 'arrow-top-right-on-square'],
                ],
            ],
        ]);
    }

    /** The admin page. */
    public function page(Request $request): Response
    {
        return Response::make($this->adminView('page', 'System Monitor', ['m' => $this->readings()]));
    }

    /** The dashboard card's data: a status list. */
    public function card(WidgetContext $ctx): array
    {
        $m = $this->readings();
        if ($m['load'] === null && $m['mem_used_pct'] === null && $m['disk_free_pct'] === null) {
            // Not an error: this host doesn't let PHP see these figures.
            throw new WidgetUnavailable('This host does not let PHP read load, memory or disk figures.');
        }
        $warnDisk = (int) $ctx->setting('warn_disk', 10);
        $items = [];
        if ($m['load'] !== null) {
            $perCore = $m['cores'] > 0 ? $m['load'][0] / $m['cores'] : $m['load'][0];
            $items[] = ['label' => 'Load (1 min)', 'detail' => number_format($m['load'][0], 2) . ($m['cores'] ? " on {$m['cores']} cores" : ''),
                        'state' => $perCore >= 1.5 ? 'error' : ($perCore >= 0.8 ? 'warn' : 'ok')];
        }
        if ($m['mem_used_pct'] !== null) {
            $items[] = ['label' => 'Memory used', 'detail' => $m['mem_used_pct'] . '%',
                        'state' => $m['mem_used_pct'] >= 95 ? 'error' : ($m['mem_used_pct'] >= 85 ? 'warn' : 'ok')];
        }
        if ($m['disk_free_pct'] !== null) {
            $items[] = ['label' => 'Disk free', 'detail' => $m['disk_free'] . ' (' . $m['disk_free_pct'] . '%)',
                        'state' => $m['disk_free_pct'] < $warnDisk / 2 ? 'error' : ($m['disk_free_pct'] < $warnDisk ? 'warn' : 'ok')];
        }
        $items[] = ['label' => 'PHP', 'detail' => PHP_VERSION, 'state' => 'ok'];
        $problems = count(array_filter($items, fn($i) => $i['state'] !== 'ok'));
        return [
            'summary' => $problems === 0 ? 'The server looks healthy.' : ($problems === 1 ? '1 reading needs a look.' : "{$problems} readings need a look."),
            'items'   => $items,
        ];
    }

    /** Everything PHP can see, with null for what this host hides. */
    private function readings(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        $cores = 0;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo)) $cores = substr_count($cpuinfo, "\nprocessor") + (str_starts_with($cpuinfo, 'processor') ? 1 : 0);

        $memUsedPct = null;
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo) && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $t) && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a) && (int) $t[1] > 0) {
            $memUsedPct = (int) round(100 - ((int) $a[1] / (int) $t[1] * 100));
        }

        $free = @disk_free_space(BASEHIM_ROOT);
        $total = @disk_total_space(BASEHIM_ROOT);
        $diskFreePct = ($free !== false && $total) ? (int) round($free / $total * 100) : null;

        return [
            'load'          => is_array($load) ? array_map('floatval', $load) : null,
            'cores'         => $cores,
            'mem_used_pct'  => $memUsedPct,
            'disk_free'     => $free !== false ? $this->bytes((float) $free) : null,
            'disk_total'    => $total ? $this->bytes((float) $total) : null,
            'disk_free_pct' => $diskFreePct,
            'php'           => PHP_VERSION,
            'server'        => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
            'os'            => php_uname('s') . ' ' . php_uname('r'),
        ];
    }

    private function bytes(float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
        return round($b, 1) . ' ' . $u[$i];
    }
}
