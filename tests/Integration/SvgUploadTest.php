<?php

declare(strict_types=1);

/*
 * 1.2.51: SVG uploads are checked (SvgGuard) and served with a policy that
 * stops script. Authors can upload; an SVG with a script, opened directly,
 * ran with the site's origin and the viewer's session.
 */

function upload_as(Browser $b, string $name, string $bytes): HttpResponse
{
    $boundary = '----bh' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{$b->csrf}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\n"
        . "Content-Type: image/svg+xml\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
    return $b->request('POST', '/admin/media/upload', $body, [
        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest',
    ]);
}

test('svg upload: an SVG with script is refused, a clean one is accepted and served without script', function () {
    $b = bh_browser('author');
    $made = [];
    try {
        $evil = '<svg xmlns="http://www.w3.org/2000/svg" onload="fetch(\'/admin/users\')"><circle r="1"/></svg>';
        $res = upload_as($b, 'logo-' . bin2hex(random_bytes(3)) . '.svg', $evil);
        if (isset($res->json()['data']['id'])) $made[] = $res->json()['data'];
        assert_same(422, $res->status, $res->body);
        assert_contains('event handler', (string) ($res->json()['error'] ?? ''));

        $res = upload_as($b, 'clean-' . bin2hex(random_bytes(3)) . '.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><circle cx="1" cy="1" r="1"/></svg>');
        if (isset($res->json()['data']['id'])) $made[] = $res->json()['data'];
        assert_same(201, $res->status, $res->body);
        $file = (new Browser(bh_env()['base_url']))->request('GET', '/uploads/' . $res->json()['data']['storage_path']);
        assert_same(200, $file->status);
        assert_contains('sandbox', (string) $file->header('Content-Security-Policy'));
        assert_same('nosniff', (string) $file->header('X-Content-Type-Options'));
    } finally {
        foreach ($made as $row) {
            @unlink(BASEHIM_ROOT . '/storage/uploads/' . $row['storage_path']);
            bh_db()->execute('DELETE FROM {media} WHERE id = :i', ['i' => (int) $row['id']]);
        }
    }
});
