<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Cache;
use App\Core\HookRegistry;
use App\Core\Time;
use App\Services\ScheduledPublisher;
use App\Services\SchedulerService;

/*
 * 1.2.60: the editor's Publish panel — publish now or schedule — and the
 * publisher that takes scheduled posts live. Saves go through the real form
 * (POST /admin/posts/{id}), visitors through the real front end.
 */

/** The editor form as the block editor posts it. */
function sp_form(Browser $b, array $extra = []): array
{
    return $extra + [
        '_csrf' => $b->csrf, '_post_settings' => '1', 'title' => 'Scheduling test', 'content' => '<p>Body</p>',
        'content_format' => 'html', 'comment_status' => 'open',
    ];
}

function sp_submit(Browser $b, string $path, array $form): HttpResponse
{
    return $b->request('POST', $path, http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded']);
}

/** [date, time] of a Unix time in the site timezone, as the panel's fields hold them. */
function sp_local(int $ts): array
{
    $dt = Time::local($ts);
    return [$dt->format('Y-m-d'), $dt->format('H:i')];
}

function sp_row(int $id): array
{
    return bh_db()->selectOne('SELECT id, slug, status, published_at, title FROM {posts} WHERE id = :i', ['i' => $id]);
}

function sp_make(string $owner, string $status = 'draft', ?string $publishedAt = null): int
{
    return (int) bh_db()->insert('posts', [
        'uuid' => bh_uuid(), 'author_id' => bh_env()['users'][$owner], 'type' => 'post', 'status' => $status,
        'slug' => 'sched-' . bin2hex(random_bytes(4)), 'title' => "{$owner} {$status}", 'content' => '<p>x</p>',
        'content_format' => 'html', 'published_at' => $publishedAt,
    ]);
}

/** The shared Publish control's settings, as the editor page carries them. */
function sp_panel_config(string $html): array
{
    if (!preg_match('/data-bh-publish-mount data-config="([^"]*)"/', $html, $m)) fail('no Publish control on the page');
    return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
}

function sp_publisher(): ScheduledPublisher
{
    return Application::getInstance()->make(ScheduledPublisher::class);
}

/** The id of the newest post (after a create through the form). */
function sp_latest(): int
{
    return (int) bh_db()->selectOne('SELECT MAX(id) AS id FROM {posts}')['id'];
}

function sp_near_now(?string $utc, string $msg): void
{
    assert_true($utc !== null && abs(strtotime($utc . ' UTC') - time()) <= 120, "{$msg} (got " . var_export($utc, true) . ')');
}

function sp_public_status(string $slug): int
{
    return (new Browser(bh_env()['base_url']))->request('GET', '/posts/' . $slug)->status;
}

test('schedule: a new post published immediately goes live at the server\'s time', function () {
    $b = bh_browser('editor');
    // A browser-sent date is ignored for "now".
    $res = sp_submit($b, '/admin/posts', sp_form($b, ['title' => 'Now post', 'status' => 'published', 'publish_mode' => 'now',
        'publish_date' => '2001-01-01', 'publish_time' => '00:00']));
    assert_same(302, $res->status);
    $row = sp_row(sp_latest());
    assert_same('published', $row['status']);
    sp_near_now($row['published_at'], 'published_at is now');
    assert_same(200, sp_public_status($row['slug']));
});

test('schedule: a draft save never publishes or schedules, whatever the panel holds', function () {
    $b = bh_browser('editor');
    [$d, $t] = sp_local(time() + 86400);
    sp_submit($b, '/admin/posts', sp_form($b, ['title' => 'Draft post', 'status' => 'draft', 'publish_mode' => 'schedule',
        'publish_date' => $d, 'publish_time' => $t]));
    $row = sp_row(sp_latest());
    assert_same('draft', $row['status']);
    assert_same(null, $row['published_at']);
});

test('schedule: a new post scheduled for later is stored in UTC and hidden from every public route', function () {
    $b = bh_browser('editor');
    $at = (int) (ceil((time() + 3 * 86400) / 60) * 60);
    [$d, $t] = sp_local($at);
    $res = sp_submit($b, '/admin/posts', sp_form($b, ['title' => 'Future post zebra', 'status' => 'published',
        'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same(302, $res->status);
    $id = sp_latest();
    $row = sp_row($id);
    assert_same('scheduled', $row['status']);
    assert_same(gmdate('Y-m-d H:i:00', $at), $row['published_at'], 'the chosen site-local time, stored as UTC');

    // Not public anywhere, even after the publisher has looked.
    sp_publisher()->publishDue();
    assert_same('scheduled', sp_row($id)['status'], 'not due, not published');
    assert_same(404, sp_public_status($row['slug']));
    $anon = new Browser(bh_env()['base_url']);
    assert_not_contains($row['slug'], $anon->request('GET', '/api/v1/posts?per_page=100')->body);
    assert_not_contains($row['slug'], $anon->request('GET', '/feed')->body);
    assert_not_contains($row['slug'], $anon->request('GET', '/sitemap.xml')->body);
    assert_not_contains('Future post zebra', $anon->request('GET', '/search?q=zebra')->body);
    // An editor can still preview it.
    assert_same(200, $b->request('GET', '/posts/' . $row['slug'])->status);
    // The editor shows the schedule.
    $cfg = sp_panel_config($b->request('GET', "/admin/posts/{$id}/edit")->body);
    assert_same(['scheduled', $d, $t, 'schedule'], [$cfg['status'], $cfg['date'], $cfg['time'], $cfg['mode']]);
    assert_contains('Scheduled for', $cfg['summary']);
});

test('schedule: the first visitor after the time sees the post — publishing runs before routing', function () {
    $id = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 3600));
    $slug = sp_row($id)['slug'];
    assert_same(404, sp_public_status($slug));
    // Time passes (the stored time moves into the past, as the clock would).
    bh_db()->execute('UPDATE {posts} SET published_at = :p WHERE id = :i', ['p' => gmdate('Y-m-d H:i:s', time() - 30), 'i' => $id]);
    Application::getInstance()->make(Cache::class)->delete(ScheduledPublisher::CACHE_KEY);
    assert_same(200, sp_public_status($slug), 'live on the very first request');
    $row = sp_row($id);
    assert_same('published', $row['status']);
    assert_same(gmdate('Y-m-d H:i', time() - 30), substr($row['published_at'], 0, 16), 'the scheduled time is kept as the publication date');
});

test('schedule: a missed window is caught up, and the cron URL publishes too', function () {
    $id = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() - 7 * 86400));
    $token = Application::getInstance()->make(SchedulerService::class)->cronToken();
    $res = (new Browser(bh_env()['base_url']))->request('GET', '/api/v1/schedule/run?token=' . $token);
    assert_same(200, $res->status, $res->body);
    assert_same('published', sp_row($id)['status']);
    assert_true(array_key_exists('posts_published', (array) $res->json()));
});

