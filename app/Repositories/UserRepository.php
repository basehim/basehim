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

    /**
     * Change a user's `meta` under a row lock (1.2.70, issues.md BH-008).
     *
     * $change gets the current raw column and returns the new JSON string, or
     * null to leave it as it is. The row is read with SELECT … FOR UPDATE
     * inside a transaction, so a second request changing the same user's meta
     * waits for this one instead of overwriting it with what it read earlier.
     * Inside a transaction someone else already opened, it joins that one.
     *
     * @param callable(?string):?string $change
     * @return bool false when there is no such user
     */
    public function lockedMetaUpdate(int $id, callable $change): bool
    {
        $pdo = $this->db->connection();
        $run = function () use ($id, $change): bool {
            $row = $this->db->selectOne(
                'SELECT meta FROM {users} WHERE id = :id AND deleted_at IS NULL FOR UPDATE',
                ['id' => $id]
            );
            if ($row === null) return false;
            $new = $change($row['meta'] ?? null);
            if ($new !== null) {
                $this->db->execute('UPDATE {users} SET meta = :m WHERE id = :id', ['m' => $new, 'id' => $id]);
            }
            return true;
        };
        if ($pdo->inTransaction()) {
            return $run();
        }
        // A deadlock or serialization failure (SQLSTATE 40001, MySQL 1213) is
        // the server telling us to try again, so do: the change is re-applied
        // to whatever is there now.
        for ($attempt = 1; ; $attempt++) {
            try {
                return (bool) $this->db->transaction(fn() => $run());
            } catch (\PDOException $e) {
                $retry = $e->getCode() === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
                if (!$retry || $attempt >= 5) throw $e;
                usleep(random_int(5000, 40000) * $attempt);
            }
        }
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
            $where[] = '(username LIKE :search1 OR email LIKE :search2 OR display_name LIKE :search3)';
            // One placeholder per use: native prepared statements reject a repeated
            // name (SQLSTATE HY093), which made every user search fail.
            $like = '%' . addcslashes((string) $filters['search'], '\\%_') . '%';
            $params['search1'] = $like; $params['search2'] = $like; $params['search3'] = $like;
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
