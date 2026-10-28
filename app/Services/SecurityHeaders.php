<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;

/**
 * Security headers on every response Basehim sends (1.2.90).
 *
 * Settings → Security. What is sent:
 *
 * - X-Powered-By: Basehim, in place of PHP's own "PHP/8.x.y" (which tells
 *   anyone the exact PHP version);
 * - X-XSS-Protection: 0. The old "1; mode=block" switched on a browser filter
 *   that has been removed from every current browser and was itself a source
 *   of cross-site leaks; current advice (OWASP, MDN) is 0 or nothing;
 * - X-Content-Type-Options, X-Frame-Options, Referrer-Policy and
 *   Permissions-Policy, as .htaccess already does on Apache and LiteSpeed,
 *   so nginx and other servers get them too (a response's own value wins);
 * - Strict-Transport-Security on HTTPS responses (on by default, one year);
 * - Content-Security-Policy on HTML pages:
 *     baseline, always enforced:  object-src 'none'; base-uri 'self';
 *                                  frame-ancestors 'self'; upgrade-insecure-requests
 *     scripts, on the public site: script-src 'nonce-…' 'strict-dynamic' https: 'unsafe-inline'
 *       mode "report"  (default) as Content-Security-Policy-Report-Only:
 *                       violations are reported to /csp-report and listed in
 *                       Settings → Security; nothing is blocked;
 *       mode "enforce" blocks what the policy doesn't allow;
 *       mode "off"     no CSP at all.
 *
 * The script policy is Google's "strict CSP": every <script> element in the
 * page gets this response's nonce, and scripts those load are trusted in
 * turn ('strict-dynamic'), so themes, apps, analytics and ads keep working.
 * What it blocks is script that does not arrive as a <script> element of the
 * page: inline event handlers (onclick="…"), javascript: links, and scripts
 * injected into the page after it loaded. https: and 'unsafe-inline' are
 * ignored by browsers that understand nonces; they only keep very old
 * browsers working.
 *
 * The admin gets the baseline only: its screens and apps' admin pages use
 * inline handlers that a script policy would break.
 */
final class SecurityHeaders
{
    public const GROUP = 'security';
    public const MODES = ['off', 'report', 'enforce'];
    public const HSTS_AGES = [15552000 => '6 months', 31536000 => '1 year', 63072000 => '2 years'];
    public const DEFAULTS = [
        'hsts' => true,
        'hsts_max_age' => 31536000,
        'hsts_subdomains' => false,
        'hsts_preload' => false,
        'csp_mode' => 'report',
    ];
    public const REPORT_PATH = '/csp-report';

    private static ?string $nonce = null;

    /** This response's CSP nonce (one per request). */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** The settings, with defaults and types fixed. */
    public static function settings(): array
    {
        $s = self::DEFAULTS;
        try {
            $svc = Application::getInstance()->make(SettingService::class);
            foreach (array_keys(self::DEFAULTS) as $k) {
                $v = $svc->get(self::GROUP, $k, null);
                if ($v !== null && $v !== '') $s[$k] = $v;
            }
        } catch (\Throwable) {
            // No database yet (installer): defaults.
        }
        foreach (['hsts', 'hsts_subdomains', 'hsts_preload'] as $k) {
            $s[$k] = filter_var($s[$k], FILTER_VALIDATE_BOOLEAN);
        }
        $s['hsts_max_age'] = (int) $s['hsts_max_age'];
        if (!isset(self::HSTS_AGES[$s['hsts_max_age']])) $s['hsts_max_age'] = self::DEFAULTS['hsts_max_age'];
        if (!in_array($s['csp_mode'], self::MODES, true)) $s['csp_mode'] = self::DEFAULTS['csp_mode'];
        return $s;
    }

    /**
     * Add the headers to a response about to be sent. $private: an admin or
     * AJAX response (no script policy, no nonces). Never throws.
     */
    public static function apply(Response $response, Request $request, bool $private): void
    {
        try {
            self::applyOrFail($response, $request, $private);
        } catch (\Throwable) {
            // Headers are a hardening layer: never fail a response over them.
        }
    }

