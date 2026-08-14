<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;

/**
 * PasswordResetService — secure, single-use, expiring reset tokens.
 *
 * Security properties:
 *  - Tokens are 64 hex chars (256 bits) and only their SHA-256 hash is stored.
 *  - Tokens expire after 60 minutes and are single-use.
 *  - Requests are rate-limited to 3 per email per hour.
 *  - The table is created on first use, so no manual migration is required
 *    (a matching migration file ships in database/migrations for new installs).
 */
class PasswordResetService
{
    private const EXPIRY_MINUTES = 60;
    private const MAX_PER_HOUR = 3;

    /**
     * Create a reset token for an email. Returns the RAW token to embed in the
     * emailed link, or null when rate-limited. The caller must not reveal to
     * the requester whether the email exists.
     */
    public function createToken(string $email): ?string
    {
        $db = $this->db();
        $this->ensureTable($db);
        $this->purgeExpired($db);

        $recent = $db->selectOne(
            'SELECT COUNT(*) AS c FROM {password_resets} WHERE email = :e AND created_at > :cut',
            ['e' => $email, 'cut' => date('Y-m-d H:i:s', time() - 3600)]
        );
        if ((int) ($recent['c'] ?? 0) >= self::MAX_PER_HOUR) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $db->insert('password_resets', [
            'email'      => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** Look up a valid (unexpired, unused) token. Returns the row or null. */
    public function validateToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $db = $this->db();
        $this->ensureTable($db);
        $row = $db->selectOne(
            'SELECT * FROM {password_resets}
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1',
            ['h' => hash('sha256', $token), 'now' => date('Y-m-d H:i:s')]
        );
        return $row ?: null;
    }

    /** Mark a token used and invalidate any other outstanding tokens for the email. */
    public function consume(array $row): void
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $db->execute('UPDATE {password_resets} SET used_at = :n WHERE id = :id', ['n' => $now, 'id' => (int) $row['id']]);
        $db->execute('UPDATE {password_resets} SET used_at = :n WHERE email = :e AND used_at IS NULL', ['n' => $now, 'e' => (string) $row['email']]);
    }

    // ------------------------------------------------------------------

    private function ensureTable(Database $db): void
    {
        try {
            $db->execute(
                'CREATE TABLE IF NOT EXISTS {password_resets} (
                    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `email`      VARCHAR(255) NOT NULL,
                    `token_hash` CHAR(64) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `used_at`    DATETIME NULL,
                    `created_at` DATETIME NOT NULL,
                    KEY `idx_pr_email` (`email`),
                    KEY `idx_pr_token` (`token_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            // Table may already exist or the DB user may lack CREATE — either
            // way subsequent queries will surface a real problem if there is one.
        }
    }

    private function purgeExpired(Database $db): void
    {
        try {
            $db->execute(
                'DELETE FROM {password_resets} WHERE expires_at < :cut',
                ['cut' => date('Y-m-d H:i:s', time() - 7 * 86400)]
            );
        } catch (\Throwable) {}
    }

    private function db(): Database
    {
        return Application::getInstance()->make(Database::class);
    }
}
