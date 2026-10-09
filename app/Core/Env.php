<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Env
 *
 * Simple .env loader. No vlucas/phpdotenv dependency.
 */
final class Env
{
    private static array $data = [];
    /** Keys whose value was quoted in .env: kept as strings, never cast. */
    private static array $quoted = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded || !is_file($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // A later unquoted line for the same key wins, cast as usual.
            unset(self::$quoted[$key]);

            // Strip surrounding quotes
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = substr($value, -1);
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                    self::$quoted[$key] = true;
                }
            }

            self::$data[$key] = $value;
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$data)) {
            $v = self::$data[$key];
            // A quoted value is literal text. DB_PASSWORD="null" is the
            // password "null"; it used to become PHP null (and "true" became
            // true), so the database connection failed. Fixed in 1.2.43.
            if (isset(self::$quoted[$key])) return $v;
            // Cast common literals
            return match (strtolower((string)$v)) {
                'true', '(true)'   => true,
                'false', '(false)' => false,
                'null', '(null)'   => null,
                'empty', '(empty)' => '',
                default            => $v,
            };
        }
        return $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
