<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;

/**
 * TwoFactorService — two-step sign-in with a code sent by email.
 *
 * After the password is accepted, an account with two-step verification is
 * not signed in yet: a 6-digit code is emailed and must be entered first.
 *
 * Who is asked depends on the site policy (Settings › Authentication):
 *   off       nobody, even users who turned it on
 *   optional  users who turned it on in their profile (default)
 *   admins    as optional, and always for administrators
 *   all       everyone
 *
 * Codes live in {auth_two_factor}, one row per user and purpose ("login" or
 * "setup"), so limits hold across sessions and browsers:
 *   - a code is valid for 10 minutes and for one use;
 *   - 5 wrong guesses burn the code;
 *   - 10 wrong guesses within an hour lock code entry for 30 minutes;
 *   - a new code at most every 30 seconds, and 6 per hour.
 * Only an HMAC of each code is stored.
 *
 * Emergency switch: while a file named `disable-2fa` exists in /storage,
 * nobody is asked for a code. It lets the owner of a site whose email has
 * stopped working sign in through the hosting file manager.
 */
final class TwoFactorService
{
    public const POLICIES = ['off', 'optional', 'admins', 'all'];
    public const TRUST_COOKIE = 'basehim_2fa_trust';

    private const CODE_TTL = 600;          // seconds a code stays valid
    private const MAX_GUESSES = 5;         // per code
    private const MAX_FAILURES = 10;       // per hour, then a lock
    private const LOCK_MINUTES = 30;
    private const RESEND_GAP = 30;         // seconds between codes
    private const MAX_SENDS = 6;           // per hour

    private bool $schemaReady = false;

    public function __construct(private Database $db) {}

    // ==================================================================
    // Policy
    // ==================================================================

    /** Site policy: off | optional | admins | all. */
    public function policy(): string
    {
        $p = (string) $this->setting('two_factor', 'optional');
        return in_array($p, self::POLICIES, true) ? $p : 'optional';
    }

    /** Days a "trust this device" choice lasts; 0 turns the option off. */
    public function trustDays(): int
    {
        return max(0, min(90, (int) $this->setting('two_factor_trust_days', 30)));
    }

    /** True while storage/disable-2fa exists. */
    public function emergencyDisabled(): bool
    {
        return defined('BASEHIM_ROOT') && is_file(BASEHIM_ROOT . '/storage/disable-2fa');
    }

    /** Has this user turned two-step verification on for themselves? */
    public function isEnabled(array $user): bool
    {
        return !empty(self::security($user)['two_factor']);
    }

    /** Does the site policy require it for this user regardless of their choice? */
    public function isRequired(array $user): bool
    {
        $p = $this->policy();
        if ($p === 'all') return true;
        return $p === 'admins' && in_array((string) ($user['role'] ?? ''), ['admin', 'super_admin'], true);
    }

    /** Must this user enter an emailed code to finish signing in? */
    public function applies(array $user): bool
    {
        if ($this->policy() === 'off' || $this->emergencyDisabled()) return false;
        if (trim((string) ($user['email'] ?? '')) === '') return false;
        // An administrator let this person in once without a code (lost mailbox).
        if (!empty(self::security($user)['skip_once'])) return false;
        return $this->isEnabled($user) || $this->isRequired($user);
    }

    /** Let the next sign-in of this user through without a code (admin reset). */
    public function allowOnce(int $userId): void
    {
        self::updateSecurity($this->db, $userId, function (array $s): array {
            $s['skip_once'] = true;
            return $s;
        });
    }

    /** Called after a successful sign-in: a one-time pass is used up. */
    public function consumePass(array $user): void
    {
        if (!empty(self::security($user)['skip_once'])) {
            self::updateSecurity($this->db, (int) $user['id'], function (array $s): array {
                unset($s['skip_once']);
                return $s;
            });
        }
    }

    // ==================================================================
    // Turning it on and off
    // ==================================================================

    public function enable(int $userId): void
    {
        self::updateSecurity($this->db, $userId, function (array $s): array {
            $s['two_factor'] = true;
            $s['two_factor_at'] = date('c');
            return $s;
        });
    }

    public function disable(int $userId): void
    {
        self::updateSecurity($this->db, $userId, function (array $s): array {
            unset($s['two_factor'], $s['two_factor_at']);
            return $s;
        });
        $this->clear($userId, 'login');
        $this->clear($userId, 'setup');
    }

    // ==================================================================
    // Codes
    // ==================================================================

