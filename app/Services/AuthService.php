<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
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
        $this->session->set('auth_fp', $this->fingerprint($user));
    }

    public function logoutSession(): void
    {
        $this->session->forget('user_id');
        $this->session->forget('user_role');
        $this->session->forget('logged_in_at');
        $this->session->forget('auth_fp');
        $this->session->forget('two_factor_pending');
        $this->session->regenerate();
    }

    /**
     * The signed-in user of this browser session, or null.
     *
     * Every place that trusts the session goes through here, so a session
     * ends as soon as its account is suspended or deleted, its password is
     * changed, or "sign out everywhere" is used: the session carries a
     * fingerprint of the password hash and the account's security epoch, and
     * one that no longer matches is signed out. Before 1.2.33 a session lived
     * on after a password change, and a suspended account with an open
     * session was sent back and forth between /admin/login and the dashboard.
     */
    public function sessionUser(): ?array
    {
        $id = (int) ($this->session->get('user_id') ?? 0);
        if ($id <= 0) return null;
        $user = $this->db->selectOne('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            $this->logoutSession();
            return null;
        }
        $fp = $this->fingerprint($user);
        $have = $this->session->get('auth_fp');
        if (!is_string($have) || $have === '') {
            // A session from before 1.2.33: adopt it rather than sign everyone out.
            $this->session->set('auth_fp', $fp);
        } elseif (!hash_equals($fp, $have)) {
            $this->logoutSession();
            return null;
        }
        return $user;
    }

    /** Keep this session valid after the user changed their own password. */
    public function refreshSessionFingerprint(int $userId): void
    {
        $user = $this->db->selectOne('SELECT * FROM {users} WHERE id = :id', ['id' => $userId]);
        if ($user && (int) ($this->session->get('user_id') ?? 0) === $userId) {
            $this->session->set('auth_fp', $this->fingerprint($user));
        }
    }

    /**
     * Sign the user out of every other browser and device: sessions, remember-me
     * cookies, API refresh tokens and trusted-device cookies all stop working.
     * The current session stays signed in when it belongs to this user.
     */
    public function signOutEverywhere(int $userId): void
    {
        \App\Services\TwoFactorService::updateSecurity($this->db, $userId, function (array $s): array {
            $s['epoch'] = bin2hex(random_bytes(8));
            return $s;
        });
        try { Application::getInstance()->make(AuthSecurityService::class)->revokeAllForUser($userId); } catch (\Throwable) {}
        self::revokeTokens($this->db, $userId);
        $this->refreshSessionFingerprint($userId);
    }

    /** API refresh tokens and connected AI clients (MCP OAuth) of a user. API keys are kept. */
    public static function revokeTokens(Database $db, int $userId): void
    {
        try {
            $db->execute('UPDATE {refresh_tokens} SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL', ['u' => $userId]);
        } catch (\Throwable) {}
        try {
            $db->execute('DELETE FROM {mcp_oauth_tokens} WHERE user_id = :u', ['u' => $userId]);
        } catch (\Throwable) {}
    }

    /** Binds a session to the account's password and security epoch. */
    public function fingerprint(array $user): string
    {
        $epoch = (string) (\App\Services\TwoFactorService::security($user)['epoch'] ?? '');
        return substr(hash_hmac('sha256', 'session|' . (int) $user['id'] . '|' . ($user['password_hash'] ?? '') . '|' . $epoch, self::secret()), 0, 40);
    }

    /**
     * The site's signing secret: APP_KEY from .env, or — when that is empty —
     * a random secret generated once and kept in the settings table. Never a
     * value an outsider could guess.
     */
    public static function secret(): string
    {
        static $secret = null;
        if ($secret !== null) return $secret;
        $app = Application::getInstance();
        try {
            $k = (string) $app->make(Config::class)->get('app.key', '');
            if (strlen($k) >= 16) return $secret = $k;
        } catch (\Throwable) {}
        try {
            /** @var SettingService $settings */
            $settings = $app->make(SettingService::class);
            $s = (string) $settings->get('security', 'secret', '');
            if (strlen($s) < 32) {
                $s = bin2hex(random_bytes(32));
                $settings->set('security', 'secret', $s, true);
            }
            return $secret = $s;
        } catch (\Throwable) {}
        // Last resort, per-installation and not guessable from outside.
        return $secret = hash('sha256', __DIR__ . php_uname() . (string) @filemtime(__FILE__));
    }

    public function currentUserId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id !== null ? (int)$id : null;
    }

    /** The signed-in user, with the same checks as the admin (see sessionUser()). */
    public function currentUser(): ?array
    {
        return $this->sessionUser();
    }

    /**
     * Issue JWT pair (access + refresh) for API auth.
     */
    public function issueTokens(array $user, ?string $family = null): array
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
        // Rotated tokens keep their family, so reuse of an old one can end the chain.
        $family = $family ?: Helpers::uuid();
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
        $payload = Jwt::decode($token, $secret);
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
        if (!$row) {
            // A token that was already used is being presented again: someone
            // copied it. Revoke its whole family so neither copy keeps working.
            $used = $this->db->selectOne(
                'SELECT family FROM {refresh_tokens} WHERE token_hash = :h AND used_at IS NOT NULL',
                ['h' => $hash]
            );
            if ($used && !empty($used['family'])) {
                $this->db->execute(
                    'UPDATE {refresh_tokens} SET revoked_at = NOW() WHERE family = :f AND revoked_at IS NULL',
                    ['f' => (string) $used['family']]
                );
            }
            return null;
        }

        $user = $this->db->selectOne(
            "SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL AND status = 'active'",
            ['id' => (int)$row['user_id']]
        );
        if (!$user) return null;

        // Mark used — conditionally, so two requests racing with the same
        // token cannot both get a new pair.
        $claimed = $this->db->execute(
            'UPDATE {refresh_tokens} SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => (int) $row['id']]
        );
        if ($claimed < 1) return null;

        return $this->issueTokens($user, (string) ($row['family'] ?? '') ?: null);
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
    /**
     * Whether the user holds a capability — the same answer the admin area
     * gives (CheckCapability), including per-user grants and denials.
     *
     * This used to read `capabilities.<role>` from the config, but roles live
     * under `capabilities.roles.<role>`, so it found no capabilities and
     * returned false for every user, the super admin included.
     */
    public function userCan(?array $user, string $capability): bool
    {
        return \App\Http\Middleware\CheckCapability::userCan($user, $capability);
    }
}
