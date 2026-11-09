<?php

declare(strict_types=1);

namespace App\Services;

/**
 * PackageSignature — checks that a package from the update service was
 * signed by the Basehim publisher (1.2.103).
 *
 * The SHA-256 a site already checked came from the same server as the file,
 * so it only caught damaged downloads: whoever controlled the update server
 * controlled both. A signature is made offline with a private key that never
 * sits on that server (tools/basehim-sign.php), and checked here against the
 * public keys built into core. A server that is taken over can still refuse
 * to serve updates, but it can no longer hand sites code of its own.
 *
 * What is signed binds the bytes to what they claim to be, so a genuine
 * package cannot be passed off as another version or another app:
 *
 *   basehim-package / v1 / kind / slug / version / sha256
 *
 * Extra keys can be trusted with UPDATE_TRUSTED_KEYS in .env (comma
 * separated), for a developer running a hub of their own. Only the site owner
 * can edit .env.
 */
final class PackageSignature
{
    /**
     * Public keys of the Basehim publisher, "bhpk1:" + base64. A key is added
     * here before it signs anything and removed only after every package it
     * signed has been re-signed.
     */
    public const PUBLISHER_KEYS = [
    ];

    public const KINDS = ['core', 'app', 'theme'];

    /** The exact bytes that are signed. tools/basehim-sign.php builds the same. */
    public static function message(string $kind, string $slug, string $version, string $sha256): string
    {
        return "basehim-package\nv1\nkind={$kind}\nslug={$slug}\nversion={$version}\nsha256={$sha256}\n";
    }

    /** Raw 32-byte keys, by key id (first 16 hex of the key's SHA-256). */
    public static function trustedKeys(): array
    {
        $list = self::PUBLISHER_KEYS;
        try {
            $extra = trim((string) (\App\Core\Env::get('UPDATE_TRUSTED_KEYS', '') ?? ''));
            if ($extra !== '') $list = array_merge($list, array_map('trim', explode(',', $extra)));
        } catch (\Throwable) {}
        $out = [];
        foreach ($list as $k) {
            $raw = self::decodeKey((string) $k);
            if ($raw !== null) $out[self::keyId($raw)] = $raw;
        }
        return $out;
    }

    /** True once at least one key is trusted: from then on packages must be signed. */
    public static function required(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached') && self::trustedKeys() !== [];
    }

    public static function keyId(string $rawPublic): string
    {
        return substr(hash('sha256', $rawPublic), 0, 16);
    }

    public static function decodeKey(string $k): ?string
    {
        if (!preg_match('/^bhpk1:([A-Za-z0-9+\/]+={0,2})$/', trim($k), $m)) return null;
        $raw = base64_decode($m[1], true);
        return is_string($raw) && strlen($raw) === 32 ? $raw : null;
    }

    /** A signature string as the hub sends it, or '' when it is not one. */
    public static function clean(mixed $sig): string
    {
        $sig = is_string($sig) ? trim($sig) : '';
        return preg_match('/^bhsig1:[0-9a-f]{16}:[A-Za-z0-9+\/]{86}==$/', $sig) ? $sig : '';
    }

    /**
     * Check a package. Returns null when it is acceptable, or the reason it
     * is not, in words a site owner can act on.
     *
     * When no key is trusted (a build made before the publisher key existed)
     * there is nothing to check against and null is returned; the SHA-256
     * check still applies.
     */
    public static function check(string $file, string $kind, string $slug, string $version, string $signature): ?string
    {
        if (!self::required()) return null;
        if (!in_array($kind, self::KINDS, true)) return 'Unknown package type.';
        $signature = self::clean($signature);
        if ($signature === '') {
            return 'The package is not signed by the Basehim publisher, so it was not installed.';
        }
        [, $keyId, $b64] = explode(':', $signature, 3);
        $keys = self::trustedKeys();
        if (!isset($keys[$keyId])) {
            return 'The package is signed with a key this site does not trust, so it was not installed.';
        }
        $raw = base64_decode($b64, true);
        $sha = @hash_file('sha256', $file);
        if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || !is_string($sha)
            || !sodium_crypto_sign_verify_detached($raw, self::message($kind, $slug, $version, $sha), $keys[$keyId])) {
            return 'The package signature does not match the file, so it was not installed.';
        }
        return null;
    }
}
