<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;

/**
 * ActivityLogService — per-user audit trail.
 *
 * Records auth events (logins, failures, resets), content changes
 * (post/page create/update/delete) and account/audit events (role changes,
 * permission overrides, suspensions, ownership transfers…).
 *
 * The table is created on first use (a matching migration ships in
 * database/migrations for fresh installs). Apps can both write entries —
 *   \App\Services\ActivityLogService::record($userId, 'myapp.thing', …)
 * — and observe them via the `activity.logged` action.
 */
class ActivityLogService
{
    /** Filter groups used by the user Activity tab. */
    public const FILTERS = [
        'all'     => null,
        'logins'  => 'auth.%',
        'content' => ['post.%', 'page.%'],
        'audit'   => 'user.%',
    ];

    /** Static convenience for terse call-sites (safe: never throws). */
    public static function record(?int $userId, string $event, ?string $objectType = null, ?int $objectId = null, ?string $detail = null): void
    {
        try {
            Application::getInstance()->make(self::class)->log($userId, $event, $objectType, $objectId, $detail);
        } catch (\Throwable) {
            // Activity logging must never break the action being logged.
        }
    }

    public function log(?int $userId, string $event, ?string $objectType = null, ?int $objectId = null, ?string $detail = null): void
    {
        try {
            $db = $this->db();
            $this->ensureTable($db);
            $row = [
                'user_id'     => $userId,
                'event'       => substr($event, 0, 80),
                'object_type' => $objectType !== null ? substr($objectType, 0, 40) : null,
                'object_id'   => $objectId,
                'detail'      => $detail !== null ? substr($detail, 0, 500) : null,
                'ip'          => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            $db->insert('user_activity_log', $row);
            try {
                Application::getInstance()->make(\App\Core\HookRegistry::class)->doAction('activity.logged', $row);
            } catch (\Throwable) {}
        } catch (\Throwable) {
            // Never let audit logging take down the request.
        }
    }

    /**
     * Paginated activity for one user.
     * $filter: all | logins | content | audit
     */
    public function forUser(int $userId, string $filter = 'all', int $page = 1, int $perPage = 30): array
    {
        $db = $this->db();
        $this->ensureTable($db);

        $where = 'user_id = :uid';
        $params = ['uid' => $userId];
        $like = self::FILTERS[$filter] ?? null;
        if (is_string($like)) {
            $where .= ' AND event LIKE :ev';
            $params['ev'] = $like;
        } elseif (is_array($like)) {
            $parts = [];
            foreach ($like as $i => $pattern) {
                $parts[] = "event LIKE :ev{$i}";
                $params["ev{$i}"] = $pattern;
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }

        $count = $db->selectOne("SELECT COUNT(*) AS c FROM {user_activity_log} WHERE {$where}", $params);
        $total = (int) ($count['c'] ?? 0);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = $db->select(
            "SELECT * FROM {user_activity_log} WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data' => $rows,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    // ------------------------------------------------------------------

    private function ensureTable(Database $db): void
    {
        try {
            $db->execute(
                'CREATE TABLE IF NOT EXISTS {user_activity_log} (
                    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `user_id`     BIGINT UNSIGNED NULL,
                    `event`       VARCHAR(80) NOT NULL,
                    `object_type` VARCHAR(40) NULL,
                    `object_id`   BIGINT UNSIGNED NULL,
                    `detail`      VARCHAR(500) NULL,
                    `ip`          VARCHAR(45) NULL,
                    `user_agent`  VARCHAR(255) NULL,
                    `created_at`  DATETIME NOT NULL,
                    KEY `idx_ual_user` (`user_id`, `id`),
                    KEY `idx_ual_event` (`event`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {}
    }

    private function db(): Database
    {
        return Application::getInstance()->make(Database::class);
    }
}
