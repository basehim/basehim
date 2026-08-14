<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * ApiKeyService
 *
 * Manages API keys for desktop/external app connectivity.
 * Keys are stored as SHA-256 hashes; the plain-text key is ONLY
 * returned once at creation time and never stored.
 *
 * Key format:  basehim_<40 random hex chars>
 * Key prefix:  basehim_XXXXXXXX  (leading chars — shown in UI)
 *
 * validate() checks the raw key against ACCEPTED_PREFIXES rather than a
 * literal, so the accepted set is defined in exactly one place.
 */
class ApiKeyService
{
    /** Prefix for newly minted keys. */
    public const KEY_PREFIX = 'basehim_';

    /** Every prefix accepted on validation. Currently just the one. */
    public const ACCEPTED_PREFIXES = ['basehim_'];

    /** Available permission scopes */
    /**
     * Every scope that may be granted on a key: the built-in list below, plus
     * anything apps contribute through the `api.scopes` filter.
     *
     * SCOPES was a bare constant and create() intersected against it, so a
     * scope an app invented was silently dropped from the key — the key was
     * created, the scope was not on it, and the app's tools simply never
     * appeared in tools/list with nothing anywhere saying why.
     *
     * @return array<string,string> scope => human description
     */
    public static function availableScopes(): array
    {
        $scopes = self::SCOPES;
        try {
            $contributed = \App\Core\Application::getInstance()
                ->make(\App\Core\HookRegistry::class)
                ->applyFilters('api.scopes', []);

            foreach ((array) $contributed as $scope => $label) {
                if (!is_string($scope) || $scope === '') continue;
                // Built-ins win: an app must not redefine what posts:write means.
                if (isset($scopes[$scope])) continue;
                $scopes[$scope] = is_string($label) ? $label : $scope;
            }
        } catch (\Throwable) {
            // Container unavailable (CLI, early boot) — the built-ins still work.
        }
        return $scopes;
    }

    public const SCOPES = [
        'posts:read'       => 'Read posts & pages',
        'posts:write'      => 'Create / update / delete posts & pages',
        'media:read'       => 'List & download media',
        'media:write'      => 'Upload & delete media',
        'users:read'       => 'List & view users',
        'users:write'      => 'Create / update / delete users',
        'comments:read'    => 'Read comments',
        'comments:write'   => 'Moderate & delete comments',
        'taxonomies:read'  => 'Read categories & tags',
        'taxonomies:write' => 'Manage categories & tags',
        'settings:read'    => 'Read site settings',
        'settings:write'   => 'Update site settings',
        'menus:read'       => 'Read navigation menus',
        'menus:write'      => 'Manage navigation menus',
    ];

    public function __construct(private Database $db) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new API key.
     * Returns ['key' => 'basehim_...', 'record' => [...]]
     * The plain 'key' is NOT stored — show it to the user once and discard.
     */
    public function create(int $userId, string $name, array $scopes = [], ?int $rateLimit = 1000, ?\DateTimeImmutable $expiresAt = null): array
    {
        $random = bin2hex(random_bytes(20));          // 40 hex chars
        $plain  = self::KEY_PREFIX . $random;         // basehim_<40>
        $prefix = substr($plain, 0, 16);              // basehim_XXXXXXXX
        $hash   = hash('sha256', $plain);

        $uuid = $this->generateUuid();
        $validScopes = array_values(array_intersect($scopes, array_keys(self::availableScopes())));

        $id = $this->db->insert('api_keys', [
            'uuid'        => $uuid,
            'user_id'     => $userId,
            'name'        => $name,
            'key_prefix'  => $prefix,
            'key_hash'    => $hash,
            'scopes'      => json_encode($validScopes),
            'rate_limit'  => $rateLimit ?? 1000,
            'is_active'   => 1,
            'expires_at'  => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        $record = $this->find((int)$id);

        return ['key' => $plain, 'record' => $record];
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {api_keys} WHERE id = :id', ['id' => $id]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUserId(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT k.*, u.display_name AS creator_name
             FROM {api_keys} k
             JOIN {users} u ON u.id = k.user_id
             WHERE k.user_id = :uid
             ORDER BY k.created_at DESC',
            ['uid' => $userId]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function all(): array
    {
        $rows = $this->db->select(
            'SELECT k.*, u.display_name AS creator_name
             FROM {api_keys} k
             JOIN {users} u ON u.id = k.user_id
             ORDER BY k.created_at DESC'
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function revoke(int $id): void
    {
        $this->db->update('api_keys', [
            'is_active'  => 0,
            'revoked_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('api_keys', ['id' => $id]);
    }

    public function updateScopes(int $id, array $scopes): void
    {
        $validScopes = array_values(array_intersect($scopes, array_keys(self::availableScopes())));
        $this->db->update('api_keys', [
            'scopes' => json_encode($validScopes),
        ], ['id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Validation (used by API middleware)
    // -------------------------------------------------------------------------

    /**
     * Validate a raw key string.
     * Returns the key record (with scopes) or null.
     */
    /**
     * Does this token look like one of our API keys?
     *
     * Callers must use this rather than hard-coding a prefix: a stray literal
     * elsewhere silently rejects valid keys.
     */
    public static function looksLikeKey(string $token): bool
    {
        foreach (self::ACCEPTED_PREFIXES as $p) {
            if (str_starts_with($token, $p)) return true;
        }
        return false;
    }

    public function validate(string $rawKey): ?array
    {
        // Reject anything that is not shaped like one of our keys up front.
        if (!self::looksLikeKey($rawKey)) {
            return null;
        }

        $hash = hash('sha256', $rawKey);
        $row  = $this->db->selectOne(
            'SELECT * FROM {api_keys} WHERE key_hash = :h AND is_active = 1',
            ['h' => $hash]
        );

        if (!$row) return null;

        $record = $this->hydrate($row);

        // Check expiry
        if ($record['expires_at'] && strtotime($record['expires_at']) < time()) {
            return null;
        }

        // Update last_used
        $this->db->update('api_keys', [
            'last_used_at' => date('Y-m-d H:i:s'),
            'last_used_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], ['id' => $record['id']]);

        return $record;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function hydrate(array $row): array
    {
        $row['scopes'] = is_string($row['scopes']) ? (json_decode($row['scopes'], true) ?? []) : ($row['scopes'] ?? []);
        return $row;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
