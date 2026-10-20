<?php

declare(strict_types=1);

/*
 * 1.2.67: the Media Library modernisation — media.list paging (has_more,
 * stable order, checked filters), media.get, media.delete with per-file
 * results, the media.library Data Table, and the upload floor (extensions no
 * setting can allow, content that must match its name).
 */

/** Rows made by these tests, all with one created_at so only the id orders them. */
function mlt_seed(int $n, string $owner = 'admin', string $mime = 'image/png', string $when = '2024-03-15 10:00:00'): array
{
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $tag = 'mlt-' . bin2hex(random_bytes(3));
        $ids[] = (int) bh_db()->insert('media', [
            'uuid' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffff)),
            'author_id' => bh_env()['users'][$owner], 'title' => $tag, 'alt_text' => '',
            'mime_type' => $mime, 'file_name' => $tag . '.png', 'original_name' => $tag . '.png', 'file_size' => 100 + $i,
            'storage_path' => 'tests/' . $tag . '.png', 'url' => '/uploads/tests/' . $tag . '.png', 'created_at' => $when,
        ]);
    }
    return $ids;
}

function mlt_clean(array $ids): void
{
    foreach ($ids as $id) bh_db()->execute('DELETE FROM {media} WHERE id = :i', ['i' => (int) $id]);
}

function mlt_upload(Browser $b, string $name, string $bytes, string $type = 'application/octet-stream'): HttpResponse
{
    $boundary = '----bh' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{$b->csrf}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\n"
        . "Content-Type: {$type}\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
    return $b->request('POST', '/admin/media/upload', $body, [
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest',
    ]);
}

function mlt_png(): string
{
    $im = imagecreatetruecolor(4, 3);
    ob_start(); imagepng($im); return (string) ob_get_clean();
}

test('media.list: Load More pages never repeat or skip a file, even with equal dates', function () {
    $ids = mlt_seed(25);
    try {
        $b = bh_browser('admin');
        $seen = [];
        $pages = 0;
        $page = 1;
        do {
            $j = $b->ajaxGet('media.list', ['per_page' => 7, 'page' => $page, 'q' => 'mlt-', 'month' => '2024-03'])->json();
            assert_true($j['success'] ?? false, json_encode($j));
            foreach ($j['data']['data'] as $m) $seen[] = (int) $m['id'];
            $more = $j['data']['meta']['has_more'];
            assert_same($page < $j['data']['meta']['last_page'], $more, 'has_more agrees with last_page');
            assert_same($page === 1, isset($j['data']['counts']), 'type counts come with page 1 only');
            $page++; $pages++;
        } while ($more && $pages < 10);
        sort($seen);
        $want = $ids; sort($want);
        assert_same($want, $seen, 'every file exactly once');
        assert_same(4, $pages);
    } finally {
        mlt_clean($ids);
    }
});

test('media.list: types, type, month and search are checked, and search is literal', function () {
    $img = mlt_seed(2);
    $svg = mlt_seed(1, 'admin', 'image/svg+xml');
    $pdf = mlt_seed(1, 'admin', 'application/pdf', '2024-04-02 09:00:00');
    try {
        $b = bh_browser('admin');
        $total = fn(array $q) => $b->ajaxGet('media.list', $q + ['q' => 'mlt-'])->json()['data']['meta']['total'] ?? null;
        assert_same(3, $total(['types' => 'image,svg']), 'picker types');
        assert_same(1, $total(['types' => 'image,svg', 'type' => 'svg']), 'a pill inside the allowed types');
        assert_same(0, $total(['types' => 'svg', 'type' => 'image']), 'a pill outside them shows nothing, not everything');
        assert_same(1, $total(['month' => '2024-04']), 'month');
        assert_same(3, $total(['month' => '2024-03']));
        $j = $b->ajaxGet('media.list', ['types' => 'image,svg', 'q' => 'mlt-'])->json()['data'];
        assert_same(3, $j['counts']['all'], '"All" counts only the allowed types');
        assert_same(0, $b->ajaxGet('media.list', ['q' => '%'])->json()['data']['meta']['total'], '% is matched literally');
        assert_same(0, $b->ajaxGet('media.list', ['q' => '_'])->json()['data']['meta']['total'], '_ is matched literally');
        assert_error($b->ajaxGet('media.list', ['type' => 'image/png']), 422, 'VALIDATION_FAILED', 'raw mime prefixes are not taken here');
        assert_error($b->ajaxGet('media.list', ['types' => 'image,exe']), 422, 'VALIDATION_FAILED');
        assert_error($b->ajaxGet('media.list', ['month' => '2024-13']), 422, 'VALIDATION_FAILED');
        assert_error($b->ajaxGet('media.list', ['month' => "2024-01' OR 1=1"]), 422, 'VALIDATION_FAILED');
        assert_error($b->ajaxGet('media.list', ['sort' => 'id; DROP TABLE x']), 422, 'VALIDATION_FAILED');
        assert_error($b->ajaxGet('media.list', ['per_page' => 1000]), 422, 'VALIDATION_FAILED', 'page size is bounded');
        $months = $b->ajaxGet('media.list', ['months' => 1])->json()['data']['months'] ?? [];
        assert_true(in_array('2024-04', array_column($months, 'value'), true), 'the month list');
    } finally {
        mlt_clean(array_merge($img, $svg, $pdf));
    }
});

