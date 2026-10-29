<?php

declare(strict_types=1);

/*
 * 1.2.91: media search matches every word, in any order, across the file's
 * details (BH-071). Folder asset URLs (BH-070) are tested in Release1290Test.
 */

function r1291_media(array $cols): int
{
    $tag = 'r1291-' . bin2hex(random_bytes(3));
    return (int) bh_db()->insert('media', $cols + [
        'uuid' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffff)),
        'author_id' => bh_env()['users']['admin'], 'alt_text' => '', 'mime_type' => 'image/png',
        'file_name' => $tag . '.png', 'original_name' => $tag . '.png', 'file_size' => 100,
        'storage_path' => 'tests/' . $tag . '.png', 'url' => '/uploads/tests/' . $tag . '.png',
    ]);
}

test('1.2.91 e2e: media search finds every word in any order, across title, file names, alt text and caption', function () {
    $ids = [
        'droplet' => r1291_media(['title' => 'Zqx Droplet Pricing 2026', 'original_name' => 'Zqx Droplet Pricing 2026.PNG', 'file_name' => 'zqx-droplet-pricing-2026.png']),
        'snip'    => r1291_media(['title' => 'Zqxsnip_2026-10-27_10-04-58', 'original_name' => 'Zqxsnip_2026-10-27_10-04-58.png', 'file_name' => 'zqxsnip-2026-10-27-10-04-58.png']),
        'caption' => r1291_media(['title' => 'avatar', 'caption' => 'Zqx team photo, Lahore office']),
        'alt'     => r1291_media(['title' => 'b7e82a1a-zqx', 'alt_text' => 'Zqx Specified Key Was Too Long']),
        'stored'  => r1291_media(['title' => 'IMG_0001', 'original_name' => 'IMG_0001.jpg', 'file_name' => 'zqx-server-rack.png']),
    ];
    try {
        $b = bh_browser('admin');
        $find = function (string $q) use ($b, $ids): array {
            $j = $b->ajaxGet('media.list', ['q' => $q, 'per_page' => 50])->json();
            assert_true($j['success'] ?? false, json_encode($j));
            $byId = array_flip($ids);
            $out = [];
            foreach ($j['data']['data'] as $m) if (isset($byId[(int) $m['id']])) $out[] = $byId[(int) $m['id']];
            sort($out);
            return $out;
        };
        assert_same(['droplet'], $find('zqx pricing'), 'words apart');
        assert_same(['droplet'], $find('PRICING zqx'), 'any order and case');
        assert_same(['droplet'], $find('zqx-droplet'), 'hyphen as a word break');
        assert_same(['snip'], $find('zqxsnip 2026'), 'space where the name has _');
        assert_same(['caption'], $find('zqx lahore'), 'caption');
        assert_same(['alt'], $find('zqx too long'), 'alt text');
        assert_same(['stored'], $find('zqx rack'), 'stored file name');
        assert_same([], $find('zqx pricing azure'), 'every word must match');
        assert_same(['alt', 'caption', 'droplet', 'snip', 'stored'], $find('zqx'), 'part of a word');

        // The Data Table (List view) and the type counts use the same search.
        $t = $b->ajaxGet('content.table', ['table' => 'media.library', 'search' => 'zqx pricing'])->json();
        assert_same(1, $t['data']['meta']['total'] ?? null, json_encode($t['data']['meta'] ?? $t));
        $c = $b->ajaxGet('media.list', ['q' => 'zqx pricing'])->json()['data']['counts'] ?? [];
        assert_same(1, $c['all'] ?? null, json_encode($c));
    } finally {
        foreach ($ids as $id) bh_db()->execute('DELETE FROM {media} WHERE id = :i', ['i' => $id]);
    }
});
