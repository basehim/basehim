<?php
declare(strict_types=1);

namespace Example\SeoCheck;

use App\Core\AdminBar\AdminBarContext;

/**
 * Contextual SEO in the admin bar (docs/ADMIN-BAR.md): a score panel and an
 * item in core's page menu ("context"), only on posts and pages, only for
 * people who manage SEO. The score is a simple, honest check of the title,
 * excerpt and length, enough to show the shape of a real one.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->panel([
            'id'         => 'score',
            'parent'     => 'context',             // core's page menu: "Edit post", "Edit page"
            'label'      => 'SEO score',
            'context'    => ['post', 'page'],
            'capability' => 'manage_seo',
            'priority'   => 6,                     // just under core's Details panel
            'data'       => fn(AdminBarContext $ctx) => $this->score($ctx),
        ]);
        $bar->item([
            'id'         => 'edit',
            'parent'     => 'context',
            'label'      => 'SEO settings',
            'icon'       => 'document-magnifying-glass',
            'group'      => 'SEO',
            'context'    => ['post', 'page'],
            'capability' => 'manage_seo',
            'href'       => '/admin/settings/seo',
            'priority'   => 70,
        ]);
    }

    private function score(AdminBarContext $ctx): array
    {
        $post = $this->api()->content($ctx->contentType())->find($ctx->contentId());
        if ($post === null) return ['empty' => 'Nothing to check.'];
        $checks = [];
        $title = mb_strlen((string) $post['title']);
        $checks[] = [$title >= 30 && $title <= 65, $title < 30 ? 'The title is short (' . $title . ' characters)' : ($title > 65 ? 'The title may be cut off in results' : 'Title length is good')];
        $checks[] = [trim(strip_tags((string) ($post['excerpt'] ?? ''))) !== '', 'An excerpt is ' . (trim((string) ($post['excerpt'] ?? '')) !== '' ? 'set' : 'missing') . ' (used as the description)'];
        $words = str_word_count(strip_tags((string) ($post['content'] ?? '')));
        $checks[] = [$words >= 300, number_format($words) . ' words' . ($words < 300 ? ': thin for search' : '')];
        $passed = count(array_filter($checks, fn($c) => $c[0]));
        $score = (int) round($passed / count($checks) * 100);
        return [
            'score' => ['value' => $score, 'max' => 100, 'label' => 'SEO score', 'tone' => $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger')],
            'list'  => array_map(fn($c) => ['title' => $c[1], 'tone' => $c[0] ? 'success' : 'warning'], $checks),
        ];
    }
}
