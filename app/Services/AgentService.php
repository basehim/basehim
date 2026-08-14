<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HookRegistry;
use App\Core\Logger;

/**
 * AgentService — Basehim core's built-in bridge to Circuits-DIY Engine desktop
 * agents (e.g. the ROG NUC).
 *
 * This used to live in the Circuits app; it now ships in core so ANY app
 * can talk to agents through one shared, audited channel. A app never talks
 * to a device directly — it goes through this service, which:
 *
 *   • tracks registered agents and their online/offline state
 *   • queues commands for an agent and exposes their results
 *   • stores signed desktop modules and serves them to agents on demand
 *   • records which module is installed on which agent
 *
 * The device-facing HTTP contract (register / heartbeat / commands / ack /
 * module fetch) is served by routes in routes/api.php which delegate here.
 *
 * App-facing methods are grouped at the top; the device-facing methods used
 * by the API controller are grouped below.
 */
class AgentService
{
    /** Seconds since last_seen within which an agent counts as online. */
    public const ONLINE_WINDOW = 90;

    /**
     * Commands core knows how to deliver. Apps may queue any of these; the
     * desktop app decides what it supports. Kept permissive on purpose — the
     * device is the final authority on what it will run.
     */
    public const KNOWN_COMMANDS = [
        'shutdown', 'restart', 'cancel-shutdown', 'lock', 'sleep',
        'message', 'sync-now', 'app-quit',
        'module-install', 'module-update', 'module-remove', 'module-toggle',
        'metrics-snapshot',          // ask the agent for an immediate metrics push
    ];

    public function __construct(
        private Database $db,
        private HookRegistry $hooks,
        private ?Logger $logger = null,
    ) {}

