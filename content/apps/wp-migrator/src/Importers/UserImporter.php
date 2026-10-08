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

        // The password is fixed when the job starts (Wizard::start). Up to
        // 1.3.1 the fallback was generated here as an argument default — so
        // eagerly, on every batch, even when a password had been given: each
        // batch of 25 users got a different random password and a warning.
        $defaultPassword = (string) ($this->opt('default_password') ?? '');
        if ($defaultPassword === '') {
            $defaultPassword = 'ChangeMe!' . bin2hex(random_bytes(6));
            $this->warn('no default password stored for this job; this batch uses ' . $defaultPassword);
        }
        $defaultRole = (string) $this->opt('default_role', 'author');
        if (!in_array($defaultRole, ['author', 'editor', 'contributor', 'subscriber'], true)) $defaultRole = 'author';

        foreach ($rows as $row) {
            $oldId  = (int)$row['ID'];
            $login  = trim((string)($row['user_login'] ?? '')) ?: ('user' . $oldId);
            $email  = strtolower(trim((string)($row['user_email'] ?? '')));
            if (!$email) {
                // WXR sometimes omits emails for guest authors; synthesize one.
                $email = $login . '@imported.local';
            }
            $display = (string)($row['display_name'] ?? $login) ?: $login;

            // De-dup by email only. 1.3.1 also matched by username, which
            // attributed a WordPress "admin" (any email) to this site's own
            // "admin" account.
            $existing = $users->findByEmail($email);
            if ($existing) {
                $this->idMap->put('user', $oldId, (int)$existing['id']);
                // Posts name their author by login (WXR dc:creator, and the
                // MySQL source too). 1.3.1 recorded the login only for new
                // users, so every post by an already-existing user fell back
                // to user #1.
                $this->idMap->put('user_login', $login, (int)$existing['id']);
                $this->log("user {$login} matched existing account #{$existing['id']} by email");
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
                $this->warn("failed to create user {$login}: " . $e->getMessage());
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

}
