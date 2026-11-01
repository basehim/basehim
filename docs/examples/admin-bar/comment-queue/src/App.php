<?php
declare(strict_types=1);

namespace Example\CommentQueue;

/**
 * Pending comments in the admin bar (docs/ADMIN-BAR.md): a menu for
 * moderators with a count badge, a panel listing the newest pending
 * comments, and a notice in the bell when any are waiting.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu([
            'id'         => 'discussion',
            'label'      => 'Discussion',
            'icon'       => 'chat-bubble-left-right',
            'capability' => 'moderate_comments',
            'priority'   => 61,
        ]);
        $bar->indicator([
            'id'     => 'pending',
            'parent' => 'discussion',
            'type'   => 'count',                    // a zero count shows no badge
            'label'  => 'comments waiting',
            'value'  => fn() => (int) ($this->api()->comments()->counts()['pending'] ?? 0),
        ]);
        $bar->panel([
            'id'     => 'latest',
            'parent' => 'discussion',
            'label'  => 'Waiting for approval',
            'data'   => fn() => $this->latest(),
        ]);
        $bar->item(['id' => 'all', 'parent' => 'discussion', 'label' => 'Moderate comments', 'icon' => 'check-badge',
                    'href' => '/admin/comments?status=pending']);
        $bar->notifications([
            'id'         => 'waiting',
            'capability' => 'moderate_comments',
            'items'      => function () {
                $n = (int) ($this->api()->comments()->counts()['pending'] ?? 0);
                return $n > 0 ? [['id' => 'pending', 'title' => $n . ' comment' . ($n === 1 ? ' is' : 's are') . ' waiting', 'tone' => 'warning',
                                  'url' => '/admin/comments?status=pending']] : [];
            },
        ]);
    }

    private function latest(): array
    {
        $rows = $this->api()->comments()->paginate(['status' => 'pending'], 1, 5)['data'] ?? [];
        if (!$rows) return ['empty' => 'No comments are waiting.'];
        return [
            'list' => array_map(fn($c) => [
                'title' => mb_strimwidth(strip_tags((string) $c['content']), 0, 80, '…'),
                'meta'  => (string) ($c['author_name'] ?? 'Someone'),
                'url'   => '/admin/comments?status=pending',
            ], $rows),
            'actions' => [['label' => 'Moderate', 'url' => '/admin/comments?status=pending']],
        ];
    }
}
