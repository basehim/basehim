<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Config;
use App\Core\Database;

/**
 * SystemInfoService — read-only diagnostics for the admin System page.
 *
 * Everything here is derived at request time from the live environment; nothing
 * is cached, so the page always reflects the current state of the server.
 */
class SystemInfoService
{
    public function overview(): array
    {
        $checks = $this->healthChecks();
        $failing = array_filter($checks, fn($c) => $c['status'] === 'fail');
        $warning = array_filter($checks, fn($c) => $c['status'] === 'warn');

        return [
            'basehim_version' => defined('BASEHIM_VERSION') ? BASEHIM_VERSION : 'unknown',
            'php_version'  => PHP_VERSION,
            'server'       => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'os'           => php_uname('s') . ' ' . php_uname('r'),
            'health'       => empty($failing) ? (empty($warning) ? 'good' : 'warn') : 'fail',
            'checks'       => $checks,
            'fail_count'   => count($failing),
            'warn_count'   => count($warning),
        ];
    }

    /** Traffic-light checks shown on the Overview tab. */
    public function healthChecks(): array
    {
        $checks = [];

        // PHP version
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $checks[] = $this->check('PHP version', $phpOk ? 'pass' : 'warn',
            PHP_VERSION . ($phpOk ? '' : ' (8.1+ recommended)'));

        // Required extensions
        foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'gd', 'curl', 'fileinfo'] as $ext) {
            $loaded = extension_loaded($ext);
            $required = in_array($ext, ['pdo_mysql', 'mbstring', 'json'], true);
            $checks[] = $this->check("Extension: {$ext}",
                $loaded ? 'pass' : ($required ? 'fail' : 'warn'),
                $loaded ? 'Loaded' : 'Missing' . ($required ? ' (required)' : ' (recommended)'));
        }

        // Database connectivity
        try {
            $this->db()->selectOne('SELECT 1 AS ok');
            $checks[] = $this->check('Database connection', 'pass', 'Connected');
        } catch (\Throwable $e) {
            $checks[] = $this->check('Database connection', 'fail', 'Cannot connect');
        }

        // Writable paths
        foreach ([
            'storage/logs'  => BASEHIM_ROOT . '/storage/logs',
            'storage/cache' => BASEHIM_ROOT . '/storage/cache',
            'content/uploads' => BASEHIM_ROOT . '/content/uploads',
        ] as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $checks[] = $this->check("Writable: {$label}",
                $writable ? 'pass' : 'fail',
                !is_dir($path) ? 'Missing' : ($writable ? 'Writable' : 'Not writable'));
        }

        // OPcache
        $opcache = function_exists('opcache_get_status');
        $checks[] = $this->check('OPcache', $opcache ? 'pass' : 'warn',
            $opcache ? 'Enabled' : 'Not available');

        // Disk space
        $free = @disk_free_space(BASEHIM_ROOT);
        $total = @disk_total_space(BASEHIM_ROOT);
        if ($free && $total) {
            $pctFree = $free / $total * 100;
            $checks[] = $this->check('Disk space',
                $pctFree < 5 ? 'fail' : ($pctFree < 15 ? 'warn' : 'pass'),
                $this->bytes($free) . ' free of ' . $this->bytes($total) . ' (' . round($pctFree, 1) . '%)');
        }

        // HTTPS
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $checks[] = $this->check('HTTPS', $https ? 'pass' : 'warn',
            $https ? 'Secure connection' : 'Not using HTTPS');

        return $checks;
    }

    public function phpInfo(): array
    {
        return [
            'PHP version'          => PHP_VERSION,
            'SAPI'                 => PHP_SAPI,
            'Memory limit'         => ini_get('memory_limit'),
            'Max execution time'   => ini_get('max_execution_time') . 's',
            'Upload max filesize'  => ini_get('upload_max_filesize'),
            'Post max size'        => ini_get('post_max_size'),
            'Max file uploads'     => ini_get('max_file_uploads'),
            'Max input vars'       => ini_get('max_input_vars'),
            'Default charset'      => ini_get('default_charset'),
            'Timezone'             => date_default_timezone_get(),
            'Display errors'       => ini_get('display_errors') ? 'On' : 'Off',
            'Error reporting'      => (string) error_reporting(),
        ];
    }

    public function extensions(): array
    {
        $ext = get_loaded_extensions();
        sort($ext);
        return $ext;
    }

    public function serverInfo(): array
    {
        return [
            'Server software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'Operating system'  => php_uname(),
            'Server name'       => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'Document root'     => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'Basehim root'      => BASEHIM_ROOT,
            'Install base'      => defined('BASEHIM_BASE') && BASEHIM_BASE !== '' ? BASEHIM_BASE : '/ (root)',
            'Server protocol'   => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown',
            'HTTPS'             => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Yes' : 'No',
        ];
    }

    public function opcacheStatus(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => false];
        }
        $status = @opcache_get_status(false);
        if (!is_array($status)) return ['enabled' => false];
        $mem = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        $used = (float) ($mem['used_memory'] ?? 0);
        $freeM = (float) ($mem['free_memory'] ?? 0);
        $total = $used + $freeM + (float) ($mem['wasted_memory'] ?? 0);
        return [
            'enabled'       => (bool) ($status['opcache_enabled'] ?? false),
            'used'          => $this->bytes($used),
            'free'          => $this->bytes($freeM),
            'used_pct'      => $total > 0 ? round($used / $total * 100, 1) : 0,
            'cached_scripts'=> (int) ($stats['num_cached_scripts'] ?? 0),
            'hits'          => (int) ($stats['hits'] ?? 0),
            'misses'        => (int) ($stats['misses'] ?? 0),
            'hit_rate'      => round((float) ($stats['opcache_hit_rate'] ?? 0), 1),
        ];
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    public function databaseInfo(): array
    {
        $config = Application::getInstance()->make(Config::class)->get('database', []);
        $info = [
            'Driver'   => $config['driver'] ?? 'mysql',
            'Host'     => $config['host'] ?? 'unknown',
            'Database' => $config['database'] ?? 'unknown',
            'Charset'  => $config['charset'] ?? 'utf8mb4',
        ];
        try {
            $ver = $this->db()->selectOne('SELECT VERSION() AS v');
            $info['Server version'] = $ver['v'] ?? 'unknown';
        } catch (\Throwable) {
            $info['Server version'] = 'unavailable';
        }
        return $info;
    }

    /** Per-table row counts and sizes (MySQL information_schema). */
    public function tableStats(): array
    {
        try {
            $db = $this->db();
            $dbName = Application::getInstance()->make(Config::class)->get('database.database', '');
            $rows = $db->select(
                "SELECT table_name AS name, table_rows AS rows_est,
                        (data_length + index_length) AS size
                 FROM information_schema.tables
                 WHERE table_schema = :db
                 ORDER BY (data_length + index_length) DESC",
                ['db' => $dbName]
            );
            $out = [];
            $totalSize = 0;
            foreach ($rows as $r) {
                $size = (int) ($r['size'] ?? 0);
                $totalSize += $size;
                $out[] = [
                    'name' => $r['name'],
                    'rows' => (int) ($r['rows_est'] ?? 0),
                    'size' => $this->bytes($size),
                ];
            }
            return ['tables' => $out, 'total_size' => $this->bytes($totalSize), 'count' => count($out)];
        } catch (\Throwable $e) {
            return ['tables' => [], 'total_size' => '—', 'count' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Applied vs available migrations. */
    public function migrations(): array
    {
        $dir = BASEHIM_ROOT . '/database/migrations';
        $available = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.sql') ?: [] as $f) {
                $available[] = basename($f);
            }
            sort($available);
        }
        $applied = [];
        try {
            $rows = $this->db()->select('SELECT migration FROM {migrations} ORDER BY migration');
            $applied = array_map(fn($r) => $r['migration'], $rows);
        } catch (\Throwable) {
            // migrations table may not exist yet
        }
        $pending = array_values(array_diff(
            array_map(fn($f) => preg_replace('/\.sql$/', '', $f), $available),
            $applied
        ));
        return ['available' => $available, 'applied' => $applied, 'pending' => $pending];
    }

    // ------------------------------------------------------------------
    // Logs
    // ------------------------------------------------------------------

    public function logFiles(): array
    {
        $dir = BASEHIM_ROOT . '/storage/logs';
        $files = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.log') ?: [] as $f) {
                $files[] = [
                    'name'     => basename($f),
                    'size'     => $this->bytes((int) @filesize($f)),
                    'modified' => date('Y-m-d H:i', (int) @filemtime($f)),
                ];
            }
            usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
        }
        return $files;
    }

    /** Tail the last $lines of a log file (safe: name is basename-only). */
    public function readLog(string $name, int $lines = 300): array
    {
        $name = basename($name);
        if (!preg_match('/^[\w.\-]+\.log$/', $name)) return ['error' => 'Invalid log name'];
        $path = BASEHIM_ROOT . '/storage/logs/' . $name;
        if (!is_file($path)) return ['error' => 'Log not found'];

        $all = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $slice = array_slice($all, -$lines);
        return ['name' => $name, 'lines' => $slice, 'total' => count($all)];
    }

    public function deleteLog(string $name): bool
    {
        $name = basename($name);
        if (!preg_match('/^[\w.\-]+\.log$/', $name)) return false;
        $path = BASEHIM_ROOT . '/storage/logs/' . $name;
        return is_file($path) ? @unlink($path) : false;
    }

    // ------------------------------------------------------------------
    // Cache / maintenance
    // ------------------------------------------------------------------

    public function cacheInfo(): array
    {
        $cacheDir = BASEHIM_ROOT . '/storage/cache';
        $files = 0; $size = 0;
        if (is_dir($cacheDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) { $files++; $size += $f->getSize(); }
            }
        }
        return [
            'app_files'      => $files,
            'app_size'       => $this->bytes($size),
            'opcache'        => function_exists('opcache_reset'),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function check(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function bytes(float|int $b): string
    {
        $b = (float) $b;
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return round($b, $i === 0 ? 0 : 1) . ' ' . $u[$i];
    }

    private function db(): Database
    {
        return Application::getInstance()->make(Database::class);
    }
}
