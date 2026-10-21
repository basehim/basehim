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
            'description' => 'Quick overview of your site activity',
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
                'hint'  => sprintf('%d published · %s', $c['published'] ?? 0, self::plural($c['draft'] ?? 0, 'draft')),
                'href'  => $ctx->url('/admin/posts'),
                'tone'  => 'blue',
                'icon'  => 'document-text',
                'trend' => self::growth($c['total'], $c['recent']),
            ];
        }
        if ($ctx->can('edit_pages')) {
            $all = $ctx->can('edit_others_pages');
            $c = self::statusCounts($db, 'page', $all ? null : $uid, $since);
            $items[] = ['label' => $all ? 'Pages' : 'Your pages', 'value' => $c['total'],
                        'hint' => sprintf('%d published', $c['published'] ?? 0), 'href' => $ctx->url('/admin/pages'),
                        'tone' => 'violet', 'icon' => 'document', 'trend' => self::growth($c['total'], $c['recent'])];
        }
        if ($ctx->can('upload_media')) {
            $row = $db->selectOne('SELECT COUNT(*) AS c, COALESCE(SUM(file_size), 0) AS s, SUM(created_at >= :since) AS r FROM {media}', ['since' => $since]) ?? [];
            $items[] = ['label' => 'Media', 'value' => (int) ($row['c'] ?? 0),
                        'hint' => self::bytes((int) ($row['s'] ?? 0)) . ' in the library', 'href' => $ctx->url('/admin/media'),
                        'tone' => 'green', 'icon' => 'photo', 'trend' => self::growth((int) ($row['c'] ?? 0), (int) ($row['r'] ?? 0))];
        }
        if ($ctx->can('moderate_comments')) {
            $rows = $db->select("SELECT status, COUNT(*) AS c FROM {comments} WHERE status IN ('approved','pending') GROUP BY status");
            $by = array_column($rows, 'c', 'status');
            $pending = (int) ($by['pending'] ?? 0);
            $items[] = ['label' => 'Comments', 'value' => (int) ($by['approved'] ?? 0) + $pending,
                        'hint' => $pending > 0 ? "{$pending} waiting for review" : 'All reviewed',
                        'href' => $ctx->url('/admin/comments'), 'tone' => 'amber', 'icon' => 'chat-bubble-oval-left',
                        'badge' => $pending > 0 ? ['text' => (string) $pending, 'tone' => 'amber', 'icon' => 'clock'] : null];
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
            $items[] = ['label' => 'People', 'value' => $total, 'hint' => 'Active accounts',
                        'href' => $ctx->url('/admin/users'), 'tone' => 'indigo', 'icon' => 'user-group', 'wide' => true,
                        'people' => ['items' => $people, 'more' => max(0, $total - count($people))]];
        }
        return ['items' => $items];
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
        return ['direction' => 'up', 'text' => '+' . ($pct > 999 ? '999' : $pct) . '%', 'good' => true];
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
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
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
