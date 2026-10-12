<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

/**
 * Downloader
 *
 * The only way this app fetches a remote file. Up to 1.3.1 two copies of
 * this code existed (MediaImporter::fetch, UrlMapper::download) and both:
 *
 *   - accepted any URL scheme curl understands. An attachment URL of
 *     `file:///path/to/.env` in a WXR file was read from the server's own
 *     disk and published under /uploads/ — and the file_get_contents()
 *     fallback read plain local paths the same way;
 *   - followed redirects and accepted any host, including 127.0.0.1,
 *     10.x, 169.254.169.254 (cloud metadata) and other internal services;
 *   - read the whole body into memory before checking the size cap.
 *
 * Now: http/https only, every hop of a redirect is resolved and checked,
 * the connection is pinned to the address that was checked (no DNS
 * rebinding between check and connect), private/reserved addresses are
 * refused unless the operator allowed them, and the body is streamed to a
 * temp file that is aborted the moment it passes the cap.
 */
final class Downloader
{
    public const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    /** Why the last download failed, for the log. */
    public string $lastError = '';

    public function __construct(
        private bool $allowPrivate = false,
        private int $timeout = 30,
        private int $connectTimeout = 10,
    ) {}

    /**
     * Download $url into a new temp file under $tmpDir.
     *
     * @return array{path:string,mime:string,size:int}|null  null on failure (see $lastError)
     */
    public function toTempFile(string $url, string $tmpDir, int $maxBytes = self::DEFAULT_MAX_BYTES): ?array
    {
        $this->lastError = '';
        if (!function_exists('curl_init')) return $this->fail('the curl extension is not available');

        $tmp = @tempnam($tmpDir, 'wpmig_dl_');
        if ($tmp === false) return $this->fail('could not create a temp file in ' . $tmpDir);

        $current = $url;
        for ($hop = 0; $hop <= 5; $hop++) {
            $target = $this->checkUrl($current);
            if ($target === null) { @unlink($tmp); return null; }

            $fh = @fopen($tmp, 'wb');
            if ($fh === false) { @unlink($tmp); return $this->fail('could not write temp file'); }

            $ch = curl_init($current);
            $tooBig = false;
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => false,          // every hop is checked below
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_USERAGENT      => 'Basehim-WpMigrator/1.4',
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // Connect to the address we checked, not whatever DNS says next.
                CURLOPT_RESOLVE        => [$target['host'] . ':' . $target['port'] . ':' . $target['ipForResolve']],
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_XFERINFOFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($maxBytes, &$tooBig) {
                    if ($dlTotal > $maxBytes || $dlNow > $maxBytes) { $tooBig = true; return 1; }
                    return 0;
                },
            ]);
            $ok = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
            $err      = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($tooBig) { @unlink($tmp); return $this->fail('larger than ' . round($maxBytes / 1048576) . ' MB'); }
            if ($ok === false) { @unlink($tmp); return $this->fail($err !== '' ? $err : 'download failed'); }

            if ($status >= 300 && $status < 400 && $location !== '') {
                $current = $location;
                continue;
            }
            if ($status >= 400 || $status === 0) { @unlink($tmp); return $this->fail('HTTP ' . $status); }

            clearstatcache(true, $tmp);
            $size = (int) @filesize($tmp);
            if ($size <= 0) { @unlink($tmp); return $this->fail('empty response'); }

            $mime = $mime !== '' ? strtolower(trim((string) strtok($mime, ';'))) : 'application/octet-stream';
            return ['path' => $tmp, 'mime' => $mime, 'size' => $size];
        }
        @unlink($tmp);
        return $this->fail('too many redirects');
    }

    /** Convenience for small files (resized copies): bytes or null. */
    public function toString(string $url, string $tmpDir, int $maxBytes = self::DEFAULT_MAX_BYTES): ?string
    {
        $r = $this->toTempFile($url, $tmpDir, $maxBytes);
        if ($r === null) return null;
        $data = @file_get_contents($r['path']);
        @unlink($r['path']);
        return $data === false ? null : $data;
    }

    /**
     * Validate a URL and resolve its host.
     *
     * @return array{host:string,port:int,ipForResolve:string}|null
     */
    private function checkUrl(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->fail("refused non-http(s) URL ({$scheme}:)");
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') return $this->fail('URL has no host');
        if (isset($parts['user']) || isset($parts['pass'])) return $this->fail('URLs with credentials are refused');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            $ips = [$bare];
        } else {
            $ips = [];
            $records = @dns_get_record($bare, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
            if (!$ips) {
                $v4 = @gethostbynamel($bare) ?: [];
                $ips = $v4;
            }
        }
        if (!$ips) return $this->fail("could not resolve {$bare}");

        if (!$this->allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    return $this->fail("refused {$bare} ({$ip}): private or reserved address — enable "
                        . "\"Allow downloads from private network addresses\" if the old site really is on your local network");
                }
            }
        }
        $ip = $ips[0];
        return [
            'host' => $bare,
            'port' => $port,
            'ipForResolve' => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
        ];
    }

    public static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) is judged by its IPv4 part.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) $ip = $m[1];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            // Ranges PHP's flags do not cover: 0/8, 100.64/10 (CGNAT), 192.0.0/24, 198.18/15 (benchmark).
            foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) return false;
            }
        } else {
            $lower = strtolower($ip);
            // Unique-local fc00::/7 and link-local fe80::/10.
            if (preg_match('/^f[cd][0-9a-f]{2}:/', $lower) || preg_match('/^fe[89ab][0-9a-f]:/', $lower)) return false;
        }
        return true;
    }

    private function fail(string $why): ?array
    {
        $this->lastError = $why;
        return null;
    }
}
