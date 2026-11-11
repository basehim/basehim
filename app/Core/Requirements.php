<?php
declare(strict_types=1);

namespace App\Core;

/**
 * What Basehim needs from the server (1.2.124): one list, read by the
 * installer's server check and by System → Health.
 *
 * The two kept separate lists before, and they disagreed: the installer
 * required openssl and fileinfo while Health called them recommended, and
 * neither checked zip, which updates and theme and app installs need.
 *
 * Required items stop the installer and show red in Health; recommended ones
 * show amber and say what stops working without them.
 *
 * json isn't listed: it has been part of PHP itself since 8.0 and can't be
 * turned off.
 */
final class Requirements
{
    /** The lowest PHP version Basehim runs on. */
    public const PHP_MIN = '8.1.0';

    /** The versions to choose when there's a choice. */
    public const PHP_RECOMMENDED = '8.2 or 8.3';

    /**
     * extension => [label, required, what it is for]. The purpose is plain
     * text; each screen formats it.
     */
    public const EXTENSIONS = [
        'pdo_mysql' => ['PDO MySQL', true,  'Connects to the MySQL or MariaDB database.'],
        'mbstring'  => ['mbstring',  true,  'Handles text in every language.'],
        'openssl'   => ['OpenSSL',   true,  'Makes secure connections: updates, the marketplace and email over TLS.'],
        'fileinfo'  => ['fileinfo',  true,  'Checks the type of uploaded files.'],
        'gd'        => ['GD (image sizes)', false, 'Makes the smaller image sizes. Without it, images upload at full size only.'],
        'curl'      => ['cURL',      false, 'Makes updates, the marketplace and apps that call other sites more reliable.'],
        'zip'       => ['Zip (updates and installs)', false, 'Needed to install updates, themes and apps from the dashboard. Without it, upload them by FTP.'],
    ];

    /** Whether this PHP is new enough. */
    public static function phpOk(?string $version = null): bool
    {
        return version_compare($version ?? PHP_VERSION, self::PHP_MIN, '>=');
    }

    /** The minimum as people write it: "8.1". */
    public static function phpMinLabel(): string
    {
        return implode('.', array_slice(explode('.', self::PHP_MIN), 0, 2));
    }

    /**
     * Each extension with whether it is loaded.
     *
     * @param callable(string):bool|null $loaded for tests; extension_loaded by default
     * @return list<array{extension:string,label:string,required:bool,purpose:string,loaded:bool}>
     */
    public static function extensions(?callable $loaded = null): array
    {
        $loaded ??= 'extension_loaded';
        $out = [];
        foreach (self::EXTENSIONS as $ext => [$label, $required, $purpose]) {
            $out[] = [
                'extension' => $ext,
                'label'     => $label,
                'required'  => $required,
                'purpose'   => $purpose,
                'loaded'    => (bool) $loaded($ext),
            ];
        }
        return $out;
    }

    /** @return string[] the extensions the installer insists on */
    public static function requiredExtensions(): array
    {
        return array_keys(array_filter(self::EXTENSIONS, static fn(array $e): bool => $e[1]));
    }
}
