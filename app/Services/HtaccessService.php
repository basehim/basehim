<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Writes the canonical-URL redirect rules into the site's .htaccess.
 *
 * ── Why this is more careful than it looks ──────────────────────────────────
 *
 * .htaccess is the one file that can make a site unreachable while also taking
 * away the admin screen you would use to fix it. A bad rule here is not a bug
 * report, it is a support call and an FTP client. So:
 *
 *   - the rules live between two markers and nothing outside them is touched,
 *     so hand-written rules and whatever the host added survive;
 *   - the file is written atomically through a temporary file and a rename, so
 *     a failure halfway through cannot leave a half-written .htaccess;
 *   - a timestamped backup is kept before every write;
 *   - after writing, the site is fetched over HTTP. If it does not answer, the
 *     backup goes straight back — the operator never sees a broken site.
 *
 * The HTTPS rule is also written to defer to a proxy: `%{HTTPS} off` alone is
 * wrong behind a load balancer or Cloudflare, where the connection to Apache is
 * genuinely plain HTTP and the redirect loops forever.
 */
final class HtaccessService
{
    private const BEGIN = '# BEGIN Basehim canonical URL';
    private const END   = '# END Basehim canonical URL';

    public function __construct(
        private SettingService $settings,
        private ?\App\Core\Logger $logger = null
    ) {}

    public function path(): string
    {
        return rtrim(BASEHIM_ROOT, '/') . '/.htaccess';
    }

    /** Is the file there and writable? Shown on the settings screen. */
    public function status(): array
    {
        $p = $this->path();
        return [
            'path'     => $p,
            'exists'   => is_file($p),
            'writable' => is_file($p) ? is_writable($p) : is_writable(dirname($p)),
            'managed'  => is_file($p) && str_contains((string) @file_get_contents($p), self::BEGIN),
        ];
    }

    /**
     * Build the rules for the chosen preferences.
     *
     * Returns '' when nothing is wanted, which is how the block gets removed.
     */
    public function buildBlock(string $host, bool $forceHttps): string
    {
        $host = strtolower(trim($host));
        $wantWww    = $host === 'www';
        $wantNoWww  = $host === 'root';
        if (!$forceHttps && !$wantWww && !$wantNoWww) return '';

        $lines = [];
        $lines[] = self::BEGIN;
        $lines[] = '# Managed by Basehim. Edit the Permalinks settings screen rather than';
        $lines[] = '# this block: anything written here is replaced when those are saved.';
        $lines[] = '# Rules outside the BEGIN/END markers are never touched.';
        $lines[] = '';

        if ($wantWww || $wantNoWww) {
            /*
             * The host rules need to know whether to send the visitor to http or
             * https, and hard-coding either is wrong: hard-coding https breaks a
             * site not yet on a certificate, hard-coding http throws away a
             * secure connection. So the scheme is captured into an environment
             * variable first and the host rules use that.
             */
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    # Remember the scheme the visitor actually used, so the host rules';
            $lines[] = '    # below can redirect without changing it.';
            $lines[] = '    RewriteEngine On';
            $lines[] = '    RewriteRule ^ - [E=BH_PROTO:http]';
            $lines[] = '    RewriteCond %{HTTPS} =on [OR]';
            $lines[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} =https [OR]';
            $lines[] = '    RewriteCond %{HTTP:X-Forwarded-SSL} =on';
            $lines[] = '    RewriteRule ^ - [E=BH_PROTO:https]';
            $lines[] = '</IfModule>';
            $lines[] = '';
        }

        $lines[] = '<IfModule mod_rewrite.c>';
        $lines[] = '    RewriteEngine On';
        $lines[] = '';

        if ($forceHttps) {
            $lines[] = '    # Redirect to HTTPS.';
            $lines[] = '    #';
            $lines[] = '    # Three conditions, not one. Behind Cloudflare or a load balancer the';
            $lines[] = '    # connection to Apache really is plain HTTP, so testing %{HTTPS} alone';
            $lines[] = '    # redirects a request that already arrived over HTTPS — and redirects it';
            $lines[] = '    # to itself, forever. The forwarded headers say what the visitor used.';
            $lines[] = '    RewriteCond %{HTTPS} !=on';
            $lines[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} !=https';
            $lines[] = '    RewriteCond %{HTTP:X-Forwarded-SSL} !=on';
            $lines[] = '    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]';
            $lines[] = '';
        }

        if ($wantWww) {
            $lines[] = '    # Add www.';
            $lines[] = '    #';
            $lines[] = '    # Only for a name that has exactly one dot: "example.com" gets the www,';
            $lines[] = '    # "sub.example.com" does not, or every subdomain would be rewritten into';
            $lines[] = '    # a hostname that does not exist. localhost and bare IPs are left alone';
            $lines[] = '    # for the same reason.';
            $lines[] = '    RewriteCond %{HTTP_HOST} !^www\\. [NC]';
            $lines[] = '    RewriteCond %{HTTP_HOST} ^[^.]+\\.[^.]+$ [NC]';
            $lines[] = '    RewriteCond %{HTTP_HOST} !^localhost [NC]';
            $lines[] = '    RewriteCond %{HTTP_HOST} !^\\d+\\.\\d+\\.\\d+\\.\\d+$';
            $lines[] = '    RewriteRule ^ %{ENV:BH_PROTO}://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]';
            $lines[] = '';
        }

        if ($wantNoWww) {
            $lines[] = '    # Remove www.';
            $lines[] = '    RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]';
            $lines[] = '    RewriteRule ^ %{ENV:BH_PROTO}://%1%{REQUEST_URI} [R=301,L]';
            $lines[] = '';
        }

        $lines[] = '</IfModule>';
        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /**
     * Write the block into .htaccess, replacing any previous one.
     *
     * @return array{ok:bool, message:string, backup:?string}
     */
    public function apply(string $host, bool $forceHttps, ?string $verifyUrl = null): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return ['ok' => false, 'backup' => null, 'message' =>
                'There is no .htaccess at the site root, so these rules cannot be written. '
              . 'This is normal on nginx, where the equivalent belongs in the server config.'];
        }
        if (!is_writable($path)) {
            return ['ok' => false, 'backup' => null, 'message' =>
                'The .htaccess file is not writable. Give it write permission, or paste the '
              . 'rules in by hand using the copy below.'];
        }

