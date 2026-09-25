<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The one migration runner. The updater and the System page's "Run
 * migrations" button both call it.
 *
 * Why it exists:
 *
 * - The installer recorded migrations by file name ("005_fix_post_status.sql")
 *   while the updater and the System page recorded them without the extension
 *   ("005_fix_post_status"). Neither recognised the other's rows, so the first
 *   update or button press after an install ran every migration a second
 *   time. Here a name is always compared without its extension, so both forms
 *   count as the same migration, whichever wrote it.
 * - The updater and the System page each had their own copy of the runner.
 *   The copies had already drifted once: the button's copy never expanded
 *   {table} tokens and had never worked.
 * - Each migration file was sent to PDO::exec() whole. MySQL reports an error
 *   only for the first statement of such a batch, so a migration that failed
 *   halfway was recorded as applied. Here each statement runs on its own, and
 *   a migration is recorded only after every statement has succeeded.
 * - Two runs at once (an update and a button press) could apply the same
 *   migration twice. A named lock serialises them.
 */
final class MigrationService
{
    public function __construct(private Database $db)
    {
    }

    /** "database/migrations/005_fix_post_status.sql" → "005_fix_post_status". */
    public static function key(string $nameOrPath): string
    {
        return (string) preg_replace('/\.sql$/i', '', basename($nameOrPath));
    }

    /** @return string[] every migration file, in the order it runs */
    public function files(): array
    {
        $files = glob(BASEHIM_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files); // full name: some numbers repeat (two 002_, two 003_)
        return $files;
    }

    /** @return string[] applied migration keys, however they were recorded */
    public function applied(): array
    {
        try {
            $rows = $this->db->select('SELECT migration FROM {migrations}');
        } catch (\Throwable) {
            return []; // no table yet
        }
        $keys = [];
        foreach ($rows as $r) $keys[self::key((string) $r['migration'])] = true;
        return array_keys($keys);
    }

    /** @return string[] keys of migrations not yet applied */
    public function pending(): array
    {
        $applied = array_flip($this->applied());
        $out = [];
        foreach ($this->files() as $f) {
            $k = self::key($f);
            if (!isset($applied[$k])) $out[] = $k;
        }
        return $out;
    }

    /**
     * Apply every pending migration, in order.
     *
     * @return array{applied: string[], error: ?string}
     */
    public function run(): array
    {
        $applied = [];
        $pdo = $this->db->connection();
        $px = fn(string $sql): string => $this->db->expand($sql);

        try {
            $pdo->exec($px(
                'CREATE TABLE IF NOT EXISTS {migrations} (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `applied_at` DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            ));
        } catch (\Throwable $e) {
            return ['applied' => [], 'error' => 'Could not create the migrations table: ' . $e->getMessage()];
        }

        $lock = 'basehim_migrations_' . substr(md5(BASEHIM_ROOT), 0, 16);
        // Every result set is closed before the next statement: an open one
        // makes MySQL refuse the next query ("unbuffered queries are active").
        try {
            $st = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 30)");
            $got = (int) $st->fetchColumn();
            $st->closeCursor();
        } catch (\Throwable) {
            $got = 0;
        }
        if ($got !== 1) {
            return ['applied' => [], 'error' => 'Another migration run is in progress. Try again in a minute.'];
        }

        try {
            // Read inside the lock: another run may have just finished.
            $done = array_flip($this->applied());
            $record = $pdo->prepare($px('INSERT INTO {migrations} (migration, applied_at) VALUES (?, ?)'));

            foreach ($this->files() as $file) {
                $key = self::key($file);
                if (isset($done[$key])) continue;

                $sql = file_get_contents($file);
                if ($sql === false) {
                    return ['applied' => $applied, 'error' => "Could not read {$key}."];
                }
                foreach (self::statements($sql) as $i => $statement) {
                    try {
                        $pdo->exec($px($statement));
                    } catch (\Throwable $e) {
                        // Not recorded: it runs again, from the top, next time.
                        // Migrations are written to be safe to re-run.
                        return ['applied' => $applied,
                                'error' => "{$key}, statement " . ($i + 1) . ': ' . $e->getMessage()];
                    }
                }
                $record->execute([$key, date('Y-m-d H:i:s')]);
                $record->closeCursor();
                $done[$key] = true;
                $applied[] = $key;
            }
            return ['applied' => $applied, 'error' => null];
        } catch (\Throwable $e) {
            return ['applied' => $applied, 'error' => $e->getMessage()];
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")")->closeCursor(); } catch (\Throwable) {}
        }
    }

    /**
     * Split a migration file into statements, the same way the installer
     * does: drop whole-line `--` comments, then split where a semicolon ends a
     * line. PREPARE/EXECUTE blocks split into statements that are each valid
     * on their own, and their @session variables carry across on one
     * connection.
     *
     * @return string[]
     */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*--/', $line)) continue;
            $lines[] = $line;
        }
        $parts = preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}
