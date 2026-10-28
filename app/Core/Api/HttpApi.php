<?php

declare(strict_types=1);

namespace App\Core\Api;

/**
 * HttpApi — outbound HTTP for apps.
 *
 * Apps otherwise reach for file_get_contents() on a URL, which on shared
 * hosting either has allow_url_fopen disabled or blocks with no timeout until
 * the request dies. This wraps curl with defaults that fail fast.
 *
 * Every method returns a predictable array — never throws, never emits a
 * warning — so a remote service being down degrades the app rather than the
 * page:
 *
 *     ['ok' => bool, 'status' => int, 'body' => string,
 *      'headers' => array, 'error' => ?string]
 */
class HttpApi extends Resource
{
    /** Every outbound call needs the same permission. */
    protected function permissionFor(string $operation): ?string
    {
        return 'http.outbound';
    }

    private int $timeout = 15;
    private array $headers = [];
    private bool $allowPrivate = false;
    private int $maxBytes = 20971520;

    /**
     * Allow private, loopback and other non-public addresses for requests
     * made with this instance: for an app that talks to a service on the
     * server's own network on purpose. Never for a URL a visitor typed.
     * (1.2.90; .env HTTP_ALLOW_PRIVATE allows named hosts site-wide.)
     */
    public function allowPrivateNetwork(bool $allow = true): self
    {
        $this->allowPrivate = $allow;
        return $this;
    }

    /** The largest response accepted, in bytes (default 20 MB, 1 KB – 512 MB). */
    public function maxBytes(int $bytes): self
    {
        $this->maxBytes = max(1024, min(536870912, $bytes));
        return $this;
    }

    /** Set the timeout in seconds for subsequent calls (1-120). */
    public function timeout(int $seconds): self
    {
        $this->timeout = max(1, min(120, $seconds));
        return $this;
    }

