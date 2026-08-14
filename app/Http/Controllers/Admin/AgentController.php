<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AgentService;
use App\Services\SettingService;

/**
 * Admin UI + JSON API for the core Desktop Agent subsystem.
 *
 * Lives in core (not a app) so connectivity is a first-class part of
 * Basehim. Lets an admin see agents, control onboarding (pairing code), send
 * power commands, and view/push desktop modules. Apps can build their own
 * richer UIs on top of AgentService; this is the built-in baseline.
 */
class AgentController extends Controller
{
    private function svc(): AgentService
    {
        return $this->app->make(AgentService::class);
    }

    private function settings(): SettingService
    {
        return $this->app->make(SettingService::class);
    }

    private function isAdmin(): bool
    {
        $u = $this->user();
        return $u && in_array($u['role'] ?? '', ['super_admin', 'admin'], true);
    }

    /** Current user's id as a nullable int (session stores it as a string). */
    private function currentUserId(): ?int
    {
        $u = $this->user();
        return isset($u['id']) ? (int) $u['id'] : null;
    }

    // ---- Page ----
    public function index(Request $request): Response
    {
        return $this->view('agents.index', [
            'title'       => 'Desktop Agents',
            'currentUser' => $this->user(),
            'pairingCode' => $this->settings()->get('core.agents', 'pairing_code', ''),
        ]);
    }

    // ---- JSON: list agents (with online state + metrics) ----
    public function apiList(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        return $this->json([
            'ok'      => true,
            'agents'  => $this->svc()->agents(),
            'modules' => $this->svc()->modules(),
        ]);
    }

    // ---- JSON: send a command to an agent ----
    public function apiCommand(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $agentId = (int) $request->input('agent_id', 0);
        $command = (string) $request->input('command', '');
        $payload = $request->input('payload', []);
        if (!is_array($payload)) $payload = [];
        if ($agentId <= 0 || $command === '') return $this->json(['ok' => false, 'error' => 'agent_id and command required'], 400);

        try {
            $u = $this->user();
            $cmdId = $this->svc()->sendCommand($agentId, $command, $payload, 'core', $this->currentUserId());
            return $this->json(['ok' => true, 'command_id' => $cmdId]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Could not queue command: ' . $e->getMessage()], 500);
        }
    }

    // ---- JSON: poll a command's status ----
    public function apiCommandStatus(Request $request, string $id): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $st = $this->svc()->commandStatus((int) $id);
        if (!$st) return $this->json(['ok' => false, 'error' => 'Not found'], 404);
        return $this->json(['ok' => true, 'command' => $st]);
    }

    // ---- JSON: install / remove a module on an agent ----
    public function apiModuleInstall(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $moduleId = (int) $request->input('module_id', 0);
        $agentId  = (int) $request->input('agent_id', 0);
        if ($moduleId <= 0 || $agentId <= 0) return $this->json(['ok' => false, 'error' => 'module_id and agent_id required'], 400);
        $u = $this->user();
        $cmdId = $this->svc()->installModuleOnAgent($moduleId, $agentId, 'core', $this->currentUserId());
        return $this->json(['ok' => true, 'command_id' => $cmdId]);
    }

    public function apiModuleRemove(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $moduleId = (int) $request->input('module_id', 0);
        $agentId  = (int) $request->input('agent_id', 0);
        if ($moduleId <= 0 || $agentId <= 0) return $this->json(['ok' => false, 'error' => 'module_id and agent_id required'], 400);
        $u = $this->user();
        $cmdId = $this->svc()->removeModuleFromAgent($moduleId, $agentId, 'core', $this->currentUserId());
        return $this->json(['ok' => true, 'command_id' => $cmdId]);
    }

    // ---- JSON: pairing code management ----
    public function apiPairing(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $action = (string) $request->input('action', 'get');
        if ($action === 'generate') {
            $code = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $this->settings()->set('core.agents', 'pairing_code', $code);
            return $this->json(['ok' => true, 'pairing_code' => $code]);
        }
        if ($action === 'clear') {
            $this->settings()->set('core.agents', 'pairing_code', '');
            return $this->json(['ok' => true, 'pairing_code' => '']);
        }
        return $this->json(['ok' => true, 'pairing_code' => $this->settings()->get('core.agents', 'pairing_code', '')]);
    }

    // ---- JSON: rename / delete an agent ----
    public function apiRename(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $agentId = (int) $request->input('agent_id', 0);
        $name    = trim((string) $request->input('name', ''));
        if ($agentId <= 0 || $name === '') return $this->json(['ok' => false, 'error' => 'agent_id and name required'], 400);
        $this->app->make(\App\Core\Database::class)->update('agents', ['name' => substr($name, 0, 150)], ['id' => $agentId]);
        return $this->json(['ok' => true]);
    }

    public function apiDelete(Request $request): Response
    {
        if (!$this->isAdmin()) return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        $agentId = (int) $request->input('agent_id', 0);
        if ($agentId <= 0) return $this->json(['ok' => false, 'error' => 'agent_id required'], 400);
        $db = $this->app->make(\App\Core\Database::class);
        $db->delete('agent_commands', ['agent_id' => $agentId]);
        $db->delete('agent_module_targets', ['agent_id' => $agentId]);
        $db->delete('agents', ['id' => $agentId]);
        return $this->json(['ok' => true]);
    }
}
