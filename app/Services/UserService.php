<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Core\HookRegistry;
use App\Core\Helpers;

class UserService
{
    public function __construct(
        private UserRepository $repo,
        private HookRegistry $hooks
    ) {}

    public function find(int $id): ?array { return $this->repo->find($id); }

    /**
     * Read one key from a user's `meta` JSON column.
     *
     * `users.meta` has existed since the initial schema; this is the first
     * reader/writer for it. It is the per-user store for small, user-scoped
     * state such as Data Table preferences — not a general key/value bag for
     * large data. Returns $default when the user, the column or the key is
     * absent or the JSON is unreadable.
     */
    public function meta(int $userId, string $key, mixed $default = null): mixed
    {
        $user = $this->repo->find($userId);
        if ($user === null) {
            return $default;
        }
        $meta = $this->decodeMeta($user['meta'] ?? null);
        return array_key_exists($key, $meta) ? $meta[$key] : $default;
    }

    /**
     * Write one key into a user's `meta` JSON column, leaving other keys
     * untouched. A null value removes the key. The stored JSON is capped so a
     * client cannot grow the row without bound (preferences are small).
     */
    public function setMeta(int $userId, string $key, mixed $value): bool
    {
        $user = $this->repo->find($userId);
        if ($user === null) {
            return false;
        }
        $meta = $this->decodeMeta($user['meta'] ?? null);
        if ($value === null) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 65535) {
            return false;
        }
        return $this->repo->update($userId, ['meta' => $json]) >= 0;
    }

    /** @return array<string,mixed> */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    public function findByEmail(string $email): ?array { return $this->repo->findByEmail($email); }
    public function findByUsername(string $username): ?array { return $this->repo->findByUsername($username); }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->repo->paginate($filters, $page, $perPage);
    }

    public function totalCount(): int { return $this->repo->totalCount(); }

    public function create(array $data): int
    {
        $data = $this->hooks->applyFilters('user.before_create', $data);

        $payload = [
            'uuid' => Helpers::uuid(),
            'username' => $data['username'],
            'email' => strtolower(trim($data['email'])),
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'display_name' => $data['display_name'] ?? $data['username'],
            'bio' => $data['bio'] ?? null,
            'role' => $data['role'] ?? 'subscriber',
            'status' => self::validStatus($data['status'] ?? 'active'),
            'locale' => $data['locale'] ?? 'en_US',
            'timezone' => $data['timezone'] ?? 'UTC',
        ];

        $id = $this->repo->create($payload);
        $this->hooks->doAction('user.created', $this->repo->find($id));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->repo->find($id);
        if (!$existing) return false;

        $payload = [];
        foreach (['display_name', 'bio', 'role', 'status', 'locale', 'timezone', 'avatar_media_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (!empty($data['email'])) {
            $payload['email'] = strtolower(trim($data['email']));
        }
        if (!empty($data['password'])) {
            $payload['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        if (array_key_exists('status', $payload)) {
            $payload['status'] = self::validStatus($payload['status'] ?? 'active', (string) ($existing['status'] ?? 'active'));
        }

        if (!empty($payload)) {
            $this->repo->update($id, $payload);
        }

        // A new password ends every other way in: remember-me cookies and API
        // refresh tokens are revoked here, and browser sessions end on their
        // next request because they carry a fingerprint of the old hash
        // (AuthService::sessionUser). The same when the account is blocked.
        $blocked = isset($payload['status']) && $payload['status'] !== 'active' && ($existing['status'] ?? '') === 'active';
        if (isset($payload['password_hash']) || $blocked) {
            try {
                $app = \App\Core\Application::getInstance();
                $app->make(AuthSecurityService::class)->revokeAllForUser($id);
                AuthService::revokeTokens($app->make(\App\Core\Database::class), $id);
            } catch (\Throwable) {}
        }
        // The administration email follows its account's address (1.2.66) when
        // the person making the change may change settings.
        if (isset($payload['email']) && $payload['email'] !== strtolower((string) ($existing['email'] ?? ''))) {
            (new AdminEmailService())->accountEmailChanged((string) ($existing['email'] ?? ''), $payload['email']);
        }
        $this->hooks->doAction('user.updated', $this->repo->find($id), $existing);
        return true;
    }

    /**
     * Account statuses the users table can hold. Anything else ("pending" was
     * offered by the New User form) used to be stored as an empty string on
     * MySQL without strict mode, leaving an account nobody could sign in to
     * or see the status of.
     */
    public const STATUSES = ['active', 'inactive', 'suspended'];

    public static function validStatus(mixed $status, string $fallback = 'active'): string
    {
        $s = strtolower(trim((string) $status));
        if ($s === 'pending') $s = 'inactive';
        return in_array($s, self::STATUSES, true) ? $s : (in_array($fallback, self::STATUSES, true) ? $fallback : 'active');
    }

    public function delete(int $id): bool
    {
        $existing = $this->repo->find($id);
        if (!$existing) return false;
        $this->repo->softDelete($id);
        $this->hooks->doAction('user.deleted', $existing);
        return true;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->repo->emailExists($email, $excludeId);
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        return $this->repo->usernameExists($username, $excludeId);
    }
}
