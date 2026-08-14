<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class UserRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM {users} WHERE email = :email AND deleted_at IS NULL', ['email' => $email]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->selectOne('SELECT * FROM {users} WHERE username = :username AND deleted_at IS NULL', ['username' => $username]);
    }

    public function findByLogin(string $login): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM {users} WHERE (email = :email OR username = :username) AND deleted_at IS NULL LIMIT 1',
            ['email' => $login, 'username' => $login]
        );
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM {users} WHERE email = :email AND deleted_at IS NULL';
        $p = ['email' => $email];
        if ($excludeId) { $sql .= ' AND id <> :ex'; $p['ex'] = $excludeId; }
        return $this->db->selectOne($sql, $p) !== null;
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM {users} WHERE username = :username AND deleted_at IS NULL';
        $p = ['username' => $username];
        if ($excludeId) { $sql .= ' AND id <> :ex'; $p['ex'] = $excludeId; }
        return $this->db->selectOne($sql, $p) !== null;
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert('users', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('users', $data, ['id' => $id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('users', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = 'role = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(username LIKE :search OR email LIKE :search OR display_name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $countRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM {users} WHERE {$whereSql}", $params);
        $total = (int)($countRow['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT id, uuid, username, email, display_name, role, status, last_login_at, created_at
             FROM {users} WHERE {$whereSql}
             ORDER BY created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage))],
        ];
    }

    public function totalCount(): int
    {
        $r = $this->db->selectOne('SELECT COUNT(*) AS c FROM {users} WHERE deleted_at IS NULL');
        return (int)($r['c'] ?? 0);
    }

    public function touchLastLogin(int $id): void
    {
        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }
}
