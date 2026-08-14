<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Http\Middleware\CheckCapability;
use App\Services\UserService;

/**
 * UsersApi — read and manage user accounts.
 *
 * Password hashes are stripped from every return value. An app that wants to
 * check a password should not be reading the hash; it should be calling the
 * auth service.
 */
class UsersApi extends Resource
{
    private function service(): UserService
    {
        return $this->make(UserService::class);
    }

    public function find(int $id): ?array
    {
        $row = $this->attempt(fn() => $this->service()->find($id), null, 'find');
        return is_array($row) ? $this->safe($row) : null;
    }

    public function findByEmail(string $email): ?array
    {
        $row = $this->attempt(fn() => $this->service()->findByEmail($email), null, 'findByEmail');
        return is_array($row) ? $this->safe($row) : null;
    }

    public function findByUsername(string $username): ?array
    {
        $row = $this->attempt(fn() => $this->service()->findByUsername($username), null, 'findByUsername');
        return is_array($row) ? $this->safe($row) : null;
    }

    /** @param array $filters search, role, status */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $result = $this->attempt(
            fn() => $this->service()->paginate($filters, max(1, $page), max(1, min(100, $perPage))),
            ['data' => [], 'meta' => []],
            'paginate'
        );
        $result['data'] = array_map([$this, 'safe'], (array) ($result['data'] ?? []));
        return $result;
    }

    public function all(array $filters = [], int $limit = 500): array
    {
        $out = [];
        $page = 1;
        while (count($out) < $limit) {
            $chunk = $this->paginate($filters, $page, 100);
            $rows = $chunk['data'] ?? [];
            if (!$rows) break;
            foreach ($rows as $row) {
                $out[] = $row;
                if (count($out) >= $limit) break 2;
            }
            if (count($rows) < 100) break;
            $page++;
        }
        return $out;
    }

    /** Create a user. Returns the new id, or 0. */
    public function create(array $data): int
    {
        $id = (int) $this->attempt(fn() => $this->service()->create($data), 0, 'create');
        if ($id > 0) $this->log("Created user #{$id}");
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->update($id, $data), false, 'update');
        if ($ok) $this->log("Updated user #{$id}");
        return $ok;
    }

    public function delete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->delete($id), false, 'delete');
        if ($ok) $this->log("Deleted user #{$id}", [], 'warning');
        return $ok;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->emailExists($email, $excludeId), false, 'emailExists');
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->usernameExists($username, $excludeId), false, 'usernameExists');
    }

    /** The signed-in user, or null on a request with no session (cron, webhook). */
    public function current(): ?array
    {
        try {
            $user = $this->app->has('auth.user') ? $this->app->make('auth.user') : null;
            return is_array($user) ? $this->safe($user) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Capability check against any user row. */
    public function can(?array $user, string $capability): bool
    {
        if (!$user) return false;
        return $this->attempt(fn() => CheckCapability::userCan($user, $capability), false, 'can') === true;
    }

    /** Capability check against the signed-in user. */
    public function currentCan(string $capability): bool
    {
        return $this->can($this->current(), $capability);
    }

    /** Strip secrets before a row leaves the API. */
    private function safe(array $user): array
    {
        unset($user['password_hash'], $user['remember_token']);
        return $user;
    }
}
