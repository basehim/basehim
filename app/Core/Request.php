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
    /**
     * The AJAX gateway's path, relative to the install base. capture() does
     * not decode this path's body: the gateway reads it itself, after checking
     * its size, so an oversized request is refused before any JSON is parsed.
     */
    public const AJAX_PATH = '/ajax';

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
        $gateway = self::isAjaxGatewayPath($uri);
        if ($gateway && $method === 'POST' && stripos($contentType, 'multipart/form-data') === 0) {
            // Uploads to the AJAX gateway (1.2.87): PHP has parsed the form;
            // the gateway reads its "action" and "data" fields from here.
            // JSON to the gateway is still read from php://input by the gateway.
            $body = $_POST;
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$gateway) {
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

    private static function isAjaxGatewayPath(string $uri): bool
    {
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        return rtrim($uri, '/') === self::AJAX_PATH;
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

    /**
     * Cloudflare's published ranges (https://www.cloudflare.com/ips/). A
     * request from one of these came through Cloudflare, which sets
     * CF-Connecting-IP and CF-Visitor itself, overwriting anything the client
     * sent, so those two headers are believed from these addresses (1.2.90).
     * X-Forwarded-For is not: a Cloudflare Worker can fetch from these ranges
     * with any X-Forwarded-For it likes.
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Proxies whose X-Forwarded-* headers are believed: TRUSTED_PROXIES in
     * .env (IPs or CIDR ranges, comma-separated). Empty by default, as the
     * AJAX gateway has always had it; one list for all of core since 1.2.90.
     *
     * @return string[]
     */
    public static function trustedProxies(): array
    {
        try {
            $raw = (string) (Env::get('TRUSTED_PROXIES', '') ?? '');
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== ''));
    }

    /** True when the connecting address is in TRUSTED_PROXIES. */
    public static function fromTrustedProxy(array $server): bool
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $trusted = self::trustedProxies();
        return $trusted !== [] && filter_var($remote, FILTER_VALIDATE_IP) !== false && self::ipInAny($remote, $trusted);
    }

    /** True when the connecting address is one of Cloudflare's. */
    public static function fromCloudflare(array $server): bool
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) !== false && self::ipInAny($remote, self::CLOUDFLARE);
    }

    /**
     * Was this request made over HTTPS? Directly (HTTPS / port 443); through
     * a trusted proxy that says so (X-Forwarded-Proto, X-Forwarded-SSL);
     * through Cloudflare (CF-Visitor); or, for a CDN that isn't listed, to
     * the host of an https:// APP_URL. Before 1.2.90 seven places believed
     * X-Forwarded-Proto from any visitor, who could flip the scheme of
     * absolute URLs (and of cached feeds and sitemaps).
     *
     * @param array|null $server $_SERVER-shaped; defaults to $_SERVER
     */
    public static function serverIsSecure(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        $https = strtolower((string) ($s['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') return true;
        if ((string) ($s['SERVER_PORT'] ?? '') === '443') return true;
        if (self::fromTrustedProxy($s)) {
            $proto = strtolower(trim(explode(',', (string) ($s['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($proto === 'https' || strtolower((string) ($s['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
        }
        if (self::fromCloudflare($s) && str_contains(strtolower((string) ($s['HTTP_CF_VISITOR'] ?? '')), '"scheme":"https"')) {
            return true;
        }
        // Nothing a visitor sends can change this answer either way.
        return self::appUrlIsHttpsFor((string) ($s['HTTP_HOST'] ?? ''));
    }

    private static function appUrlIsHttpsFor(string $host): bool
    {
        try {
            $app = (string) (Env::get('APP_URL', '') ?? '');
        } catch (\Throwable) {
            return false;
        }
        $p = parse_url($app);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') return false;
        $want = strtolower((string) ($p['host'] ?? ''));
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        return $want !== '' && $host === $want;
    }

    public function isSecure(): bool
    {
        return self::serverIsSecure($this->server);
    }

    /**
     * The visitor's address for rate limits and anything else that must not
     * be spoofable: X-Forwarded-For only from TRUSTED_PROXIES, and
     * CF-Connecting-IP only on requests that come from Cloudflare.
     */
    public function visitorIp(): string
    {
        if (self::fromCloudflare($this->server)) {
            $cf = trim((string) ($this->server['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($cf, FILTER_VALIDATE_IP) !== false) return $cf;
        }
        return $this->clientIp(self::trustedProxies());
    }

    /**
     * The first address any forwarding header names, believed from anyone.
     * For display only: a visitor can put any address here. Rate limits and
     * other checks use visitorIp().
     */
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

    /**
     * The client's address, believing X-Forwarded-For only from a trusted proxy.
     *
     * ip() above takes the first entry of X-Forwarded-For or CF-Connecting-IP
     * from anyone, so a client can name any address it likes — fine for
     * display, useless for a rate limit. Here the connecting address is the
     * client unless it is one of $trustedProxies; then X-Forwarded-For is
     * read from the right (the end our proxy appended), skipping further
     * trusted hops, and the first address that is not a trusted proxy is the
     * client. Anything malformed falls back to the connecting address.
     *
     * @param string|string[] $trustedProxies IPs or CIDR ranges, list or comma-separated
     */
    public function clientIp(string|array $trustedProxies = []): string
    {
        $remote = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) return '0.0.0.0';

        $trusted = is_array($trustedProxies) ? $trustedProxies : explode(',', $trustedProxies);
        $trusted = array_values(array_filter(array_map('trim', $trusted), fn($t) => $t !== ''));
        if ($trusted === [] || !self::ipInAny($remote, $trusted)) return $remote;

        $xff = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') return $remote;
        $hops = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) return $remote;
            if (!self::ipInAny($hop, $trusted)) return $hop;
        }
        return $remote;
    }

    /** @param string[] $ranges IPs or CIDR ranges */
    public static function ipInAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::ipMatches($ip, $range)) return true;
        }
        return false;
    }

    /** True when $ip equals $range, or falls inside it when $range is CIDR (IPv4 or IPv6). */
    public static function ipMatches(string $ip, string $range): bool
    {
        $range = trim($range);
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
        $max = strlen($ipBin) * 8;
        if ($bits === null) return $ipBin === $netBin;
        if (!ctype_digit($bits) || (int) $bits > $max) return false;
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
        $rem = $bits % 8;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
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
