<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CanonicalUrlService
 *
 * Writes the canonical-host and force-HTTPS redirects into .htaccess.
 *
 * A wrong rule here does not produce an error message — it produces a site that
 * will not load, including the admin screen you would use to undo it. So this
 * takes more care than the setting deserves on the face of it:
 *
 *   - Rules go between markers, so the rest of the file is never touched.
 *   - The previous file is copied aside before anything is written.
 *   - After writing, the site is fetched. If it is broken, the backup is put
 *     back automatically and the change is reported as failed. That check is the
 *     whole point: everything else is guesswork about what Apache will accept.
 *   - Forcing HTTPS is refused outright when the site does not already answer
 *     over HTTPS, because the result would be a redirect to a URL that does not
 *     work, from every page at once.
 */
final class CanonicalUrlService
{
    private const BEGIN = '# BEGIN Basehim canonical URL';
    private const END   = '# END Basehim canonical URL';

    /** Modes the admin can pick. */
    public const MODE_OFF  = 'off';
    public const MODE_WWW  = 'www';
    public const MODE_BARE = 'bare';

    public function __construct(private SettingService $settings) {}

    private function path(): string
    {
        return BASEHIM_ROOT . '/.htaccess';
    }

    // ==================================================================
    // Reading the current state
    // ==================================================================

    /** @return array{mode:string, https:bool, host:string, managed:bool, writable:bool} */
    public function status(): array
    {
        $p = $this->path();
        return [
            'mode'     => (string) $this->settings->get('permalinks', 'canonical_mode', self::MODE_OFF),
            'https'    => (bool) $this->settings->get('permalinks', 'force_https', false),
            'host'     => $this->currentHost(),
            'managed'  => is_file($p) && str_contains((string) @file_get_contents($p), self::BEGIN),
            'writable' => is_file($p) ? is_writable($p) : is_writable(BASEHIM_ROOT),
        ];
    }

    /** Host as the browser sees it, without any www prefix. */
    public function bareHost(): string
    {
        $h = $this->currentHost();
        return preg_replace('/^www\./i', '', $h) ?? $h;
    }

    private function currentHost(): string
    {
        $h = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($h === '') $h = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return strtolower(preg_replace('/:\d+$/', '', $h) ?? $h);
    }

    /** The URL this configuration would send every visitor to. */
    public function canonicalUrl(string $mode, bool $https): string
    {
        $bare = $this->bareHost();
        $host = $mode === self::MODE_WWW ? 'www.' . $bare : $bare;
        return ($https ? 'https://' : 'http://') . $host . '/';
    }

    // ==================================================================
    // Applying
    // ==================================================================

    /**
     * Write the rules and verify the site still loads.
     *
     * @return array{ok:bool, message:string, rolledBack?:bool, url?:string}
     */
    public function apply(string $mode, bool $https): array
    {
        $mode = in_array($mode, [self::MODE_OFF, self::MODE_WWW, self::MODE_BARE], true) ? $mode : self::MODE_OFF;
        $p = $this->path();

        if (!is_file($p)) {
            return ['ok' => false, 'message' => 'No .htaccess file at the site root, so there is nothing to write to. '
                                              . 'This setting only applies on Apache.'];
        }
        if (!is_writable($p)) {
            return ['ok' => false, 'message' => '.htaccess is not writable (' . substr(sprintf('%o', fileperms($p)), -4)
                                              . '). Change it to 644 and try again.'];
        }

        // Forcing HTTPS with no working certificate takes the site off the air.
        if ($https) {
            $probe = $this->probe('https://' . $this->hostFor($mode) . '/');
            if (!$probe['reachable']) {
                return ['ok' => false, 'message' =>
                    'Refusing to force HTTPS: ' . $probe['detail'] . '. Every page would redirect to an address '
                  . 'that does not answer. Install a certificate for ' . $this->hostFor($mode) . ' first.'];
            }
        }

        $original = (string) file_get_contents($p);
        $backup   = $p . '.basehim-bak';
        @copy($p, $backup);

        $next = $this->stripBlock($original);
        if ($mode !== self::MODE_OFF || $https) {
            $next = $this->buildBlock($mode, $https) . "\n" . ltrim($next, "\n");
        }

        if (@file_put_contents($p, $next) === false) {
            return ['ok' => false, 'message' => 'Could not write .htaccess.'];
        }
        @clearstatcache(true, $p);

        // The only test that means anything: ask the server.
        $url = $this->canonicalUrl($mode === self::MODE_OFF ? self::MODE_BARE : $mode, $https);
        $check = $this->probe($url);
        if (!$check['reachable']) {
            @file_put_contents($p, $original);
            @clearstatcache(true, $p);
            return ['ok' => false, 'rolledBack' => true, 'message' =>
                'The new rules stopped the site loading (' . $check['detail'] . '), so they were removed and '
              . 'the previous .htaccess restored. Nothing has changed.'];
        }

        $this->settings->set('permalinks', 'canonical_mode', $mode);
        $this->settings->set('permalinks', 'force_https', $https ? '1' : '0');

        $desc = $mode === self::MODE_OFF
            ? ($https ? 'HTTPS is now forced.' : 'Canonical redirects removed.')
            : 'Visitors are now sent to ' . $url . ' with a 301.';

        return ['ok' => true, 'url' => $url, 'message' => $desc];
    }