    /**
     * Idempotently ensure the agent tables exist. Basehim has no automatic
     * migration runner for existing installs, so core self-heals its own schema
     * the first time the service is used. Guarded so the DDL runs at most once
     * per process and is skipped entirely once the tables are present.
     */
    private bool $schemaChecked = false;
    public function ensureSchema(): void
    {
        if ($this->schemaChecked) return;
        $this->schemaChecked = true;
        // All statements are CREATE TABLE IF NOT EXISTS, so running them every
        // boot is cheap and self-healing. We must NOT early-return just because
        // `agents` exists — a partial migration (agents created but
        // agent_commands missing) would otherwise never repair, and queuing a
        // command would 500 on a missing table.

        $statements = [
            "CREATE TABLE IF NOT EXISTS {agents} (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `uuid` CHAR(36) NOT NULL,
                `name` VARCHAR(150) NOT NULL DEFAULT 'Desktop Agent',
                `token` CHAR(64) NOT NULL,
                `hostname` VARCHAR(191) NULL,
                `platform` VARCHAR(60) NULL,
                `app_version` VARCHAR(40) NULL,
                `specs` MEDIUMTEXT NULL,
                `capabilities` MEDIUMTEXT NULL,
                `metrics` MEDIUMTEXT NULL,
                `status` ENUM('online','offline') NOT NULL DEFAULT 'offline',
                `paired_by` INT UNSIGNED NULL,
                `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_uuid` (`uuid`),
                KEY `idx_status` (`status`),
                KEY `idx_last_seen` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS {agent_commands} (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `agent_id` INT UNSIGNED NOT NULL,
                `command` VARCHAR(64) NOT NULL,
                `payload` MEDIUMTEXT NULL,
                `source` VARCHAR(80) NOT NULL DEFAULT 'core',
                `status` ENUM('queued','sent','done','failed','expired') NOT NULL DEFAULT 'queued',
                `result` MEDIUMTEXT NULL,
                `error` VARCHAR(1000) NULL,
                `created_by` INT UNSIGNED NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `sent_at` TIMESTAMP NULL DEFAULT NULL,
                `finished_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `idx_agent_status` (`agent_id`, `status`),
                KEY `idx_source` (`source`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS {agent_modules} (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `module_slug` VARCHAR(80) NOT NULL,
                `owner` VARCHAR(80) NOT NULL DEFAULT 'core',
                `name` VARCHAR(150) NOT NULL,
                `version` VARCHAR(40) NOT NULL,
                `key_id` VARCHAR(40) NULL,
                `package` LONGTEXT NOT NULL,
                `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `auto_install` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_owner_module` (`owner`, `module_slug`),
                KEY `idx_module` (`module_slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS {agent_module_targets} (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `module_id` INT UNSIGNED NOT NULL,
                `agent_id` INT UNSIGNED NOT NULL,
                `state` ENUM('pending','installed','failed','removed') NOT NULL DEFAULT 'pending',
                `installed_version` VARCHAR(40) NULL,
                `error` VARCHAR(1000) NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_module_agent` (`module_id`, `agent_id`),
                KEY `idx_agent` (`agent_id`),
                KEY `idx_state` (`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            try { $this->db->execute($sql); } catch (\Throwable $e) {
                if ($this->logger) $this->logger->error('Agent schema create failed', ['error' => $e->getMessage()]);
            }
        }
    }

    // ======================================================================
    // ===== App-facing API ==============================================
    // ======================================================================

    /**
     * List agents with a computed `online` flag. Apps use this to render
     * "active agents", pick a target, etc.
     *
     * @return array<int,array<string,mixed>>
     */
    public function agents(bool $onlineOnly = false): array
    {
        $this->ensureSchema();
        $rows = $this->db->select('SELECT * FROM {agents} ORDER BY name ASC');
        $out = [];
        foreach ($rows as $r) {
            $r['online'] = $this->isOnline($r);
            unset($r['token']);                  // never leak tokens to apps
            if ($onlineOnly && !$r['online']) continue;
            $r['specs']        = $this->decode($r['specs'] ?? null);
            $r['capabilities'] = $this->decode($r['capabilities'] ?? null);
            $r['metrics']      = $this->decode($r['metrics'] ?? null);
            $out[] = $r;
        }
        return $out;
    }

    /** Fetch a single agent by id (token stripped). */
    public function agent(int $agentId): ?array
    {
        $this->ensureSchema();
        $r = $this->db->selectOne('SELECT * FROM {agents} WHERE id = :id', ['id' => $agentId]);
        if (!$r) return null;
        $r['online'] = $this->isOnline($r);
        unset($r['token']);
        $r['specs']        = $this->decode($r['specs'] ?? null);
        $r['capabilities'] = $this->decode($r['capabilities'] ?? null);
        $r['metrics']      = $this->decode($r['metrics'] ?? null);
        return $r;
    }

    /** Find an agent by UUID (token stripped). */
    public function agentByUuid(string $uuid): ?array
    {
        $this->ensureSchema();
        $r = $this->db->selectOne('SELECT * FROM {agents} WHERE uuid = :u', ['u' => $uuid]);
        if (!$r) return null;
        $r['online'] = $this->isOnline($r);
        unset($r['token']);
        return $r;
    }

    /** True if the agent (row or id) is currently online. */
    public function isOnline(array|int $agent): bool
    {
        $this->ensureSchema();
        if (is_int($agent)) {
            $agent = $this->db->selectOne('SELECT last_seen_at FROM {agents} WHERE id = :id', ['id' => $agent]) ?: [];
        }
        $last = $agent['last_seen_at'] ?? null;
        if (!$last) return false;
        return (time() - strtotime((string) $last)) <= self::ONLINE_WINDOW;
    }

    /**
     * Queue a command for an agent. Returns the command id. `source` should be
     * the calling app's slug so commands are attributable; defaults to core.
     *
     * Example (from a app):
     *   $this->agents()->sendCommand($agentId, 'restart', [], $this->slug());
     */
    public function sendCommand(int $agentId, string $command, array $payload = [], string $source = 'core', int|string|null $userId = null): int
    {
        $this->ensureSchema();
        $userId = ($userId === null || $userId === '') ? null : (int) $userId;
        $id = (int) $this->db->insert('agent_commands', [
            'agent_id' => $agentId,
            'command'  => $command,
            'payload'  => $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'source'   => substr($source, 0, 80),
            'status'   => 'queued',
            'created_by' => $userId,
        ]);
        $this->fire('agent.command.queued', ['agent_id' => $agentId, 'command' => $command, 'id' => $id, 'source' => $source]);
        return $id;
    }

    /** Read a command's current status/result (for the app's UI to poll). */
    public function commandStatus(int $commandId): ?array
    {
        $this->ensureSchema();
        $r = $this->db->selectOne('SELECT id, agent_id, command, status, result, error, source, created_at, finished_at FROM {agent_commands} WHERE id = :id', ['id' => $commandId]);
        if (!$r) return null;
        $r['result'] = $this->decode($r['result'] ?? null);
        return $r;
    }

    /** Recent commands for an agent (optionally filtered to a app source). */
    public function commandHistory(int $agentId, ?string $source = null, int $limit = 50): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));
        if ($source !== null) {
            return $this->db->select(
                'SELECT id, command, status, error, source, created_at, finished_at FROM {agent_commands} WHERE agent_id = :a AND source = :s ORDER BY id DESC LIMIT ' . $limit,
                ['a' => $agentId, 's' => $source]
            );
        }
        return $this->db->select(
            'SELECT id, command, status, error, source, created_at, finished_at FROM {agent_commands} WHERE agent_id = :a ORDER BY id DESC LIMIT ' . $limit,
            ['a' => $agentId]
        );
    }

    /**
     * Register (or update) a signed desktop module for delivery to agents. A
     * app calls this — typically automatically via its shipped
     * desktop-modules/ folder (see registerModulesFromManifest) — so that the
     * desktop app gains the companion module it needs.
     *
     * $package is the verbatim signed package object (cdiy-module-pkg-1).
     *
     * @return int module id
     */
    public function registerModule(string $owner, array $package, array $opts = []): int
    {
        $this->ensureSchema();
        // Pull display metadata out of the signed payload if not supplied.
        $meta = $this->packageMeta($package);
        $slug    = $opts['module_slug'] ?? $meta['slug'] ?? null;
        $name    = $opts['name']        ?? $meta['name'] ?? ($slug ?: 'Module');
        $version = $opts['version']     ?? $meta['version'] ?? '0.0.0';
        if (!$slug) throw new \InvalidArgumentException('registerModule: module slug missing from package and options');

        $json = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = $this->db->selectOne(
            'SELECT id FROM {agent_modules} WHERE owner = :o AND module_slug = :s',
            ['o' => $owner, 's' => $slug]
        );
        $data = [
            'module_slug'  => $slug,
            'owner'        => substr($owner, 0, 80),
            'name'         => substr($name, 0, 150),
            'version'      => substr($version, 0, 40),
            'key_id'       => $package['key_id'] ?? null,
            'package'      => $json,
            'size_bytes'   => strlen($json),
            'auto_install' => array_key_exists('auto_install', $opts) ? (int) (bool) $opts['auto_install'] : 1,
        ];
        if ($existing) {
            $this->db->update('agent_modules', $data, ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = (int) $this->db->insert('agent_modules', $data);
        }
        $this->fire('agent.module.registered', ['owner' => $owner, 'module_slug' => $slug, 'version' => $version, 'id' => $id]);
        return $id;
    }

    /** Remove a registered module (and stop offering it to agents). */
    public function unregisterModule(string $owner, string $moduleSlug): void
    {
        $this->ensureSchema();
        $row = $this->db->selectOne('SELECT id FROM {agent_modules} WHERE owner = :o AND module_slug = :s', ['o' => $owner, 's' => $moduleSlug]);
        if (!$row) return;
        $this->db->delete('agent_module_targets', ['module_id' => (int) $row['id']]);
        $this->db->delete('agent_modules', ['id' => (int) $row['id']]);
        $this->fire('agent.module.unregistered', ['owner' => $owner, 'module_slug' => $moduleSlug]);
    }

    /** Modules registered by an owner (app slug), package stripped. */
    public function modules(?string $owner = null): array
    {
        $this->ensureSchema();
        $rows = $owner !== null
            ? $this->db->select('SELECT id, module_slug, owner, name, version, key_id, size_bytes, auto_install, updated_at FROM {agent_modules} WHERE owner = :o ORDER BY name', ['o' => $owner])
            : $this->db->select('SELECT id, module_slug, owner, name, version, key_id, size_bytes, auto_install, updated_at FROM {agent_modules} ORDER BY owner, name');
        return $rows;
    }

    /**
     * Push a registered module to an agent: queues a module-install command
     * carrying the module id, and records a pending target row. The agent will
     * fetch the signed package by id, verify it, and install it.
     */
    public function installModuleOnAgent(int $moduleId, int $agentId, string $source = 'core', int|string|null $userId = null): int
    {
        $userId = ($userId === null || $userId === '') ? null : (int) $userId;
        $this->ensureSchema();
        $mod = $this->db->selectOne('SELECT * FROM {agent_modules} WHERE id = :id', ['id' => $moduleId]);
        if (!$mod) throw new \InvalidArgumentException('Unknown module id');

        // Upsert the per-agent target as pending.
        $existing = $this->db->selectOne('SELECT id FROM {agent_module_targets} WHERE module_id = :m AND agent_id = :a', ['m' => $moduleId, 'a' => $agentId]);
        if ($existing) {
            $this->db->update('agent_module_targets', ['state' => 'pending', 'error' => null], ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('agent_module_targets', ['module_id' => $moduleId, 'agent_id' => $agentId, 'state' => 'pending']);
        }
        return $this->sendCommand($agentId, 'module-install', [
            'module_id'   => $moduleId,     // for server-side reconciliation
            'package_id'  => $moduleId,     // desktop fetches /modules/{package_id}
            'module_slug' => $mod['module_slug'],
            'version'     => $mod['version'],
        ], $source, $userId);
    }

    /** Remove a module from an agent. */
    public function removeModuleFromAgent(int $moduleId, int $agentId, string $source = 'core', int|string|null $userId = null): int
    {
        $userId = ($userId === null || $userId === '') ? null : (int) $userId;
        $this->ensureSchema();
        $mod = $this->db->selectOne('SELECT module_slug FROM {agent_modules} WHERE id = :id', ['id' => $moduleId]);
        $slug = $mod['module_slug'] ?? null;
        $this->db->update('agent_module_targets', ['state' => 'removed'], ['module_id' => $moduleId, 'agent_id' => $agentId]);
        return $this->sendCommand($agentId, 'module-remove', ['module_id' => $moduleId, 'slug' => $slug], $source, $userId);
    }

    /**
     * Auto-install all auto_install modules owned by $owner onto every online
     * agent that doesn't already have them. Called on app activation so a
     * freshly-installed app lights up its desktop companion automatically.
     *
     * @return int number of install commands queued
     */
    public function autoInstallOwnerModules(string $owner, int|string|null $userId = null): int
    {
        $userId = ($userId === null || $userId === '') ? null : (int) $userId;
        $this->ensureSchema();
        $mods = $this->db->select('SELECT id FROM {agent_modules} WHERE owner = :o AND auto_install = 1', ['o' => $owner]);
        if (!$mods) return 0;
        $agents = $this->db->select('SELECT id, last_seen_at FROM {agents}');
        $queued = 0;
        foreach ($mods as $m) {
            foreach ($agents as $a) {
                // Skip agents that already have it installed at this version.
                $tgt = $this->db->selectOne(
                    'SELECT state FROM {agent_module_targets} WHERE module_id = :m AND agent_id = :a',
                    ['m' => (int) $m['id'], 'a' => (int) $a['id']]
                );
                if ($tgt && $tgt['state'] === 'installed') continue;
                $this->installModuleOnAgent((int) $m['id'], (int) $a['id'], $owner, $userId);
                $queued++;
            }
        }
        return $queued;
    }

    // ======================================================================
    // ===== Device-facing API (used by the REST controller) ================
    // ======================================================================

    /**
     * Register or re-register an agent. First contact creates the row and mints
     * a token; subsequent calls with the same uuid + matching token refresh it.
     * Returns [row, token, created].
     */
    public function registerAgent(array $data): array
    {
        $this->ensureSchema();
        $uuid = (string) ($data['uuid'] ?? $data['agent_uuid'] ?? '');
        if ($uuid === '') {
            $uuid = $this->uuid4();
        }
        $existing = $this->db->selectOne('SELECT * FROM {agents} WHERE uuid = :u', ['u' => $uuid]);

        // The desktop app sends hardware fields flat at the top level
        // (hostname, cpu_model, cpu_cores, os_release, arch, total_memory, …)
        // rather than under a nested "specs" object. Fold those into the specs
        // JSON so the System Monitor dashboard can show CPU/cores/OS/etc.
        $specs = isset($data['specs']) && is_array($data['specs']) ? $data['specs'] : [];
        $flatSpecKeys = [
            'hostname', 'label', 'machine', 'platform', 'os', 'os_release', 'os_type',
            'arch', 'cpu_model', 'cpuBrand', 'cpu_cores', 'cpuCores', 'cpu_physical',
            'cpu_speed', 'cpuSpeed', 'total_memory', 'memTotal', 'free_memory',
            'gpu_model', 'gpus', 'node_version', 'app_version', 'user_account',
        ];
        foreach ($flatSpecKeys as $k) {
            if (array_key_exists($k, $data) && !array_key_exists($k, $specs)) {
                $specs[$k] = $data[$k];
            }
        }
        // The desktop nests a few extras under meta.
        if (isset($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $mk => $mv) {
                if (!array_key_exists($mk, $specs)) $specs[$mk] = $mv;
            }
        }

        $fields = [
            'name'        => substr((string) ($data['name'] ?? $data['label'] ?? $data['hostname'] ?? 'Desktop Agent'), 0, 150),
            'hostname'    => isset($data['hostname']) ? substr((string) $data['hostname'], 0, 191) : null,
            'platform'    => isset($data['platform']) ? substr((string) $data['platform'], 0, 60) : null,
            'app_version' => isset($data['app_version']) ? substr((string) $data['app_version'], 0, 40) : null,
            'specs'       => $specs ? json_encode($specs, JSON_UNESCAPED_SLASHES) : null,
            'capabilities'=> isset($data['capabilities']) ? json_encode($data['capabilities'], JSON_UNESCAPED_SLASHES) : null,
            'status'      => 'online',
            'last_seen_at'=> date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            // Keep the existing token (re-registration); only mint if missing.
            $token = (string) ($existing['token'] ?: $this->token());
            $fields['token'] = $token;
            if (isset($data['paired_by'])) $fields['paired_by'] = (int) $data['paired_by'];
            $this->db->update('agents', $fields, ['id' => (int) $existing['id']]);
            $row = $this->db->selectOne('SELECT * FROM {agents} WHERE id = :id', ['id' => (int) $existing['id']]);
            $this->fire('agent.reregistered', ['uuid' => $uuid, 'id' => (int) $existing['id']]);
            return [$row, $token, false];
        }

        $token = $this->token();
        $fields['uuid']  = $uuid;
        $fields['token'] = $token;
        if (isset($data['paired_by'])) $fields['paired_by'] = (int) $data['paired_by'];
        $id = (int) $this->db->insert('agents', $fields);
        $row = $this->db->selectOne('SELECT * FROM {agents} WHERE id = :id', ['id' => $id]);
        $this->fire('agent.registered', ['uuid' => $uuid, 'id' => $id]);
        return [$row, $token, true];
    }

    /** Validate an agent's bearer token against its uuid. Returns row or null. */
    public function authenticateAgent(string $uuid, ?string $bearer): ?array
    {
        $this->ensureSchema();
        if (!$bearer) return null;
        $row = $this->db->selectOne('SELECT * FROM {agents} WHERE uuid = :u', ['u' => $uuid]);
        if (!$row) return null;
        if (!hash_equals((string) $row['token'], $bearer)) return null;
        return $row;
    }

    /** Heartbeat: mark online, store latest metrics. */
    public function heartbeat(int $agentId, array $data = []): void
    {
        $this->ensureSchema();
        $upd = ['status' => 'online', 'last_seen_at' => date('Y-m-d H:i:s')];
        if (isset($data['metrics'])) $upd['metrics'] = json_encode($data['metrics'], JSON_UNESCAPED_SLASHES);
        if (isset($data['app_version'])) $upd['app_version'] = substr((string) $data['app_version'], 0, 40);
        $this->db->update('agents', $upd, ['id' => $agentId]);
        $this->fire('agent.heartbeat', ['agent_id' => $agentId]);
    }

    /**
     * Pull queued commands for an agent, marking them sent. Doubles as a
     * heartbeat. Returns a list of {id, command, payload}.
     */
    public function pullCommands(int $agentId, int $max = 20): array
    {
        $this->ensureSchema();
        $this->heartbeat($agentId);
        $max = max(1, min(50, $max));
        $rows = $this->db->select(
            "SELECT id, command, payload FROM {agent_commands} WHERE agent_id = :a AND status = 'queued' ORDER BY id ASC LIMIT " . $max,
            ['a' => $agentId]
        );
        foreach ($rows as &$r) {
            $this->db->update('agent_commands', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], ['id' => (int) $r['id']]);
            $r['payload'] = $this->decode($r['payload'] ?? null) ?: new \stdClass();
        }
        return $rows;
    }

    /** Agent acknowledges a command with a result/error. */
    public function ackCommand(int $agentId, int $commandId, bool $ok, mixed $result = null, ?string $error = null): bool
    {
        $this->ensureSchema();
        $cmd = $this->db->selectOne('SELECT * FROM {agent_commands} WHERE id = :id AND agent_id = :a', ['id' => $commandId, 'a' => $agentId]);
        if (!$cmd) return false;
        $this->db->update('agent_commands', [
            'status'      => $ok ? 'done' : 'failed',
            'result'      => $result !== null ? json_encode($result, JSON_UNESCAPED_SLASHES) : null,
            'error'       => $error ? substr($error, 0, 1000) : null,
            'finished_at' => date('Y-m-d H:i:s'),
        ], ['id' => $commandId]);

        // Reconcile module install/remove targets so the admin sees state.
        $this->reconcileModuleTarget($agentId, (string) $cmd['command'], $this->decode($cmd['payload'] ?? null) ?: [], $ok, $error);
        $this->fire('agent.command.acked', ['agent_id' => $agentId, 'id' => $commandId, 'ok' => $ok]);
        return true;
    }

    /** Serve a signed module package to an agent by module id. */
    public function modulePackage(int $moduleId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->selectOne('SELECT package FROM {agent_modules} WHERE id = :id', ['id' => $moduleId]);
        if (!$row) return null;
        return json_decode((string) $row['package'], true) ?: null;
    }

    // ======================================================================
    // ===== App-manifest integration ====================================
    // ======================================================================

    /**
     * Scan a app folder for shipped desktop modules and register them.
     *
     * Convention: a app may ship signed packages under
     *   {app}/desktop-modules/*.pkg.json
     * Each is a cdiy-module-pkg-1 signed package. They are registered with the
     * app slug as owner and (by default) flagged auto_install, so activating
     * the app pushes them to agents.
     *
     * Called by AppService on activation. Safe to call repeatedly.
     *
     * @return array<string> registered module slugs
     */
    public function registerModulesFromManifest(string $owner, string $appPath): array
    {
        $this->ensureSchema();
        $dir = rtrim($appPath, '/').'/desktop-modules';
        if (!is_dir($dir)) return [];
        $registered = [];
        foreach (glob($dir.'/*.pkg.json') ?: [] as $file) {
            try {
                $pkg = json_decode((string) file_get_contents($file), true);
                if (!is_array($pkg) || ($pkg['format'] ?? '') !== 'cdiy-module-pkg-1') continue;
                // Optional sidecar {name}.meta.json can set name/auto_install.
                $opts = [];
                $metaFile = preg_replace('/\.pkg\.json$/', '.meta.json', $file);
                if (is_file($metaFile)) {
                    $m = json_decode((string) file_get_contents($metaFile), true);
                    if (is_array($m)) $opts = $m;
                }
                $this->registerModule($owner, $pkg, $opts);
                $meta = $this->packageMeta($pkg);
                if (!empty($meta['slug'])) $registered[] = $meta['slug'];
            } catch (\Throwable $e) {
                if ($this->logger) $this->logger->error('Failed to register desktop module', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }
        return $registered;
    }

    // ======================================================================
    // ===== Internals ======================================================
    // ======================================================================

    private function reconcileModuleTarget(int $agentId, string $command, array $payload, bool $ok, ?string $error): void
    {
        $moduleId = (int) ($payload['module_id'] ?? 0);
        if ($moduleId <= 0) return;
        if (str_starts_with($command, 'module-install') || $command === 'module-update') {
            $state = $ok ? 'installed' : 'failed';
            $ver = $this->db->selectOne('SELECT version FROM {agent_modules} WHERE id = :id', ['id' => $moduleId])['version'] ?? null;
            $this->upsertTarget($moduleId, $agentId, $state, $ok ? $ver : null, $ok ? null : $error);
        } elseif ($command === 'module-remove') {
            $this->upsertTarget($moduleId, $agentId, $ok ? 'removed' : 'failed', null, $ok ? null : $error);
        }
    }

    private function upsertTarget(int $moduleId, int $agentId, string $state, ?string $version, ?string $error): void
    {
        $existing = $this->db->selectOne('SELECT id FROM {agent_module_targets} WHERE module_id = :m AND agent_id = :a', ['m' => $moduleId, 'a' => $agentId]);
        $data = ['state' => $state, 'installed_version' => $version, 'error' => $error ? substr($error, 0, 1000) : null];
        if ($existing) $this->db->update('agent_module_targets', $data, ['id' => (int) $existing['id']]);
        else $this->db->insert('agent_module_targets', array_merge($data, ['module_id' => $moduleId, 'agent_id' => $agentId]));
    }

    private function packageMeta(array $package): array
    {
        // The signed payload (base64) holds slug/name/version.
        $b64 = $package['payload_b64'] ?? null;
        if (!$b64) return [];
        $payload = json_decode((string) base64_decode((string) $b64, true), true);
        if (!is_array($payload)) return [];
        return [
            'slug'    => $payload['slug'] ?? null,
            'version' => $payload['version'] ?? null,
            'name'    => $payload['name'] ?? null,
        ];
    }

    private function decode(?string $json): mixed
    {
        if ($json === null || $json === '') return null;
        return json_decode($json, true);
    }

    private function fire(string $event, array $data): void
    {
        try { $this->hooks->doAction($event, $data); } catch (\Throwable) {}
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
