<?php

declare(strict_types=1);

use App\Core\Jwt;
use App\Services\AuthService;

/*
 * 1.2.51: junk bearer tokens are rejected (401), not a 500; tokens without
 * exp are not ours; and a published placeholder JWT_SECRET is never used to
 * sign or verify.
 */

function jwt_raw(array $header, array $payload, string $sig = 'x'): string
{
    $b = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    return $b((string) json_encode($header)) . '.' . $b((string) json_encode($payload)) . '.' . $b($sig);
}

test('jwt: unknown algorithms and malformed headers are rejected, not thrown', function () {
    $p = ['sub' => 1, 'exp' => time() + 60];
    assert_same(null, Jwt::decode(jwt_raw(['alg' => 'none'], $p), 'k'));
    assert_same(null, Jwt::decode(jwt_raw(['alg' => ['HS256']], $p), 'k'));
    assert_same(null, Jwt::decode(jwt_raw(['alg' => 'RS256'], $p), 'k'));
    assert_same(null, Jwt::decode('a.b', 'k'));
});

test('jwt: a valid token decodes; one without exp, expired or re-signed does not', function () {
    $key = str_repeat('k', 40);
    $ok = Jwt::encode(['sub' => 7, 'exp' => time() + 60], $key);
    assert_same(7, Jwt::decode($ok, $key)['sub'] ?? null);
    assert_same(null, Jwt::decode($ok, $key . 'x'), 'other key');
    assert_same(null, Jwt::decode(Jwt::encode(['sub' => 7], $key), $key), 'no exp');
    assert_same(null, Jwt::decode(Jwt::encode(['sub' => 7, 'exp' => time() - 1], $key), $key), 'expired');
});

test('jwt: placeholder or short secrets are replaced by a per-site key', function () {
    foreach (['', 'short', 'change-me-please-this-must-be-a-long-random-string',
              'please-change-this-to-a-long-random-string-at-least-32-chars'] as $weak) {
        $used = AuthService::jwtSecretFrom($weak);
        assert_true($used !== $weak && strlen($used) === 64, 'weak secret used as is: ' . json_encode($weak));
    }
    $real = bin2hex(random_bytes(32));
    assert_same($real, AuthService::jwtSecretFrom($real));
});
