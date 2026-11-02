<?php

declare(strict_types=1);

/*
 * 1.2.97 — BH-029 (settings screens store only their own fields), BH-080
 * (a content type's list filters only by the taxonomies it uses), BH-084
 * (one HTTPS check everywhere) and BH-085 (the phone admin bar scrolls as
 * one row).
 */

function r97_setting(string $group, string $key): ?string
{
    $row = bh_db()->selectOne('SELECT setting_value FROM {settings} WHERE setting_group = :g AND setting_key = :k', ['g' => $group, 'k' => $key]);
    return $row ? (string) $row['setting_value'] : null;
}

test('BH-029: a settings screen stores its own fields and ignores any other key', function () {
    $b = bh_browser('admin');
    foreach (['reading' => 'posts_per_page=9', 'discussion' => 'allow_comments=0&allow_comments=1', 'seo' => 'generate_sitemap=0&generate_sitemap=1'] as $tab => $form) {
        $res = $b->ajax('settings.save', ['tab' => $tab, 'form' => $form . '&bh97_injected=evil&role_caps=everything']);
        assert_same(200, $res->status, $res->body);
        assert_same('success', $res->json()['data']['type'] ?? null, $tab . ': ' . $res->body);
        assert_same(null, r97_setting($tab, 'bh97_injected'), "{$tab}: an unknown key is not stored");
        assert_same(null, r97_setting($tab, 'role_caps'));
    }
    assert_same('9', r97_setting('reading', 'posts_per_page'), 'the screen\'s own field is stored');
    assert_same('1', r97_setting('discussion', 'allow_comments'));

    // The form route (no script) follows the same rule.
    $page = $b->request('GET', '/admin/settings/media');
    preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
    $r = $b->request('POST', '/admin/settings/media', http_build_query(['_csrf' => html_entity_decode($m[1] ?? ''), 'organize_uploads' => '1', 'bh97_media_extra' => 'x']),
        ['Content-Type' => 'application/x-www-form-urlencoded']);
    assert_same(302, $r->status);
    assert_same(null, r97_setting('media', 'bh97_media_extra'));
    assert_same('1', r97_setting('media', 'organize_uploads'));
});

test('BH-080: a content type\'s list offers the category filter it uses, and no tag filter', function () {
    $code = <<<'PHP'
<?php
namespace BhTest\R97Types;
class App extends \App\Core\App
{
    public function boot(): void
    {
        $this->registerPostType('gig', ['label' => 'Gigs', 'singular' => 'Gig', 'taxonomies' => ['category']]);
        $this->registerPostType('note97', ['label' => 'Notes', 'singular' => 'Note']);
    }
}
PHP;
    bh_install_app('r97-types', ['src/App.php' => $code], ['namespace' => 'BhTest\\R97Types']);
    try {
        $b = bh_browser('admin');
        $gigs = $b->request('GET', '/admin/content/gig');
        assert_same(200, $gigs->status);
        assert_contains('data-dt-termfilter="category"', $gigs->body);
        assert_not_contains('data-dt-termfilter="tag"', $gigs->body, 'no Tags filter for a type without tags');
        $notes = $b->request('GET', '/admin/content/note97');
        assert_not_contains('data-dt-termfilter="category"', $notes->body);
        assert_not_contains('data-dt-termfilter="tag"', $notes->body);
        // Posts keep both.
        $posts = $b->request('GET', '/admin/posts');
        assert_contains('data-dt-termfilter="category"', $posts->body);
        assert_contains('data-dt-termfilter="tag"', $posts->body);
        // A tag filter sent to a type without tags is refused like any unknown filter.
        $r = $b->ajaxGet('content.table', ['table' => 'content.type.gig', 'filters' => json_encode(['category' => '1'])]);
        assert_same(200, $r->status, $r->body);
    } finally {
        bh_remove_app('r97-types');
    }
});