test('schedule: a scheduled post can be moved, published now, or taken back to draft', function () {
    $b = bh_browser('editor');
    $id = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 86400));

    [$d, $t] = sp_local($at = (int) (ceil((time() + 5 * 86400) / 60) * 60));
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'scheduled', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    $row = sp_row($id);
    assert_same('scheduled', $row['status']);
    assert_same(gmdate('Y-m-d H:i:00', $at), $row['published_at'], 'moved');

    // An Update from a form with no panel keeps the schedule.
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'scheduled', 'title' => 'Renamed']));
    $row = sp_row($id);
    assert_same('scheduled', $row['status']);
    assert_same('Renamed', $row['title']);
    assert_same(gmdate('Y-m-d H:i:00', $at), $row['published_at'], 'updating a scheduled post does not publish it');

    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'now']));
    $row = sp_row($id);
    assert_same('published', $row['status']);
    sp_near_now($row['published_at'], 'publish now replaces the future time');

    $id2 = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 86400));
    sp_submit($b, "/admin/posts/{$id2}", sp_form($b, ['status' => 'draft']));
    assert_same(['draft', null], array_values(array_intersect_key(sp_row($id2), ['status' => 1, 'published_at' => 1])));
});

test('schedule: editing a live post keeps its status and its date', function () {
    $b = bh_browser('editor');
    $id = sp_make('editor', 'published', '2024-05-01 10:00:00');
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'now', 'title' => 'Edited']));
    assert_same(['published', '2024-05-01 10:00:00'], [sp_row($id)['status'], sp_row($id)['published_at']]);
    // An older form without the panel.
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published']));
    assert_same(['published', '2024-05-01 10:00:00'], [sp_row($id)['status'], sp_row($id)['published_at']]);
    // A save without the settings panel cannot touch status at all.
    $form = sp_form($b, ['title' => 'No panel']);
    unset($form['_post_settings']);
    sp_submit($b, "/admin/posts/{$id}", $form);
    assert_same(['published', '2024-05-01 10:00:00'], [sp_row($id)['status'], sp_row($id)['published_at']]);
});

