<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request
 *
 * PSR-7-inspired request object. We don't pull in psr/http-message;
 * this is a minimal but practical replacement.
 */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $cookies,
        public readonly array $server,
        public readonly array $headers,
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (str_contains($contentType, 'application/json')) {
                $raw = file_get_contents('php://input') ?: '';
                $decoded = json_decode($raw, true);
                $body = is_array($decoded) ? $decoded : [];
            } else {
                $body = $_POST;
                if (empty($body) && $method !== 'POST') {
                    $raw = file_get_contents('php://input') ?: '';
                    parse_str($raw, $body);
                }
            }
        }

        return new self(
            method:  $method,
            uri:     $uri,
            query:   $_GET,
            body:    $body,
            files:   $_FILES,
            cookies: $_COOKIE,
            server:  $_SERVER,
            headers: self::collectHeaders(),
        );
    }

    private static function collectHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        } else {
            foreach ($_SERVER as $k => $v) {
                if (str_starts_with($k, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($k, 5)));
                    $headers[$name] = $v;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE']))   $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
            if (isset($_SERVER['CONTENT_LENGTH'])) $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        // The Authorization header is frequently stripped by Apache/cPanel under
        // mod_rewrite, so getallheaders() may omit it. Recover it from the other
        // places PHP exposes it. Without this, bearer-token auth fails and
        // command polls return 401 — i.e. remote commands silently never run.
        if (empty($headers['authorization'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION']
                 ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                 ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']
                 ?? null;
            if (!$auth && function_exists('apache_request_headers')) {
                $apache = array_change_key_case(apache_request_headers() ?: [], CASE_LOWER);
                if (!empty($apache['authorization'])) $auth = $apache['authorization'];
            }
            if ($auth) $headers['authorization'] = $auth;
        }
        return $headers;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && stripos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
        return null;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', '') ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '') ?? '';
        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }

    public function ip(): string
    {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (!empty($this->server[$key])) {
                $ip = explode(',', $this->server[$key])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    public function path(): string
    {
        return $this->uri;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        $result = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                $result[$k] = $all[$k];
            }
        }
        return $result;
    }

    public function integer(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        if (is_bool($v)) return $v;
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }
}
