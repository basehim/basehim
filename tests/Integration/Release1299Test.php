<?php

declare(strict_types=1);

/*
 * 1.2.99, on the running test site: the home page's title is "Site name –
 * Tagline" with the separator from Settings → SEO, and the Customizer's
 * Site name / Site tagline switches and logo width reach the theme.
 */

function r99_settings(): \App\Services\SettingService
{
    return \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
}

/** Set settings for one test and put the old values back afterwards. */
function r99_with(array $values, callable $fn): void
{
    $s = r99_settings();
    $old = [];
    foreach ($values as $path => $v) {
        [$g, $k] = explode('.', $path, 2);
        $old[$path] = $s->get($g, $k, null);
        $s->set($g, $k, $v);
    }
    try { $fn(); } finally {
        foreach ($old as $path => $v) { [$g, $k] = explode('.', $path, 2); $s->set($g, $k, $v ?? ''); }
    }
}

function r99_title(string $html): string
{
    return preg_match('#<title>(.*?)</title>#s', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
}

test('1.2.99 home title: site name, the chosen separator, then the tagline; just the name without one', function () {
    r99_with(['general.site_title' => 'Northwind Studio', 'general.tagline' => 'Design and print', 'seo.title_separator' => '|',
              'seo.home_title' => '', 'reading.homepage_type' => 'posts'], function () {
        $res = bh_browser()->request('GET', '/');
        assert_same(200, $res->status);
        assert_same('Northwind Studio | Design and print', r99_title($res->body));
    });
    r99_with(['general.site_title' => 'Northwind Studio', 'general.tagline' => '', 'seo.home_title' => ''], function () {
        assert_same('Northwind Studio', r99_title(bh_browser()->request('GET', '/')->body), 'no dangling separator');
    });
    r99_with(['general.site_title' => 'Northwind Studio', 'general.tagline' => 'Design and print', 'seo.home_title' => 'Welcome %sep% %site%', 'seo.title_separator' => '·'], function () {
        assert_same('Welcome · Northwind Studio', r99_title(bh_browser()->request('GET', '/')->body), 'Settings → SEO → Home page title still wins');
    });
});

test('1.2.99 header: Site name and Site tagline can each be hidden; the logo width reaches the page as a variable', function () {
    r99_with(['general.site_title' => 'Northwind Studio', 'general.tagline' => 'Design and print',
              'appearance.show_site_title' => true, 'appearance.show_tagline' => true, 'appearance.logo_width' => 0], function () {
        $html = bh_browser()->request('GET', '/')->body;
        $header = (string) strstr((string) strstr($html, '<header'), '</header>', true);
        assert_contains('Northwind Studio</div>', $header);
        assert_contains('Design and print</div>', $header);
        assert_not_contains('--bh-logo-width', $html, 'Auto: the theme\'s own size');
    });
    r99_with(['general.site_title' => 'Northwind Studio', 'general.tagline' => 'Design and print',
              'appearance.show_site_title' => false, 'appearance.show_tagline' => true, 'appearance.logo_width' => 180], function () {
        $html = bh_browser()->request('GET', '/')->body;
        $header = (string) strstr((string) strstr($html, '<header'), '</header>', true);
        assert_not_contains('Northwind Studio</div>', $header, 'name hidden');
        assert_contains('aria-label="Northwind Studio"', $header, 'still named for screen readers when there is no logo');
        assert_contains('Design and print</div>', $header);
        assert_contains('--bh-logo-width: 180px;--bh-logo-height: auto', $html);
    });
    r99_with(['general.tagline' => 'Design and print', 'appearance.show_site_title' => true, 'appearance.show_tagline' => false], function () {
        $header = (string) strstr((string) strstr(bh_browser()->request('GET', '/')->body, '<header'), '</header>', true);
        assert_not_contains('Design and print', $header, 'tagline hidden');
    });
});

test('1.2.99 customizer screen: Site tagline, Logo width (Auto) and the two switches; saving them works', function () {
    $b = bh_browser('admin');
    $res = $b->request('GET', '/admin/customize');
    assert_same(200, $res->status);
    assert_contains('>Site tagline</label>', $res->body);
    assert_not_contains('Site description', $res->body);
    assert_contains('data-path="identity.logo_width"', $res->body);
    assert_contains('data-zero="Auto"', $res->body);
    assert_contains('Show in the header', $res->body);
    assert_contains('id="cz-identity-show-site-title"', $res->body);

    r99_with(['appearance.show_tagline' => true, 'appearance.logo_width' => 0], function () use ($b) {
        $save = $b->ajax('customizer.save', ['values' => json_encode(['identity.show_tagline' => false, 'identity.logo_width' => '120'])]);
        assert_same(200, $save->status, $save->body);
        assert_false((bool) r99_settings()->get('appearance', 'show_tagline', true));
        assert_same(120, (int) r99_settings()->get('appearance', 'logo_width', 0));
    });
});
