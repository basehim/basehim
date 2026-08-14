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

        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => 'Basehim-App/' . $this->slug
                                      . ' (' . (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') . ')',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($error !== null) {
            $this->log("HTTP {$method} {$url} failed: {$error}", [], 'warning');
            return $fail($error);
        }

        $responseHeaders = [];
        $responseBody = '';
        if (is_string($raw)) {
            foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
            }
            $responseBody = substr($raw, $headerSize);
        }

        return [
            'ok'      => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => $responseBody,
            'headers' => $responseHeaders,
            'error'   => null,
        ];
    }
}
