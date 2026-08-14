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
            'status' => $data['status'] ?? 'active',
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

        if (!empty($payload)) {
            $this->repo->update($id, $payload);
        }
        $this->hooks->doAction('user.updated', $this->repo->find($id), $existing);
        return true;
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