    private static function applyOrFail(Response $response, Request $request, bool $private): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
        if ($private && $request->method === 'GET') {
            try {
                self::upgradeHtaccessOnce();
            } catch (\Throwable) {
                // Tried again on the next admin page.
            }
        }
        $has = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $set = static function (string $name, string $value) use ($response, &$has): void {
            if (!isset($has[strtolower($name)])) {
                $response->header($name, $value);
                $has[strtolower($name)] = $value;
            }
        };

        $set('X-Powered-By', 'Basehim');
        $set('X-Content-Type-Options', 'nosniff');
        $set('X-XSS-Protection', '0');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        $s = self::settings();
        $secure = $request->isSecure();
        if ($secure && $s['hsts'] && !self::isLocalHost((string) ($request->server['HTTP_HOST'] ?? ''))) {
            $set('Strict-Transport-Security', 'max-age=' . $s['hsts_max_age']
                . ($s['hsts_subdomains'] ? '; includeSubDomains' : '')
                . ($s['hsts_preload'] ? '; preload' : ''));
        }

        if ($s['csp_mode'] === 'off' || !self::isHtml($response) || isset($has['content-security-policy'])) {
            return;
        }
        $baseline = "object-src 'none'; base-uri 'self'; frame-ancestors 'self'" . ($secure ? '; upgrade-insecure-requests' : '');
        if ($private) {
            $set('Content-Security-Policy', $baseline);
            return;
        }