    /** Remove the managed block entirely, leaving the rest of the file alone. */
    public function remove(): array
    {
        $p = $this->path();
        if (!is_file($p) || !is_writable($p)) {
            return ['ok' => false, 'message' => '.htaccess is missing or not writable.'];
        }
        @copy($p, $p . '.basehim-bak');
        file_put_contents($p, $this->stripBlock((string) file_get_contents($p)));
        $this->settings->set('permalinks', 'canonical_mode', self::MODE_OFF);
        $this->settings->set('permalinks', 'force_https', '0');
        return ['ok' => true, 'message' => 'Canonical URL rules removed.'];
    }

    private function hostFor(string $mode): string
    {
        $bare = $this->bareHost();
        return $mode === self::MODE_WWW ? 'www.' . $bare : $bare;
    }

    // ==================================================================
    // The rules
    // ==================================================================

    private function buildBlock(string $mode, bool $https): string
    {
        $bare = $this->bareHost();
        $host = $this->hostFor($mode);
        $L = [];
        $L[] = self::BEGIN;
        $L[] = '# Managed by Basehim — Settings → Permalinks. Edits between these';
        $L[] = '# markers are overwritten; put your own rules outside them.';
        $L[] = '<IfModule mod_rewrite.c>';
        $L[] = '    RewriteEngine On';
        $L[] = '';
        $L[] = '    # Let certificate authorities validate over plain HTTP. Without this,';
        $L[] = '    # forcing HTTPS blocks the ACME challenge and renewals start failing';
        $L[] = '    # — quietly, until the certificate expires.';
        $L[] = '    RewriteRule ^\.well-known/acme-challenge/ - [L]';

        // Canonical host first, redirecting straight to the final scheme. Doing
        // it the other way round costs an extra hop — http://example.com would
        // go to https://example.com and only then to https://www.example.com —
        // and every hop is latency the visitor pays and link equity search
        // engines discount.
        if ($mode !== self::MODE_OFF) {
            $scheme = $https ? 'https' : 'http';
            $L[] = '';
            $L[] = '    # Canonical host: everything else redirects to ' . $host . '.';
            $L[] = '    # The first condition excludes the target itself, or it would loop.';
            $L[] = '    RewriteCond %{HTTP_HOST} !^' . preg_quote($host, '/') . '$ [NC]';
            $L[] = '    RewriteCond %{HTTP_HOST} ^(www\\.)?' . preg_quote($bare, '/') . '$ [NC]';
            $L[] = '    RewriteRule ^ ' . $scheme . '://' . $host . '%{REQUEST_URI} [R=301,L]';
        }

        if ($https) {
            $L[] = '';
            $L[] = '    # Anything already on the canonical host but still plain HTTP.';
            $L[] = '    # Behind a proxy or CDN — most cPanel hosts, anything with Cloudflare';
            $L[] = '    # in front — %{HTTPS} reads "off" even when the visitor is already';
            $L[] = '    # secure, so the forwarded headers are checked too. Testing only';
            $L[] = '    # %{HTTPS} there gives an endless redirect.';
            $L[] = '    RewriteCond %{HTTPS} !=on';
            $L[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} !=https';
            $L[] = '    RewriteCond %{HTTP:X-Forwarded-SSL} !=on';
            $L[] = '    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]';
        }

        $L[] = '</IfModule>';
        $L[] = self::END;
        return implode("\n", $L) . "\n";
    }

    private function stripBlock(string $content): string
    {
        $pattern = '/\R*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\R*/s';
        $out = (string) preg_replace($pattern, "\n", $content);

        // The block normally sits at the top, and the replacement newline would
        // otherwise leave a blank first line that accumulates nowhere but looks
        // like the file was mangled. Removing the rules should give back exactly
        // the file we started with.
        if (preg_match('/^\s*' . preg_quote(self::BEGIN, '/') . '/', $content)) {
            $out = ltrim($out, "\n");
        }
        return $out;
    }

    /** Preview the rules without writing them, so the admin can read them first. */
    public function preview(string $mode, bool $https): string
    {
        return $this->buildBlock($mode, $https);
    }

    // ==================================================================
    // Probing
    // ==================================================================

    /**
     * Does this URL answer without a server error?
     *
     * A redirect is a pass — that is what we are configuring. Only a connection
     * failure or a 5xx counts as broken.
     *
     * @return array{reachable:bool, status:int, detail:string}
     */
    private function probe(string $url): array
    {
        if (!function_exists('curl_init')) {
            // Without curl there is no way to check, so the change is allowed
            // and the backup is the safety net rather than the probe.
            return ['reachable' => true, 'status' => 0, 'detail' => 'not checked (curl unavailable)'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,   // a self-signed cert still proves TLS answers
            CURLOPT_USERAGENT      => 'Basehim/canonical-url-check',
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err !== '')       return ['reachable' => false, 'status' => 0, 'detail' => $err];
        if ($status === 0)     return ['reachable' => false, 'status' => 0, 'detail' => 'no response'];
        if ($status >= 500)    return ['reachable' => false, 'status' => $status, 'detail' => 'server returned ' . $status];
        if ($status >= 400 && $status !== 401 && $status !== 403) {
            return ['reachable' => false, 'status' => $status, 'detail' => 'server returned ' . $status];
        }
        return ['reachable' => true, 'status' => $status, 'detail' => 'HTTP ' . $status];
    }
}