        $current = (string) file_get_contents($path);
        $block   = $this->buildBlock($host, $forceHttps);
        $updated = $this->replaceBlock($current, $block);

        if ($updated === $current) {
            return ['ok' => true, 'backup' => null, 'message' => 'No change was needed.'];
        }

        // Keep a copy before touching anything.
        $backup = $path . '.bak-' . date('Ymd-His');
        if (!@copy($path, $backup)) {
            return ['ok' => false, 'backup' => null, 'message' =>
                'Could not write a backup beside .htaccess, so nothing has been changed.'];
        }

        /*
         * Write through a temporary file and rename. A partial write to a live
         * .htaccess is a 500 on every request including the admin, and rename
         * is atomic on the same filesystem — the file is either the old one or
         * the new one, never half of each.
         */
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $updated) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'backup' => $backup, 'message' =>
                'Could not write .htaccess. The original is untouched.'];
        }

        // Prove the site still answers. If it does not, put the backup back
        // before the operator discovers it the hard way.
        if ($verifyUrl !== null) {
            $code = $this->probe($verifyUrl);
            if ($code === 0 || $code >= 500) {
                @copy($backup, $path);
                return ['ok' => false, 'backup' => $backup, 'message' =>
                    'The new rules stopped the site responding (' . ($code ?: 'no response')
                  . '), so the previous .htaccess has been restored. Your host may not allow '
                  . 'these directives.'];
            }
        }

        $this->log('canonical URL rules written to .htaccess (' . $host . ', https='
            . ($forceHttps ? 'on' : 'off') . ')');

        return ['ok' => true, 'backup' => $backup, 'message' =>
            $block === '' ? 'The redirect rules were removed.' : 'The redirect rules were saved.'];
    }

    /** Swap the managed block, leaving everything else exactly as it was. */
    private function replaceBlock(string $content, string $block): string
    {
        $pattern = '/\n?' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s';

        if (preg_match($pattern, $content)) {
            return $block === ''
                ? (string) preg_replace($pattern, "\n", $content)
                : (string) preg_replace($pattern, "\n" . $block . "\n", $content);
        }

        if ($block === '') return $content;

        /*
         * A new block goes at the very top. Apache applies rewrite rules in
         * order, and the existing front-controller rule ends with [L] and sends
         * everything to index.php — a redirect placed after it would never be
         * reached.
         */
        return $block . "\n\n" . ltrim($content, "\n");
    }

    private function probe(string $url): int
    {
        if (!function_exists('curl_init')) return -1;   // cannot check; do not judge
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_NOBODY         => true,
            CURLOPT_USERAGENT      => 'Basehim/self-check',
        ]);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $code;
    }

    private function log(string $msg): void
    {
        try { $this->logger?->info('Basehim: ' . $msg); } catch (\Throwable) {}
    }
}
