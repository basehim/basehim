<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autoloader
 *
 * A minimal PSR-4-ish autoloader. Maps namespaces to directories so we
 * don't depend on Composer (cPanel sometimes makes Composer awkward).
 */
final class Autoloader
{
    private static array $prefixes = [
        'App\\' => '/app/',
    ];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function addNamespace(string $prefix, string $relativePath): void
    {
        self::$prefixes[rtrim($prefix, '\\') . '\\'] = '/' . trim($relativePath, '/') . '/';
    }

    public static function load(string $class): bool
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                continue;
            }

            $relativeClass = substr($class, $len);
            $file = BASEHIM_ROOT . $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file)) {
                require $file;
                return true;
            }
        }
        return false;
    }
}
