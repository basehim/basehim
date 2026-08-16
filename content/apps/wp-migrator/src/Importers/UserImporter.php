<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Services\UserService;

/**
 * UserImporter
 *
 * Brings WordPress users across as Basehim users. Sets a default password
 * (specified in job options) since WP password hashes use phpass, which
 * Basehim doesn't recognize. Users will need to reset on first login.
 *
 * Re-runs are idempotent: a user is identified by email; if a user with
 * the same email exists, we reuse their ID instead of creating a duplicate.
 */
class UserImporter extends Importer
{
    public function entityType(): string { return 'users'; }

    public function total(): int { return $this->source->countUsers(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchUsers($offset, $limit);
        if (!$rows) return 0;

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);

        $defaultPassword = (string) $this->opt('default_password', $this->fallbackPassword());
        $defaultRole     = (string) $this->opt('default_role', 'author');

        foreach ($rows as $row) {
            $oldId  = (int)$row['ID'];
            $login  = trim((string)($row['user_login'] ?? '')) ?: ('user' . $oldId);
            $email  = strtolower(trim((string)($row['user_email'] ?? '')));
            if (!$email) {
                // WXR sometimes omits emails for guest authors; synthesize one.
                $email = $login . '@imported.local';
            }
            $display = (string)($row['display_name'] ?? $login) ?: $login;

            // De-dup: if the user already exists, just remember the mapping.
            $existing = $users->findByEmail($email) ?? $users->findByUsername($login);
            if ($existing) {
                $this->idMap->put('user', $oldId, (int)$existing['id']);
                continue;
            }

            try {
                $newId = $users->create([
                    'username'     => $this->uniqueUsername($login, $users),
                    'email'        => $email,
                    'password'     => $defaultPassword,
                    'display_name' => $display,
                    'role'         => $defaultRole,
                    'status'       => 'active',
                ]);
                $this->idMap->put('user', $oldId, $newId);
                // Also map by WP login since posts may reference dc:creator (username, not ID).
                $this->idMap->put('user_login', $login, $newId);
                $this->state->bumpCount($this->jobId, 'users');
            } catch (\Throwable $e) {
                $this->log("failed to create user {$login}: " . $e->getMessage());
            }
        }
        return count($rows);
    }

    private function uniqueUsername(string $base, UserService $users): string
    {
        $username = preg_replace('/[^a-z0-9._-]+/i', '', $base) ?: 'user';
        $candidate = $username;
        $i = 1;
        while ($users->usernameExists($candidate)) {
            $candidate = $username . $i;
            $i++;
            if ($i > 999) break;
        }
        return $candidate;
    }

    /** Last-resort password if the operator didn't set one. Always logged. */
    private function fallbackPassword(): string
    {
        $pw = 'ChangeMe!' . bin2hex(random_bytes(4));
        $this->log("WARNING: no default password provided; using one-time fallback '{$pw}'");
        return $pw;
    }
}
