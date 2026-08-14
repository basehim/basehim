<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HookRegistry;

class CommentService
{
    public function __construct(
        private Database $db,
        private HookRegistry $hooks,
        private SettingService $settings,
        private CommentNotifier $notifier
    ) {}

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM {comments} WHERE id = :id', ['id' => $id]);
    }

    public function forPost(int $postId, string $status = 'approved'): array
    {
        return $this->db->select(
            'SELECT * FROM {comments} WHERE post_id = :pid AND status = :s ORDER BY created_at ASC',
            ['pid' => $postId, 's' => $status]
        );
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.content LIKE :search OR c.author_name LIKE :search OR c.author_email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        $countRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM {comments} c WHERE {$whereSql}", $params);
        $total = (int)($countRow['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT c.*, p.title AS post_title, p.slug AS post_slug, p.type AS post_type
             FROM {comments} c
             LEFT JOIN {posts} p ON p.id = c.post_id
             WHERE {$whereSql}
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage))],
        ];
    }

    public function create(array $data): int
    {
        $data = $this->hooks->applyFilters('comment.before_create', $data);

        $postId = (int)$data['post_id'];

        // Validate the parent: it must exist, belong to THIS post, and be
        // approved (you can't reply to a hidden/cross-post comment).
        $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        $parent = null;
        if ($parentId) {
            $parent = $this->find($parentId);
            if (!$parent || (int)$parent['post_id'] !== $postId || ($parent['status'] ?? '') !== 'approved') {
                $parentId = null;
                $parent = null;
            }
        }

        $payload = [
            'post_id' => $postId,
            'parent_id' => $parentId,
            'author_id' => !empty($data['author_id']) ? (int)$data['author_id'] : null,
            'author_name' => $data['author_name'] ?? null,
            'author_email' => $data['author_email'] ?? null,
            'author_url' => self::safeAuthorUrl($data['author_url'] ?? null),
            'author_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'content' => $data['content'] ?? '',
            'status' => $data['status'] ?? 'pending',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ];

        $id = (int)$this->db->insert('comments', $payload);
        $comment = $this->find($id);
        $this->hooks->doAction('comment.created', $comment);

        // Notifications (best-effort).
        $post = $this->postRow($postId);
        if ($post) {
            $this->notifier->newComment($comment, $post);
            if ($parent && ($comment['status'] ?? '') === 'approved') {
                $this->notifier->reply($comment, $parent, $post);
            }
        }

        return $id;
    }

    public function setStatus(int $id, string $status): bool
    {
        $existing = $this->find($id);
        if (!$existing) return false;
        $this->db->update('comments', ['status' => $status], ['id' => $id]);
        $updated = $this->find($id);
        $this->hooks->doAction('comment.status_changed', $updated, $existing);

        // A reply becomes visible on approval — notify the parent's author then.
        if ($status === 'approved' && ($existing['status'] ?? '') !== 'approved' && !empty($updated['parent_id'])) {
            $parent = $this->find((int)$updated['parent_id']);
            $post = $this->postRow((int)$updated['post_id']);
            if ($parent && $post) {
                $this->notifier->reply($updated, $parent, $post);
            }
        }
        return true;
    }

    public function delete(int $id): bool
    {
        $existing = $this->find($id);
        if (!$existing) return false;
        $this->db->delete('comments', ['id' => $id]);
        $this->hooks->doAction('comment.deleted', $existing);
        return true;
    }

    public function counts(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS c FROM {comments} GROUP BY status');
        $out = ['total' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        return $out;
    }

    public function recent(int $limit = 5): array
    {
        $limit = (int)$limit;
        return $this->db->select(
            "SELECT c.*, p.title AS post_title, p.slug AS post_slug
             FROM {comments} c
             LEFT JOIN {posts} p ON p.id = c.post_id
             ORDER BY c.created_at DESC
             LIMIT {$limit}"
        );
    }

    /**
     * Normalise a commenter-supplied website URL, or drop it.
     *
     * The theme escapes this for HTML but escaping does not constrain a scheme:
     * `javascript:...` survives htmlspecialchars intact and runs when the
     * author's name is clicked. Only http(s) links are kept.
     */
    public static function safeAuthorUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;

        // Strip characters browsers ignore inside a scheme ("java\tscript:").
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        if (!preg_match('#^https?://#', $probe)) return null;

        $parts = parse_url($url);
        if (empty($parts['host'])) return null;
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return null;

        return mb_substr($url, 0, 255);
    }

    /**
     * Anti-abuse gate run before creating a public comment. Centralised here so
     * the web form and the REST API share identical protection.
     *
     * @param array $in  content, author_name, author_email, author_url,
     *                   honeypot, post_id, ip, and the proposed 'status'.
     * @return array{action:string, status?:string, message?:string}
     *   - accept: create with the returned status
     *   - reject: show the message to the user, don't create
     *   - drop:   silently pretend success (honeypot tripped) — don't create
     */
    public function guard(array $in): array
    {
        // 1. Honeypot — a hidden field only bots fill. Pretend success.
        if (trim((string)($in['honeypot'] ?? '')) !== '') {
            return ['action' => 'drop'];
        }

        $ip      = (string)($in['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        $content = trim((string)($in['content'] ?? ''));
        $email   = trim((string)($in['author_email'] ?? ''));
        $status  = (string)($in['status'] ?? 'pending');

        // 2. Flood control — reject rapid-fire posting from one IP.
        $flood = (int)$this->settings->get('discussion', 'comment_flood_seconds', 15);
        if ($flood > 0 && $ip !== '') {
            $last = $this->db->selectOne(
                'SELECT created_at FROM {comments} WHERE author_ip = :ip ORDER BY id DESC LIMIT 1',
                ['ip' => $ip]
            );
            if ($last && (time() - strtotime((string)$last['created_at'])) < $flood) {
                return ['action' => 'reject', 'message' => 'You are commenting too quickly — please wait a moment and try again.'];
            }
        }

        // 3. Duplicate — same author + body on the same post.
        if ($content !== '' && (int)($in['post_id'] ?? 0) > 0) {
            $dupe = $this->db->selectOne(
                'SELECT id FROM {comments} WHERE post_id = :p AND content = :c AND (author_email = :e OR author_ip = :ip) LIMIT 1',
                ['p' => (int)$in['post_id'], 'c' => $content, 'e' => $email, 'ip' => $ip]
            );
            if ($dupe) {
                return ['action' => 'reject', 'message' => 'Looks like you already said that — duplicate comment detected.'];
            }
        }

        // 4. Content rules. Blocklist → spam; moderation words / too many links → hold.
        $haystack = strtolower(implode("\n", [
            $content, $email,
            (string)($in['author_name'] ?? ''),
            (string)($in['author_url'] ?? ''),
        ]));

        if ($this->matchesList($haystack, (string)$this->settings->get('discussion', 'comment_blocklist', ''))) {
            return ['action' => 'accept', 'status' => 'spam'];
        }

        $maxLinks = (int)$this->settings->get('discussion', 'comment_max_links', 2);
        $linkCount = preg_match_all('#https?://#i', $content, $m);
        $overLinks = $maxLinks >= 0 && $linkCount > $maxLinks;

        if ($status !== 'spam' && ($overLinks
            || $this->matchesList($haystack, (string)$this->settings->get('discussion', 'comment_moderation_keys', '')))) {
            $status = 'pending';
        }

        return ['action' => 'accept', 'status' => $status];
    }

    /** True if any newline/comma-separated needle appears in $haystack. */
    private function matchesList(string $haystack, string $list): bool
    {
        $list = trim($list);
        if ($list === '') return false;
        foreach (preg_split('/[\r\n,]+/', $list) as $needle) {
            $needle = strtolower(trim($needle));
            if ($needle !== '' && str_contains($haystack, $needle)) return true;
        }
        return false;
    }

    /** Minimal post row used to build notification emails. */
    private function postRow(int $postId): ?array
    {
        return $this->db->selectOne(
            'SELECT id, title, slug, type, status FROM {posts} WHERE id = :id',
            ['id' => $postId]
        );
    }
}
