<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Updates one simple value in .env without touching anything else (1.2.66).
 *
 * .env also holds the database password and the signing key, so this is kept
 * deliberately narrow:
 *
 *  - only an UPPER_SNAKE key, and only a value that needs no quoting (no
 *    spaces, quotes, #, $, backslashes, backticks or line breaks), so a value
 *    can never add a line or change how the file is read;
 *  - every existing line for the key is rewritten in place (Env::load lets
 *    the last one win, so they must agree), or one line is appended;
 *  - every other line, comment and blank line is kept byte for byte;
 *  - the new file is written beside the old one and renamed over it, keeping
 *    its permissions, so a reader never sees half a file. Where the folder
 *    is not writable but the file is, it is rewritten in place under a lock.
 *
 * Returns false (and changes nothing) when the file is missing, unreadable or
 * not writable, or the key or value is not allowed. Callers report that; the
 * site keeps working, since these values are defaults.
 */
final class EnvFile
{
    public static function set(string $path, string $key, string $value): bool
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key)) return false;
        if ($value === '' || strlen($value) > 254 || !preg_match('/^[^\s"\'#$\\\\`]+$/', $value)) return false;

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) return false;
        $old = file_get_contents($real);
        if ($old === false) return false;

        $eol = str_contains($old, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r?\n/', $old);
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*(?:export\s+)?)' . $key . '(\s*)=/', $line, $m)) {
                $lines[$i] = $m[1] . $key . '=' . $value;
                $found = true;
            }
        }
        if (!$found) {
            // Keep the file's final newline; add the key before it.
            if (end($lines) === '') array_pop($lines);
            $lines[] = $key . '=' . $value;
            $lines[] = '';
        }
        $new = implode($eol, $lines);
        if ($new === $old) {
            Env::set($key, $value);
            return true;
        }

        $dir = dirname($real);
        $perms = @fileperms($real);
        $ok = false;
        if (is_writable($dir)) {
            $tmp = $dir . '/.env.tmp-' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, $new, LOCK_EX) === strlen($new)) {
                if ($perms !== false) @chmod($tmp, $perms & 0777);
                $ok = @rename($tmp, $real);
            }
            if (!$ok) @unlink($tmp);
        }
        if (!$ok && is_writable($real)) {
            $ok = @file_put_contents($real, $new, LOCK_EX) === strlen($new);
        }
        if ($ok) {
            Env::set($key, $value);
            $_ENV[$key] = $value;
        }
        return $ok;
    }
}
