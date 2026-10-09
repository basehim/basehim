<?php

declare(strict_types=1);

namespace App\Core;

/**
 * TLS settings for outgoing HTTPS from core: updates, the app and theme
 * marketplaces, and package downloads.
 *
 * Certificates are verified. Until 1.2.43 these requests ran with
 * CURLOPT_SSL_VERIFYPEER off. Whoever could intercept a site's traffic could
 * then answer for the update hub, and the SHA-256 check was no help, because
 * the hash came over the same connection. They could serve any zip with a
 * matching hash, and the updater would extract it over the site's code.
 *
 * Shared hosts sometimes ship PHP whose curl has no working CA file
 * configured. On a certificate-store error, exec() retries once with each CA
 * bundle found in the usual system locations before giving up. As a last
 * resort, a site owner can set HTTP_TLS_VERIFY=false in .env. Only the owner
 * can edit .env, and it should be temporary.
 */
final class Tls
{
    /** Well-known CA bundle locations (Debian/Ubuntu, RHEL/CentOS/cPanel, Alpine, BSD, macOS). */
    private const BUNDLES = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
        '/etc/ssl/ca-bundle.pem',
        '/etc/ssl/cert.pem',
        '/usr/local/share/certs/ca-root-nss.crt',
        '/usr/local/etc/openssl/cert.pem',
        '/opt/cpanel/ea-openssl11/etc/pki/tls/certs/ca-bundle.crt',
    ];

    public static function verifyEnabled(): bool
    {
        try {
            return Env::get('HTTP_TLS_VERIFY', true) !== false;
        } catch (\Throwable) {
            return true;
        }
    }

    /** curl options to merge into a handle's setup. */
    public static function curlOptions(): array
    {
        if (!self::verifyEnabled()) {
            return [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        }
        return [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    }

    /**
     * curl_exec() with a retry against the system CA bundles when the
     * configured CA store is missing or can't vouch for the certificate.
     *
     * Errors that aren't about the local CA store (wrong hostname, expired or
     * self-signed certificate) are left alone; a retry would not change them.
     */
    public static function exec(\CurlHandle $ch): string|bool
    {
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        // 77 = CURLE_SSL_CACERT_BADFILE, 60 = CURLE_PEER_FAILED_VERIFICATION
        // (also raised for "unable to get local issuer certificate", which is
        // what a missing or stale CA store looks like).
        if (!self::verifyEnabled() || ($errno !== 77 && !($errno === 60 && self::looksLikeMissingIssuer($ch)))) {
            return $out;
        }
        foreach (self::BUNDLES as $bundle) {
            if (!@is_readable($bundle)) continue;
            curl_setopt($ch, CURLOPT_CAINFO, $bundle);
            $out = curl_exec($ch);
            $errno = curl_errno($ch);
            if ($errno !== 77 && $errno !== 60) return $out;
        }
        return $out;
    }

    private static function looksLikeMissingIssuer(\CurlHandle $ch): bool
    {
        $msg = strtolower(curl_error($ch));
        return str_contains($msg, 'local issuer') || str_contains($msg, 'unable to get issuer')
            || str_contains($msg, 'ca cert') || str_contains($msg, 'certificate store');
    }
}
