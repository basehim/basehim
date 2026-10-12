<?php

declare(strict_types=1);

/**
 * A deliberately small test harness: no Composer, no PHPUnit, nothing to
 * install — the same constraint Basehim itself keeps.
 *
 *     test('rejects unknown actions', function () {
 *         assert_same(404, $res->status);
 *     });
 *
 * Integration tests use a real database (a fresh, throwaway one) and drive
 * the real index.php over HTTP through PHP's built-in server, with a cookie
 * jar per "browser". See tests/README.md.
 */

final class AssertionFailed extends \RuntimeException {}

/**
 * Print progress straight to STDOUT. echo would go through PHP's output
 * layer and mark headers as sent, which makes the in-process bootstrap (and
 * any CLI session) complain.
 */
function bh_out(string $format, mixed ...$args): void
{
    fwrite(STDOUT, $args === [] ? $format : vsprintf($format, $args));
}
final class TestSkipped extends \RuntimeException {}

/** @var array<int,array{name:string,fn:callable,group:string}> */
$GLOBALS['__bh_tests'] = [];
$GLOBALS['__bh_group'] = '';

function test(string $name, callable $fn): void
{
    $GLOBALS['__bh_tests'][] = ['name' => $name, 'fn' => $fn, 'group' => $GLOBALS['__bh_group']];
}

function skip(string $reason): never
{
    throw new TestSkipped($reason);
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function export(mixed $v): string
{
    $s = var_export($v, true);
    return strlen($s) > 600 ? substr($s, 0, 600) . '…' : $s;
}

function assert_true(mixed $cond, string $msg = 'Expected true'): void
{
    if ($cond !== true) fail($msg . ' (got ' . export($cond) . ')');
}

function assert_false(mixed $cond, string $msg = 'Expected false'): void
{
    if ($cond !== false) fail($msg . ' (got ' . export($cond) . ')');
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'expected ' . export($expected) . ', got ' . export($actual));
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'expected to find ' . export($needle) . ' in ' . export($haystack));
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'did not expect ' . export($needle) . ' in ' . export($haystack));
    }
}

/** @return \Throwable the caught exception */
function assert_throws(string $class, callable $fn, ?string $messageContains = null): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            fail("Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
        }
        if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
            fail("Exception message " . export($e->getMessage()) . " does not contain " . export($messageContains));
        }
        return $e;
    }
    fail("Expected {$class} to be thrown, nothing was");
}

// ---------------------------------------------------------------------------
// HTTP: one Browser = one cookie jar, like one person's browser.
// ---------------------------------------------------------------------------

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        /** @var array<string,string> lower-cased names */
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function json(): ?array
    {
        $j = json_decode($this->body, true);
        return is_array($j) ? $j : null;
    }

    public function errorCode(): ?string
    {
        return $this->json()['error']['code'] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

final class Browser
{
    /** @var array<string,string> */
    public array $cookies = [];
    public string $csrf = '';

    public function __construct(public readonly string $baseUrl) {}

    /**
     * @param array<string,string> $headers
     */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): HttpResponse
    {
        $ch = curl_init($this->baseUrl . $path);
        $sendHeaders = [];
        foreach ($headers as $k => $v) $sendHeaders[] = $k . ': ' . $v;
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) $pairs[] = $k . '=' . $v;
            $sendHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $sendHeaders,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_PROXY          => '',          // talk to the local server directly
            CURLOPT_NOPROXY        => '*',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name === 'set-cookie') {
                        $this->storeCookie($value);
                    }
                    $respHeaders[$name] = $value;
                }
                return $len;
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new HttpResponse($status, $respHeaders, (string) $raw);
    }

    private function storeCookie(string $header): void
    {
        $first = explode(';', $header, 2)[0];
        [$name, $value] = array_pad(explode('=', $first, 2), 2, '');
        $name = trim($name);
        if ($name === '') return;
        if ($value === '' || $value === 'deleted' || stripos($header, 'expires=Thu, 01 Jan 1970') !== false
            || preg_match('/max-age=0/i', $header)) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    /**
     * Call the AJAX gateway the way Basehim.ajax does: JSON, same-origin
     * fetch metadata, and this browser's CSRF token unless told otherwise.
     *
     * @param array<string,string> $headers extra headers (override defaults)
     */
    public function ajax(string $action, mixed $data = [], array $headers = [], ?string $token = null): HttpResponse
    {
        $defaults = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Origin'         => $this->baseUrl,
            'Sec-Fetch-Site' => 'same-origin',
        ];
        $tok = $token ?? $this->csrf;
        if ($tok !== '') $defaults['X-CSRF-Token'] = $tok;
        $all = array_merge($defaults, $headers);
        $all = array_filter($all, fn($v) => $v !== null);
        $payload = json_encode(['action' => $action, 'data' => $data === [] ? new \stdClass() : $data]);
        return $this->request('POST', '/ajax', $payload, $all);
    }

    public function ajaxGet(string $action, array $query = [], array $headers = []): HttpResponse
    {
        $qs = http_build_query(['action' => $action] + $query);
        return $this->request('GET', '/ajax?' . $qs, null, array_merge(['Accept' => 'application/json', 'Sec-Fetch-Site' => 'same-origin'], $headers));
    }

    /** Sign in through the real form, then pick up the admin page's token. */
    public function login(string $login, string $password): void
    {
        $page = $this->request('GET', '/admin/login');
        if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $page->body, $m)) {
            throw new \RuntimeException('No CSRF field on the login page (status ' . $page->status . ')');
        }
        $loginToken = html_entity_decode($m[1]);
        $res = $this->request('POST', '/admin/login', http_build_query([
            '_csrf' => $loginToken, 'login' => $login, 'password' => $password,
        ]), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $loc = (string) $res->header('location');
        if ($res->status !== 302 || str_contains($loc, '/admin/login')) {
            throw new \RuntimeException("Login as {$login} failed: {$res->status} → {$loc}");
        }
        $this->refreshTokenFromAdmin();
    }

    /**
     * Read window.BASEHIM_AJAX.csrf from an admin page, as the JS client would.
     * The profile page, because every role can open it.
     */
    public function refreshTokenFromAdmin(): void
    {
        $dash = $this->request('GET', '/admin/profile');
        if (!preg_match('/window\.BASEHIM_AJAX = (\{.*?\});/', $dash->body, $m)) {
            throw new \RuntimeException('No BASEHIM_AJAX config on /admin/profile (status ' . $dash->status . ')');
        }
        $cfg = json_decode($m[1], true);
        $this->csrf = (string) ($cfg['csrf'] ?? '');
    }
}
