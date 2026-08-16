<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * McpOAuthService — a minimal OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Why this exists
 * ---------------
 * Claude's remote-connector UI only offers OAuth (client id / secret) — there is
 * no field for a static bearer token. The MCP authorization spec (2025-06-18)
 * likewise requires OAuth 2.1 and forbids credentials in the query string. So a
 * Basehim API key alone cannot connect a web connector.
 *
 * Implementing this properly means a connector can be added by pasting the URL
 * and nothing else: Claude discovers the authorization server (RFC 9728), then
 * registers itself dynamically (RFC 7591), so there is no client id/secret to
 * copy anywhere.
 *
 * What's implemented
 *   - RFC 9728  Protected Resource Metadata      (discovery from the MCP URL)
 *   - RFC 8414  Authorization Server Metadata    (endpoint discovery)
 *   - RFC 7591  Dynamic Client Registration      (no manual client setup)
 *   - OAuth 2.1 authorization_code + PKCE S256   (PKCE is mandatory)
 *   - RFC 8707  Resource indicators              (tokens bound to this server)
 *   - Refresh tokens with rotation
 *
 * Only hashes of codes/tokens are stored, so a database leak cannot be replayed.
 * Schema self-heals like the rest of Basehim — no migration step on shared hosting.
 */
final class McpOAuthService
{
    /**
     * Access token lifetime (8 hours) and refresh lifetime (30 days).
     *
     * ACCESS_TTL was 1 hour until 1.42.4. That is the textbook OAuth default and
     * it is fine when refresh works silently — but an MCP working session is
     * long and interactive, so every lapse that failed to refresh cleanly
     * surfaced to a human as "reconnect the connector", mid-task. Eight hours
     * covers a working day while still bounding the damage from a leaked token,
     * which is the only thing a short TTL actually buys you. The refresh race
     * fixed in refresh() below is the other half of this; neither alone is
     * sufficient.
     *
     * Override per-site by adding 'mcp_access_ttl' => <seconds> to
     * config/app.php, which the updater never overwrites. Clamped to between
     * 5 minutes and the refresh lifetime.
     */
    public const ACCESS_TTL  = 28800;
    public const REFRESH_TTL = 2592000;

    /**
     * How long a just-rotated refresh token keeps working. See refresh().
     */
    public const REFRESH_GRACE = 60;
    /** Authorization codes are single-use and short-lived. */
    public const CODE_TTL = 300;

    public const TOKEN_PREFIX   = 'bhat_';   // Basehim access token
    public const REFRESH_PREFIX = 'bhrt_';   // Basehim refresh token

