<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Request;

/**
 * Webhook — verify that an incoming webhook really comes from its sender
 * (1.2.75, CR-28).
 *
 * A payment provider, a form service or a Git host calls a public route of
 * an app ('csrf' => false). Anyone can call that URL, so the request must
 * prove it was signed with the shared secret before it is believed, and an
 * old signed request replayed later must be refused.
 *
 *     $site->post('/shop/webhooks/pay', [$this, 'paid'], ['csrf' => false, 'rate' => [120, 60]]);
 *
 *     public function paid(Request $request): Response
 *     {
 *         $body = Webhook::payload();
 *         if (!Webhook::verifyTimestamped($body, (string) $request->header('pay-signature'), $this->secret())) {
 *             return Response::json(['error' => 'bad signature'], 400);
 *         }
 *         $event = json_decode($body, true);
 *         // At most once per event, however often the provider retries:
 *         $this->api()->idempotency()->once('pay', (string) $event['id'], fn() => $this->markPaid($event));
 *         return Response::json(['ok' => true]);
 *     }
 *
 * Comparisons are constant-time. Nothing here reads a secret from anywhere:
 * keep it in .env or the app's settings, never in code.
 */
final class Webhook
{
    /** The raw request body, exactly as sent (signatures cover these bytes). */
    public static function payload(): string
    {
        return (string) @file_get_contents('php://input');
    }

    /**
     * A plain HMAC of the body: GitHub (X-Hub-Signature-256: sha256=…), and
     * most providers that send hex or base64.
     *
     * @param string $signature the header's value; a "sha256=" style prefix is ignored
     */
    public static function verifyHmac(string $payload, string $signature, string $secret, string $algo = 'sha256'): bool
    {
        if ($secret === '' || $signature === '' || !in_array($algo, hash_hmac_algos(), true)) return false;
        $signature = trim($signature);
        // "sha256=…" (GitHub) and similar: an algorithm name, then "=".
        if (preg_match('/^(?:sha1|sha224|sha256|sha384|sha512|md5)=/i', $signature)) {
            $signature = substr($signature, strpos($signature, '=') + 1);
        }
        $raw = hash_hmac($algo, $payload, $secret, true);
        return hash_equals(bin2hex($raw), strtolower($signature))
            || hash_equals(base64_encode($raw), $signature);
    }

    /**
     * A signature that covers a timestamp, so a captured request cannot be
     * replayed later: the header "t=1700000000,v1=<hex>" (Stripe's format,
     * used by several others), signed over "{t}.{body}". Refused when the
     * timestamp is more than $tolerance seconds from now. Several v1 values
     * (during a secret rotation) are all tried.
     */
    public static function verifyTimestamped(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') return false;
        $now ??= time();
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) $t = (int) $v;
            if ($k === 'v1' && $v !== '') $sigs[] = strtolower($v);
        }
        if ($t === null || !$sigs || abs($now - $t) > max(1, $tolerance)) return false;
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) return true;
        }
        return false;
    }

    /** The header value from a request, case-insensitively, or ''. */
    public static function header(Request $request, string $name): string
    {
        return trim((string) ($request->header(strtolower($name)) ?? ''));
    }
}
