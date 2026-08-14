<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Config;
use App\Core\Session;
use App\Core\Jwt;
use App\Core\Helpers;

class AuthService
{
    /**
     * A fixed bcrypt hash used only to burn CPU on the "user not found" path so
     * its timing matches a real (wrong-password) verify. Never matches any input.
     */
    private const TIMING_EQUALIZER_HASH = '$2y$12$7HxcYnpi47MMzgql9YFaROeD8Pk28ITA4VE8lebLhDO9LOoyeo9bi';

    public function __construct(
        private Database $db,
        private Config $config,
        private Session $session
    ) {}

    /**
     * Attempt credential login. Returns user array on success, null on failure.
     */
    public function attempt(string $login, string $password): ?array
    {
        $user = $this->db->selectOne(
            "SELECT * FROM {users}
             WHERE (email = :email OR username = :username)
               AND status = 'active' AND deleted_at IS NULL
             LIMIT 1",
            ['email' => $login, 'username' => $login]
        );

        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        // Touch last login
        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);

        return $user;
    }

    /**
     * Credential check that reports WHY a login failed, so the UI can tell a
     * genuinely wrong password apart from a correct password on a blocked
     * account. Returns:
     *   ['ok' => true,  'user' => [...]]                      — success
     *   ['ok' => false, 'reason' => 'invalid']                — no match / bad password
     *   ['ok' => false, 'reason' => 'blocked', 'status' => s] — right password, inactive/suspended/etc.
     *
     * @return array{ok:bool, user?:array, reason?:string, status?:string}
     */
    public function attemptDetailed(string $login, string $password): array
    {
        // Look the account up regardless of status (but not soft-deleted).
        $user = $this->db->selectOne(
            "SELECT * FROM {users}
             WHERE (email = :email OR username = :username)
               AND deleted_at IS NULL
             LIMIT 1",
            ['email' => $login, 'username' => $login]
        );

        if (!$user) {
            // Equalise timing: without this, a missing account returns instantly
            // while a real account runs bcrypt, letting an attacker enumerate
            // valid usernames/emails by measuring response time. Run a throwaway
            // verify against a fixed hash so both paths cost the same.
            password_verify($password, self::TIMING_EQUALIZER_HASH);
            return ['ok' => false, 'reason' => 'invalid'];
        }
        if (!password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $status = (string) ($user['status'] ?? 'active');
        if ($status !== 'active') {
            // Correct password, but the account can't sign in.
            return ['ok' => false, 'reason' => 'blocked', 'status' => $status, 'user' => $user];
        }

        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
        return ['ok' => true, 'user' => $user];
    }

    /**
     * Log in via session (admin panel).
     */
    public function loginSession(array $user): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', (int)$user['id']);
        $this->session->set('user_role', $user['role']);
        $this->session->set('logged_in_at', time());
    }

    public function logoutSession(): void
    {
        $this->session->forget('user_id');
        $this->session->forget('user_role');
        $this->session->forget('logged_in_at');
        $this->session->regenerate();
    }

    public function currentUserId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id !== null ? (int)$id : null;
    }

    public function currentUser(): ?array
    {
        $id = $this->currentUserId();
        if (!$id) return null;
        return $this->db->selectOne('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /**
     * Issue JWT pair (access + refresh) for API auth.
     */
    public function issueTokens(array $user): array
    {
        $secret = $this->config->get('auth.jwt.secret');
        $alg = $this->config->get('auth.jwt.algorithm', 'HS256');
        $issuer = $this->config->get('auth.jwt.issuer', 'basehim');
        $audience = $this->config->get('auth.jwt.audience', 'basehim-client');
        $accessTtl = (int)$this->config->get('auth.jwt.access_ttl', 900);
        $refreshTtl = (int)$this->config->get('auth.jwt.refresh_ttl', 1209600);

        $now = time();
        $accessPayload = [
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => (int)$user['id'],
            'iat' => $now,
            'exp' => $now + $accessTtl,
            'role' => $user['role'],
            'username' => $user['username'],
        ];
        $access = Jwt::encode($accessPayload, $secret, $alg);

        // Refresh token: random opaque, store hashed
        $refreshPlain = Helpers::randomString(64);
        $family = Helpers::uuid();
        $this->db->insert('refresh_tokens', [
            'user_id' => (int)$user['id'],
            'token_hash' => hash('sha256', $refreshPlain),
            'family' => $family,
            'expires_at' => date('Y-m-d H:i:s', $now + $refreshTtl),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $refreshPlain,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
        ];
    }

    /**
     * Verify a JWT bearer token. Returns user array on success.
     */
    public function userFromToken(string $token): ?array
    {
        $secret = $this->config->get('auth.jwt.secret');
        $payload = Jwt::decode($token, $secret, (string) $this->config->get('auth.jwt.algorithm', 'HS256'));
        if (!$payload || !isset($payload['sub'])) return null;

        $user = $this->db->selectOne(
            'SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL AND status = \'active\'',
            ['id' => (int)$payload['sub']]
        );
        return $user ?: null;
    }

    /**
     * Rotate a refresh token.
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $row = $this->db->selectOne(
            'SELECT * FROM {refresh_tokens}
             WHERE token_hash = :h AND revoked_at IS NULL AND used_at IS NULL
               AND expires_at > NOW()',
            ['h' => $hash]
        );
        if (!$row) return null;

        // Status matters here, not just deletion: without it a suspended
        // account kept minting fresh access tokens indefinitely, so suspending
        // someone did not actually end their API access.
        $user = $this->db->selectOne(
            "SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL AND status = 'active'",
            ['id' => (int)$row['user_id']]
        );
        if (!$user) return null;

        // Mark used
        $this->db->update('refresh_tokens',
            ['used_at' => date('Y-m-d H:i:s')],
            ['id' => $row['id']]
        );

        return $this->issueTokens($user);
    }

    public function revokeRefreshToken(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        $this->db->execute(
            'UPDATE {refresh_tokens} SET revoked_at = NOW() WHERE token_hash = :h',
            ['h' => $hash]
        );
    }

    /**
     * Check if user has a capability.
     */
    public function userCan(?array $user, string $capability): bool
    {
        if (!$user) return false;
        $caps = $this->config->get('capabilities.' . $user['role'], []);
        if (in_array('*', $caps, true)) return true;
        return in_array($capability, $caps, true);
    }
}