test('media.list / media.get / media.delete: sign-in, capability and CSRF are required', function () {
    $anon = new Browser(bh_env()['base_url']);
    assert_same(401, $anon->ajaxGet('media.list')->status);
    assert_error(bh_browser('subscriber')->ajaxGet('media.list'), 403, 'FORBIDDEN');
    assert_error(bh_browser('contributor')->ajaxGet('media.get', ['id' => bh_env()['media']['admin']]), 403, 'FORBIDDEN');
    $res = bh_browser('author')->ajax('media.delete', ['ids' => [bh_env()['media']['author']]], [], 'not-the-token');
    assert_true(in_array($res->status, [403, 419], true), 'a bad token is refused: ' . $res->status);
    assert_true((bool) bh_db()->selectOne('SELECT id FROM {media} WHERE id = :i', ['i' => bh_env()['media']['author']]), 'nothing was deleted');
});

test('media.get: one item with what you may do to it; unknown ids are 404', function () {
    $j = bh_browser('author')->ajaxGet('media.get', ['id' => bh_env()['media']['admin']])->json();
    assert_same(bh_env()['media']['admin'], (int) $j['data']['item']['id']);
    assert_same(['edit' => false, 'delete' => false], $j['data']['can'], "an author may not change an administrator's file");
    $j = bh_browser('author')->ajaxGet('media.get', ['id' => bh_env()['media']['author']])->json();
    assert_same(['edit' => true, 'delete' => true], $j['data']['can']);
    assert_error(bh_browser('admin')->ajaxGet('media.get', ['id' => 99999999]), 404, 'NOT_FOUND');
});

test("media.delete: a result per file; someone else's is denied; repeating is harmless", function () {
    $mine = mlt_seed(2, 'author');
    $theirs = mlt_seed(1, 'admin');
    try {
        $b = bh_browser('author');
        $j = $b->ajax('media.delete', ['ids' => [$mine[0], $theirs[0], 99999998]])->json();
        assert_true($j['success'] ?? false, json_encode($j));
        $by = array_column($j['data']['results'], 'status', 'id');
        assert_same('deleted', $by[$mine[0]]);
        assert_same('denied', $by[$theirs[0]]);
        assert_same('missing', $by[99999998]);
        assert_same([1, 1, 1, 0], [$j['data']['deleted'], $j['data']['denied'], $j['data']['missing'], $j['data']['failed']]);
        assert_true((bool) bh_db()->selectOne('SELECT id FROM {media} WHERE id = :i', ['i' => $theirs[0]]), "the administrator's file is still there");
        // The same request again: already gone is 'missing', not an error.
        $again = $b->ajax('media.delete', ['ids' => [$mine[0]]])->json();
        assert_same('missing', $again['data']['results'][0]['status']);
        // Only someone else's: refused outright, with the reason.
        assert_error($b->ajax('media.delete', ['ids' => [$theirs[0]]]), 403, 'FORBIDDEN');
        assert_error($b->ajax('media.delete', ['ids' => array_fill(0, 501, 1)]), 422, 'VALIDATION_FAILED', 'bulk is bounded');
    } finally {
        mlt_clean(array_merge($mine, $theirs));
    }
});

test('media delete: the row goes first, then the original and its sizes', function () {
    $b = bh_browser('admin');
    $res = mlt_upload($b, 'mlt-delete-' . bin2hex(random_bytes(3)) . '.png', mlt_png(), 'image/png');
    assert_same(201, $res->status, $res->body);
    $row = $res->json()['data'];
    $file = BASEHIM_ROOT . '/storage/uploads/' . $row['storage_path'];
    assert_true(is_file($file), 'the upload is on disk');
    $j = $b->ajax('media.delete', ['ids' => [(int) $row['id']]])->json();
    assert_same('deleted', $j['data']['results'][0]['status']);
    clearstatcache();   // this process looked at the file before the server removed it
    assert_false(is_file($file), 'the original is gone');
    $sizes = is_string($row['sizes'] ?? null) ? json_decode($row['sizes'], true) : ($row['sizes'] ?? []);
    foreach ((array) $sizes as $v) {
        assert_false(is_file(dirname($file) . '/' . $v['file']), 'generated size ' . $v['file'] . ' is gone');
    }
    assert_same(null, bh_db()->selectOne('SELECT id FROM {media} WHERE id = :i', ['i' => (int) $row['id']]));
});

