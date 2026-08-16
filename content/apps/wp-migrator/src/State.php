<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Database;

/**
 * State
 *
 * Tracks migration job progress in app_wpmig_jobs. Handles step
 * transitions and batch cursor advancement.
 */
class State
{
    /** Order in which migration steps run. Earlier steps are prerequisites
     *  for later ones (users before posts, posts before comments, etc.). */
    public const STEPS = [
        'users',
        'taxonomies',
        'media',
        'posts',            // posts + pages, including postmeta and SEO meta
        'featured_media',   // requires both posts and media
        'comments',
        'menus',
        'redirects',
        'rewrite_content',
    ];

    public function __construct(private Database $db) {}

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM app_wpmig_jobs WHERE id = :id', ['id' => $id]);
        if (!$row) return null;
        return $this->decode($row);
    }

    public function currentJob(): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM app_wpmig_jobs
             WHERE status IN ('pending','running')
             ORDER BY id DESC LIMIT 1"
        );
        return $row ? $this->decode($row) : null;
    }

    public function lastJob(): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM app_wpmig_jobs ORDER BY id DESC LIMIT 1');
        return $row ? $this->decode($row) : null;
    }

    public function create(string $source, array $config, array $options): int
    {
        // Database::insert returns string (PDO::lastInsertId). Cast to int
        // since our schema uses BIGINT and the rest of the app uses int IDs.
        return (int) $this->db->insert('app_wpmig_jobs', [
            'status'  => 'pending',
            'source'  => $source,
            'config'  => json_encode($config),
            'options' => json_encode($options),
            'step'    => self::STEPS[0],
            'cursor'  => 0,
            'totals'  => json_encode([]),
            'counts'  => json_encode([]),
            'log'     => '',
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $changes): void
    {
        if (empty($changes)) return;
        $set = [];
        foreach (['status', 'step', 'cursor', 'totals', 'counts', 'log', 'finished_at'] as $col) {
            if (array_key_exists($col, $changes)) {
                $set[$col] = is_array($changes[$col]) ? json_encode($changes[$col]) : $changes[$col];
            }
        }
        if ($set) $this->db->update('app_wpmig_jobs', $set, ['id' => $id]);
    }

    public function appendLog(int $id, string $line): void
    {
        $row = $this->find($id);
        if (!$row) return;
        $log = $row['log'] ?? '';
        $log .= '[' . date('H:i:s') . '] ' . $line . "\n";
        // Cap log size to avoid runaway growth (last ~32KB).
        if (strlen($log) > 32768) $log = substr($log, -32768);
        $this->db->update('app_wpmig_jobs', ['log' => $log], ['id' => $id]);
    }

    public function bumpCount(int $jobId, string $entity, int $by = 1): void
    {
        $row = $this->find($jobId);
        if (!$row) return;
        $counts = $row['counts'] ?: [];
        $counts[$entity] = ($counts[$entity] ?? 0) + $by;
        $this->update($jobId, ['counts' => $counts]);
    }

    public function setTotal(int $jobId, string $entity, int $total): void
    {
        $row = $this->find($jobId);
        if (!$row) return;
        $totals = $row['totals'] ?: [];
        $totals[$entity] = $total;
        $this->update($jobId, ['totals' => $totals]);
    }

    public function nextStep(string $current): ?string
    {
        $idx = array_search($current, self::STEPS, true);
        if ($idx === false || $idx === count(self::STEPS) - 1) return null;
        return self::STEPS[$idx + 1];
    }

    /** Reset cursor at start of a new step. */
    public function advanceToStep(int $jobId, string $step): void
    {
        $this->update($jobId, ['step' => $step, 'cursor' => 0]);
    }

    /** Decode JSON columns and cast numeric fields to int. */
    private function decode(array $row): array
    {
        // MySQL/PDO returns numeric columns as strings unless emulation is off.
        // Cast everywhere we use IDs/cursors so int-typed methods don't blow up.
        if (isset($row['id']))     $row['id']     = (int)$row['id'];
        if (isset($row['cursor'])) $row['cursor'] = (int)$row['cursor'];
        foreach (['config', 'options', 'totals', 'counts'] as $k) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $decoded = json_decode($row[$k], true);
                $row[$k] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }
}