test('schedule: a live post is rescheduled only on an explicit Schedule', function () {
    $b = bh_browser('editor');
    $id = sp_make('editor', 'published', '2024-05-01 10:00:00');
    [$d, $t] = sp_local($at = (int) (ceil((time() + 2 * 86400) / 60) * 60));
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same(['scheduled', gmdate('Y-m-d H:i:00', $at)], [sp_row($id)['status'], sp_row($id)['published_at']]);
    assert_same(404, sp_public_status(sp_row($id)['slug']));
});

test('schedule: a past time is refused without losing the edit or changing the status', function () {
    $b = bh_browser('editor');
    $id = sp_make('editor', 'draft');
    [$d, $t] = sp_local(time() - 3600);
    $res = sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['title' => 'Kept edit', 'status' => 'published',
        'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same(302, $res->status);
    $row = sp_row($id);
    assert_same(['draft', null, 'Kept edit'], [$row['status'], $row['published_at'], $row['title']]);
    assert_contains('has to be in the future', $b->request('GET', "/admin/posts/{$id}/edit")->body);

    // A new post with a past time is created as a draft.
    sp_submit($b, '/admin/posts', sp_form($b, ['title' => 'Past new', 'status' => 'published',
        'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    $row = sp_row(sp_latest());
    assert_same(['draft', null], [$row['status'], $row['published_at']]);

    // A live post with a past "schedule" stays live with its date.
    $live = sp_make('editor', 'published', '2024-05-01 10:00:00');
    sp_submit($b, "/admin/posts/{$live}", sp_form($b, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same(['published', '2024-05-01 10:00:00'], [sp_row($live)['status'], sp_row($live)['published_at']]);
});

test('schedule: only roles that may publish can schedule; contributors submit for review', function () {
    [$d, $t] = sp_local(time() + 86400);
    $c = bh_browser('contributor');
    foreach ([
        ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t],
        ['status' => 'scheduled', 'publish_date' => $d, 'publish_time' => $t],
        ['status' => 'private'],
        ['status' => 'published', 'publish_mode' => 'now'],
    ] as $extra) {
        $id = sp_make('contributor');
        sp_submit($c, "/admin/posts/{$id}", sp_form($c, $extra));
        $row = sp_row($id);
        assert_same('pending', $row['status'], json_encode($extra));
        assert_same(null, $row['published_at'], 'no date set: ' . json_encode($extra));
    }
    // The contributor's editor offers review, not scheduling.
    $edit = $c->request('GET', '/admin/posts/' . sp_make('contributor') . '/edit')->body;
    assert_contains('Submit for review', $edit);
    assert_same(false, sp_panel_config($edit)['can_publish']);

    // An author may schedule their own post…
    $a = bh_browser('author');
    $own = sp_make('author');
    sp_submit($a, "/admin/posts/{$own}", sp_form($a, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same('scheduled', sp_row($own)['status']);
    // …but not someone else's.
    $other = sp_make('author2');
    sp_submit($a, "/admin/posts/{$other}", sp_form($a, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same('draft', sp_row($other)['status']);

    // The form still needs its CSRF token.
    $id = sp_make('editor');
    $e = bh_browser('editor');
    $form = sp_form($e, ['status' => 'published', 'publish_mode' => 'schedule', 'publish_date' => $d, 'publish_time' => $t]);
    $form['_csrf'] = 'forged';
    sp_submit($e, "/admin/posts/{$id}", $form);
    assert_same('draft', sp_row($id)['status']);
});

test('schedule: Save draft over AJAX cannot schedule, and refuses a scheduled post', function () {
    $b = bh_browser('editor');
    $res = $b->ajax('posts.save_draft', ['type' => 'post', 'title' => 'x', 'status' => 'scheduled']);
    assert_same(422, $res->status, 'status is limited to draft/pending');
    $res = $b->ajax('posts.save_draft', ['type' => 'post', 'title' => 'x', 'publish_mode' => 'schedule']);
    assert_same(422, $res->status, 'the panel fields are not accepted');
    $id = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 86400));
    assert_error($b->ajax('posts.save_draft', ['type' => 'post', 'id' => $id, 'title' => 'y'], ['Idempotency-Key' => bin2hex(random_bytes(10))]), 409, 'ALREADY_PUBLISHED');
});

test('schedule: bulk Publish on a scheduled post publishes it now; bulk Draft drops its date', function () {
    $b = bh_browser('editor');
    $p = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 86400));
    $d = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() + 86400));
    sp_submit($b, '/admin/posts/bulk', ['_csrf' => $b->csrf, 'bulk_action' => 'publish', 'ids' => [$p]]);
    sp_near_now(sp_row($p)['published_at'], 'a future date is not kept on a published post');
    assert_same('published', sp_row($p)['status']);
    sp_submit($b, '/admin/posts/bulk', ['_csrf' => $b->csrf, 'bulk_action' => 'draft', 'ids' => [$d]]);
    assert_same(['draft', null], [sp_row($d)['status'], sp_row($d)['published_at']]);
});

test('schedule: running the publisher again publishes nothing twice, and hooks fire once', function () {
    $hooks = Application::getInstance()->make(HookRegistry::class);
    $seen = [];
    $hooks->addAction('post.published', function ($post) use (&$seen) { $seen[] = (int) $post['id']; });
    $id = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() - 5));
    $trashed = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() - 5));
    bh_db()->execute('UPDATE {posts} SET deleted_at = :d WHERE id = :i', ['d' => gmdate('Y-m-d H:i:s'), 'i' => $trashed]);

    $first = sp_publisher()->publishDue();
    $second = sp_publisher()->publishDue();
    assert_true(in_array($id, $first['published'], true));
    assert_false(in_array($id, $second['published'], true));
    assert_same(1, count(array_filter($seen, fn($x) => $x === $id)), 'post.published fired once');
    assert_same('scheduled', sp_row($trashed)['status'], 'a trashed post is never published');
    assert_same(1, (int) bh_db()->selectOne("SELECT COUNT(*) AS n FROM {user_activity_log} WHERE event = 'post.scheduled_published' AND object_id = :i", ['i' => $id])['n']);
});

