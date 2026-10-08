<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Database;

/**
 * State
 *
 * Tracks migration job progress in app_wpmig_jobs. Handles step
 * transitions, batch cursor advancement and the job log.
 *
 * 1.4.0:
 *   - The log is appended with one UPDATE (no read-modify-write of the
 *     whole row), keeps the newest LOG_MAX_BYTES, and every line is also
 *     written to the per-app log file (Admin > Apps > Logs), which up to
 *     1.3.1 never received anything but "activated".
 *   - Counts/totals are bumped atomically with JSON_SET instead of
 *     re-reading the job row (log included) once per imported record.
 *   - Secrets (MySQL password, default user password) are redacted from
 *     anything sent to the browser and scrubbed from the row when a job ends.
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

    /** Newest part of the job log kept in the database. */
    public const LOG_MAX_BYTES = 262144; // 256 KB

    /** @var \Closure(string,string):void|null  (message, level) => per-app file log */
    private ?\Closure $fileLog;

    public function __construct(private Database $db, ?\Closure $fileLog = null)
    {
        $this->fileLog = $fileLog;
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {app_wpmig_jobs} WHERE id = :id', ['id' => $id]);
        if (!$row) return null;
        return $this->decode($row);
    }

    public function currentJob(): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM {app_wpmig_jobs}
             WHERE status IN ('pending','running')
             ORDER BY id DESC LIMIT 1"
        );
        return $row ? $this->decode($row) : null;
    }

    public function lastJob(): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {app_wpmig_jobs} ORDER BY id DESC LIMIT 1');
        return $row ? $this->decode($row) : null;
    }

    public function create(string $source, array $config, array $options): int
    {
        return (int) $this->db->insert('app_wpmig_jobs', [
            'status'  => 'pending',
            'source'  => $source,
            'config'  => json_encode($config),
            'options' => json_encode($options),
            'step'    => self::STEPS[0],
            'cursor'  => 0,
            'totals'  => json_encode(new \stdClass()),
            'counts'  => json_encode(new \stdClass()),
            'log'     => '',
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $changes): void
    {
        if (empty($changes)) return;
        $set = [];
        foreach (['status', 'step', 'cursor', 'totals', 'counts', 'log', 'finished_at', 'config', 'options'] as $col) {
            if (array_key_exists($col, $changes)) {
                $set[$col] = is_array($changes[$col]) ? json_encode($changes[$col]) : $changes[$col];
            }
        }
        if ($set) $this->db->update('app_wpmig_jobs', $set, ['id' => $id]);
    }

    /**
     * Append a line to the job log (database) and the per-app log file.
     * $level: info | warning | error.
     */
    public function appendLog(int $id, string $line, string $level = 'info', bool $mirrorToFile = true): void
    {
        $line = str_replace(["\r", "\n"], ' ', $line);
        $stamped = '[' . date('H:i:s') . '] ' . ($level !== 'info' ? strtoupper($level) . ': ' : '') . $line . "\n";
        try {
            $this->db->execute(
                'UPDATE {app_wpmig_jobs}
                    SET log = RIGHT(CONCAT(COALESCE(log, \'\'), :l), ' . self::LOG_MAX_BYTES . ')
                  WHERE id = :id',
                ['l' => $stamped, 'id' => $id]
            );
        } catch (\Throwable) {
            // Never let logging break a batch.
        }
        if ($this->fileLog && $mirrorToFile) {
            try { ($this->fileLog)("job #{$id}: {$line}", $level); } catch (\Throwable) {}
        }
    }

    /** The newest $bytes of a job's log. */
    public function logTail(int $id, int $bytes = 20000): string
    {
        $row = $this->db->selectOne(
            'SELECT RIGHT(COALESCE(log, \'\'), ' . max(1, $bytes) . ') AS t FROM {app_wpmig_jobs} WHERE id = :id',
            ['id' => $id]
        );
        return (string) ($row['t'] ?? '');
    }

    public function fullLog(int $id): string
    {
        $row = $this->db->selectOne('SELECT log FROM {app_wpmig_jobs} WHERE id = :id', ['id' => $id]);
        return (string) ($row['log'] ?? '');
    }

    public function bumpCount(int $jobId, string $entity, int $by = 1): void
    {
        $this->jsonAdd('counts', $jobId, $entity, $by, true);
    }

    public function setTotal(int $jobId, string $entity, int $total): void
    {
        $this->jsonAdd('totals', $jobId, $entity, $total, false);
    }

    /** Atomic JSON counter update; falls back to read-modify-write. */
    private function jsonAdd(string $col, int $jobId, string $key, int $value, bool $increment): void
    {
        $key = preg_replace('/[^a-z0-9_]/i', '', $key) ?: 'x';
        $path = '$.' . $key;
        try {
            // An empty list ('[]', written by 1.3.1 and earlier) is not an object: start from '{}'.
            $obj = "(CASE WHEN {$col} IS NOT NULL AND JSON_TYPE({$col}) = 'OBJECT' THEN {$col} ELSE '{}' END)";
            $expr = $increment ? "COALESCE(JSON_EXTRACT({$obj}, :p1), 0) + :v" : ':v';
            $params = ['p0' => $path, 'v' => $value, 'id' => $jobId];
            if ($increment) $params['p1'] = $path;
            $this->db->execute(
                "UPDATE {app_wpmig_jobs} SET {$col} = JSON_SET({$obj}, :p0, {$expr}) WHERE id = :id",
                $params
            );
            return;
        } catch (\Throwable) {
            // Old MySQL without JSON functions: fall through.
        }
        $row = $this->db->selectOne("SELECT {$col} FROM {app_wpmig_jobs} WHERE id = :id", ['id' => $jobId]);
        if (!$row) return;
        $data = json_decode((string) ($row[$col] ?? ''), true) ?: [];
        $data[$key] = $increment ? (int) ($data[$key] ?? 0) + $value : $value;
        $this->db->update('app_wpmig_jobs', [$col => json_encode($data)], ['id' => $jobId]);
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

    /**
     * Remove secrets from a finished job's stored config/options. The MySQL
     * password and the default user password were kept in plain text in
     * app_wpmig_jobs forever up to 1.3.1.
     */
    public function scrubSecrets(int $jobId): void
    {
        $job = $this->find($jobId);
        if (!$job) return;
        $config = $job['config'] ?? [];
        $options = $job['options'] ?? [];
        $pw = (string) ($options['default_password'] ?? '');
        if (isset($config['password'])) $config['password'] = '';
        if (isset($options['default_password'])) $options['default_password'] = null;
        $this->update($jobId, ['config' => $config, 'options' => $options]);
        // A generated user password was written to the job log at start.
        if (strlen($pw) >= 8) {
            try {
                $this->db->execute(
                    'UPDATE {app_wpmig_jobs} SET log = REPLACE(log, :pw, :r) WHERE id = :id',
                    ['pw' => $pw, 'r' => '[password removed]', 'id' => $jobId]
                );
            } catch (\Throwable) {}
        }
    }

    /** A job as it may be shown to the browser: no secrets, no raw log. */
    public static function redact(?array $job): ?array
    {
        if (!$job) return $job;
        unset($job['log']);
        if (isset($job['config']['password']))         $job['config']['password'] = $job['config']['password'] !== '' ? '••••' : '';
        if (isset($job['config']['file']))             $job['config']['file'] = basename((string) $job['config']['file']);
        if (array_key_exists('default_password', $job['options'] ?? [])) {
            $job['options']['default_password'] = $job['options']['default_password'] ? '••••' : null;
        }
        return $job;
    }

    /** Decode JSON columns and cast numeric fields to int. */
    private function decode(array $row): array
    {
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
