<?php
declare(strict_types=1);

namespace Example\PageStats;

use App\Core\AdminBar\AdminBarContext;

/**
 * An analytics menu in the frontend admin bar (docs/ADMIN-BAR.md).
 *
 * - a top-level "Analytics" menu, for people who can edit posts
 * - a panel with the viewed page's numbers, loaded when the menu opens
 * - a badge with today's most-read count, loaded after the page
 *
 * The numbers come from core's view counter (posts.view_count), so the
 * example works on any site without collecting anything itself.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();

        $bar->menu([
            'id'         => 'analytics',
            'label'      => 'Analytics',
            'icon'       => 'chart-bar',
            'capability' => 'edit_posts',
            'priority'   => 60,
        ]);
        $bar->panel([
            'id'      => 'this-page',
            'parent'  => 'analytics',
            'label'   => 'This page',
            'context' => ['single'],                 // only on a post, page or item
            'data'    => fn(AdminBarContext $ctx, array $user) => $this->pagePanel($ctx),
        ]);
        $bar->item([
            'id'     => 'top',
            'parent' => 'analytics',
            'label'  => 'Most read',
            'icon'   => 'arrow-trending-up',
            'items'  => fn() => $this->topItems(),  // a list built when the bar is drawn
        ]);
        $bar->indicator([
            'id'     => 'total',
            'parent' => 'analytics',
            'type'   => 'text',
            'tone'   => 'info',
            'label'  => 'views on the top post',
            'value'  => fn() => $this->topViews(),
        ]);
    }

    private function pagePanel(AdminBarContext $ctx): array
    {
        $post = $this->api()->content($ctx->contentType())->find($ctx->contentId());
        if ($post === null) return ['empty' => 'No numbers for this page.'];
        $views = (int) ($post['view_count'] ?? 0);
        return [
            'rows' => [
                ['label' => 'Views', 'value' => number_format($views)],
                ['label' => 'Published', 'value' => !empty($post['published_at']) ? \App\Core\Time::format($post['published_at']) : 'not yet'],
            ],
            'actions' => [['label' => 'All posts', 'url' => '/admin/posts?sort=views_desc']],
        ];
    }

    private function topItems(): array
    {
        $out = [];
        foreach ($this->api()->posts()->paginate(['status' => 'published', 'sort' => 'views_desc'], 1, 5)['data'] ?? [] as $p) {
            $out[] = ['id' => 'p' . (int) $p['id'], 'label' => (string) $p['title'], 'meta' => number_format((int) $p['view_count']) . ' views',
                      'href' => '/admin/posts/' . (int) $p['id'] . '/edit'];
        }
        return $out;
    }

    private function topViews(): string
    {
        $top = $this->api()->posts()->paginate(['status' => 'published', 'sort' => 'views_desc'], 1, 1)['data'][0] ?? null;
        return $top ? number_format((int) $top['view_count']) : '';
    }
}