    /**
     * Create a code and email it.
     *
     * @return array{ok:bool, error?:string, retry?:int}
     *   error: 'locked' | 'wait' | 'limit' | 'mail' | 'no_email'
     */
    public function send(array $user, string $purpose, string $ip = ''): array
    {
        $this->ensureSchema();
        $uid = (int) $user['id'];
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') return ['ok' => false, 'error' => 'no_email'];

        $row = $this->row($uid, $purpose);
        $now = time();
        if ($row && !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > $now) {
            return ['ok' => false, 'error' => 'locked', 'retry' => strtotime((string) $row['locked_until']) - $now];
        }
        $windowStart = $row && !empty($row['window_start']) ? strtotime((string) $row['window_start']) : 0;
        $inWindow = $windowStart > $now - 3600;
        $sends = $inWindow ? (int) $row['sends'] : 0;
        if ($row && !empty($row['last_sent_at']) && strtotime((string) $row['last_sent_at']) > $now - self::RESEND_GAP) {
            return ['ok' => false, 'error' => 'wait', 'retry' => strtotime((string) $row['last_sent_at']) + self::RESEND_GAP - $now];
        }
        if ($sends >= self::MAX_SENDS) {
            return ['ok' => false, 'error' => 'limit', 'retry' => max(60, $windowStart + 3600 - $now)];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $fields = [
            'code_hash'    => $this->hash($uid, $purpose, $code),
            'expires_at'   => date('Y-m-d H:i:s', $now + self::CODE_TTL),
            'attempts'     => 0,
            'last_sent_at' => date('Y-m-d H:i:s', $now),
            'window_start' => date('Y-m-d H:i:s', $inWindow ? $windowStart : $now),
            'sends'        => $sends + 1,
            'failures'     => $inWindow && $row ? (int) $row['failures'] : 0,
            'locked_until' => null,
            'updated_at'   => date('Y-m-d H:i:s', $now),
        ];
        $this->save($uid, $purpose, $fields, (bool) $row);

        if (!$this->mail($user, $purpose, $code, $ip)) {
            return ['ok' => false, 'error' => 'mail'];
        }
        return ['ok' => true];
    }

    /**
     * Check a code. A correct code is used up.
     *
     * @return string 'ok' | 'invalid' | 'expired' | 'locked'
     */
    public function verify(int $userId, string $purpose, string $code): string
    {
        $this->ensureSchema();
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);

        // Claim one guess first, atomically, so parallel requests cannot share
        // the same remaining attempts: only a live, unlocked code with guesses
        // left is counted, and if nothing was counted there is nothing to try.
        $claimed = $this->db->execute(
            'UPDATE {auth_two_factor} SET attempts = attempts + 1, updated_at = :now
              WHERE user_id = :u AND purpose = :p AND code_hash IS NOT NULL
                AND expires_at > :now2 AND attempts < :max
                AND (locked_until IS NULL OR locked_until <= :now3)',
            ['now' => $nowSql, 'now2' => $nowSql, 'now3' => $nowSql, 'max' => self::MAX_GUESSES, 'u' => $userId, 'p' => $purpose]
        );
        $row = $this->row($userId, $purpose);
        if ($claimed < 1 || !$row) {
            if ($row && !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > $now) return 'locked';
            return 'expired';
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';
        $hash = (string) $row['code_hash'];
        if (strlen($code) === 6 && $hash !== '' && hash_equals($hash, $this->hash($userId, $purpose, $code))) {
            // Single use: only the request that clears this exact code wins.
            $used = $this->db->execute(
                'UPDATE {auth_two_factor} SET code_hash = NULL, expires_at = NULL, attempts = 0, failures = 0, locked_until = NULL, updated_at = :now
                  WHERE user_id = :u AND purpose = :p AND code_hash = :h',
                ['now' => $nowSql, 'u' => $userId, 'p' => $purpose, 'h' => $hash]
            );
            return $used > 0 ? 'ok' : 'expired';
        }

        $inWindow = !empty($row['window_start']) && strtotime((string) $row['window_start']) > $now - 3600;
        $failures = ($inWindow ? (int) $row['failures'] : 0) + 1;
        $fields = ['failures' => $failures, 'updated_at' => $nowSql];
        if (!$inWindow) { $fields['window_start'] = $nowSql; $fields['sends'] = 0; }
        $burn = (int) $row['attempts'] >= self::MAX_GUESSES;
        if ($burn) { $fields['code_hash'] = null; $fields['expires_at'] = null; }
        if ($failures >= self::MAX_FAILURES) {
            $fields['locked_until'] = date('Y-m-d H:i:s', $now + self::LOCK_MINUTES * 60);
            $fields['code_hash'] = null;
            $fields['expires_at'] = null;
        }
        $this->save($userId, $purpose, $fields, true);
        if (isset($fields['locked_until'])) return 'locked';
        return $burn ? 'expired' : 'invalid';
    }

    /** Forget any code for this user and purpose. */
    public function clear(int $userId, string $purpose): void
    {
        $this->ensureSchema();
        try {
            $this->db->execute('DELETE FROM {auth_two_factor} WHERE user_id = :u AND purpose = :p', ['u' => $userId, 'p' => $purpose]);
        } catch (\Throwable) {}
    }

    /** A plain-language reason for a failed send(). */
    public static function sendError(array $result): string
    {
        $mins = max(1, (int) ceil(((int) ($result['retry'] ?? 60)) / 60));
        return match ($result['error'] ?? '') {
            'wait'     => 'A code was sent a moment ago. You can ask for another in ' . max(1, (int) ($result['retry'] ?? 30)) . ' seconds.',
            'limit'    => 'Too many codes have been sent. Try again in about ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.',
            'locked'   => 'Too many wrong codes. Code entry is paused for ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.',
            'no_email' => 'This account has no email address, so a code cannot be sent. Ask an administrator for help.',
            default    => 'The code could not be emailed. Please try again, or ask an administrator to check the site\'s email settings.',
        };
    }

    // ==================================================================
    // Trusted devices
    // ==================================================================

    /** Cookie value that skips the code for this user on this browser. */
    public function trustToken(array $user, int $days): string
    {
        $exp = time() + $days * 86400;
        $payload = (int) $user['id'] . '.' . $exp;
        return $payload . '.' . $this->trustSig($user, $payload);
    }

    /** Does the cookie mark this browser as trusted for this user? */
    public function isTrusted(array $user, string $cookie): bool
    {
        if ($this->trustDays() === 0 || $cookie === '') return false;
        $parts = explode('.', $cookie);
        if (count($parts) !== 3 || (int) $parts[0] !== (int) $user['id'] || (int) $parts[1] < time()) return false;
        return hash_equals($this->trustSig($user, $parts[0] . '.' . $parts[1]), $parts[2]);
    }

    /**
     * Signed with the user's password hash and security epoch, so a password
     * change, "sign out everywhere" or turning two-step on again ends trust.
     */
    private function trustSig(array $user, string $payload): string
    {
        $s = self::security($user);
        return hash_hmac('sha256', 'trust|' . $payload . '|' . ($user['password_hash'] ?? '') . '|' . ($s['epoch'] ?? '') . '|' . ($s['two_factor_at'] ?? ''), AuthService::secret());
    }

    // ==================================================================
    // Per-user security record (users.meta.security)
    // ==================================================================

    /** The security part of a user's meta. */
    public static function security(array $user): array
    {
        $m = $user['meta'] ?? null;
        if (is_string($m) && $m !== '') $m = json_decode($m, true);
        $s = is_array($m) ? ($m['security'] ?? []) : [];
        return is_array($s) ? $s : [];
    }

    /** Read-modify-write users.meta.security from the current row. */
    public static function updateSecurity(Database $db, int $userId, callable $fn): void
    {
        $row = $db->selectOne('SELECT meta FROM {users} WHERE id = :id', ['id' => $userId]);
        if (!$row) return;
        $meta = is_string($row['meta'] ?? null) && $row['meta'] !== '' ? json_decode((string) $row['meta'], true) : [];
        if (!is_array($meta)) $meta = [];
        $sec = is_array($meta['security'] ?? null) ? $meta['security'] : [];
        $sec = $fn($sec);
        if ($sec) $meta['security'] = $sec; else unset($meta['security']);
        $db->execute('UPDATE {users} SET meta = :m WHERE id = :id', [
            'm' => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'id' => $userId,
        ]);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function mail(array $user, string $purpose, string $code, string $ip): bool
    {
        try {
            $app = Application::getInstance();
            /** @var Mailer $mailer */
            $mailer = $app->make(Mailer::class);
            $site = (string) ($this->generalSetting('site_title') ?: 'Basehim');
            $name = htmlspecialchars((string) (($user['display_name'] ?? '') ?: ($user['username'] ?? '')));
            $when = date('M j, Y \a\t H:i T');
            $where = $ip !== '' ? htmlspecialchars($ip) : 'an unknown address';
            $agent = htmlspecialchars(self::describeAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
            $intro = match ($purpose) {
                'setup' => '<p>Enter this code to finish turning on two-step verification for your account:</p>',
                'email' => '<p>Enter this code on your profile page to confirm this as the new email address of your account:</p>',
                default => '<p>Someone signed in to your account with the correct password. Enter this code to finish signing in:</p>',
            };
            $body = "<p>Hi {$name},</p>" . $intro
                . '<p style="text-align:center;margin:22px 0;"><span style="display:inline-block;background:#0f172a;color:#ffffff;'
                . 'font-size:28px;letter-spacing:8px;font-weight:bold;padding:12px 22px;border-radius:10px;font-family:Menlo,Consolas,monospace;">' . $code . '</span></p>'
                . '<p style="font-size:13px;color:#475569;">The code expires in 10 minutes and works once.</p>'
                . '<p style="font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:12px;margin-top:18px;">'
                . 'Requested ' . $when . ' from ' . $where . ($agent !== '' ? ' (' . $agent . ')' : '') . '.<br>'
                . ($purpose === 'login'
                    ? 'If this was not you, someone knows your password: change it now and tell your site administrator.'
                    : 'If you did not ask for this, you can ignore this email.')
                . '</p>';
            $subject = $code . ' is your ' . $site . ($purpose === 'login' ? ' sign-in code' : ' verification code');
            $heading = match ($purpose) { 'setup' => 'Confirm two-step verification', 'email' => 'Confirm your new email address', default => 'Your sign-in code' };
            return $mailer->sendTemplate((string) $user['email'], $subject, $heading, $body);
        } catch (\Throwable) {
            return false;
        }
    }

    /** "Chrome on Windows" from a user-agent string. */
    public static function describeAgent(string $ua): string
    {
        if ($ua === '') return '';
        $browser = match (true) {
            str_contains($ua, 'Edg/')     => 'Edge',
            str_contains($ua, 'OPR/')     => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/')  => 'Chrome',
            str_contains($ua, 'Safari/')  => 'Safari',
            default => '',
        };
        $os = match (true) {
            str_contains($ua, 'Windows')   => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X')  => 'macOS',
            str_contains($ua, 'Android')   => 'Android',
            str_contains($ua, 'Linux')     => 'Linux',
            default => '',
        };
        if ($browser === '' && $os === '') return 'an app or script';
        return trim($browser . ($browser !== '' && $os !== '' ? ' on ' : '') . $os);
    }

    private function hash(int $uid, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $uid . '|' . $purpose . '|' . $code, AuthService::secret());
    }

    private function row(int $uid, string $purpose): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM {auth_two_factor} WHERE user_id = :u AND purpose = :p',
            ['u' => $uid, 'p' => $purpose]
        ) ?: null;
    }

