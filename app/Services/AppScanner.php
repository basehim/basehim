<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AppScanner — flags app code that reaches around the permission broker.
 *
 * A heuristic, not a verdict. This is a regex pass over PHP source: it cannot
 * tell a legitimate use from a malicious one, it does not follow variables, and
 * anything determined can defeat it in a line of obfuscation. What it does is
 * make the obvious cases visible at install time, so an operator installing an
 * app that declares "posts.read" and then opens its own PDO connection can see
 * that before activating it.
 *
 * Findings are advisory. Nothing here blocks an install — a false positive
 * refusing a legitimate app would be worse than a flag the operator can read
 * and judge. Several patterns below have entirely proper uses; the point is
 * that they should be a deliberate choice rather than a surprise.
 */
class AppScanner
{
    /**
     * pattern => [label, severity, why it matters]
     *
     * Severity is 'high' when the construct bypasses the broker outright, and
     * 'medium' when it merely deserves a look.
     */
    private const PATTERNS = [
        '/\bnew\s+\\\\?PDO\b/i' => [
            'Direct database connection', 'high',
            'Opens its own PDO connection, which bypasses every permission on the app\'s declaration.',
        ],
        '/\beval\s*\(/i' => [
            'eval()', 'high',
            'Executes strings as code. Whatever the app declares, eval can do anything.',
        ],
        '/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i' => [
            'Shell execution', 'high',
            'Runs operating-system commands, outside anything Basehim can mediate.',
        ],
        '/\b(?:file_get_contents|fopen|include|require)\s*\(\s*[^)]*\.env/i' => [
            'Reads .env', 'high',
            'Reads the environment file directly, which holds the database password and JWT secret.',
        ],
        '/\bassert\s*\(\s*\$/i' => [
            'Dynamic assert()', 'high',
            'assert() with a variable argument executes code on older PHP configurations.',
        ],
        '/\b(?:create_function|call_user_func(?:_array)?)\s*\(\s*\$_(?:GET|POST|REQUEST)/i' => [
            'Calls a user-supplied function name', 'high',
            'Invokes a function named by request input — a remote code execution pattern.',
        ],
        '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]*\]\s*\)?\s*;?\s*(?:\/\/.*)?$\s*.*\b(?:unlink|rmdir|file_put_contents)\s*\(/im' => [
            'Filesystem write from request input', 'medium',
            'Writes or deletes files using request input, which is worth checking for path traversal.',
        ],
        '/\bbase64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{60,}/' => [
            'Large encoded blob', 'medium',
            'Decodes a long embedded string. Legitimate for bundled data, also how payloads hide.',
        ],
        '/\bcurl_setopt\s*\([^)]*CURLOPT_SSL_VERIFYPEER\s*,\s*(?:false|0)\b/i' => [
            'Disables TLS verification', 'medium',
            'Turns off certificate checking on outbound requests, exposing them to interception.',
        ],
        '/\bmove_uploaded_file\s*\(/i' => [
            'Accepts file uploads', 'medium',
            'Handles uploads. Confirm it validates type and destination.',
        ],
    ];

    /** Files bigger than this are skipped — a minified bundle is not source. */
    private const MAX_FILE_BYTES = 1048576;

    /** Cap on files examined, so a huge app can't stall an install. */
    private const MAX_FILES = 400;

    /**
     * Scan a directory of app code.
     *
     * @return array{findings: array, files_scanned: int, high: int, medium: int, skipped: bool}
     */
    public function scan(string $dir): array
    {
        $findings = [];
        $scanned = 0;
        $skipped = false;

        if (!is_dir($dir)) {
            return ['findings' => [], 'files_scanned' => 0, 'high' => 0, 'medium' => 0, 'skipped' => false];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) continue;
                if (strtolower($file->getExtension()) !== 'php') continue;

                if ($scanned >= self::MAX_FILES) { $skipped = true; break; }
                if ($file->getSize() > self::MAX_FILE_BYTES) { $skipped = true; continue; }

                $source = @file_get_contents($file->getPathname());
                if ($source === false) continue;
                $scanned++;

                $relative = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
                foreach (self::PATTERNS as $pattern => [$label, $severity, $why]) {
                    if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                        $findings[] = [
                            'file'     => $relative,
                            'line'     => substr_count(substr($source, 0, (int) $m[0][1]), "\n") + 1,
                            'label'    => $label,
                            'severity' => $severity,
                            'why'      => $why,
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // A traversal failure yields a partial result, which is still
            // better than none — the caller is told scanning was incomplete.
            $skipped = true;
        }

        return [
            'findings'      => $findings,
            'files_scanned' => $scanned,
            'high'          => count(array_filter($findings, fn($f) => $f['severity'] === 'high')),
            'medium'        => count(array_filter($findings, fn($f) => $f['severity'] === 'medium')),
            'skipped'       => $skipped,
        ];
    }

    /** A one-line summary for the admin list. */
    public function summarise(array $result): string
    {
        $high = (int) ($result['high'] ?? 0);
        $medium = (int) ($result['medium'] ?? 0);
        if ($high === 0 && $medium === 0) return 'No flags';

        $parts = [];
        if ($high) $parts[] = $high . ' high';
        if ($medium) $parts[] = $medium . ' to review';
        return implode(', ', $parts);
    }
}
