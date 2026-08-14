# Basehim Desktop Agent API (core)

Basehim now has **built-in** connectivity to Circuits-DIY Engine desktop agents
(e.g. the ROG NUC). What used to live in the Circuits app is now core, so any
app can talk to desktop agents through one shared, audited service — and can
ship its own desktop module that installs automatically.

> **Note — `$this->agents()` is not implemented.** The base class
> `App\Core\App` has no `agents()` helper, so the examples below that call it
> will fatal. Until the helper is added, reach the service through the
> container instead:
>
> ```php
> $agents = $this->make(\App\Services\AgentService::class);
> ```
>
> Every method shown below exists on `AgentService` and works as documented —
> only the shorthand accessor is missing.

## The big picture

```
  App (PHP, server)            Basehim core                 Desktop agent (PC)
  ────────────────────            ────────────                 ──────────────────
  $this->agents()                 AgentService                 circuits-agent
    ->sendCommand(...)   ───▶   queue command      ◀──poll───  GET /agents/{uuid}/commands
    ->agents()                  track online state  ──heartbeat→ POST .../heartbeat
    ->registerModule(...)       store signed pkg    ◀──fetch──  GET .../modules/{id}
  desktop-modules/*.pkg.json  ─auto-register+push on activate→  installs the module
```

A app never opens a socket to a device. It calls `AgentService`; core handles
delivery, auth, online-tracking, and module signing/verification (the device
verifies signatures before installing).

## For app developers

Inside any app (extends `App\Core\App`), call `$this->agents()` to get the
core `AgentService`.

### See agents

```php
$all    = $this->agents()->agents();              // every agent + online flag
$online = $this->agents()->agents(onlineOnly: true);
$one    = $this->agents()->agent($agentId);
```

Each row includes `online` (bool), `name`, `last_seen_at`, plus decoded
`specs`, `capabilities`, and `metrics`. Tokens are never exposed to apps.

### Send a command

```php
$cmdId = $this->agents()->sendCommand(
    $agentId,
    'restart',                 // or shutdown, lock, sleep, message, sync-now, metrics-snapshot, …
    ['reason' => 'maintenance'],
    $this->slug()              // source = your app slug (for attribution)
);

// Poll for the result (e.g. from your app's admin AJAX endpoint):
$status = $this->agents()->commandStatus($cmdId);  // ['status'=>'done','result'=>...]
```

The device is the authority on which commands it supports; unknown commands are
acked as failed. `commandHistory($agentId, $this->slug())` returns recent
commands your app queued.

### Ship a desktop module (the automatic part)

If your app needs a companion module running inside the desktop app (for
example, a system-metrics collector), ship the **signed** package in your app
zip under:

```
your-app/
  app.json
  src/App.php
  desktop-modules/
    sysmon.pkg.json        ← signed package (cdiy-module-pkg-1)
    sysmon.meta.json       ← optional: { "name": "...", "auto_install": true }
```

On activation, core automatically:
1. registers every `desktop-modules/*.pkg.json` with your app as owner, and
2. pushes the `auto_install` ones to all agents (queues `module-install`).

So **you only upload the app zip** — the desktop module installs itself onto
the connected PC. You can also do it manually:

```php
$id = $this->agents()->registerModule($this->slug(), $signedPackage);
$this->agents()->installModuleOnAgent($id, $agentId, $this->slug());
```

Sign packages with the Circuits-DIY Engine tools
(`node tools/sign-module.js --module ./sysmon --key …`). The desktop app only
installs packages whose signature matches a trusted key baked into the app, so a
compromised server still can't push arbitrary code.

### Lifecycle hygiene

In your app's `onDeactivate()`/`onUninstall()` you may unregister modules:

```php
$this->agents()->unregisterModule($this->slug(), 'sysmon');
```

## Device-facing REST API (what the desktop app calls)

All under `/api/v1/agents` (alias `/api/v1/circuits/agents`). Per-agent bearer
token auth (issued at register); these are **not** behind the user session, so a
background agent works with nobody logged in.

| Method | Path | Purpose |
|---|---|---|
| POST | `/agents/register` | First contact / re-register; returns `agent_token` |
| POST | `/agents/{uuid}/heartbeat` | Mark online, report metrics |
| GET  | `/agents/{uuid}/commands` | Pull queued commands (also a heartbeat) |
| POST | `/agents/{uuid}/commands/{id}/ack` | Report a command's result |
| GET  | `/agents/{uuid}/modules/{id}` | Fetch a signed module package |

Register response:
```json
{ "ok": true, "agent_uuid": "…", "agent_id": 1, "agent_token": "…",
  "config": { "heartbeat_interval": 30, "command_poll_interval": 5 } }
```

## Events (hooks)

`AgentService` fires actions you can listen to with `addAction(...)`:
`agent.registered`, `agent.reregistered`, `agent.heartbeat`,
`agent.command.queued`, `agent.command.acked`, `agent.module.registered`,
`agent.module.unregistered`.

## Schema

Migration `004_agents.sql` creates `agents`, `agent_commands`, `agent_modules`,
`agent_module_targets`. Core also self-heals: if the tables are missing,
`AgentService` creates them on first use, so an existing install doesn't need a
manual migration step.

## Migrating off the Circuits / NAS app's own agent routes

Because core now serves `/api/v1/(circuits/)agents/*`, a app should no longer
register those same routes. If you previously used the Circuits app's
built-in agent endpoints, drop them and call `$this->agents()` instead. The
desktop app needs no change — it already targets `/api/v1/circuits/agents/*`,
which core now answers.
