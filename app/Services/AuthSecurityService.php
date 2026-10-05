<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * AuthSecurityService — the security layer around login:
 *   - tracks failed attempts per identifier+IP (throttling)
 *   - decides when a math captcha is required (after N wrong passwords)
 *   - decides when to fall back to email OTP (after N captcha failures)
 *   - issues / validates / revokes "remember me" cookie tokens
 *   - generates and verifies email OTP codes
 *
 * All tables self-heal via ensureSchema() (idempotent CREATE TABLE IF NOT
 * EXISTS), so no migration step is needed on shared hosting.
 */
final class AuthSecurityService
{
    /** Cookie holding the remember-me token. */
    public const REMEMBER_COOKIE = 'basehim_remember';

    /** Max wrong OTP guesses before the code is burned. */
    private const OTP_MAX_ATTEMPTS = 5;

    private bool $schemaReady = false;

    public function __construct(private Database $db) {}

    // ==================================================================
    // Schema
    // ==================================================================

    public function ensureSchema(): void
    {
        if ($this->schemaReady) return;
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS {auth_login_attempts} (
                    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `identifier` VARCHAR(190) NOT NULL,
                    `ip`         VARCHAR(45) NOT NULL,
                    `fails`      INT UNSIGNED NOT NULL DEFAULT 0,
                    `captcha_fails` INT UNSIGNED NOT NULL DEFAULT 0,
                    `otp_hash`   VARCHAR(255) NULL,
                    `otp_expires_at` DATETIME NULL,
                    `otp_user_id` BIGINT UNSIGNED NULL,
                    `otp_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                    `locked_until` DATETIME NULL,
                    `updated_at` DATETIME NOT NULL,
                    UNIQUE KEY `uniq_ident_ip` (`identifier`, `ip`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            // Self-heal for installs created before otp_attempts existed, so the
            // OTP guess cap works even if the migration hasn't been run yet.
            try {
                $this->db->execute(
                    'ALTER TABLE {auth_login_attempts} ADD COLUMN IF NOT EXISTS `otp_attempts` INT UNSIGNED NOT NULL DEFAULT 0'
                );
            } catch (\Throwable) {}
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS {auth_remember_tokens} (
                    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id`    BIGINT UNSIGNED NOT NULL,
                    `selector`   CHAR(32) NOT NULL,
                    `validator_hash` CHAR(64) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` DATETIME NOT NULL,
                    UNIQUE KEY `uniq_selector` (`selector`),
                    KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $this->schemaReady = true;
        } catch (\Throwable) {
            // Table may already exist or DB not ready; treat as best-effort.
            $this->schemaReady = true;
        }
    }

    // ==================================================================
    // Attempt tracking
    // ==================================================================

    private function key(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    private function row(string $identifier, string $ip): ?array
    {
        $this->ensureSchema();
        return $this->db->selectOne(
            'SELECT * FROM {auth_login_attempts} WHERE identifier = :i AND ip = :ip',
            ['i' => $this->key($identifier), 'ip' => $ip]
        ) ?: null;
    }

    /** Current failed-password count for this identifier+IP. */
    public function failCount(string $identifier, string $ip): int
    {
        return (int) (($this->row($identifier, $ip)['fails'] ?? 0));
    }

    public function captchaFailCount(string $identifier, string $ip): int
    {
        return (int) (($this->row($identifier, $ip)['captcha_fails'] ?? 0));
    }

    /** Record one failed password attempt. */
    public function recordFailure(string $identifier, string $ip): void
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');
        $existing = $this->row($identifier, $ip);
        if ($existing) {
            $this->db->execute(
                'UPDATE {auth_login_attempts} SET fails = fails + 1, updated_at = :now WHERE id = :id',
                ['now' => $now, 'id' => (int) $existing['id']]
            );
        } else {
            $this->db->insert('auth_login_attempts', [
                'identifier' => $this->key($identifier),
                'ip'         => $ip,
                'fails'      => 1,
                'captcha_fails' => 0,
                'updated_at' => $now,
            ]);
        }
    }

    public function recordCaptchaFailure(string $identifier, string $ip): void
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');
        $existing = $this->row($identifier, $ip);
        if ($existing) {
            $this->db->execute(
                'UPDATE {auth_login_attempts} SET captcha_fails = captcha_fails + 1, updated_at = :now WHERE id = :id',
                ['now' => $now, 'id' => (int) $existing['id']]
            );
        } else {
            $this->db->insert('auth_login_attempts', [
                'identifier' => $this->key($identifier),
                'ip'         => $ip,
                'fails'      => 0,
                'captcha_fails' => 1,
                'updated_at' => $now,
            ]);
        }
    }

    /** Clear all counters after a successful login. */
    public function clear(string $identifier, string $ip): void
    {
        $this->ensureSchema();
        try {
            $this->db->execute(
                'DELETE FROM {auth_login_attempts} WHERE identifier = :i AND ip = :ip',
                ['i' => $this->key($identifier), 'ip' => $ip]
            );
        } catch (\Throwable) {}
    }

    /**
     * Is a math captcha required now? True once failures reach the configured
     * limit (default 3).
     */
    public function captchaRequired(string $identifier, string $ip, int $limit): bool
    {
        return $this->failCount($identifier, $ip) >= max(1, $limit);
    }

    /**
     * Should we escalate to email OTP? True once the user has BOTH exhausted
     * password attempts (>= limit) AND failed the captcha `captchaLimit` times.
     */
    public function otpRequired(string $identifier, string $ip, int $limit, int $captchaLimit): bool
    {
        $r = $this->row($identifier, $ip);
        if (!$r) return false;
        return (int) $r['fails'] >= max(1, $limit)
            && (int) $r['captcha_fails'] >= max(1, $captchaLimit);
    }

    // ==================================================================
    // Email OTP
    // ==================================================================

    /** Generate a 6-digit OTP, store its hash, and return the plain code. */
    public function generateOtp(string $identifier, string $ip, int $userId, int $ttlMinutes = 10): string
    {
        $this->ensureSchema();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', time() + $ttlMinutes * 60);
        $now = date('Y-m-d H:i:s');
        $existing = $this->row($identifier, $ip);
        if ($existing) {
            $this->db->execute(
                'UPDATE {auth_login_attempts} SET otp_hash = :h, otp_expires_at = :e, otp_user_id = :u, otp_attempts = 0, updated_at = :now WHERE id = :id',
                ['h' => $hash, 'e' => $expires, 'u' => $userId, 'now' => $now, 'id' => (int) $existing['id']]
            );
        } else {
            $this->db->insert('auth_login_attempts', [
                'identifier' => $this->key($identifier),
                'ip'         => $ip,
                'fails'      => 0,
                'captcha_fails' => 0,
                'otp_hash'   => $hash,
                'otp_expires_at' => $expires,
                'otp_user_id' => $userId,
                'otp_attempts' => 0,
                'updated_at' => $now,
            ]);
        }
        return $code;
    }

    public function hasActiveOtp(string $identifier, string $ip): bool
    {
        $r = $this->row($identifier, $ip);
        if (!$r || empty($r['otp_hash']) || empty($r['otp_expires_at'])) return false;
        return strtotime((string) $r['otp_expires_at']) > time();
    }

    /**
     * Verify an OTP code; on success returns the associated user id.
     *
     * Guessing is capped: a 6-digit code is only ~10^6, so without a limit an
     * attacker who has triggered the OTP could brute-force it inside the TTL.
     * After OTP_MAX_ATTEMPTS wrong guesses the code is burned and the user must
     * restart the flow (which emails a fresh code).
     */
    public function verifyOtp(string $identifier, string $ip, string $code): ?int
    {
        $r = $this->row($identifier, $ip);
        if (!$r || empty($r['otp_hash']) || empty($r['otp_expires_at'])) return null;
        if (strtotime((string) $r['otp_expires_at']) <= time()) return null;

        if ((int) ($r['otp_attempts'] ?? 0) >= self::OTP_MAX_ATTEMPTS) {
            $this->invalidateOtp((int) $r['id']);
            return null;
        }

        if (!password_verify(trim($code), (string) $r['otp_hash'])) {
            $this->db->execute(
                'UPDATE {auth_login_attempts} SET otp_attempts = otp_attempts + 1, updated_at = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => (int) $r['id']]
            );
            if ((int) ($r['otp_attempts'] ?? 0) + 1 >= self::OTP_MAX_ATTEMPTS) {
                $this->invalidateOtp((int) $r['id']);
            }
            return null;
        }

        return (int) $r['otp_user_id'];
    }

    /** Wipe an active OTP so no further guess (right or wrong) can use it. */
    private function invalidateOtp(int $rowId): void
    {
        try {
            $this->db->execute(
                'UPDATE {auth_login_attempts} SET otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => $rowId]
            );
        } catch (\Throwable) {}
    }

    // ==================================================================
    // Remember-me tokens (selector : validator split)
    // ==================================================================

    /**
     * Issue a remember token. Returns the cookie value "selector:validator".
     * Only the validator's hash is stored, so a DB leak can't reconstruct
     * a usable cookie.
     */
    public function issueRemember(int $userId, int $days = 30): string
    {
        $this->ensureSchema();
        $selector = bin2hex(random_bytes(16));           // 32 hex chars
        $validator = bin2hex(random_bytes(32));          // 64 hex chars
        $this->db->insert('auth_remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => date('Y-m-d H:i:s', time() + $days * 86400),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return $selector . ':' . $validator;
    }

    /** Resolve a remember cookie to a user id, or null if invalid/expired. */
    public function resolveRemember(string $cookie): ?int
    {
        $this->ensureSchema();
        if (!str_contains($cookie, ':')) return null;
        [$selector, $validator] = explode(':', $cookie, 2);
        if ($selector === '' || $validator === '') return null;

        $row = $this->db->selectOne(
            'SELECT * FROM {auth_remember_tokens} WHERE selector = :s',
            ['s' => $selector]
        );
        if (!$row) return null;
        if (strtotime((string) $row['expires_at']) <= time()) {
            $this->revokeSelector($selector);
            return null;
        }
        // Constant-time compare of the stored hash.
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            return null;
        }
        return (int) $row['user_id'];
    }

    public function revokeSelector(string $selector): void
    {
        $this->ensureSchema();
        try {
            $this->db->execute('DELETE FROM {auth_remember_tokens} WHERE selector = :s', ['s' => $selector]);
        } catch (\Throwable) {}
    }

    /** Revoke a remember token from its full cookie value. */
    public function revokeRememberCookie(string $cookie): void
    {
        if (!str_contains($cookie, ':')) return;
        [$selector] = explode(':', $cookie, 2);
        if ($selector !== '') $this->revokeSelector($selector);
    }

    /** Revoke every remember token for a user (e.g. on password change). */
    public function revokeAllForUser(int $userId): void
    {
        $this->ensureSchema();
        try {
            $this->db->execute('DELETE FROM {auth_remember_tokens} WHERE user_id = :u', ['u' => $userId]);
        } catch (\Throwable) {}
    }

    // ==================================================================
    // Math captcha (stateless, HMAC-signed)
    // ==================================================================

    /**
     * Build a math captcha challenge. Returns [question, token] where token is
     * an HMAC-signed answer that round-trips through the form (no session/DB
     * needed, tamper-proof).
     */
    public function makeCaptcha(string $secret): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $ops = ['+', '-', '×'];
        $op = $ops[array_rand($ops)];
        $answer = match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '×' => $a * $b,
        };
        $expires = time() + 600;
        $payload = $answer . '|' . $expires;
        $sig = hash_hmac('sha256', $payload, $secret);
        $token = base64_encode($payload . '|' . $sig);
        return ['question' => "{$a} {$op} {$b}", 'token' => $token];
    }

    /** Validate a captcha answer against its signed token. */
    public function checkCaptcha(string $answer, string $token, string $secret): bool
    {
        $raw = base64_decode($token, true);
        if ($raw === false || substr_count($raw, '|') < 2) return false;
        $parts = explode('|', $raw);
        $sig = array_pop($parts);
        [$expected, $expires] = $parts;
        if ((int) $expires < time()) return false;
        $calc = hash_hmac('sha256', $expected . '|' . $expires, $secret);
        if (!hash_equals($calc, $sig)) return false;
        return trim($answer) !== '' && (string) (int) trim($answer) === (string) (int) $expected;
    }
}