test('schedule: concurrent publisher runs claim each post exactly once', function () {
    bh_require_innodb();
    $ids = [];
    for ($i = 0; $i < 6; $i++) $ids[] = sp_make('editor', 'scheduled', gmdate('Y-m-d H:i:s', time() - 5));
    $token = Application::getInstance()->make(SchedulerService::class)->cronToken();
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 8; $i++) {
        $ch = curl_init(bh_env()['base_url'] . '/api/v1/schedule/run?token=' . $token);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    $total = 0;
    foreach ($handles as $ch) {
        $j = json_decode((string) curl_multi_getcontent($ch), true);
        $total += (int) ($j['posts_published'] ?? 0);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    foreach ($ids as $id) assert_same('published', sp_row($id)['status']);
    $in = implode(',', $ids);
    $logged = (int) bh_db()->selectOne("SELECT COUNT(*) AS n FROM {user_activity_log} WHERE event = 'post.scheduled_published' AND object_id IN ({$in})")['n'];
    assert_same(6, $logged, 'one publication per post across all requests');
    assert_true($total <= 6, 'the requests together report each post once at most');
});

test('schedule: the panel\'s date and time are read in the site timezone, daylight saving included', function () {
    $settings = Application::getInstance()->make(\App\Services\SettingService::class);
    $before = (string) $settings->get('general', 'timezone', '');
    $settings->set('general', 'timezone', 'America/New_York');
    Time::reset();
    try {
        $b = bh_browser('editor');
        $id = sp_make('editor');
        // 2 Nov 2027, 09:30 New York (EST, after the clocks go back) = 14:30 UTC.
        sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'schedule',
            'publish_date' => '2027-11-08', 'publish_time' => '09:30']));
        assert_same('2027-11-08 14:30:00', sp_row($id)['published_at']);
        // Summer: EDT, UTC-4.
        sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'scheduled', 'publish_mode' => 'schedule',
            'publish_date' => '2027-07-01', 'publish_time' => '09:30']));
        assert_same('2027-07-01 13:30:00', sp_row($id)['published_at']);
        // The editor shows it back in site time, with the zone named.
        $cfg = sp_panel_config($b->request('GET', "/admin/posts/{$id}/edit")->body);
        assert_same(['2027-07-01', '09:30', 'America/New_York'], [$cfg['date'], $cfg['time'], $cfg['tz']]);
    } finally {
        $settings->set('general', 'timezone', $before);
        Time::reset();
    }
});

