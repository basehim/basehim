<?php

declare(strict_types=1);

use App\Services\SeoHeadService;

/*
 * 1.2.88: an author page is a ProfilePage, and Google requires it to say whose
 * profile it is. Before, the ProfilePage had no mainEntity and the Rich
 * Results test reported 'Missing field "mainEntity"' as a critical issue.
 */

function seo_head_service(): SeoHeadService
{
    // Settings with nothing saved: every SEO group and JSON-LD type on (the defaults).
    $settings = new class extends \App\Services\SettingService {
        public function __construct() {}
        public function getGroup(string $group): array { return []; }
        public function get(string $group, string $key, $default = null) { return $default; }
    };
    return new SeoHeadService($settings, new \App\Core\HookRegistry());
}

function seo_author_head(array $author): array
{
    $_SERVER['HTTP_HOST'] = 'www.circuits-diy.com';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = '/author/afzal-rehmani';
    return seo_head_service()->build([
        'type' => 'author', 'template' => 'archive', 'post' => null, 'terms' => null, 'term' => null,
        'author' => $author, 'query' => null, 'page' => 1, 'is_preview' => false,
        'seo' => ['title' => 'Posts by Afzal Rehmani', 'description' => 'Afzal is the founder and editor of Circuits-DIY.com.',
                  'canonical' => 'https://www.circuits-diy.com/author/afzal-rehmani'],
    ]);
}

function seo_node(array $nodes, string $type): ?array
{
    foreach ($nodes as $n) if (($n['@type'] ?? '') === $type) return $n;
    return null;
}

test('seo: an author ProfilePage names its Person as mainEntity, and the Person is in the graph', function () {
    $head = seo_author_head([
        'id' => 3, 'display_name' => 'Afzal Rehmani', 'slug' => 'afzal-rehmani', 'username' => 'afzal-rehmani',
        'url' => '/author/afzal-rehmani', 'bio' => "Afzal is the founder and editor of Circuits-DIY.com,\nan electronics engineering community.",
        'avatar_url' => '/uploads/2026/05/afzal.jpg', 'post_count' => 412,
    ]);
    $page = seo_node($head['jsonld'], 'ProfilePage');
    $person = seo_node($head['jsonld'], 'Person');
    assert_true($page !== null, 'a ProfilePage');
    assert_true($person !== null, 'a Person node');
    assert_same(['@id' => 'https://www.circuits-diy.com/author/afzal-rehmani#person'], $page['mainEntity'] ?? null);
    assert_same('https://www.circuits-diy.com/author/afzal-rehmani#person', $person['@id']);
    assert_same('Afzal Rehmani', $person['name']);
    assert_same('https://www.circuits-diy.com/author/afzal-rehmani', $person['url']);
    assert_same('afzal-rehmani', $person['identifier']);
    assert_same('Afzal is the founder and editor of Circuits-DIY.com, an electronics engineering community.', $person['description']);
    assert_same('https://www.circuits-diy.com/uploads/2026/05/afzal.jpg', $person['image']['url']);
    assert_same(['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/WriteAction', 'userInteractionCount' => 412],
        $person['agentInteractionStatistic']);
});

test('seo: a minimal author (no bio, no avatar) still gets a valid mainEntity', function () {
    $head = seo_author_head(['id' => 9, 'display_name' => 'Sam', 'slug' => 'sam', 'url' => '/author/sam', 'bio' => '', 'avatar_url' => null, 'post_count' => 1]);
    $page = seo_node($head['jsonld'], 'ProfilePage');
    $person = seo_node($head['jsonld'], 'Person');
    assert_same(['@id' => 'https://www.circuits-diy.com/author/sam#person'], $page['mainEntity'] ?? null);
    assert_true(!isset($person['description']) && !isset($person['image']), 'empty fields are left out, not sent empty');
});

test('seo: the Person carries only the public profile, never account details', function () {
    $head = seo_author_head([
        'id' => 3, 'display_name' => 'Afzal Rehmani', 'slug' => 'afzal-rehmani', 'url' => '/author/afzal-rehmani',
        'bio' => '', 'avatar_url' => null, 'post_count' => 5,
        // Even if a theme or app passed more, none of it is copied.
        'email' => 'afzal@example.com', 'password_hash' => 'x', 'role' => 'administrator',
    ]);
    $json = json_encode($head['jsonld']);
    foreach (['afzal@example.com', 'password', 'administrator'] as $leak) {
        assert_true(!str_contains($json, $leak), "no {$leak} in the structured data");
    }
});

test('seo: other page types get no Person and no mainEntity', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';
    $head = seo_head_service()->build(['type' => 'archive', 'template' => 'archive', 'post' => null,
        'term' => ['name' => 'Projects', 'slug' => 'projects'], 'author' => null, 'page' => 1, 'seo' => ['canonical' => 'https://example.com/category/projects']]);
    assert_true(seo_node($head['jsonld'], 'Person') === null);
    $page = seo_node($head['jsonld'], 'CollectionPage');
    assert_true($page !== null && !isset($page['mainEntity']));
});