test('upload: content must match its name, and no setting can allow scripts or pages', function () {
    $b = bh_browser('author');
    $made = [];
    $settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
    $before = $settings->get('media', 'allowed_types');
    try {
        $res = mlt_upload($b, 'holiday.jpg', '<html><body><script>alert(1)</script></body></html>', 'image/jpeg');
        assert_same(422, $res->status, $res->body);
        assert_contains('program or web page', (string) ($res->json()['error'] ?? ''));

        $res = mlt_upload($b, 'notes.png', "just some text\n", 'image/png');
        assert_same(422, $res->status, 'text named .png');

        // A PNG saved as .jpg is a real image: accepted.
        $res = mlt_upload($b, 'mlt-really-png-' . bin2hex(random_bytes(3)) . '.jpg', mlt_png(), 'image/jpeg');
        assert_same(201, $res->status, $res->body);
        $made[] = $res->json()['data'];

        // Even when Settings → Media lists them.
        $settings->set('media', 'allowed_types', 'jpg, png, php, phtml, html, htaccess');
        foreach (['shell.php' => '<?php echo 1;', 'x.phtml' => '<?php echo 1;', 'page.html' => '<p>hi</p>', '.htaccess' => 'Options +ExecCGI'] as $name => $bytes) {
            $res = mlt_upload($b, $name, $bytes, 'text/plain');
            assert_same(422, $res->status, $name . ': ' . $res->body);
        }
        assert_same(0, (int) bh_db()->selectOne("SELECT COUNT(*) AS n FROM {media} WHERE file_name LIKE '%.php' OR file_name LIKE '%.html' OR file_name LIKE '%.phtml'")['n']);
        $allowed = \App\Core\Application::getInstance()->make(\App\Services\MediaService::class)->allowedTypes(['jpg']);
        assert_false(in_array('php', $allowed, true), 'allowedTypes() never lists php');
    } finally {
        $settings->set('media', 'allowed_types', $before === null ? '' : $before);
        foreach ($made as $row) {
            @unlink(BASEHIM_ROOT . '/storage/uploads/' . $row['storage_path']);
            bh_db()->execute('DELETE FROM {media} WHERE id = :i', ['i' => (int) $row['id']]);
        }
    }
});

test('media.library table: server paging, type and month filters, sorting, and its capability', function () {
    $ids = array_merge(mlt_seed(3), mlt_seed(1, 'admin', 'image/svg+xml', '2023-11-05 08:00:00'));
    try {
        $b = bh_browser('admin');
        $q = fn(array $p) => $b->ajaxGet('content.table', $p + ['table' => 'media.library', 'search' => 'mlt-'])->json();
        $j = $q(['per_page' => 20]);
        assert_true($j['success'] ?? false, json_encode($j));
        assert_same(4, $j['data']['meta']['total']);
        assert_contains('data-media-open', $j['data']['rows'][0]['cells']['name']);
        assert_same(1, $q(['filters' => json_encode(['type' => 'svg'])])['data']['meta']['total']);
        assert_same(1, $q(['filters' => json_encode(['month' => '2023-11'])])['data']['meta']['total']);
        assert_same(4, $q(['filters' => json_encode(['type' => 'exe'])])['data']['meta']['total'], 'an unknown type is ignored, not obeyed');
        $sizes = array_map(fn($r) => (int) $r['id'], $q(['sort' => 'size_desc'])['data']['rows']);
        assert_same(4, count($sizes));
        assert_error(bh_browser('subscriber')->ajaxGet('content.table', ['table' => 'media.library']), 403, 'FORBIDDEN');
    } finally {
        mlt_clean($ids);
    }
});

test('media screens: the library and the picker scripts load, and old routes still answer', function () {
    $b = bh_browser('admin');
    $page = $b->request('GET', '/admin/media');
    assert_same(200, $page->status);
    assert_contains('data-nml-panel="list"', $page->body);
    assert_contains('"table":"media.library"', $page->body);
    $json = $b->request('GET', '/admin/media/json?per_page=2', null, ['Accept' => 'application/json']);
    assert_same(200, $json->status, 'GET /admin/media/json is kept for older callers');
    assert_true(isset($json->json()['meta']['total']));
});
