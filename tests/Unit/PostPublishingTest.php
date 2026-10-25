<?php

declare(strict_types=1);

use App\Services\PostPublishing;

/*
 * 1.2.60: how a save from the editor's publish panel becomes a status and a
 * publication time. No database: PostPublishing is pure.
 */

/** 2026-10-18 03:42:00 UTC — a fixed "now" for every case. */
const PP_NOW = 1792294920;

function pp(?array $existing, array $input, bool $canPublish = true, string $tz = 'Asia/Karachi', int $now = PP_NOW): array
{
    return PostPublishing::resolve($existing, $input, $canPublish, new DateTimeZone($tz), $now);
}

test('publishing: a new post published immediately takes the server time, not one the browser sent', function () {
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'now', 'publish_date' => '2020-01-01', 'publish_time' => '00:00']);
    assert_same('published', $r['status']);
    assert_same(gmdate('Y-m-d H:i:s', PP_NOW), $r['published_at']);
    assert_true(!isset($r['error']));
});

test('publishing: a form without the panel publishes immediately, as before', function () {
    $r = pp(null, ['status' => 'published']);
    assert_same('published', $r['status']);
    assert_same(gmdate('Y-m-d H:i:s', PP_NOW), $r['published_at']);
});

test('publishing: scheduling stores the site-local time as UTC', function () {
    // 20 Oct 2026 09:30 in Karachi (UTC+5) is 04:30 UTC.
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-10-20', 'publish_time' => '09:30']);
    assert_same('scheduled', $r['status']);
    assert_same('2026-10-20 04:30:00', $r['published_at']);
    // The same wall time in New York (EDT, UTC-4) is 13:30 UTC.
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-10-20', 'publish_time' => '09:30'], true, 'America/New_York');
    assert_same('2026-10-20 13:30:00', $r['published_at']);
});

test('publishing: daylight saving is applied for the chosen date, not today', function () {
    // New York leaves DST on 1 Nov 2026: 9:30 on 2 Nov is EST (UTC-5).
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-11-02', 'publish_time' => '09:30'], true, 'America/New_York');
    assert_same('2026-11-02 14:30:00', $r['published_at']);
    // A wall time inside the spring-forward gap (2:30 on 14 Mar 2027 does not
    // exist) moves forward by the gap: 03:30 EDT = 07:30 UTC.
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2027-03-14', 'publish_time' => '02:30'], true, 'America/New_York');
    assert_same('scheduled', $r['status']);
    assert_same('2027-03-14 07:30:00', $r['published_at']);
});

test('publishing: a past, present or malformed schedule is refused and the status kept', function () {
    foreach ([
        ['2026-10-18', '08:42'],   // exactly now in Karachi
        ['2026-10-18', '08:41'],   // a minute ago
        ['2025-01-01', '10:00'],
        ['2026-02-30', '10:00'],   // no such day
        ['2026-10-20', '25:00'],
        ['20-10-2026', '10:00'],
        ['', ''],
    ] as [$d, $t]) {
        $r = pp(['status' => 'draft', 'published_at' => null],
            ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]);
        assert_true(isset($r['error']), "refused: {$d} {$t}");
        assert_same('draft', $r['status'], "status kept for {$d} {$t}");
        assert_true(!array_key_exists('published_at', $r), "date untouched for {$d} {$t}");
    }
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-10-18', 'publish_time' => '08:43']);
    assert_same('scheduled', $r['status'], 'a minute ahead is fine');
});

test('publishing: dates a TIMESTAMP column cannot hold are refused', function () {
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2040-01-01', 'publish_time' => '10:00']);
    assert_true(isset($r['error']));
    assert_same('draft', $r['status']);
});

