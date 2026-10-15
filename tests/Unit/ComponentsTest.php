<?php

declare(strict_types=1);

use App\Core\Admin\Components;

test('components: avatar uses the image when a url is given', function () {
    $html = Components::avatar('Asif Mughal', '/uploads/a.webp', 28);
    assert_contains('<img', $html);
    assert_contains('/uploads/a.webp', $html);
    assert_contains('width="28"', $html);
});

test('components: avatar falls back to initials with no url', function () {
    $html = Components::avatar('Asif Mughal', null, 28);
    assert_contains('bh-ava--initials', $html);
    assert_contains('>AM<', $html);          // first + last initial
    assert_not_contains('<img', $html);
});

test('components: avatar initials handle a single name', function () {
    assert_contains('>A<', Components::avatar('Admin', null));
});

test('components: a name is escaped in the avatar alt / initials', function () {
    $html = Components::avatar('<script>x</script>', '/a.webp');
    assert_not_contains('<script>', $html);
    assert_contains('&lt;script&gt;', $html);
});

test('components: userCell shows name and optional sub-line', function () {
    $html = Components::userCell('Sara Khan', '/a.webp', '@sara');
    assert_contains('bh-usercell', $html);
    assert_contains('Sara Khan', $html);
    assert_contains('@sara', $html);
    $noSub = Components::userCell('Sara Khan', '/a.webp');
    assert_not_contains('bh-usercell__sub', $noSub);
});

test('components: thumb shows the image, or a placeholder with no url', function () {
    $img = Components::thumb('/uploads/f.webp', 'cover', 40);
    assert_contains('<img', $img);
    assert_contains('/uploads/f.webp', $img);
    $empty = Components::thumb(null, '', 40);
    assert_contains('bh-thumb--empty', $empty);
    assert_not_contains('<img', $empty);
});

test('components: statusPill maps a status to its tone and escapes', function () {
    $html = Components::statusPill('published', ['published' => 'bg-green-100 text-green-700']);
    assert_contains('bg-green-100', $html);
    assert_contains('Published', $html);
});
