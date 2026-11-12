<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Application;
use App\Core\Database;
use App\Core\WidgetRegistry;

/**
 * Core's own dashboard cards (1.2.68) — registered exactly the way an app
 * registers its cards, so they double as the reference examples
 * (docs/DASHBOARD-WIDGETS.md):
 *
 *   core.dashboard.at-a-glance          stat    counts, each behind its own capability
 *   core.dashboard.recent-posts         list    newest posts this user may edit (settings)
 *   core.dashboard.recent-comments      feed    newest comments, for moderators
 *   core.dashboard.publishing-activity  chart   posts published per month (settings)
 *   core.dashboard.site-health          status  the System page's checks, for admins
 *   core.dashboard.my-activity          feed    the user's own recent activity, for everyone
 *
 * Before 1.2.68 the dashboard showed the first three to every signed-in user,
 * drafts and pending comments included (issues.md BH-001).
 */
final class CoreDashboardWidgets
{
    public static function register(WidgetRegistry $reg): void
    {
        $reg->register('core.dashboard.at-a-glance', [
            'title'       => 'At a glance',
            // Says what the charts and the pills cover (1.2.128).
            'description' => 'Charts: last 12 weeks · changes: last 30 days',
            'icon'        => 'chart-bar',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'read',
            'dashboard'   => [
                'view'     => 'stat',
                'priority' => 10,
                'sizes'    => ['half', 'two-thirds', 'full'],
                'size'     => 'full',
                'cache'    => ['ttl' => 60, 'scope' => 'user'],
                // Nothing to count for someone who manages none of it.
                'visible'  => static fn(array $u): bool => self::anyCap($u, ['edit_posts', 'edit_pages', 'upload_media', 'moderate_comments', 'manage_users']),
                'data'     => [self::class, 'atAGlance'],
                'empty'    => ['title' => 'Nothing to count yet', 'text' => 'Counts appear once there is content you can manage.'],
            ],
        ]);

        $reg->register('core.dashboard.recent-posts', [
            'title'       => 'Recent posts',
            'description' => 'The newest posts you can edit',
            'icon'        => 'newspaper',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'edit_posts',
            'fields'      => [
                ['key' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'min' => 3, 'max' => 15, 'default' => 5],
                ['key' => 'status', 'label' => 'Show', 'type' => 'select', 'default' => 'any', 'options' => [
                    'any' => 'All statuses', 'published' => 'Published', 'draft' => 'Drafts',
                    'pending' => 'Pending review', 'scheduled' => 'Scheduled',
                ]],
            ],
            'dashboard'   => [
                'view'     => 'list',
                'priority' => 20,
                'sizes'    => ['third', 'half', 'two-thirds', 'full'],
                'size'     => 'two-thirds',
                'data'     => [self::class, 'recentPosts'],
                'actions'  => [
                    ['id' => 'all', 'label' => 'View all posts', 'url' => self::path('/admin/posts'), 'icon' => 'list-bullet'],
                    ['id' => 'new', 'label' => 'Write a post', 'url' => self::path('/admin/posts/create'), 'icon' => 'pencil-square'],
                ],
                'empty'    => ['title' => 'No posts yet', 'text' => 'Posts you write show up here.',
                               'action' => ['label' => 'Write a post', 'url' => self::path('/admin/posts/create')]],
            ],
        ]);

        $reg->register('core.dashboard.recent-comments', [
            'title'       => 'Recent comments',
            'description' => 'New comments, and the ones waiting for you',
            'icon'        => 'chat-bubble-left-right',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'moderate_comments',
            'fields'      => [
                ['key' => 'count', 'label' => 'Number of comments', 'type' => 'number', 'min' => 3, 'max' => 15, 'default' => 5],
                ['key' => 'status', 'label' => 'Show', 'type' => 'select', 'default' => 'recent', 'options' => [
                    'recent' => 'Approved and pending', 'pending' => 'Pending only', 'approved' => 'Approved only',
                ]],
            ],
            'dashboard'   => [
                'view'     => 'feed',
                'priority' => 30,
                'tone'     => 'amber',
                'sizes'    => ['third', 'half', 'full'],
                'size'     => 'third',
                'refresh'  => 300,
                'data'     => [self::class, 'recentComments'],
                'actions'  => [
                    ['id' => 'moderate', 'label' => 'Moderate comments', 'url' => self::path('/admin/comments'), 'icon' => 'chat-bubble-left-right'],
                ],
                'empty'    => ['title' => 'No comments yet', 'text' => 'New comments land here so you can reply or moderate them.'],
            ],
        ]);

        $reg->register('core.dashboard.publishing-activity', [
            'title'       => 'Publishing activity',
            'description' => 'Total posts published over time',
            'icon'        => 'calendar',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'edit_posts',
            'fields'      => [
                // 'inline': the period picker sits in the card's header (1.2.77).
                ['key' => 'months', 'label' => 'Period', 'type' => 'select', 'default' => '6', 'inline' => true, 'options' => [
                    '6' => 'Last 6 months', 'year' => 'This year', '12' => 'Last 12 months',
                ]],
            ],
            'dashboard'   => [
                'view'     => 'chart',
                'priority' => 40,
                'sizes'    => ['third', 'half', 'two-thirds', 'full'],
                'size'     => 'third',
                'cache'    => ['ttl' => 300, 'scope' => 'user'],
                'data'     => [self::class, 'publishingActivity'],
                'empty'    => ['title' => 'Nothing published in this period', 'text' => 'Published posts are counted by month.'],
            ],
        ]);

        $reg->register('core.dashboard.site-health', [
            'title'       => 'Site health',
            'description' => 'Checks from the System page',
            'icon'        => 'heart',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'manage_settings',
            'dashboard'   => [
                'view'     => 'status',
                'priority' => 50,
                'tone'     => 'green',
                'sizes'    => ['third', 'half'],
                'size'     => 'third',
                'cache'    => ['ttl' => 300, 'scope' => 'global'],
                'data'     => [self::class, 'siteHealth'],
                'actions'  => [
                    ['id' => 'system', 'label' => 'Open System', 'url' => self::path('/admin/system'), 'icon' => 'heart'],
                ],
            ],
        ]);

        $reg->register('core.dashboard.my-activity', [
            'title'       => 'Your recent activity',
            'description' => 'Your sign-ins and changes, newest first',
            'icon'        => 'clock',
            'source'      => 'core',
            'surfaces'    => ['dashboard'],
            'capability'  => 'read',
            'fields'      => [
                ['key' => 'count', 'label' => 'Number of entries', 'type' => 'number', 'min' => 3, 'max' => 20, 'default' => 6],
            ],
            'dashboard'   => [
                'view'     => 'feed',
                'priority' => 60,
                'tone'     => 'violet',
                'sizes'    => ['third', 'half', 'full'],
                'size'     => 'third',
                'data'     => [self::class, 'myActivity'],
                'actions'  => [
                    ['id' => 'profile', 'label' => 'Open my profile', 'url' => self::path('/admin/profile'), 'icon' => 'user'],
                ],
                'empty'    => ['title' => 'No activity yet', 'text' => 'Sign-ins and changes you make are listed here.'],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Providers
    // ------------------------------------------------------------------

    public static function atAGlance(WidgetContext $ctx): array
    {
        $db = self::db();
        $items = [];
        $uid = $ctx->userId();
        // Growth over the last 30 days, for the trend pills.
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);

        if ($ctx->can('edit_posts')) {
            $all = $ctx->can('edit_others_posts');
            $c = self::statusCounts($db, 'post', $all ? null : $uid, $since);
            $items[] = [
                'label' => $all ? 'Posts' : 'Your posts',
                'value' => $c['total'],
                'hint'  => sprintf('%s published · %s', number_format((int) ($c['published'] ?? 0)), self::plural((int) ($c['draft'] ?? 0), 'draft')),
                'href'  => $ctx->url('/admin/posts'),
                'tone'  => 'blue',
                'icon'  => 'document-text',
                'trend' => self::growth($c['total'], $c['recent']),
                'chart' => self::weeklyTrend($db, 'posts', $all ? null : $uid),
            ];
        }
        if ($ctx->can('edit_pages')) {
            $all = $ctx->can('edit_others_pages');
            $c = self::statusCounts($db, 'page', $all ? null : $uid, $since);
            // The legend already says how many are published; the hint says
            // when a page last changed (1.2.128).
            $sql = "SELECT MAX(updated_at) AS u FROM {posts} WHERE type = 'page' AND deleted_at IS NULL";
            $params = [];
            if (!$all) { $sql .= ' AND author_id = :a'; $params['a'] = $uid; }
            $updated = $db->selectOne($sql, $params)['u'] ?? null;
            $items[] = ['label' => $all ? 'Pages' : 'Your pages', 'value' => $c['total'],
                        'hint' => $updated ? 'Last updated ' . \App\Core\Time::ago($updated, 30) : 'No pages yet',
                        'href' => $ctx->url('/admin/pages'),
                        'tone' => 'violet', 'icon' => 'document', 'trend' => self::growth($c['total'], $c['recent']),
                        'chart' => self::statusSplit($c)];
        }
        if ($ctx->can('upload_media')) {
            $row = $db->selectOne('SELECT COUNT(*) AS c, COALESCE(SUM(file_size), 0) AS s, SUM(created_at >= :since) AS r FROM {media}', ['since' => $since]) ?? [];
            $items[] = ['label' => 'Media', 'value' => (int) ($row['c'] ?? 0),
                        'hint' => self::bytes((int) ($row['s'] ?? 0)) . ' in the library', 'href' => $ctx->url('/admin/media'),
                        'tone' => 'green', 'icon' => 'photo', 'trend' => self::growth((int) ($row['c'] ?? 0), (int) ($row['r'] ?? 0)),
                        'chart' => self::mediaSplit($db)];
        }
        if ($ctx->can('moderate_comments')) {
            $rows = $db->select("SELECT status, COUNT(*) AS c, SUM(created_at >= :since) AS r FROM {comments} WHERE status IN ('approved','pending') GROUP BY status", ['since' => $since]);
            $by = array_column($rows, 'c', 'status');
            $pending = (int) ($by['pending'] ?? 0);
            $total = (int) ($by['approved'] ?? 0) + $pending;
            $recent = (int) array_sum(array_column($rows, 'r'));
            // The hint carries the review count; the pill is the comments'
            // trend like every other tile (1.2.128). Until then the pill
            // repeated the hint's number.
            $items[] = ['label' => 'Comments', 'value' => $total,
                        'hint' => $pending > 0 ? number_format($pending) . ' waiting for review' : 'All reviewed',
                        'href' => $ctx->url('/admin/comments'), 'tone' => $pending > 0 ? 'amber' : 'blue', 'icon' => 'chat-bubble-oval-left',
                        'trend' => self::growth($total, $recent),
                        'chart' => self::weeklyTrend($db, 'comments', null)];
        }
        if ($ctx->can('manage_users')) {
            $row = $db->selectOne("SELECT COUNT(*) AS c FROM {users} WHERE status = 'active' AND deleted_at IS NULL") ?? [];
            $total = (int) ($row['c'] ?? 0);
            $faces = $db->select(
                "SELECT u.display_name, u.username, m.url AS avatar_url, m.sizes AS avatar_sizes
                   FROM {users} u LEFT JOIN {media} m ON m.id = u.avatar_media_id
                  WHERE u.status = 'active' AND u.deleted_at IS NULL
                  ORDER BY u.last_login_at IS NULL, u.last_login_at DESC, u.id DESC LIMIT 3"
            );
            $people = array_map(fn($u) => [
                'name' => (string) (($u['display_name'] ?? '') !== '' ? $u['display_name'] : $u['username']),
                'url'  => !empty($u['avatar_url']) ? \App\Services\AvatarService::pick((string) $u['avatar_url'], $u['avatar_sizes'] ?? null, 64) : null,
            ], $faces);
            $seen = (int) ($db->selectOne(
                "SELECT COUNT(*) AS c FROM {users} WHERE status = 'active' AND deleted_at IS NULL AND last_login_at >= :since",
                ['since' => $since]
            )['c'] ?? 0);
            $items[] = ['label' => 'People', 'value' => $total, 'hint' => 'Active accounts',
                        'href' => $ctx->url('/admin/users'), 'tone' => 'indigo', 'icon' => 'user-group', 'wide' => true,
                        'people' => ['items' => $people, 'more' => max(0, $total - count($people))],
                        // Who has signed in lately (1.2.128).
                        'chart' => $total > 0 ? ['type' => 'split', 'label' => 'Accounts by last sign-in',
                            'parts' => [
                                ['label' => 'Signed in recently', 'value' => $seen],
                                ['label' => 'Not in 30 days', 'value' => $total - $seen],
                            ]] : null];
        }
        return ['items' => $items];
    }

    // ------------------------------------------------------------------
    // Tile charts (1.2.128). Each tile shows a small chart of its own data in
    // place of the three decorative bars it had before.
    // ------------------------------------------------------------------

    /** Weeks a tile's trend covers, newest last. */
    private const TREND_WEEKS = 12;

    /**
     * Posts published, or comments received (approved or waiting; spam and
     * trash are left out), in each of the last 12 weeks. Weeks are counted
     * back from now, so the newest is always a full seven days.
     * A post's week is its publish time; a comment's, when it was written.
     *
     * @return array{type:string,label:string,points:list<array{value:int,label:string}>}|null
     */
    private static function weeklyTrend(Database $db, string $what, ?int $authorId): ?array
    {
        $now = time();
        $from = $now - self::TREND_WEEKS * 7 * 86400;
        $since = gmdate('Y-m-d H:i:s', $from);
        if ($what === 'posts') {
            $sql = "SELECT published_at AS at FROM {posts}
                     WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL AND published_at >= :since";
            $params = ['since' => $since];
            if ($authorId !== null) { $sql .= ' AND author_id = :a'; $params['a'] = $authorId; }
            $noun = ['post published', 'posts published'];
        } else {
            $sql = "SELECT created_at AS at FROM {comments} WHERE status IN ('approved','pending') AND created_at >= :since";
            $params = ['since' => $since];
            $noun = ['comment', 'comments'];
        }
        $counts = array_fill(0, self::TREND_WEEKS, 0);
        foreach ($db->select($sql, $params) as $r) {
            $t = strtotime((string) $r['at'] . ' UTC');
            if ($t === false || $t > $now) continue;
            $i = self::TREND_WEEKS - 1 - intdiv($now - $t, 7 * 86400);
            if ($i >= 0) $counts[$i]++;
        }
        $empty = $what === 'posts' ? 'No posts published in the last 12 weeks' : 'No comments in the last 12 weeks';

        $points = [];
        foreach ($counts as $i => $n) {
            $start = $now - (self::TREND_WEEKS - $i) * 7 * 86400;
            $points[] = ['value' => $n, 'label' => 'Week of ' . \App\Core\Time::date(gmdate('Y-m-d H:i:s', $start), 'j M') . ': '
                . number_format($n) . ' ' . $noun[$n === 1 ? 0 : 1]];
        }
        return [
            'type'   => $what === 'posts' ? 'line' : 'bars',
            'label'  => ucfirst($noun[1]) . ' per week, last ' . self::TREND_WEEKS . ' weeks',
            'points' => $points,
            'empty'  => array_sum($counts) === 0 ? $empty : '',
        ];
    }

    /** Pages by status: published, drafts, and the rest (waiting, scheduled, private). */
    private static function statusSplit(array $c): ?array
    {
        $published = (int) ($c['published'] ?? 0);
        $draft = (int) ($c['draft'] ?? 0);
        $other = max(0, (int) $c['total'] - $published - $draft);
        return ['type' => 'split', 'label' => 'Pages by status', 'empty' => $c['total'] <= 0 ? 'No pages yet' : '', 'parts' => array_values(array_filter([
            ['label' => 'Published', 'value' => $published],
            ['label' => 'Drafts', 'value' => $draft],
            ['label' => 'Other', 'value' => $other],
        ], fn($p) => $p['value'] > 0))];
    }

    /** The library's size by kind of file, largest first. */
    private static function mediaSplit(Database $db): ?array
    {
        $rows = $db->select(
            "SELECT CASE WHEN mime_type LIKE 'image/%' THEN 'Images'
                         WHEN mime_type LIKE 'video/%' OR mime_type LIKE 'audio/%' THEN 'Video and audio'
                         WHEN mime_type = 'application/pdf' OR mime_type LIKE 'text/%'
                              OR mime_type LIKE 'application/vnd.%' OR mime_type LIKE 'application/msword%' THEN 'Documents'
                         ELSE 'Other' END AS kind,
                    COALESCE(SUM(file_size), 0) AS bytes, COUNT(*) AS n
               FROM {media} GROUP BY kind"
        );
        $parts = [];
        foreach ($rows as $r) {
            if ((int) $r['bytes'] <= 0) continue;
            $parts[] = ['label' => (string) $r['kind'], 'value' => (int) $r['bytes'],
                        'note' => number_format((int) $r['n']) . ' ' . ((int) $r['n'] === 1 ? 'file' : 'files') . ', ' . self::bytes((int) $r['bytes'])];
        }
        if (!$parts) return ['type' => 'split', 'label' => 'Library size by kind of file', 'parts' => [], 'empty' => 'Nothing uploaded yet'];
        usort($parts, fn($a, $b) => $b['value'] <=> $a['value']);
        // Three at most, so the legend stays short on a phone: the two
        // largest, and the rest together as "Other".
        if (count($parts) > 3) {
            $rest = array_slice($parts, 2);
            $bytes = array_sum(array_column($rest, 'value'));
            $parts = array_slice($parts, 0, 2);
            $parts[] = ['label' => 'Other', 'value' => $bytes,
                        'note' => implode(', ', array_column($rest, 'label')) . ': ' . self::bytes($bytes)];
        }
        return ['type' => 'split', 'label' => 'Library size by kind of file', 'unit' => 'percent', 'parts' => $parts];
    }

    /**
     * A trend pill: how much the count grew in the last 30 days, against what
     * there was before them. None when nothing is new, or nothing came before.
     */
    private static function growth(int $total, int $recent): ?array
    {
        $before = $total - $recent;
        if ($recent <= 0 || $before <= 0) return null;
        $pct = (int) round($recent / $before * 100);
        if ($pct < 1) return null;
        // Past 999% a percentage says only that little came before (a young
        // site). The count says more: "+10 new". Until 1.2.126 this read
        // "+999%", a cap that looked like a real figure.
        // From 1.2.128 a count is shown whenever fewer than ten came before:
        // 5 files becoming 18 read as "+260%", which alarms more than it says.
        $text = ($pct > 999 || $before < 10) ? '+' . number_format($recent) . ' new' : '+' . $pct . '%';
        return ['direction' => 'up', 'text' => $text, 'good' => true];
    }

    public static function recentPosts(WidgetContext $ctx): array
    {
        $limit = max(3, min(15, (int) $ctx->setting('count', 5)));
        $status = (string) $ctx->setting('status', 'any');
        $where = ["p.type = 'post'", 'p.deleted_at IS NULL'];
        $params = [];
        if (!$ctx->can('edit_others_posts')) {
            $where[] = 'p.author_id = :uid';
            $params['uid'] = $ctx->userId();
        }
        if ($status !== 'any') {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $rows = self::db()->select(
            'SELECT p.id, p.title, p.status, p.created_at, p.published_at, u.display_name AS author_name
             FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id
             WHERE ' . implode(' AND ', $where) . " ORDER BY p.created_at DESC LIMIT {$limit}",
            $params
        );
        $tones = ['published' => 'green', 'draft' => 'slate', 'pending' => 'amber', 'scheduled' => 'blue', 'private' => 'violet'];
        return ['items' => array_map(fn($p) => [
            'title' => trim((string) $p['title']) !== '' ? (string) $p['title'] : '(no title)',
            'href'  => $ctx->url('/admin/posts/' . (int) $p['id'] . '/edit'),
            'meta'  => 'by ' . ($p['author_name'] ?? 'Unknown'),
            'time'  => (string) ($p['published_at'] ?: $p['created_at']),
            'badge' => ['text' => ucfirst((string) $p['status']), 'tone' => $tones[$p['status']] ?? 'slate'],
        ], $rows)];
    }

    public static function recentComments(WidgetContext $ctx): array
    {
        $limit = max(3, min(15, (int) $ctx->setting('count', 5)));
        $status = (string) $ctx->setting('status', 'recent');
        $statuses = match ($status) {
            'pending'  => "('pending')",
            'approved' => "('approved')",
            default    => "('approved','pending')",
        };
        $rows = self::db()->select(
            "SELECT c.id, c.author_name, c.content, c.status, c.created_at, p.title AS post_title
             FROM {comments} c LEFT JOIN {posts} p ON p.id = c.post_id
             WHERE c.status IN {$statuses}
             ORDER BY c.created_at DESC LIMIT {$limit}"
        );
        return ['items' => array_map(fn($c) => [
            'actor' => (string) ($c['author_name'] ?: 'Anonymous'),
            'text'  => 'on ' . ($c['post_title'] ?? '(deleted post)') . ($c['status'] === 'pending' ? ' — waiting for review' : ''),
            'quote' => mb_substr(trim(strip_tags((string) $c['content'])), 0, 160),
            'time'  => (string) $c['created_at'],
            'tone'  => $c['status'] === 'pending' ? 'amber' : 'slate',
            'href'  => $ctx->url('/admin/comments'),
        ], $rows)];
    }

    public static function publishingActivity(WidgetContext $ctx): array
    {
        $period = (string) $ctx->setting('months', '6');
        $start = new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('UTC'));
        $months = $period === 'year' ? (int) $start->format('n') : ($period === '12' ? 12 : 6);
        $start = $start->modify('-' . ($months - 1) . ' months');
        $params = ['from' => $start->format('Y-m-d H:i:s')];
        $own = '';
        if (!$ctx->can('edit_others_posts')) {
            $own = ' AND author_id = :uid';
            $params['uid'] = $ctx->userId();
        }
        // Grouped in PHP rather than with DATE_FORMAT, so it reads the same on
        // every MySQL-compatible server.
        $rows = self::db()->select(
            "SELECT published_at FROM {posts}
             WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL
               AND published_at >= :from{$own}",
            $params
        );
        $buckets = [];
        $labels = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->modify("+{$i} months");
            $buckets[$m->format('Y-m')] = 0;
            $labels[] = $m->format('M');
        }
        foreach ($rows as $r) {
            $k = substr((string) $r['published_at'], 0, 7);
            if (isset($buckets[$k])) $buckets[$k]++;
        }
        $total = array_sum($buckets);
        return [
            'type'    => 'bar',
            'labels'  => $labels,
            'series'  => [['name' => 'Posts published', 'values' => array_values($buckets)]],
            'summary' => ($total === 1 ? '1 post published' : "{$total} posts published")
                . ' since ' . $start->format('F Y'),
            'summary_icon' => 'document-text',
        ];
    }

    public static function siteHealth(WidgetContext $ctx): array
    {
        $app = Application::getInstance();
        $checks = $app->make(\App\Services\SystemInfoService::class)->healthChecks();
        $map = ['pass' => 'ok', 'warn' => 'warn', 'fail' => 'error'];
        // Problems first; passing checks folded into one line so the card stays short.
        $items = [];
        $passing = 0;
        foreach ($checks as $c) {
            $state = $map[$c['status'] ?? ''] ?? 'unknown';
            if ($state === 'ok') { $passing++; continue; }
            $items[] = ['label' => (string) $c['label'], 'state' => $state, 'detail' => (string) ($c['detail'] ?? '')];
        }
        try {
            $updates = $app->make(\App\Services\UpdateService::class)->badgeCount();
            $items[] = $updates > 0
                ? ['label' => 'Updates', 'state' => 'warn', 'detail' => $updates === 1 ? '1 update available' : "{$updates} updates available", 'href' => $ctx->url('/admin/updates')]
                : ['label' => 'Updates', 'state' => 'ok', 'detail' => 'Up to date'];
        } catch (\Throwable) {
        }
        array_unshift($items, ['label' => 'Checks passing', 'state' => 'ok', 'detail' => $passing . ' of ' . count($checks)]);
        $problems = count(array_filter($items, fn($i) => $i['state'] !== 'ok'));
        return [
            'items'   => $items,
            'summary' => $problems === 0 ? 'Everything looks good.' : ($problems === 1 ? '1 thing needs a look.' : "{$problems} things need a look."),
        ];
    }

    public static function myActivity(WidgetContext $ctx): array
    {
        $limit = max(3, min(20, (int) $ctx->setting('count', 6)));
        $rows = $ctx->make(\App\Services\ActivityLogService::class)->forUser($ctx->userId(), 'all', 1, $limit)['data'] ?? [];
        return ['items' => array_map(fn($r) => [
            'text'  => self::eventText((string) $r['event'], (string) ($r['detail'] ?? '')),
            'time'  => (string) $r['created_at'],
            'tone'  => str_contains((string) $r['event'], 'fail') ? 'red' : 'slate',
        ], $rows)];
    }

    // ------------------------------------------------------------------

    private static function eventText(string $event, string $detail): string
    {
        $label = self::eventLabel($event);
        $detail = trim($detail);
        // Sign-in events carry a detail that repeats the label ("Signed in").
        if ($detail === '' || str_starts_with($event, 'auth.') || stripos($label, $detail) !== false) return $label;
        return $label . ': ' . $detail;
    }

    private static function eventLabel(string $event): string
    {
        $known = [
            'auth.login_success' => 'Signed in', 'auth.logout' => 'Signed out', 'auth.login_failed' => 'Failed sign-in attempt',
            'auth.login_blocked' => 'Sign-in blocked', 'auth.password_changed' => 'Changed your password',
            'auth.password_reset' => 'Reset your password', 'auth.signed_out_everywhere' => 'Signed out everywhere',
            'auth.two_factor_enabled' => 'Turned on two-step sign-in', 'auth.two_factor_disabled' => 'Turned off two-step sign-in',
            'auth.two_factor_failed' => 'Wrong sign-in code', 'auth.two_factor_sent' => 'Sign-in code sent',
            'post.created' => 'Created a post', 'post.updated' => 'Updated a post', 'post.deleted' => 'Deleted a post',
            'page.created' => 'Created a page', 'page.updated' => 'Updated a page', 'page.deleted' => 'Deleted a page',
            'user.updated' => 'Updated an account', 'user.email_changed' => 'Changed an email address',
            'user.role_changed' => 'Changed a role', 'app.installed' => 'Installed an app', 'theme.installed' => 'Installed a theme',
            'update.installed' => 'Installed an update', 'system.updated' => 'Updated Basehim',
        ];
        if (isset($known[$event])) return $known[$event];
        $parts = explode('.', $event, 2);
        return ucfirst(str_replace('_', ' ', $parts[1] ?? $parts[0])) . (isset($parts[1]) ? ' (' . $parts[0] . ')' : '');
    }

    /** @return array<string,int> status => count, plus 'total' and 'recent' (created since $since) */
    private static function statusCounts(Database $db, string $type, ?int $authorId, ?string $since = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS c, SUM(created_at >= :since) AS r FROM {posts} WHERE type = :t AND deleted_at IS NULL';
        $params = ['t' => $type, 'since' => $since ?? '9999-12-31 00:00:00'];
        if ($authorId !== null) {
            $sql .= ' AND author_id = :a';
            $params['a'] = $authorId;
        }
        $out = ['total' => 0, 'recent' => 0];
        foreach ($db->select($sql . ' GROUP BY status', $params) as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
            $out['total'] += (int) $r['c'];
            $out['recent'] += (int) $r['r'];
        }
        return $out;
    }

    private static function plural(int $n, string $word): string
    {
        // Grouped like the tile's number ("4,622"), which the browser formats.
        return number_format($n) . ' ' . $word . ($n === 1 ? '' : 's');
    }

    private static function anyCap(array $user, array $caps): bool
    {
        foreach ($caps as $c) {
            if (\App\Http\Middleware\CheckCapability::userCan($user, $c)) return true;
        }
        return false;
    }

    private static function bytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
        return round($v, 1) . ' ' . $u[$i];
    }

    private static function path(string $p): string
    {
        return (defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '') . $p;
    }

    private static function db(): Database
    {
        return Application::getInstance()->make(Database::class);
    }
}