test('publishing: without the publish capability nothing goes out — not now, not scheduled, not private', function () {
    foreach ([
        ['status' => 'published', 'publish_mode' => 'now'],
        ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-10-20', 'publish_time' => '09:30'],
        ['status' => 'scheduled', 'publish_date' => '2026-10-20', 'publish_time' => '09:30'],
        ['status' => 'private'],
    ] as $input) {
        $r = pp(['status' => 'draft', 'published_at' => null], $input, false);
        assert_same('pending', $r['status'], json_encode($input));
        assert_true(!isset($r['published_at']), 'no date is set');
        assert_true(isset($r['notice']));
    }
});

test('publishing: an Update of a live post changes neither its status nor its date', function () {
    $live = ['status' => 'published', 'published_at' => '2024-05-01 10:00:00'];
    foreach ([['status' => 'published'], ['status' => 'published', 'publish_mode' => 'now']] as $input) {
        $r = pp($live, $input);
        assert_same('published', $r['status']);
        assert_true(!array_key_exists('published_at', $r), 'date untouched');
    }
});

test('publishing: a live post is rescheduled only when the editor explicitly chose Schedule', function () {
    $live = ['status' => 'published', 'published_at' => '2024-05-01 10:00:00'];
    $r = pp($live, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2026-12-01', 'publish_time' => '12:00']);
    assert_same('scheduled', $r['status']);
    assert_same('2026-12-01 07:00:00', $r['published_at']);
});

test('publishing: a scheduled post keeps, changes, or gives up its schedule as asked', function () {
    $sched = ['status' => 'scheduled', 'published_at' => '2026-10-25 05:00:00'];
    // Older form (status only): keep the stored time.
    $r = pp($sched, ['status' => 'scheduled']);
    assert_same('scheduled', $r['status']);
    assert_true(!array_key_exists('published_at', $r));
    // A new time.
    $r = pp($sched, ['status' => 'scheduled', 'publish_mode' => 'schedule', 'publish_date' => '2026-10-26', 'publish_time' => '10:00']);
    assert_same('2026-10-26 05:00:00', $r['published_at']);
    // Publish immediately: now, not the future time.
    $r = pp($sched, ['status' => 'published', 'publish_mode' => 'now']);
    assert_same('published', $r['status']);
    assert_same(gmdate('Y-m-d H:i:s', PP_NOW), $r['published_at']);
    // Back to draft: the unused date goes.
    $r = pp($sched, ['status' => 'draft']);
    assert_same('draft', $r['status']);
    assert_same(null, $r['published_at']);
});

test('publishing: a post that was live before keeps its original date when republished', function () {
    $r = pp(['status' => 'draft', 'published_at' => '2023-03-03 03:03:03'], ['status' => 'published', 'publish_mode' => 'now']);
    assert_same('published', $r['status']);
    assert_true(!array_key_exists('published_at', $r));
});

test('publishing: unknown statuses and modes are not stored', function () {
    $r = pp(['status' => 'draft', 'published_at' => null], ['status' => 'live']);
    assert_true(isset($r['error']));
    assert_same('draft', $r['status']);
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'whenever']);
    assert_same('published', $r['status'], 'an unknown mode reads as the form default');
});

test('publishing: "keep" leaves a live post and a schedule exactly as they are', function () {
    $r = pp(['status' => 'published', 'published_at' => '2024-05-01 10:00:00'], ['status' => 'published', 'publish_mode' => 'keep']);
    assert_same(['status' => 'published'], $r);
    $r = pp(['status' => 'scheduled', 'published_at' => '2026-10-25 05:00:00'], ['status' => 'published', 'publish_mode' => 'keep']);
    assert_same(['status' => 'scheduled'], $r);
    $r = pp(['status' => 'draft', 'published_at' => null], ['status' => 'published', 'publish_mode' => 'keep']);
    assert_same('published', $r['status'], 'on a draft, keep means now');
});