        $nonce = self::nonce();
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $scripts = "script-src 'nonce-{$nonce}' 'strict-dynamic' https: 'unsafe-inline'";
        $report = 'report-uri ' . $base . self::REPORT_PATH;
        if ($s['csp_mode'] === 'enforce') {
            $set('Content-Security-Policy', $baseline . '; ' . $scripts . '; ' . $report);
        } else {
            $set('Content-Security-Policy', $baseline);
            $set('Content-Security-Policy-Report-Only', "object-src 'none'; base-uri 'self'; " . $scripts . '; ' . $report);
        }
        $response->body(self::addNonces($response->getBody(), $nonce));
    }

    /**
     * Give every executable <script> element without a nonce this one.
     * Elements that already carry a nonce keep theirs; data blocks
     * (type="application/ld+json" and other non-script types) are left alone.
     */
    public static function addNonces(string $html, string $nonce): string
    {
        if (stripos($html, '<script') === false) return $html;
        $attr = ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"';
        return (string) preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m) use ($attr): string {
            if (preg_match('/\snonce\s*=/i', $m[1])) return $m[0];
            // Data blocks (JSON-LD, templates) never run, so CSP ignores them:
            // leave their markup exactly as it was.
            if (preg_match('/\stype\s*=\s*["\']?\s*([^"\'\s>]+)/i', $m[1], $t)
                && !preg_match('#^(?:module|(?:text|application)/(?:x-)?(?:java|ecma)script)$#i', $t[1])) {
                return $m[0];
            }
            return '<script' . $attr . $m[1] . '>';
        }, $html);
    }

    private static function isHtml(Response $response): bool
    {
        foreach ($response->getHeaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                return stripos((string) $value, 'text/html') !== false;
            }
        }
        // No Content-Type set: PHP sends text/html; treat a page as one.
        $start = ltrim(substr($response->getBody(), 0, 200));
        return stripos($start, '<!doctype html') === 0 || stripos($start, '<html') === 0;
    }

    private static function isLocalHost(string $host): bool
    {
        $h = strtolower((string) preg_replace('/:\d+$/', '', trim($host, '[] ')));
        return $h === '' || $h === 'localhost' || str_ends_with($h, '.localhost') || filter_var(trim($h, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    // ── Violation reports ────────────────────────────────────────────────────

    public const REPORTS_FILE = '/storage/security/csp-reports.json';
    public const MAX_REPORTS = 100;

    /**
     * Record one report from a browser (POST /csp-report). Stored grouped by
     * what was blocked and where, with a count; nothing that identifies the
     * visitor is kept. Returns false when the report was not usable.
     */
    public static function record(string $raw): bool
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) return false;
        $r = $data['csp-report'] ?? null;
        if (!is_array($r)) return false;

        $clean = static fn($v, int $max): string => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $v) ?? '', 0, $max);
        $directive = $clean($r['effective-directive'] ?? $r['violated-directive'] ?? '', 60);
        $blocked = $clean($r['blocked-uri'] ?? '', 300);
        $document = (string) ($r['document-uri'] ?? '');
        $path = $clean(parse_url($document, PHP_URL_PATH) ?: '/', 200);
        $source = $clean($r['source-file'] ?? '', 300);
        $line = (int) ($r['line-number'] ?? 0);
        $sample = $clean($r['script-sample'] ?? '', 120);
        if ($directive === '') return false;
        // Strip query strings from blocked URLs: they can carry tokens.
        if (preg_match('#^https?://#i', $blocked)) $blocked = (string) preg_replace('/[?#].*$/s', '', $blocked);

        $key = hash('sha256', $directive . '|' . $blocked . '|' . $path . '|' . $source . '|' . $line);
        $file = BASEHIM_ROOT . self::REPORTS_FILE;
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $fh = @fopen($file, 'c+');
        if (!$fh) return false;
        try {
            if (!flock($fh, LOCK_EX)) return false;
            $list = json_decode((string) stream_get_contents($fh), true);
            if (!is_array($list)) $list = [];
            $now = gmdate('c');
            if (isset($list[$key])) {
                $list[$key]['count'] = (int) $list[$key]['count'] + 1;
                $list[$key]['last'] = $now;
            } else {
                $list[$key] = [
                    'directive' => $directive, 'blocked' => $blocked, 'page' => $path,
                    'source' => $source, 'line' => $line, 'sample' => $sample,
                    'count' => 1, 'first' => $now, 'last' => $now,
                ];
                if (count($list) > self::MAX_REPORTS) {
                    uasort($list, fn($a, $b) => strcmp((string) $b['last'], (string) $a['last']));
                    $list = array_slice($list, 0, self::MAX_REPORTS, true);
                }
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($list, JSON_UNESCAPED_SLASHES));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        return true;
    }

    /** @return list<array> newest first */
    public static function reports(): array
    {
        $raw = @file_get_contents(BASEHIM_ROOT . self::REPORTS_FILE);
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($list)) return [];
        $list = array_values(array_filter($list, 'is_array'));
        usort($list, fn($a, $b) => strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')));
        return $list;
    }

    public static function clearReports(): void
    {
        @unlink(BASEHIM_ROOT . self::REPORTS_FILE);
    }

    // ── .htaccess ─────────────────────────────────────────────────────────────

    /** upgradeHtaccess() the first time an admin page is opened after the update. */
    private static function upgradeHtaccessOnce(): void
    {
        $svc = Application::getInstance()->make(SettingService::class);
        if ((string) $svc->get(self::GROUP, 'htaccess_xss', '') !== '') return;
        $result = self::upgradeHtaccess();
        $svc->set(self::GROUP, 'htaccess_xss', $result);
        try {
            Application::getInstance()->make(\App\Core\Logger::class)
                ->log($result === 'unwritable' ? 'warning' : 'info', "Security headers: .htaccess X-XSS-Protection line {$result}.");
        } catch (\Throwable) {}
    }

    /**
     * Basehim's .htaccess up to 1.2.89 sets `X-XSS-Protection "1; mode=block"`,
     * and Apache and LiteSpeed apply it after PHP, over the 0 sent above.
     * The updater never rewrites .htaccess, so this changes that one line,
     * exactly as Basehim wrote it, to "0", and touches nothing else. Run
     * once (from the admin) after the update; safe to run again.
     *
     * @return string 'updated', 'already', 'absent' (no such line) or 'unwritable'
     */
    public static function upgradeHtaccess(?string $path = null): string
    {
        $path ??= BASEHIM_ROOT . '/.htaccess';
        $old = 'Header set X-XSS-Protection "1; mode=block"';
        $new = 'Header set X-XSS-Protection "0"';
        $content = @file_get_contents($path);
        if (!is_string($content)) return 'absent';
        if (str_contains($content, $new) && !str_contains($content, $old)) return 'already';
        if (!str_contains($content, $old)) return 'absent';
        if (!is_writable($path)) return 'unwritable';
        $updated = str_replace($old, $new, $content);
        $tmp = $path . '.bh-tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $updated, LOCK_EX) !== strlen($updated)) {
            @unlink($tmp);
            return 'unwritable';
        }
        @chmod($tmp, fileperms($path) & 0777);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return 'unwritable';
        }
        return 'updated';
    }
}
