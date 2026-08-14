<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AgentService;

/**
 * AgentController — the device-facing REST surface for Circuits-DIY Engine
 * desktop agents. Mounted under /api/v1/agents (with a /circuits/agents alias
 * for the existing desktop build).
 *
 * Auth model: /register is open (first contact mints a token). Every other
 * endpoint requires the per-agent bearer token issued at registration —
 * validated here against the {uuid} in the path, NOT the CMS user session, so a
 * background agent works without anyone being logged in.
 */
class AgentController extends ApiController
{
    private function svc(): AgentService
    {
        return $this->app->make(AgentService::class);
    }

    /** Resolve + authenticate the agent named in the route, or null. */
    private function authAgent(Request $request, string $uuid): ?array
    {
        return $this->svc()->authenticateAgent($uuid, $request->bearerToken());
    }

    /** POST /agents/register — first contact or re-registration. */
    public function register(Request $request): Response
    {
        $data = $request->all();
        $uuid = (string) ($data['uuid'] ?? $data['agent_uuid'] ?? '');

        // If a known agent re-registers, require its existing token to match.
        $isKnownReauth = false;
        if ($uuid !== '') {
            $existing = $this->svc()->agentByUuid($uuid);
            if ($existing) {
                $auth = $this->svc()->authenticateAgent($uuid, $request->bearerToken());
                if (!$auth) {
                    // Unknown/!matching token for an existing uuid → reject so a
                    // stranger can't hijack an agent identity.
                    return Response::json(['ok' => false, 'error' => 'Re-registration token mismatch'], 401);
                }
                $isKnownReauth = true;
            }
        }

        // Optional pairing gate for FIRST contact only. If an admin has set a
        // pairing code, a brand-new agent must present it. Known agents
        // re-registering with a valid token bypass this (they're already trusted).
        if (!$isKnownReauth) {
            $required = $this->pairingCode();
            if ($required !== null && $required !== '') {
                $supplied = (string) ($data['pairing_code'] ?? $request->header('X-Pairing-Code', '') ?? '');
                if (!hash_equals($required, $supplied)) {
                    return Response::json(['ok' => false, 'error' => 'Pairing code required or incorrect'], 403);
                }
            }
        }

        [$row, $token, $created] = $this->svc()->registerAgent($data);
        return Response::json([
            'ok'         => true,
            'agent_uuid' => $row['uuid'],
            'agent_id'   => (int) $row['id'],
            'agent_token'=> $token,
            'created'    => $created,
            'config'     => [
                'heartbeat_interval'   => (int) ($this->heartbeatInterval()),
                'command_poll_interval'=> 5,
            ],
        ]);
    }

    /** The admin-configured pairing code, or null if onboarding is open. */
    /** The admin-configured pairing code, or null if onboarding is open. */
    private function heartbeatInterval(): int
    {
        try {
            $v = (int) $this->app->make(\App\Services\SettingService::class)
                ->get('core.agents', 'heartbeat_interval', 5);
            return max(2, min(60, $v ?: 5));   // clamp 2-60s, default 5s
        } catch (\Throwable) {
            return 5;
        }
    }

    private function pairingCode(): ?string
    {
        try {
            $val = $this->app->make(\App\Services\SettingService::class)
                ->get('core.agents', 'pairing_code', null);
            return ($val === null || $val === '') ? null : (string) $val;
        } catch (\Throwable) {
            return null; // settings unavailable → don't lock anyone out
        }
    }

    /** POST /agents/{uuid}/heartbeat */
    public function heartbeat(Request $request, string $uuid): Response
    {
        $agent = $this->authAgent($request, $uuid);
        if (!$agent) return Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
        $this->svc()->heartbeat((int) $agent['id'], $request->all());
        return Response::json(['ok' => true]);
    }

    /** GET /agents/{uuid}/commands — pull queued commands (also a heartbeat). */
    public function commands(Request $request, string $uuid): Response
    {
        $agent = $this->authAgent($request, $uuid);
        if (!$agent) return Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
        $cmds = $this->svc()->pullCommands((int) $agent['id']);
        return Response::json(['ok' => true, 'commands' => $cmds]);
    }

    /** POST /agents/{uuid}/commands/{id}/ack */
    public function ackCommand(Request $request, string $uuid, string $id): Response
    {
        $agent = $this->authAgent($request, $uuid);
        if (!$agent) return Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
        $ok = (bool) ($request->input('ok', true));
        $this->svc()->ackCommand(
            (int) $agent['id'],
            (int) $id,
            $ok,
            $request->input('result'),
            $request->input('error') ? (string) $request->input('error') : null
        );
        return Response::json(['ok' => true]);
    }

    /** GET /agents/{uuid}/modules/{id} — fetch a signed module package. */
    public function module(Request $request, string $uuid, string $id): Response
    {
        $agent = $this->authAgent($request, $uuid);
        if (!$agent) return Response::json(['ok' => false, 'error' => 'Unauthorized'], 401);
        $pkg = $this->svc()->modulePackage((int) $id);
        if (!$pkg) return Response::json(['ok' => false, 'error' => 'Module not found'], 404);
        return Response::json(['ok' => true, 'package' => $pkg]);
    }
}
