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

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerEnc, $payloadEnc, $sigEnc] = $parts;

        $header = json_decode(self::base64UrlDecode($headerEnc), true);
        $payload = json_decode(self::base64UrlDecode($payloadEnc), true);
        $providedSig = self::base64UrlDecode($sigEnc);

        if (!is_array($header) || !is_array($payload) || !isset($header['alg'])) {
            return null;
        }

        $expected = self::sign("{$headerEnc}.{$payloadEnc}", $secret, $header['alg']);
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