test('schedule: code that saves through PostService gets the same rules', function () {
    $posts = Application::getInstance()->make(\App\Services\PostService::class);
    $admin = bh_env()['users']['admin'];
    $future = gmdate('Y-m-d H:i:s', time() + 86400);
    $a = $posts->create(['title' => 'API sched', 'status' => 'scheduled', 'published_at' => $future], $admin);
    assert_same(['scheduled', $future], [sp_row($a)['status'], sp_row($a)['published_at']]);
    // An ISO time with an offset is read as the instant it names.
    $b = $posts->create(['title' => 'API iso', 'status' => 'scheduled', 'published_at' => '2030-01-01T10:00:00+05:00'], $admin);
    assert_same('2030-01-01 05:00:00', sp_row($b)['published_at']);
    $c = $posts->create(['title' => 'API past', 'status' => 'scheduled', 'published_at' => '2020-01-01 00:00:00'], $admin);
    assert_same(['published', '2020-01-01 00:00:00'], [sp_row($c)['status'], sp_row($c)['published_at']], 'a passed time is due: published');
    $d = $posts->create(['title' => 'API none', 'status' => 'scheduled'], $admin);
    assert_same(['draft', null], [sp_row($d)['status'], sp_row($d)['published_at']], 'no time, no schedule');
});

test('schedule: the System page gives administrators the cron URL, and only them', function () {
    $token = Application::getInstance()->make(SchedulerService::class)->cronToken();
    $page = bh_browser('admin')->request('GET', '/admin/system');
    assert_same(200, $page->status);
    assert_contains('/api/v1/schedule/run?token=' . $token, $page->body);
    assert_contains('Scheduled posts and tasks', $page->body);
    $editor = bh_browser('editor')->request('GET', '/admin/system');
    assert_not_contains($token, $editor->body, 'editors do not see the token');
});

test('schedule: the editor can change a live post\'s date — earlier keeps it live, later schedules it', function () {
    $b = bh_browser('editor');
    $id = sp_make('editor', 'published', '2024-05-01 10:00:00');
    $cfg = sp_panel_config($b->request('GET', "/admin/posts/{$id}/edit")->body);
    assert_same(['keep', true], [$cfg['mode'], $cfg['date'] !== '']);
    $back = Time::local(strtotime('2023-02-03 04:05:00 UTC'));
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'date',
        'publish_date' => $back->format('Y-m-d'), 'publish_time' => $back->format('H:i')]));
    assert_same(['published', '2023-02-03 04:05:00'], [sp_row($id)['status'], sp_row($id)['published_at']]);
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'keep', 'title' => 'Kept']));
    assert_same(['published', '2023-02-03 04:05:00'], [sp_row($id)['status'], sp_row($id)['published_at']]);
    [$d, $t] = sp_local($at = (int) (ceil((time() + 86400) / 60) * 60));
    sp_submit($b, "/admin/posts/{$id}", sp_form($b, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => $d, 'publish_time' => $t]));
    assert_same(['scheduled', gmdate('Y-m-d H:i:00', $at)], [sp_row($id)['status'], sp_row($id)['published_at']]);
    // Without publish rights the date cannot be changed.
    $c = bh_browser('contributor');
    $own = sp_make('contributor');
    sp_submit($c, "/admin/posts/{$own}", sp_form($c, ['status' => 'published', 'publish_mode' => 'date', 'publish_date' => '2020-01-01', 'publish_time' => '10:00']));
    assert_same(['pending', null], [sp_row($own)['status'], sp_row($own)['published_at']]);
});

test('schedule: the editor draws Categories and Tags with the shared components', function () {
    $b = bh_browser('admin');
    $id = sp_make('admin');
    $html = $b->request('GET', "/admin/posts/{$id}/edit")->body;
    assert_contains('data-bh-categories-mount', $html);
    assert_contains('data-bh-tags-mount', $html);
    assert_contains('/admin/assets/js/post-fields.js', $html);
    preg_match('/data-bh-tags-mount data-config="([^"]*)"/', $html, $m);
    $tags = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    assert_same([$id, [], []], [$tags['post_id'], $tags['tags'], $tags['pending']]);
});
