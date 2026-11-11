<?php

declare(strict_types=1);

/*
 * 1.2.127: the README shows screenshots. They are hosted on basehim.com, so
 * the checks are that every image is an https address there and says what it
 * shows (alt text) for screen readers and for when it can't load.
 */

function r12127_images(): array
{
    $md = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)\)/', $md, $m1, PREG_SET_ORDER);
    preg_match_all('/<img\b[^>]*>/i', $md, $m2);
    $out = [];
    foreach ($m1 as $m) $out[] = ['src' => $m[2], 'alt' => $m[1]];
    foreach ($m2[0] as $tag) {
        preg_match('/\bsrc="([^"]*)"/', $tag, $s);
        preg_match('/\balt="([^"]*)"/', $tag, $a);
        $out[] = ['src' => $s[1] ?? '', 'alt' => $a[1] ?? ''];
    }
    return $out;
}

test('readme: screenshots are https images on basehim.com, each with alt text', function () {
    $shots = array_filter(r12127_images(), fn($i) => !str_contains($i['src'], 'img.shields.io') && !str_contains($i['src'], '/badge.svg'));
    assert_true(count($shots) >= 4, 'the README shows its screenshots (' . count($shots) . ' found)');
    foreach ($shots as $i) {
        assert_true(str_starts_with($i['src'], 'https://www.basehim.com/uploads/'), "hosted on basehim.com: {$i['src']}");
        assert_true(strlen(trim($i['alt'])) >= 10, "alt text says what {$i['src']} shows");
    }
});
