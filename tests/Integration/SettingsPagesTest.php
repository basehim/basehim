<?php

declare(strict_types=1);

/*
 * 1.2.82 — Settings pages: cards and rows, switches instead of checkboxes,
 * the phone's Settings home, and settings.section (moving between sections
 * without a page load).
 */

function sp_value(string $group, string $key): ?string
{
    $row = bh_db()->selectOne('SELECT setting_value FROM {settings} WHERE setting_group = :g AND setting_key = :k', ['g' => $group, 'k' => $key]);
    return $row ? (string) $row['setting_value'] : null;
}

// Appearance's address redirects to the Customizer (it is a link there, not a section).
const SP_TABS = ['general', 'reading', 'writing', 'discussion', 'permalinks', 'media', 'seo', 'ai', 'email', 'authorization'];

test('settings pages: every section is built of cards and rows, with switches and no bare checkboxes', function () {
    $b = bh_browser('admin');
    foreach (SP_TABS as $tab) {
        $html = $b->request('GET', '/admin/settings/' . $tab)->body;
        assert_contains('class="bh-set', $html, $tab);
        assert_contains('bh-set-card', $html, $tab);
        assert_contains('class="bh-set-save"', $html, $tab);
        // Every checkbox on a settings page is a switch.
        preg_match_all('#<input[^>]*type="checkbox"[^>]*>#', $html, $m);
        foreach ($m[0] as $box) assert_contains('role="switch"', $box, "$tab: $box");
    }
});

test('settings pages: switches post 0 when off where the page always did, and save', function () {
    $html = bh_browser('admin')->request('GET', '/admin/settings/reading')->body;
    assert_contains('<input type="hidden" name="discourage_search" value="0"><input type="checkbox" role="switch"', $html);
    $res = bh_browser('admin')->ajax('settings.save', ['tab' => 'reading', 'form' => 'discourage_search=0&discourage_search=1']);
    assert_same(200, $res->status, $res->body);
    assert_same('1', sp_value('reading', 'discourage_search'));
    bh_browser('admin')->ajax('settings.save', ['tab' => 'reading', 'form' => 'discourage_search=0']);
    assert_same('0', sp_value('reading', 'discourage_search'));
});

test('settings pages: /admin/settings is also the phone\'s list of sections', function () {
    $html = bh_browser('admin')->request('GET', '/admin/settings')->body;
    assert_contains('bh-set--home', $html);
    foreach (SP_TABS as $tab) assert_contains('href="/admin/settings/' . $tab . '"', $html);
    assert_not_contains('bh-set--home', bh_browser('admin')->request('GET', '/admin/settings/general')->body);
});

test('settings.section: each section\'s page content, title and heading, without the layout', function () {
    $b = bh_browser('admin');
    foreach (SP_TABS as $tab) {
        $res = $b->ajaxGet('settings.section', ['tab' => $tab]);
        assert_same(200, $res->status, $res->body);
        $d = $res->json()['data'];
        assert_same($tab, $d['tab']);
        assert_true(str_starts_with(ltrim(preg_replace('#^\s*<div data-bh-flash.*?</div>#s', '', $d['html'])), '<div class="bh-set'), "$tab starts with the section: " . substr($d['html'], 0, 80));
        assert_not_contains('<main', $d['html']);
        assert_not_contains('bh-tabs::-webkit-scrollbar', $d['html'], 'no layout styles');
        assert_contains('action="/admin/settings/' . $tab . '"', $d['html'], $tab);
        assert_contains(\App\Services\SettingsSaver::label($tab) . ' Settings', $d['title']);
        assert_same(\App\Services\SettingsSaver::label($tab) . ' Settings', $d['heading']);
    }
    $home = $b->ajaxGet('settings.section', ['tab' => 'home'])->json()['data'];
    assert_same('home', $home['tab']);
    assert_contains('bh-set--home', $home['html']);
    assert_same('Settings', $home['heading']);
    assert_error($b->ajaxGet('settings.section', ['tab' => 'nope']), 422, 'VALIDATION_FAILED');
    assert_error($b->ajaxGet('settings.section', ['tab' => 'appearance']), 422, 'VALIDATION_FAILED');
});

test('settings.section: only people who may change settings', function () {
    foreach (['editor', 'author', 'subscriber'] as $who) {
        $res = bh_browser($who)->ajaxGet('settings.section', ['tab' => 'general']);
        assert_same(403, $res->status, "$who: " . $res->body);
    }
});