test('publishing: "date" re-dates a live post into the past and schedules it into the future', function () {
    $live = ['status' => 'published', 'published_at' => '2024-05-01 10:00:00'];
    $r = pp($live, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2023-02-03', 'publish_time' => '09:05']);
    assert_same(['status' => 'published', 'published_at' => '2023-02-03 04:05:00'], $r, 'Karachi 09:05 = 04:05 UTC');
    $r = pp($live, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2026-12-01', 'publish_time' => '12:00']);
    assert_same(['scheduled', '2026-12-01 07:00:00'], [$r['status'], $r['published_at']]);
    foreach ([['2026-02-30', '10:00'], ['', ''], ['2040-01-01', '10:00']] as [$d, $t]) {
        $r = pp($live, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => $d, 'publish_time' => $t]);
        assert_true(isset($r['error']), "refused {$d} {$t}");
        assert_same('published', $r['status']);
    }
    // On a post that is not live (1.2.88): a past date publishes it dated then.
    $r = pp(['status' => 'draft', 'published_at' => null], ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2023-02-03', 'publish_time' => '09:05']);
    assert_same(['published', '2023-02-03 04:05:00'], [$r['status'], $r['published_at']]);
    assert_true(!isset($r['error']));
    // Without publish rights: review, no date.
    $r = pp($live, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2023-02-03', 'publish_time' => '09:05'], false);
    assert_same('pending', $r['status']);
});

test('publishing: the panel settings name the stored date in site time and the right starting mode', function () {
    $c = PostPublishing::panel(['status' => 'published', 'published_at' => '2024-05-01 10:00:00'], true, 'page');
    assert_same(['published', 'keep', 'page', true], [$c['status'], $c['mode'], $c['type_label'], $c['can_publish']]);
    assert_true($c['date'] !== '' && $c['time'] !== '');
    $c = PostPublishing::panel(null, false);
    assert_same(['draft', 'now', '', false], [$c['status'], $c['mode'], $c['date'], $c['can_publish']]);
    assert_contains('New', $c['summary']);
});

test('publishing: "Set date" on a new post publishes it at an earlier time, so it sorts below a post published later in the day (1.2.88)', function () {
    // Post A was published at 14:00 site time (09:00 UTC) on 18 Oct 2026.
    $a = '2026-10-18 09:00:00';
    // Post B is written afterwards but dated 12:00 the same day.
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2026-10-18', 'publish_time' => '12:00'], true, 'Asia/Karachi', strtotime('2026-10-18 10:30:00 UTC'));
    assert_same('published', $r['status']);
    assert_same('2026-10-18 07:00:00', $r['published_at']);
    // Newest first: A (09:00 UTC) before B (07:00 UTC), whatever order they were written in.
    $list = [['id' => 2, 'published_at' => $r['published_at']], ['id' => 1, 'published_at' => $a]];
    usort($list, fn($x, $y) => [$y['published_at'], $y['id']] <=> [$x['published_at'], $x['id']]);
    assert_same([1, 2], array_column($list, 'id'));
});

test('publishing: "Set date" with a future time schedules a new post, and still checks the date (1.2.88)', function () {
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2026-10-20', 'publish_time' => '09:30']);
    assert_same(['scheduled', '2026-10-20 04:30:00'], [$r['status'], $r['published_at']]);
    foreach ([['', '09:30'], ['2026-02-30', '10:00'], ['2040-01-01', '10:00']] as [$d, $t]) {
        $r = pp(['status' => 'draft', 'published_at' => null], ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => $d, 'publish_time' => $t]);
        assert_true(isset($r['error']), "refused {$d} {$t}");
        assert_same('draft', $r['status']);
    }
    // A scheduled post given a past date goes live dated then.
    $r = pp(['status' => 'scheduled', 'published_at' => '2026-11-01 05:00:00'], ['status' => 'scheduled', 'publish_mode' => 'date', 'publish_date' => '2026-10-17', 'publish_time' => '08:00']);
    assert_same(['published', '2026-10-17 03:00:00'], [$r['status'], $r['published_at']]);
    // "Schedule" itself is unchanged: future only.
    $r = pp(null, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => '2023-02-03', 'publish_time' => '09:05']);
    assert_true(isset($r['error']));
});
