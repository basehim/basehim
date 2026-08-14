<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\HookRegistry;

/**
 * Mailer — Basehim core email service.
 *
 * Drivers:
 *   - mail : PHP's mail() (works out of the box on cPanel hosts)
 *   - smtp : plain-PHP SMTP client (SSL / STARTTLS / AUTH LOGIN), no Composer.
 *
 * Configure under Settings → Email (settings group `email`):
 *   driver, from_email, from_name, smtp_host, smtp_port, smtp_encryption
 *   (none|ssl|tls), smtp_username, smtp_password.
 *
 * App hooks:
 *   mail.before_send (filter)  — receives the $mail array; return a modified
 *                                array, or false to cancel the send.
 *   mail.sent        (action)  — fired after a successful send.
 *   mail.failed      (action)  — fired with ($mail, $error) on failure.
 *
 * Usage from a app or core:
 *   $mailer = $this->app->make(\App\Services\Mailer::class);
 *   $mailer->sendTemplate('user@example.com', 'Subject', 'Heading', '<p>Hi…</p>');
 */
class Mailer
{
    private string $lastError = '';

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Send an HTML email (a plain-text alternative part is derived
     * automatically). $to may be a string or an array of addresses.
     */
    public function send(string|array $to, string $subject, string $html, array $opts = []): bool
    {
        $cfg = $this->config();
        $mail = [
            'to'       => is_array($to) ? array_values($to) : [$to],
            'subject'  => $subject,
            'html'     => $html,
            'text'     => $opts['text'] ?? trim(preg_replace('/[ \t]+/', ' ', strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html)))),
            'from'     => $opts['from'] ?? $cfg['from_email'],
            'from_name'=> $opts['from_name'] ?? $cfg['from_name'],
            'reply_to' => $opts['reply_to'] ?? null,
            'headers'  => $opts['headers'] ?? [],
        ];

        // Apps may modify or cancel the send.
        $hooks = $this->hooks();
        if ($hooks) {
            $filtered = $hooks->applyFilters('mail.before_send', $mail);
            if ($filtered === false || $filtered === null) {
                $this->lastError = 'Cancelled by mail.before_send filter.';
                return false;
            }
            if (is_array($filtered)) $mail = $filtered;
        }

        $ok = false;
        $error = '';
        try {
            if (($cfg['driver'] ?? 'mail') === 'smtp' && !empty($cfg['smtp_host'])) {
                [$ok, $error] = $this->sendSmtp($mail, $cfg);
            } else {
                [$ok, $error] = $this->sendPhpMail($mail);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        if ($ok) {
            if ($hooks) $hooks->doAction('mail.sent', $mail);
        } else {
            $this->lastError = $error ?: 'Unknown mail error';
            $this->log('Mail send failed: ' . $this->lastError . ' (to: ' . implode(',', $mail['to']) . ')');
            if ($hooks) $hooks->doAction('mail.failed', $mail, $this->lastError);
        }
        return $ok;
    }

    /** Send using the standard Basehim-branded HTML wrapper. */
    public function sendTemplate(string|array $to, string $subject, string $heading, string $bodyHtml, ?string $footNote = null): bool
    {
        $site = $this->siteTitle();
        $foot = $footNote ?? "You're receiving this email from {$site}.";
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">'
            . '<tr><td style="padding:0 8px 14px;font-size:18px;font-weight:bold;color:#1d4ed8;">' . htmlspecialchars($site) . '</td></tr>'
            . '<tr><td style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;">'
            . '<h1 style="margin:0 0 14px;font-size:20px;color:#0f172a;">' . htmlspecialchars($heading) . '</h1>'
            . '<div style="font-size:14px;line-height:1.65;color:#334155;">' . $bodyHtml . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 8px;font-size:12px;color:#94a3b8;">' . htmlspecialchars($foot) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
        return $this->send($to, $subject, $html);
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** Effective configuration (settings group `email` + sensible defaults). */
    public function config(): array
    {
        $app = Application::getInstance();
        $defaults = [
            'driver'          => 'mail',
            'from_email'      => '',
            'from_name'       => $this->siteTitle(),
            'smtp_host'       => '',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_username'   => '',
            'smtp_password'   => '',
        ];
        try {
            $settings = $app->make(SettingService::class);
            $group = (array) $settings->getGroup('email');
            $cfg = array_merge($defaults, array_filter($group, fn($v) => $v !== '' && $v !== null));
            if ($cfg['from_email'] === '') {
                $cfg['from_email'] = (string) ($settings->get('general', 'admin_email', '') ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
            }
            return $cfg;
        } catch (\Throwable) {
            $defaults['from_email'] = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            return $defaults;
        }
    }

    // ------------------------------------------------------------------
    // Drivers
    // ------------------------------------------------------------------

    /** @return array{0:bool,1:string} */
    private function sendPhpMail(array $mail): array
    {
        [$headers, $body] = $this->buildMime($mail, forPhpMail: true);
        $subject = $this->encodeHeader($mail['subject']);
        $to = implode(', ', $mail['to']);
        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        return [$ok, $ok ? '' : 'PHP mail() returned false — check the server mail log.'];
    }

    /** @return array{0:bool,1:string} */
    private function sendSmtp(array $mail, array $cfg): array
    {
        $host = (string) $cfg['smtp_host'];
        $port = (int) ($cfg['smtp_port'] ?: 587);
        $enc  = (string) ($cfg['smtp_encryption'] ?: 'none');
        $timeout = 15;

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if (!$fp) return [false, "SMTP connect failed: {$errstr} ({$errno})"];
        stream_set_timeout($fp, $timeout);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break; // last line of reply
            }
            return $data;
        };
        $cmd = function (string $c, array $expect) use ($fp, $read): array {
            fwrite($fp, $c . "\r\n");
            $resp = $read();
            $code = (int) substr($resp, 0, 3);
            return [in_array($code, $expect, true), $resp];
        };

        try {
            $greeting = $read();
            if ((int) substr($greeting, 0, 3) !== 220) return [false, 'SMTP greeting failed: ' . trim($greeting)];

            $me = $_SERVER['HTTP_HOST'] ?? 'localhost';
            [$ok, $r] = $cmd('EHLO ' . $me, [250]);
            if (!$ok) return [false, 'EHLO failed: ' . trim($r)];

            if ($enc === 'tls') {
                [$ok, $r] = $cmd('STARTTLS', [220]);
                if (!$ok) return [false, 'STARTTLS refused: ' . trim($r)];
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return [false, 'TLS negotiation failed.'];
                }
                [$ok, $r] = $cmd('EHLO ' . $me, [250]);
                if (!$ok) return [false, 'EHLO (post-TLS) failed: ' . trim($r)];
            }

            if (!empty($cfg['smtp_username'])) {
                [$ok, $r] = $cmd('AUTH LOGIN', [334]);
                if (!$ok) return [false, 'AUTH LOGIN not accepted: ' . trim($r)];
                [$ok, $r] = $cmd(base64_encode((string) $cfg['smtp_username']), [334]);
                if (!$ok) return [false, 'SMTP username rejected: ' . trim($r)];
                [$ok, $r] = $cmd(base64_encode((string) $cfg['smtp_password']), [235]);
                if (!$ok) return [false, 'SMTP authentication failed: ' . trim($r)];
            }

            [$ok, $r] = $cmd('MAIL FROM:<' . $mail['from'] . '>', [250]);
            if (!$ok) return [false, 'MAIL FROM rejected: ' . trim($r)];
            foreach ($mail['to'] as $rcpt) {
                [$ok, $r] = $cmd('RCPT TO:<' . trim((string) $rcpt) . '>', [250, 251]);
                if (!$ok) return [false, 'Recipient rejected: ' . trim($r)];
            }

            [$ok, $r] = $cmd('DATA', [354]);
            if (!$ok) return [false, 'DATA rejected: ' . trim($r)];

            [$headers, $body] = $this->buildMime($mail, forPhpMail: false);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            // Dot-stuffing per RFC 5321 §4.5.2.
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($fp, $data . "\r\n.\r\n");
            $resp = $read();
            if ((int) substr($resp, 0, 3) !== 250) return [false, 'Message rejected: ' . trim($resp)];

            $cmd('QUIT', [221]);
            return [true, ''];
        } finally {
            @fclose($fp);
        }
    }

    // ------------------------------------------------------------------
    // MIME assembly
    // ------------------------------------------------------------------

    /** @return array{0:array<string>,1:string} [headers, body] */
    private function buildMime(array $mail, bool $forPhpMail): array
    {
        $boundary = 'basehim_' . bin2hex(random_bytes(12));
        $fromName = $this->encodeHeader((string) $mail['from_name']);

        $headers = [];
        if (!$forPhpMail) {
            $headers[] = 'To: ' . implode(', ', $mail['to']);
            $headers[] = 'Subject: ' . $this->encodeHeader($mail['subject']);
        }
        $headers[] = "From: {$fromName} <{$mail['from']}>";
        if (!empty($mail['reply_to'])) $headers[] = 'Reply-To: ' . $mail['reply_to'];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Basehim';
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        foreach ((array) $mail['headers'] as $h) $headers[] = $h;

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $mail['text'] . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $mail['html'] . "\r\n\r\n"
            . "--{$boundary}--";

        return [$headers, $body];
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function hooks(): ?HookRegistry
    {
        try { return Application::getInstance()->make(HookRegistry::class); }
        catch (\Throwable) { return null; }
    }

    private function siteTitle(): string
    {
        try {
            $settings = Application::getInstance()->make(SettingService::class);
            return (string) ($settings->get('general', 'site_title', '') ?: 'Basehim');
        } catch (\Throwable) {
            return 'Basehim';
        }
    }

    private function log(string $message): void
    {
        try {
            $logger = Application::getInstance()->make(\App\Core\Logger::class);
            $logger->error($message);
        } catch (\Throwable) {
            error_log('[Basehim Mailer] ' . $message);
        }
    }
}
