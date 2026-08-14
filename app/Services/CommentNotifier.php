<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * CommentNotifier — sends the transactional emails around comments:
 *   • a moderation/notification email to the site admin on every new comment
 *   • a "someone replied to you" email to the parent comment's author
 *
 * All sends are best-effort: a mail failure must never break comment submission,
 * so callers can fire these without try/catch and every method swallows errors.
 */
class CommentNotifier
{
    public function __construct(
        private Mailer $mailer,
        private SettingService $settings,
        private Config $config
    ) {}

    /** Notify the site admin that a comment arrived (held or published). */
    public function newComment(array $comment, array $post): void
    {
        try {
            if (!$this->boolSetting('notify_new_comment', true)) return;
            $to = $this->adminEmail();
            if ($to === '') return;

            $held    = ($comment['status'] ?? '') !== 'approved';
            $postTitle = (string) ($post['title'] ?? 'your post');
            $subject = ($held ? '[Moderate] ' : '') . 'New comment on "' . $postTitle . '"';

            $rows = [
                'Author'  => trim((string) ($comment['author_name'] ?? 'Anonymous')),
                'Email'   => (string) ($comment['author_email'] ?? ''),
                'IP'      => (string) ($comment['author_ip'] ?? ''),
                'Status'  => ucfirst((string) ($comment['status'] ?? 'pending')),
            ];
            $meta = '';
            foreach ($rows as $k => $v) {
                if ($v === '') continue;
                $meta .= '<tr><td style="padding:2px 10px 2px 0;color:#64748b;">' . htmlspecialchars($k)
                    . ':</td><td style="padding:2px 0;color:#0f172a;">' . htmlspecialchars($v) . '</td></tr>';
            }

            $body  = '<table style="font-size:13px;margin-bottom:14px;">' . $meta . '</table>';
            $body .= '<div style="border-left:3px solid #e2e8f0;padding:4px 0 4px 14px;color:#334155;white-space:pre-wrap;">'
                . nl2br(htmlspecialchars((string) ($comment['content'] ?? ''))) . '</div>';

            $modUrl  = $this->adminUrl('/admin/comments');
            $postUrl = $this->absolute(\App\Core\Helpers::postUrl($post) . '#comment-' . ($comment['id'] ?? ''));
            $body .= '<p style="margin-top:20px;">'
                . $this->button('Moderate comments', $modUrl)
                . ' &nbsp; '
                . '<a href="' . htmlspecialchars($postUrl) . '" style="color:#2563eb;">View on site</a>'
                . '</p>';

            $heading = $held ? 'A comment is awaiting moderation' : 'New comment posted';
            $this->mailer->sendTemplate($to, $subject, $heading, $body);
        } catch (\Throwable) {
            // swallow — notifications must never break submission
        }
    }

    /** Notify the parent comment's author that someone replied. */
    public function reply(array $comment, array $parent, array $post): void
    {
        try {
            if (!$this->boolSetting('notify_reply', true)) return;

            $to = trim((string) ($parent['author_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

            // Don't email people about their own replies.
            $replierEmail = trim((string) ($comment['author_email'] ?? ''));
            if ($replierEmail !== '' && strcasecmp($to, $replierEmail) === 0) return;

            $postTitle = (string) ($post['title'] ?? 'a post');
            $subject = 'New reply to your comment on "' . $postTitle . '"';

            $who  = trim((string) ($comment['author_name'] ?? 'Someone'));
            $body = '<p><strong>' . htmlspecialchars($who) . '</strong> replied to your comment on '
                . '<em>' . htmlspecialchars($postTitle) . '</em>:</p>';
            $body .= '<div style="border-left:3px solid #e2e8f0;padding:4px 0 4px 14px;margin:12px 0;color:#334155;white-space:pre-wrap;">'
                . nl2br(htmlspecialchars((string) ($comment['content'] ?? ''))) . '</div>';

            $url = $this->absolute(\App\Core\Helpers::postUrl($post) . '#comment-' . ($comment['id'] ?? ''));
            $body .= '<p style="margin-top:18px;">' . $this->button('Read the reply', $url) . '</p>';

            $foot = 'You received this because you commented on ' . htmlspecialchars($this->siteTitle()) . '.';
            $this->mailer->sendTemplate($to, $subject, 'You have a new reply', $body, $foot);
        } catch (\Throwable) {
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get('discussion', $key, $default);
        if (is_bool($v)) return $v;
        return $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
    }

    private function adminEmail(): string
    {
        $email = trim((string) $this->settings->get('general', 'admin_email', ''));
        if ($email === '') {
            $cfg = $this->mailer->config();
            $email = trim((string) ($cfg['from_email'] ?? ''));
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function siteTitle(): string
    {
        return (string) $this->settings->get('general', 'site_title', 'Basehim');
    }

    private function baseUrl(): string
    {
        $url = trim((string) $this->config->get('app.url', ''));
        return $url !== '' ? rtrim($url, '/') : '';
    }

    private function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = $this->baseUrl();
        return $base !== '' ? $base . '/' . ltrim($path, '/') : $path;
    }

    private function adminUrl(string $path): string
    {
        return $this->absolute($path);
    }

    private function button(string $label, string $url): string
    {
        return '<a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#2563eb;color:#ffffff;'
            . 'text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:bold;font-size:13px;">'
            . htmlspecialchars($label) . '</a>';
    }
}
