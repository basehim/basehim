<?php

declare(strict_types=1);

/*
 * 1.2.106 — the Comments list gets a Quick View. A moderator can read the whole
 * comment in place (the list truncates it to 140 chars) before approving,
 * marking spam or deleting, without an edit screen. It reuses the full-width
 * Quick View item from 1.2.105 for the comment body.
 */

test('Comments: the list registers a quick_view callable that reads author details and a full-width body', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/tables.php');

    // The comments table exposes a quick_view, and it is attached to the
    // comments.list registration (the only one whose closure uses $db here).
    assert_contains("'quick_view'", $src, 'comments.list registers a quick_view');
    assert_contains('CommentService::safeAuthorUrl', $src, 'the website link is passed through the safe-URL guard, not printed raw');
    assert_contains("'icon'     => 'chat-bubble-left-right'", $src, 'the Quick View uses the comment icon');

    // cleanView keeps the shape the callable returns: an amber "Pending" badge,
    // a Details section, and the comment body as a labelless full-width item
    // that preserves its line breaks (so a moderator sees the real text).
    $raw = [
        'title'    => 'Jane Reviewer',
        'subtitle' => 'jane@example.test',
        'icon'     => 'chat-bubble-left-right',
        'badges'   => [['label' => 'Pending', 'tone' => 'amber']],
        'sections' => [
            ['title' => 'Details', 'items' => [
                ['label' => 'Email', 'value' => 'jane@example.test', 'url' => 'mailto:jane@example.test', 'mono' => true],
                ['label' => 'On post', 'value' => 'Hello, world!', 'url' => '/admin/posts/1/edit'],
            ]],
            ['title' => 'Comment', 'items' => [
                ['label' => '', 'wide' => true, 'value' => "A first line.\nA second line, with <b>markup</b> as text."],
            ]],
        ],
    ];
    $clean = (new ReflectionMethod(\App\Http\Controllers\Ajax\TableActions::class, 'cleanView'))->invoke(null, $raw);

    assert_same('Jane Reviewer', $clean['title']);
    assert_same('amber', $clean['badges'][0]['tone'], 'a pending comment is amber');
    assert_same('Pending', $clean['badges'][0]['label']);

    $body = $clean['sections'][1]['items'][0];
    assert_true($body['wide'], 'the comment body is a full-width item');
    assert_same('', $body['label'], 'the body block carries no label');
    assert_true(str_contains($body['value'], "\n"), 'the body keeps its line breaks');
    assert_true(str_contains($body['value'], '<b>markup</b>'), 'the body is carried verbatim; the client renders it as text, not HTML');

    // Quick View is offered automatically for any table that defines the
    // callable — the row menu adds it from payload.quick_view.
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/datatable.js');
    assert_contains('payload.quick_view', $js, 'the row menu surfaces Quick View when the table defines one');
});
