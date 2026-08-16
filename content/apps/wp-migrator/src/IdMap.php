<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Database;

/**
 * IdMap
 *
 * Translates WordPress entity IDs to Basehim entity IDs. Critical for
 * wiring up cross-references (post.author -> users, comment.post -> posts,
 * featured image -> media, etc.). Persists to app_wpmig_idmap so the
 * migration is resumable across batches.
 */
class IdMap
{
    /** Cache to avoid re-querying the same mapping repeatedly. */
    private array $cache = [];

    public function __construct(private Database $db) {}

    /**
     * Store a mapping (idempotent — ignores duplicate-key collisions).
     */
    public function put(string $type, int|string $oldId, int $newId): void
    {
        $oldId = (string)$oldId;
        try {
            $this->db->insert('app_wpmig_idmap', [
                'entity_type' => $type,
                'old_id'      => $oldId,
                'new_id'      => $newId,
            ]);
        } catch (\Throwable) {
            // Likely UNIQUE constraint hit on re-run — update instead.
            $this->db->execute(
                'UPDATE app_wpmig_idmap SET new_id = :n WHERE entity_type = :t AND old_id = :o',
                ['n' => $newId, 't' => $type, 'o' => $oldId]
            );
        }
        $this->cache[$type][$oldId] = $newId;
    }

    /** Look up a single mapping. Returns null if not found. */
    public function get(string $type, int|string $oldId): ?int
    {
        $oldId = (string)$oldId;
        if (isset($this->cache[$type][$oldId])) {
            return $this->cache[$type][$oldId];
        }
        $row = $this->db->selectOne(
            'SELECT new_id FROM app_wpmig_idmap WHERE entity_type = :t AND old_id = :o',
            ['t' => $type, 'o' => $oldId]
        );
        $val = $row ? (int)$row['new_id'] : null;
        if ($val !== null) {
            $this->cache[$type][$oldId] = $val;
        }
        return $val;
    }

    /** Bulk-load mappings for a given type — useful for big lookups. */
    public function loadAll(string $type): void
    {
        $rows = $this->db->select(
            'SELECT old_id, new_id FROM app_wpmig_idmap WHERE entity_type = :t',
            ['t' => $type]
        );
        foreach ($rows as $r) {
            $this->cache[$type][(string)$r['old_id']] = (int)$r['new_id'];
        }
    }

    /** Wipe all mappings (used by the "reset" admin action). */
    public function clear(): void
    {
        $this->db->execute('DELETE FROM app_wpmig_idmap');
        $this->cache = [];
    }

    /** Count of mappings for a given type. */
    public function count(string $type): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM app_wpmig_idmap WHERE entity_type = :t',
            ['t' => $type]
        );
        return (int)($row['c'] ?? 0);
    }
}
