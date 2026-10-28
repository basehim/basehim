<?php

declare(strict_types=1);

namespace App\Core\Net;

/**
 * Which IP addresses outbound requests may reach: public unicast only.
 * (1.2.90; the same rules as Public Tools' network layer.)
 *
 * IPv4: everything except the special-purpose ranges below (IANA IPv4
 * Special-Purpose Address Registry, plus multicast and the reserved 240/4).
 *
 * IPv6: only global unicast (2000::/3), minus the special-purpose blocks
 * inside it. Everything outside 2000::/3 — loopback, unspecified,
 * IPv4-mapped and -compatible, link-local, unique-local, site-local,
 * multicast, discard — is refused. Addresses that embed an IPv4 address
 * (NAT64 64:ff9b::/96) are judged by the embedded address; 6to4 and Teredo,
 * which can tunnel to anything, are refused outright.
 *
 * A site can add its own ranges (its hosting network, say) with $extra.
 */
final class AddressPolicy
{
    /** @var list<array{0:string,1:string}> CIDR => why */
    private const V4_BLOCKED = [
        ['0.0.0.0/8', 'a “this network” address'],
        ['10.0.0.0/8', 'a private address'],
        ['100.64.0.0/10', 'a carrier-grade NAT address'],
        ['127.0.0.0/8', 'a loopback address'],
        ['169.254.0.0/16', 'a link-local address (this includes cloud metadata services)'],
        ['172.16.0.0/12', 'a private address'],
        ['192.0.0.0/24', 'an IETF protocol address'],
        ['192.0.2.0/24', 'a documentation address'],
        ['192.31.196.0/24', 'an AS112 address'],
        ['192.52.193.0/24', 'an AMT address'],
        ['192.88.99.0/24', 'a 6to4 relay address'],
        ['192.168.0.0/16', 'a private address'],
        ['192.175.48.0/24', 'an AS112 address'],
        ['198.18.0.0/15', 'a benchmarking address'],
        ['198.51.100.0/24', 'a documentation address'],
        ['203.0.113.0/24', 'a documentation address'],
        ['224.0.0.0/4', 'a multicast address'],
        ['240.0.0.0/4', 'a reserved address'],
    ];

    /** Inside 2000::/3. */
    private const V6_BLOCKED = [
        ['2001::/23', 'an IETF protocol address (Teredo, ORCHID and others)'],
        ['2001:db8::/32', 'a documentation address'],
        ['2002::/16', 'a 6to4 address'],
        ['3fff::/20', 'a documentation address'],
    ];

    /** @var list<array{0:string,1:int,2:string}> parsed extra ranges: network bytes, prefix, label */
    private array $extra = [];

    /** @param list<string> $extra additional CIDR ranges or single addresses to refuse */
    public function __construct(array $extra = [])
    {
        foreach ($extra as $range) {
            $parsed = self::parseCidr(trim((string) $range));
            if ($parsed !== null) $this->extra[] = [$parsed[0], $parsed[1], 'an address this site blocks'];
        }
    }

    public function isPublic(string $ip): bool
    {
        return $this->whyBlocked($ip) === null;
    }

    /** Null when the address is allowed, otherwise a short phrase: "a private address". */
    public function whyBlocked(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return 'not a valid IP address';

        foreach ($this->extra as [$net, $prefix, $label]) {
            if (strlen($net) === strlen($bin) && self::inPrefix($bin, $net, $prefix)) return $label;
        }

        if (strlen($bin) === 4) return self::v4Reason($bin);

        // IPv6 that embeds IPv4.
        if (str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\x00", 8))) {
            $inner = substr($bin, 12, 4);
            foreach ($this->extra as [$net, $prefix, $label]) {
                if (strlen($net) === 4 && self::inPrefix($inner, $net, $prefix)) return $label;
            }
            return self::v4Reason($inner);
        }

        // Global unicast only.
        if ((ord($bin[0]) & 0xE0) !== 0x20) {
            return match (true) {
                $bin === str_repeat("\x00", 16) => 'the unspecified address',
                $bin === str_repeat("\x00", 15) . "\x01" => 'a loopback address',
                str_starts_with($bin, str_repeat("\x00", 10) . "\xff\xff") => 'an IPv4-mapped address',
                (ord($bin[0]) & 0xFE) === 0xFC => 'a unique-local (private) address',
                ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80 => 'a link-local address',
                ord($bin[0]) === 0xFF => 'a multicast address',
                default => 'a reserved address',
            };
        }
        foreach (self::V6_BLOCKED as [$cidr, $label]) {
            [$net, $prefix] = self::parseCidr($cidr);
            if (self::inPrefix($bin, $net, $prefix)) return $label;
        }
        return null;
    }

    /** @return array{0:string,1:int}|null network bytes and prefix length */
    public static function parseCidr(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        $bin = @inet_pton($parts[0]);
        if ($bin === false) return null;
        $max = strlen($bin) * 8;
        if (!isset($parts[1])) return [$bin, $max];
        if (!ctype_digit($parts[1])) return null;
        $prefix = (int) $parts[1];
        return $prefix <= $max ? [$bin, $prefix] : null;
    }

    public static function inPrefix(string $bin, string $net, int $prefix): bool
    {
        if (strlen($bin) !== strlen($net)) return false;
        $bytes = intdiv($prefix, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) return false;
        $bits = $prefix % 8;
        if ($bits === 0) return true;
        $mask = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private static function v4Reason(string $bin): ?string
    {
        if ($bin === "\xff\xff\xff\xff") return 'the broadcast address';
        foreach (self::V4_BLOCKED as [$cidr, $label]) {
            [$net, $prefix] = self::parseCidr($cidr);
            if (self::inPrefix($bin, $net, $prefix)) return $label;
        }
        return null;
    }
}
