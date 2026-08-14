<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\Mailer;

/**
 * MailApi — outbound email through the site's configured mailer.
 *
 * Apps should not open their own SMTP connections: the site already has
 * credentials, a from-address and a branded template configured in
 * Settings → Email, and mail sent any other way will not match.
 */
class MailApi extends Resource
{
    private function mailer(): Mailer
    {
        return $this->make(Mailer::class);
    }

    /**
     * Send an HTML email.
     *
     * @param string|array $to One address, or a list of them.
     */
    public function send(string|array $to, string $subject, string $html, array $options = []): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->mailer()->send($to, $subject, $html, $options), false, 'send');
        $this->log($ok ? "Sent mail: {$subject}" : "Mail failed: {$subject}", [], $ok ? 'info' : 'warning');
        return $ok;
    }

    /**
     * Send using the site's branded template — header, styling and footer to
     * match every other email the site sends.
     */
    public function sendTemplate(
        string|array $to,
        string $subject,
        string $heading,
        string $bodyHtml,
        ?string $footNote = null
    ): bool {
        $ok = (bool) $this->attempt(
            fn() => $this->mailer()->sendTemplate($to, $subject, $heading, $bodyHtml, $footNote),
            false,
            'sendTemplate'
        );
        $this->log($ok ? "Sent templated mail: {$subject}" : "Templated mail failed: {$subject}", [], $ok ? 'info' : 'warning');
        return $ok;
    }

    /** Why the last send failed — empty when it succeeded. */
    public function lastError(): string
    {
        return (string) $this->attempt(fn() => $this->mailer()->lastError(), '', 'lastError');
    }

    /** True when the site has email configured at all. */
    public function isConfigured(): bool
    {
        $config = (array) $this->attempt(fn() => $this->mailer()->config(), [], 'config');
        return !empty($config['from_email']);
    }
}
