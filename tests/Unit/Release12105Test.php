<?php

declare(strict_types=1);

/*
 * 1.2.105 — a full-width Quick View item for a notes/description block: it may
 * carry no label and keeps its line breaks. A labelless ordinary item is still
 * dropped.
 */

test('Quick View: a wide item survives without a label and keeps more text; a labelless ordinary item is dropped', function () {
    $clean = (new ReflectionMethod(\App\Http\Controllers\Ajax\TableActions::class, 'cleanView'))->invoke(null, [
        'title' => 'v1.2.105',
        'sections' => [[
            'title' => 'Release notes',
            'items' => [
                ['label' => '', 'wide' => true, 'value' => "Line one\nLine two"],
                ['label' => '', 'value' => 'dropped — no label, not wide'],
                ['label' => 'Size', 'value' => '1 MB'],
            ],
        ]],
    ]);
    $items = $clean['sections'][0]['items'];
    assert_same(2, count($items), 'the labelless ordinary item is dropped; the wide one and the labelled one stay');
    assert_true($items[0]['wide'], 'the notes item is wide');
    assert_same("Line one\nLine two", $items[0]['value'], 'its line break is kept for pre-wrap rendering');
    assert_false($items[1]['wide'], 'an ordinary item is not wide');

    // A wide item allows more text than an ordinary one (5000 vs 2000).
    $long = (new ReflectionMethod(\App\Http\Controllers\Ajax\TableActions::class, 'cleanView'))->invoke(null, [
        'sections' => [['title' => 'N', 'items' => [['label' => '', 'wide' => true, 'value' => str_repeat('x', 5000)]]]],
    ]);
    assert_same(5000, mb_strlen($long['sections'][0]['items'][0]['value']));

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/datatable.js');
    assert_contains("bh-qv__item--wide", $js, 'the renderer marks a wide item');
});
