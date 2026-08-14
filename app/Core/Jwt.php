<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jwt
 *
 * Minimal HS256 JWT encoder/decoder. The spec uses Firebase JWT (RS256);
 * for shared hosting we use HS256 with a secret — no openssl key
 * management headaches.
 */
final class Jwt
{
    public static function encode(array $payload, string $secret, string $alg = 'HS256'): string
    {
        $header = ['typ' => 'JWT', 'alg' => $alg];
        $headerEnc = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEnc = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = self::sign("{$headerEnc}.{$payloadEnc}", $secret, $alg);
        $sigEnc = self::base64UrlEncode($signature);
        return "{$headerEnc}.{$payloadEnc}.{$sigEnc}";
    }

    /**
     * Verify and decode a token.
     *
     * $expectedAlg is pinned by the CALLER, never read from the token. Reading
     * it from the header is the classic algorithm-confusion shape: harmless
     * here while every supported algorithm is HMAC with the same secret, but it
     * also meant an unsupported `alg` threw an uncaught RuntimeException out of
     * the auth middleware — a 500 with the attacker's string in it — and it is
     * one refactor away from being a real forgery bug.
     */
    public static function decode(string $token, string $secret, string $expectedAlg = 'HS256'): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerEnc, $payloadEnc, $sigEnc] = $parts;

        $header = json_decode(self::base64UrlDecode($headerEnc), true);
        $payload = json_decode(self::base64UrlDecode($payloadEnc), true);
        $providedSig = self::base64UrlDecode($sigEnc);

        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        // Reject rather than adapt: a token whose alg is not the one we issue
        // is not our token.
        if (($header['alg'] ?? '') !== $expectedAlg) {
            return null;
        }

        try {
            $expected = self::sign("{$headerEnc}.{$payloadEnc}", $secret, $expectedAlg);
        } catch (\RuntimeException) {
            return null;
        }
        if (!hash_equals($expected, $providedSig)) {
            return null;
        }

        if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
            return null;
        }
        if (isset($payload['nbf']) && (int)$payload['nbf'] > time()) {
            return null;
        }

        return $payload;
    }

    private static function sign(string $data, string $secret, string $alg): string
    {
        return match ($alg) {
            'HS256' => hash_hmac('sha256', $data, $secret, true),
            'HS384' => hash_hmac('sha384', $data, $secret, true),
            'HS512' => hash_hmac('sha512', $data, $secret, true),
            default => throw new \RuntimeException("Unsupported JWT algorithm: {$alg}"),
        };
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