    /**
     * Scopes the MCP connector may request. Deliberately narrower than the full
     * API key scope list: only advertise what the MCP tools actually use, so a
     * connector can never be granted more reach than it can exercise.
     */
    /**
     * Every scope an MCP connector may request: the built-in list below, plus
     * anything apps contribute through the `mcp.scopes` filter.
     *
     * MCP_SCOPES is a second, independent scope list from ApiKeyService::SCOPES.
     * OAuth is how Claude's web connector authenticates, so THIS is the list
     * that governs it — and making only the API-key list extensible (1.41.0)
     * left app-contributed tools unreachable over OAuth: the scope was never
     * offered at authorization, so it never reached the token, so the tools
     * were filtered out of tools/list with nothing saying why.
     *
     * The original principle still holds — only advertise what tools actually
     * use, so a connector can never be granted more reach than it can exercise.
     * An app that registers MCP tools registers their scopes here, which keeps
     * the two in step instead of hardcoding one and forgetting the other.
     *
     * @return array<int,string>
     */
    public static function mcpScopes(): array
    {
        $scopes = self::MCP_SCOPES;
        try {
            $contributed = \App\Core\Application::getInstance()
                ->make(\App\Core\HookRegistry::class)
                ->applyFilters('mcp.scopes', []);

            foreach ((array) $contributed as $scope) {
                if (is_string($scope) && $scope !== '' && !in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        } catch (\Throwable) {
            // Container unavailable — the built-ins still work.
        }
        return $scopes;
    }

    public const MCP_SCOPES = [
        'posts:read', 'posts:write',
        'taxonomies:read', 'taxonomies:write',
        'media:read',
        'comments:read', 'comments:write',
        'settings:read',
        'users:read',
    ];

    private bool $ready = false;

    public function __construct(private Database $db) {}

    // ==================================================================
    // Schema
    // ==================================================================

    public function ensureSchema(): void
    {
        if ($this->ready) return;
        $this->ready = true;
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS `mcp_oauth_clients` (
                    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `client_id`      VARCHAR(64) NOT NULL,
                    `secret_hash`    VARCHAR(255) NULL,
                    `client_name`    VARCHAR(190) NOT NULL DEFAULT "MCP client",
                    `redirect_uris`  TEXT NOT NULL,
                    `is_public`      TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at`     DATETIME NOT NULL,
                    UNIQUE KEY `uniq_client` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS `mcp_oauth_codes` (
                    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `code_hash`      CHAR(64) NOT NULL,
                    `client_id`      VARCHAR(64) NOT NULL,
                    `user_id`        BIGINT UNSIGNED NOT NULL,
                    `redirect_uri`   VARCHAR(500) NOT NULL,
                    `code_challenge` VARCHAR(255) NOT NULL,
                    `challenge_method` VARCHAR(10) NOT NULL DEFAULT "S256",
                    `scope`          VARCHAR(500) NOT NULL DEFAULT "",
                    `resource`       VARCHAR(500) NULL,
                    `expires_at`     DATETIME NOT NULL,
                    UNIQUE KEY `uniq_code` (`code_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS `mcp_oauth_tokens` (
                    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `access_hash`    CHAR(64) NOT NULL,
                    `refresh_hash`   CHAR(64) NULL,
                    `client_id`      VARCHAR(64) NOT NULL,
                    `user_id`        BIGINT UNSIGNED NOT NULL,
                    `scope`          VARCHAR(500) NOT NULL DEFAULT "",
                    `resource`       VARCHAR(500) NULL,
                    `expires_at`     DATETIME NOT NULL,
                    `refresh_expires_at` DATETIME NULL,
                    `created_at`     DATETIME NOT NULL,
                    UNIQUE KEY `uniq_access` (`access_hash`),
                    KEY `idx_refresh` (`refresh_hash`),
                    KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            // Best effort — tables may already exist.
        }
    }

    // ==================================================================
    // Discovery metadata
    // ==================================================================

    /** Absolute origin + install base, e.g. https://example.com/sub */
    public function issuer(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $scheme = 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        return $scheme . '://' . $host . $base;
    }

    /** The canonical identifier of the MCP resource itself. */
    public function resourceUrl(): string
    {
        return $this->issuer() . '/mcp';
    }

    public function protectedResourceMetadata(): array
    {
        return [
            'resource'                 => $this->resourceUrl(),
            'authorization_servers'    => [$this->issuer()],
            'scopes_supported'         => self::mcpScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Basehim MCP',
            'resource_documentation'   => $this->issuer() . '/admin/api/reference',
        ];
    }

    public function authorizationServerMetadata(): array
    {
        $b = $this->issuer();
        return [
            'issuer'                                => $b,
            'authorization_endpoint'                => $b . '/oauth/authorize',
            'token_endpoint'                        => $b . '/oauth/token',
            'registration_endpoint'                 => $b . '/oauth/register',
            'scopes_supported'                      => self::mcpScopes(),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'service_documentation'                 => $b . '/admin/api/reference',
        ];
    }

    // ==================================================================
    // Dynamic client registration (RFC 7591)
    // ==================================================================

    /**
     * Register a client. Public clients (no secret) are supported because the
     * spec expects PKCE to carry the security for them.
     */
    public function registerClient(array $meta): array
    {
        $this->ensureSchema();

        $uris = array_values(array_filter(
            array_map('strval', (array) ($meta['redirect_uris'] ?? [])),
            fn($u) => $this->isValidRedirect($u)
        ));
        if (!$uris) {
            throw new \InvalidArgumentException('redirect_uris must contain at least one https (or localhost) URI');
        }

        $authMethod = (string) ($meta['token_endpoint_auth_method'] ?? 'client_secret_post');
        $isPublic   = $authMethod === 'none';

        $clientId = 'bhc_' . bin2hex(random_bytes(16));
        $secret   = $isPublic ? null : bin2hex(random_bytes(32));

        $this->db->insert('mcp_oauth_clients', [
            'client_id'     => $clientId,
            'secret_hash'   => $secret !== null ? hash('sha256', $secret) : null,
            'client_name'   => mb_substr((string) ($meta['client_name'] ?? 'MCP client'), 0, 190),
            'redirect_uris' => json_encode($uris),
            'is_public'     => $isPublic ? 1 : 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $out = [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => time(),
            'redirect_uris'              => $uris,
            'client_name'                => (string) ($meta['client_name'] ?? 'MCP client'),
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $isPublic ? 'none' : 'client_secret_post',
        ];
        if ($secret !== null) {
            $out['client_secret'] = $secret;
            $out['client_secret_expires_at'] = 0;   // never expires
        }
        return $out;
    }

    public function findClient(string $clientId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->selectOne('SELECT * FROM mcp_oauth_clients WHERE client_id = :c', ['c' => $clientId]);
        if (!$row) return null;
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }

    /** Redirect URIs must be exact-match registered (OAuth 2.1). */
    public function redirectAllowed(array $client, string $uri): bool
    {
        return in_array($uri, $client['redirect_uris'] ?? [], true);
    }

    private function isValidRedirect(string $u): bool
    {
        $p = parse_url($u);
        if (!$p || empty($p['scheme'])) return false;
        if (!empty($p['fragment'])) return false;                    // fragments are not allowed
        $host = $p['host'] ?? '';
        if ($p['scheme'] === 'https') return true;
        // Allow http only on loopback (native/CLI clients).
        if ($p['scheme'] === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
        // Custom app schemes (e.g. claude://) are permitted for native clients.
        return !in_array($p['scheme'], ['http', 'javascript', 'data', 'file'], true);
    }

    // ==================================================================
    // Authorization codes
    // ==================================================================

    public function issueCode(string $clientId, int $userId, string $redirectUri, string $challenge, string $method, string $scope, ?string $resource): string
    {
        $this->ensureSchema();
        $code = bin2hex(random_bytes(32));
        $this->db->insert('mcp_oauth_codes', [
            'code_hash'        => hash('sha256', $code),
            'client_id'        => $clientId,
            'user_id'          => $userId,
            'redirect_uri'     => $redirectUri,
            'code_challenge'   => $challenge,
            'challenge_method' => $method,
            'scope'            => $scope,
            'resource'         => $resource,
            'expires_at'       => date('Y-m-d H:i:s', time() + self::CODE_TTL),
        ]);
        return $code;
    }

    /** Consume a code (single use) and verify PKCE. */
    public function redeemCode(string $code, string $clientId, string $redirectUri, string $verifier): array
    {
        $this->ensureSchema();
        $hash = hash('sha256', $code);
        $row = $this->db->selectOne('SELECT * FROM mcp_oauth_codes WHERE code_hash = :h', ['h' => $hash]);
        if (!$row) throw new \RuntimeException('invalid_grant');

        // Always burn the code, even if the rest fails — codes are single-use.
        try { $this->db->delete('mcp_oauth_codes', ['id' => (int) $row['id']]); } catch (\Throwable) {}

        if (strtotime((string) $row['expires_at']) <= time())     throw new \RuntimeException('invalid_grant');
        if (!hash_equals((string) $row['client_id'], $clientId))  throw new \RuntimeException('invalid_grant');
        if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) throw new \RuntimeException('invalid_grant');

        // PKCE S256 — mandatory.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row['code_challenge'], $expected)) throw new \RuntimeException('invalid_grant');

        return $row;
    }

    // ==================================================================
    // Tokens
    // ==================================================================

    /**
     * Effective access-token lifetime: the config override when present and
     * sane, otherwise ACCESS_TTL. Clamped so a typo can neither mint a token
     * that expires instantly nor one that outlives its own refresh token.
     */
    public function accessTtl(): int
    {
        $configured = 0;
        try {
            $configured = (int) \App\Core\Application::getInstance()
                ->make(\App\Core\Config::class)
                ->get('app.mcp_access_ttl', 0);
        } catch (\Throwable) {
            // Config unavailable (early boot, install) — fall back to the default.
        }

        if ($configured <= 0) {
            return self::ACCESS_TTL;
        }
        return max(300, min($configured, self::REFRESH_TTL));
    }

    public function issueTokens(string $clientId, int $userId, string $scope, ?string $resource): array
    {
        $this->ensureSchema();
        $ttl     = $this->accessTtl();
        $access  = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
        $this->db->insert('mcp_oauth_tokens', [
            'access_hash'        => hash('sha256', $access),
            'refresh_hash'       => hash('sha256', $refresh),
            'client_id'          => $clientId,
            'user_id'            => $userId,
            'scope'              => $scope,
            'resource'           => $resource,
            'expires_at'         => date('Y-m-d H:i:s', time() + $ttl),
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $ttl,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ];
    }

    /** Rotate a refresh token (old one is invalidated). */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $this->ensureSchema();
        $row = $this->db->selectOne(
            'SELECT * FROM mcp_oauth_tokens WHERE refresh_hash = :h', ['h' => hash('sha256', $refreshToken)]
        );
        if (!$row) throw new \RuntimeException('invalid_grant');
        if (!hash_equals((string) $row['client_id'], $clientId)) throw new \RuntimeException('invalid_grant');
        if (empty($row['refresh_expires_at']) || strtotime((string) $row['refresh_expires_at']) <= time()) {
            throw new \RuntimeException('invalid_grant');
        }
        // Rotate with a GRACE WINDOW rather than deleting outright.
        //
        // The old code deleted this row and immediately issued a new pair. That
        // is correct for one caller and broken for two: an MCP client typically
        // has several tool calls in flight when a token lapses, they all present
        // the same refresh token at once, the first one wins, and every other
        // finds no row and gets invalid_grant. The client cannot recover from
        // that, so a routine refresh surfaced to the user as "reconnect the
        // connector" — twice in a single working session during 1.42.3 testing.
        //
        // Keeping the old refresh token alive briefly means a racing sibling
        // request gets its own valid pair instead of a hard failure. The window
        // is short, single-use in practice, and the row is reaped by cleanup().
        // Only ever SHORTEN the window — never extend one already closing.
        try {
            $graceUntil = time() + self::REFRESH_GRACE;
            if (strtotime((string) $row['refresh_expires_at']) > $graceUntil) {
                $this->db->update(
                    'mcp_oauth_tokens',
                    ['refresh_expires_at' => date('Y-m-d H:i:s', $graceUntil)],
                    ['id' => (int) $row['id']]
                );
            }
        } catch (\Throwable) {
            // Worst case the old row lingers until cleanup(); still better than
            // deleting it and failing the concurrent refresh.
        }

        return $this->issueTokens($clientId, (int) $row['user_id'], (string) $row['scope'], $row['resource'] ?? null);
    }

    /**
     * Validate an access token. Returns ['user_id'=>int,'scopes'=>string[]] or null.
     * The audience is checked so a token minted for another resource is rejected.
     */
    public function validateAccessToken(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) return null;
        $this->ensureSchema();
        $row = $this->db->selectOne(
            'SELECT * FROM mcp_oauth_tokens WHERE access_hash = :h', ['h' => hash('sha256', $token)]
        );
        if (!$row) return null;
        if (strtotime((string) $row['expires_at']) <= time()) return null;

        $res = (string) ($row['resource'] ?? '');
        if ($res !== '' && rtrim($res, '/') !== rtrim($this->resourceUrl(), '/')) {
            return null;   // token was not issued for this MCP server
        }
        return [
            'user_id' => (int) $row['user_id'],
            'scopes'  => array_values(array_filter(explode(' ', (string) $row['scope']))),
            'client_id' => (string) $row['client_id'],
        ];
    }

    /** Housekeeping — drop expired codes/tokens. Cheap, best-effort. */
    public function gc(): void
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->execute('DELETE FROM mcp_oauth_codes WHERE expires_at < :n', ['n' => $now]);
            $this->db->execute('DELETE FROM mcp_oauth_tokens WHERE refresh_expires_at IS NOT NULL AND refresh_expires_at < :n', ['n' => $now]);
        } catch (\Throwable) {}
    }

    /** Only these scopes may ever be granted through the MCP connector. */
    public function sanitizeScopes(string $requested): string
    {
        $all = self::mcpScopes();
        $want = array_values(array_filter(explode(' ', trim($requested))));
        if (!$want) $want = ['posts:read', 'taxonomies:read'];   // safe default: read-only
        $ok = array_values(array_intersect($want, $all));
        return implode(' ', $ok ?: ['posts:read']);
    }
}
