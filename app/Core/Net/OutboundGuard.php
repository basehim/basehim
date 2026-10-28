<?php

declare(strict_types=1);

namespace App\Core\Net;

use App\Core\Env;

/**
 * Checks a URL before core connects to it on an app's behalf (1.2.90).
 *
 * An app that fetches an address someone typed — a link preview, an import,
 * a webhook tester — must not become a way into the server's own network.
 * check() resolves the host once, refuses unless every address is public
 * (AddressPolicy), and returns the address to connect to, so the caller can
 * pin it (CURLOPT_RESOLVE) and DNS cannot be switched between the check and
 * the connection.
 *
 * Exceptions for intranet services: HTTP_ALLOW_PRIVATE in .env (host names,
 * IPs or CIDR ranges, comma-separated), or an app calling
 * api()->http()->allowPrivateNetwork() for one request.
 */
final class OutboundGuard
{
    private AddressPolicy $policy;

    /** @var list<string> */
    private array $allowHosts = [];
    /** @var list<string> */
    private array $allowRanges = [];

    public function __construct(private bool $allowPrivate = false)
    {
        $this->policy = new AddressPolicy();
        $raw = '';
        try {
            $raw = (string) (Env::get('HTTP_ALLOW_PRIVATE', '') ?? '');
        } catch (\Throwable) {
            // no .env: nothing extra allowed
        }
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            if (AddressPolicy::parseCidr($entry) !== null) $this->allowRanges[] = $entry;
            else $this->allowHosts[] = strtolower(rtrim($entry, '.'));
        }
    }

    /**
     * @return array{scheme:string, host:string, port:int, ip:?string}
     *         ip is null when private addresses are allowed for this request
     *         (curl then resolves the name itself)
     * @throws \RuntimeException with a message safe to show
     */
    public function check(string $url): array
    {
        if (strlen($url) > 8000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \RuntimeException('The URL contains characters that are not allowed.');
        }
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!is_array($p) || !in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http:// and https:// URLs are allowed.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('URLs with a user name or password are not allowed; send credentials in a header.');
        }
        $host = strtolower(rtrim((string) ($p['host'] ?? ''), '.'));
        if ($host === '') throw new \RuntimeException('The URL has no host.');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        $bare = trim($host, '[]');
        $isIp = filter_var($bare, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && preg_match('/^(?:0x[0-9a-f]*|\d+)(?:\.(?:0x[0-9a-f]*|\d+))*$/', $host)) {
            // 2130706433, 0177.0.0.1, 0x7f.1: other spellings of IP addresses.
            throw new \RuntimeException('Write IP addresses in the usual dotted form.');
        }

        if ($this->allowPrivate || in_array($host, $this->allowHosts, true)) {
            return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => null];
        }

        $ips = $isIp ? [$bare] : $this->resolve($host);
        if (!$ips) throw new \RuntimeException("{$host} could not be resolved.");
        $chosen = null;
        foreach ($ips as $ip) {
            if ($this->allowRanges && \App\Core\Request::ipInAny($ip, $this->allowRanges)) {
                $chosen ??= $ip;
                continue;
            }
            $why = $this->policy->whyBlocked($ip);
            if ($why !== null) {
                throw new \RuntimeException("{$host} resolves to {$ip}, {$why}; outbound requests only reach public addresses (see HTTP_ALLOW_PRIVATE).");
            }
            if ($chosen === null || (str_contains($chosen, ':') && !str_contains($ip, ':'))) $chosen = $ip;
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $chosen];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $out = [];
        if (function_exists('dns_get_record')) {
            foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
                $rows = @dns_get_record($host, $type);
                foreach (is_array($rows) ? $rows : [] as $r) {
                    if (!empty($r[$key])) $out[] = (string) $r[$key];
                }
            }
        }
        if (!$out) {
            // Hosts files and resolvers dns_get_record can't see.
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) $out = $v4;
        }
        return array_values(array_unique($out));
    }
}