    private function save(int $uid, string $purpose, array $fields, bool $exists): void
    {
        if (!$exists) {
            try {
                $this->db->insert('auth_two_factor', ['user_id' => $uid, 'purpose' => $purpose] + $fields);
                return;
            } catch (\PDOException) {
                // Created by a parallel request a moment ago: update it instead.
            }
        }
        $sets = [];
        $params = ['u' => $uid, 'p' => $purpose];
        foreach ($fields as $k => $v) { $sets[] = "`{$k}` = :f_{$k}"; $params['f_' . $k] = $v; }
        $this->db->execute('UPDATE {auth_two_factor} SET ' . implode(', ', $sets) . ' WHERE user_id = :u AND purpose = :p', $params);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) return;
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS {auth_two_factor} (
                    `user_id`      BIGINT UNSIGNED NOT NULL,
                    `purpose`      VARCHAR(16) NOT NULL,
                    `code_hash`    CHAR(64) NULL,
                    `expires_at`   DATETIME NULL,
                    `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
                    `failures`     INT UNSIGNED NOT NULL DEFAULT 0,
                    `sends`        INT UNSIGNED NOT NULL DEFAULT 0,
                    `window_start` DATETIME NULL,
                    `last_sent_at` DATETIME NULL,
                    `locked_until` DATETIME NULL,
                    `updated_at`   DATETIME NOT NULL,
                    PRIMARY KEY (`user_id`, `purpose`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {}
        $this->schemaReady = true;
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return Application::getInstance()->make(SettingService::class)->get('authorization', $key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function generalSetting(string $key): string
    {
        try {
            return (string) Application::getInstance()->make(SettingService::class)->get('general', $key, '');
        } catch (\Throwable) {
            return '';
        }
    }
}