    /** Merge headers into subsequent calls. ['Accept' => 'application/json'] */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
        return $this;
    }

    /** Shorthand for a bearer token. */
    public function withToken(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function get(string $url, array $query = []): array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, null);
    }

    /** POST a JSON body. */
    public function post(string $url, array $data = []): array
    {
        return $this->request('POST', $url, json_encode($data, JSON_UNESCAPED_SLASHES), 'application/json');
    }

    /** POST a form-encoded body. */
    public function postForm(string $url, array $data = []): array
    {
        return $this->request('POST', $url, http_build_query($data), 'application/x-www-form-urlencoded');
    }

    public function put(string $url, array $data = []): array
    {
        return $this->request('PUT', $url, json_encode($data, JSON_UNESCAPED_SLASHES), 'application/json');
    }

    public function delete(string $url): array
    {
        return $this->request('DELETE', $url, null);
    }

    /**
     * GET and decode JSON in one step. Returns null when the request failed or
     * the body was not valid JSON — the overwhelmingly common case for apps
     * talking to an API, without the boilerplate.
     */
    public function getJson(string $url, array $query = []): ?array
    {
        $res = $this->withHeaders(['Accept' => 'application/json'])->get($url, $query);
        if (!$res['ok']) return null;
        $json = json_decode($res['body'], true);
        return is_array($json) ? $json : null;
    }

    /** POST JSON and decode the JSON response. */
    public function postJson(string $url, array $data = []): ?array
    {
        $res = $this->withHeaders(['Accept' => 'application/json'])->post($url, $data);
        if (!$res['ok']) return null;
        $json = json_decode($res['body'], true);
        return is_array($json) ? $json : null;
    }

    private function request(string $method, string $url, ?string $body, ?string $contentType = null): array
    {
        $fail = fn(string $why): array => [
            'ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => $why,
        ];

        // HttpApi builds its own curl handle rather than routing through
        // attempt(), so the gate is explicit here.
        if (!$this->permitted('request')) {
            return $fail("The 'http.outbound' permission is required to make network requests.");
        }
        if (!preg_match('#^https?://#i', $url)) {
            return $fail('Only http:// and https:// URLs are allowed.');
        }
        if (!function_exists('curl_init')) {
            return $fail('The curl extension is not available on this server.');
        }

        /*
         * Redirects are followed here, not by curl (1.2.90): every hop is
         * checked by OutboundGuard (public addresses only, unless allowed)
         * and connected to the address that was checked, so neither a
         * redirect nor a DNS change can lead the request into the server's
         * own network. Authorization is not sent to another host.
         */
        $guard = new \App\Core\Net\OutboundGuard($this->allowPrivate);
        $origHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        for ($hop = 0; ; $hop++) {
            try {
                $target = $guard->check($url);
            } catch (\RuntimeException $e) {
                $this->log("HTTP {$method} refused: " . $e->getMessage(), [], 'warning');
                return $fail($e->getMessage());
            }
            $sameHost = $target['host'] === $origHost;
            $res = $this->once($method, $url, $body, $contentType, $target, $sameHost);
            if ($res['error'] !== null || !in_array($res['status'], [301, 302, 303, 307, 308], true)) {
                return $res;
            }
            $location = (string) ($res['headers']['location'] ?? '');
            if ($location === '') return $res;
            if ($hop >= 5) return $fail('Too many redirects (more than 5).');
            $url = self::resolveUrl($url, $location);
            if ($url === null) return $fail('The server redirected to an address that is not valid.');
            // 301/302/303 turn a POST into a GET, as browsers and curl do; 307/308 keep it.
            if (in_array($res['status'], [301, 302, 303], true) && $method !== 'GET' && $method !== 'HEAD') {
                $method = 'GET';
                $body = null;
                $contentType = null;
            }
        }
    }

    /** One request to a checked target; no redirects followed. */
    private function once(string $method, string $url, ?string $body, ?string $contentType, array $target, bool $sameHost): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            if (!$sameHost && strcasecmp($name, 'Authorization') === 0) continue;
            $headers[] = $name . ': ' . $value;
        }
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $max = $this->maxBytes;
        $received = 0;
        $tooBig = false;
        $raw = '';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => 'Basehim-App/' . $this->slug
                                      . ' (' . (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') . ')',
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$raw, &$received, &$tooBig, $max): int {
                $received += strlen($chunk);
                if ($received > $max) {
                    $tooBig = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ] + (class_exists(\App\Core\Tls::class) ? \App\Core\Tls::curlOptions() : []);
        if ($target['ip'] !== null) {
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $opts[CURLOPT_RESOLVE] = [trim($target['host'], '[]') . ':' . $target['port'] . ':' . $ip];
        }
        curl_setopt_array($ch, $opts);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        class_exists(\App\Core\Tls::class) ? \App\Core\Tls::exec($ch) : curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $primary = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        if ($tooBig) {
            $error = 'The response is larger than ' . $this->maxBytes . ' bytes (see maxBytes()).';
        }
        if ($error === null && $target['ip'] !== null && $primary !== '' && @inet_pton($primary) !== @inet_pton($target['ip'])) {
            $error = 'The connection did not go to the checked address.';
        }
        if ($error !== null) {
            $this->log("HTTP {$method} {$url} failed: {$error}", [], 'warning');
            return ['ok' => false, 'status' => $status, 'body' => '', 'headers' => [], 'error' => $error];
        }

        $responseHeaders = [];
        // With 1xx interim responses the header block holds several; keep the last.
        $block = substr($raw, 0, $headerSize);
        $parts = preg_split("/\r\n\r\n(?=HTTP\/)/", rtrim($block));
        foreach (explode("\r\n", (string) end($parts)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return [
            'ok'      => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => (string) substr($raw, $headerSize),
            'headers' => $responseHeaders,
            'error'   => null,
        ];
    }

    /** Resolve a Location header against the URL it came from. */
    private static function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1f\x7f]/', $location)) return null;
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return preg_match('#^https?://#i', $location) ? $location : null;
        }
        $b = parse_url($base);
        if (!is_array($b) || empty($b['scheme']) || empty($b['host'])) return null;
        $origin = $b['scheme'] . '://' . (str_contains($b['host'], ':') && $b['host'][0] !== '[' ? '[' . $b['host'] . ']' : $b['host'])
            . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) return $b['scheme'] . ':' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        $path = (string) ($b['path'] ?? '/');
        if (str_starts_with($location, '?')) return $origin . $path . $location;
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        $segments = [];
        foreach (explode('/', $dir . $location) as $seg) {
            if ($seg === '..') { if (count($segments) > 1) array_pop($segments); }
            elseif ($seg !== '.') $segments[] = $seg;
        }
        return $origin . implode('/', $segments);
    }
}
