<?php
declare(strict_types=1);

namespace Example\SystemMonitor;

use App\Core\Request;
use App\Core\Response;

/**
 * Example app showing how an app uses Basehim core's built-in
 * Agent API. It does two things:
 *
 *   1. Ships a desktop module (desktop-modules/sysmon.pkg.json). Because core
 *      auto-registers and pushes auto_install modules on activation, the
 *      desktop app gains the "sysmon" metrics collector just by activating this
 *      app — no manual module upload.
 *
 *   2. Provides an admin page + JSON API that reads agents and their reported
 *      metrics through $this->agents(), and can ask an agent for a fresh
 *      snapshot via a queued command.
 *
 * The actual charts/UI are left as a stub — this is about the connectivity
 * pattern, not the dashboard.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->addAdminMenu([
            'url'   => '/admin/sysmon',
            'label' => 'System Monitor',
            'icon'  => 'fa-gauge-high',
        ]);

        // Admin page (renders agents + a placeholder for charts).
        $this->adminGet('/sysmon', [$this, 'page']);

        // JSON: list agents with their latest metrics.
        $this->adminGet('/sysmon/api/agents.json', [$this, 'apiAgents']);

        // JSON: ask a specific agent for an immediate metrics snapshot.
        $this->adminPost('/sysmon/api/snapshot', [$this, 'apiSnapshot']);

        // JSON: poll a queued command's result.
        $this->adminGet('/sysmon/api/command/{id}.json', [$this, 'apiCommand']);
    }

    public function onActivate(): void
    {
        // Nothing required here for desktop modules — core auto-registers and
        // pushes desktop-modules/*.pkg.json on activation. This hook is just for
        // any app-specific setup you might want.
    }

    public function onDeactivate(): void
    {
        // Optional: stop offering our desktop module to agents.
        // $this->agents()->unregisterModule($this->slug(), 'sysmon');
    }

    // ---- Admin page ----
    public function page(Request $request): Response
    {
        $agents = $this->agents()->agents();
        $rows = '';
        foreach ($agents as $a) {
            $m = $a['metrics'] ?? [];
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) $a['name']),
                $a['online'] ? '🟢 online' : '⚪ offline',
                htmlspecialchars((string) ($m['cpu'] ?? '—')),
                htmlspecialchars((string) ($m['mem'] ?? '—'))
            );
        }
        $html = '<h1>System Monitor</h1>'
              . '<p>Agents reporting to this site. Charts would render here from the metrics stream.</p>'
              . '<table class="table"><thead><tr><th>Agent</th><th>Status</th><th>CPU</th><th>Memory</th></tr></thead>'
              . '<tbody>' . ($rows ?: '<tr><td colspan="4">No agents yet.</td></tr>') . '</tbody></table>';
        return Response::make($this->adminView('layout', 'System Monitor', ['content' => $html]) ?? $html);
    }

    // ---- JSON: agents + metrics ----
    public function apiAgents(Request $request): Response
    {
        return Response::json(['ok' => true, 'agents' => $this->agents()->agents()]);
    }

    // ---- JSON: request a fresh snapshot from an agent ----
    public function apiSnapshot(Request $request): Response
    {
        $agentId = (int) $request->input('agent_id', 0);
        if ($agentId <= 0) return Response::json(['ok' => false, 'error' => 'agent_id required'], 400);
        // Queue a command; the desktop "sysmon" module responds by pushing
        // metrics on its next heartbeat (or directly acking with a snapshot).
        $cmdId = $this->agents()->sendCommand($agentId, 'metrics-snapshot', [], $this->slug());
        return Response::json(['ok' => true, 'command_id' => $cmdId]);
    }

    // ---- JSON: poll a command's result ----
    public function apiCommand(Request $request, string $id): Response
    {
        $status = $this->agents()->commandStatus((int) $id);
        if (!$status) return Response::json(['ok' => false, 'error' => 'Not found'], 404);
        return Response::json(['ok' => true, 'command' => $status]);
    }
}
