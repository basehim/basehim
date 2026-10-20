<?php

declare(strict_types=1);

/*
 * 1.2.66: EnvFile changes one simple .env value and nothing else.
 */

use App\Core\EnvFile;

function envfile_tmp(string $contents): string
{
    $f = tempnam(sys_get_temp_dir(), 'bhenv');
    file_put_contents($f, $contents);
    return $f;
}

test('EnvFile: rewrites only the key\'s line and keeps every other line byte for byte', function () {
    $orig = "# comment\nAPP_KEY=abc\n\nDB_PASSWORD=\"p@ss word\"\nADMIN_EMAIL=old@example.com\nAPP_URL=https://x.test\n";
    $f = envfile_tmp($orig);
    assert_true(EnvFile::set($f, 'ADMIN_EMAIL', 'new@example.com'));
    assert_same(str_replace('old@example.com', 'new@example.com', $orig), file_get_contents($f));
    unlink($f);
});

test('EnvFile: appends a missing key before the final newline; keeps CRLF files CRLF', function () {
    $f = envfile_tmp("A=1\nB=2\n");
    assert_true(EnvFile::set($f, 'ADMIN_EMAIL', 'a@b.test'));
    assert_same("A=1\nB=2\nADMIN_EMAIL=a@b.test\n", file_get_contents($f));
    unlink($f);
    $f = envfile_tmp("A=1\r\nADMIN_EMAIL=x@y.test\r\n");
    assert_true(EnvFile::set($f, 'ADMIN_EMAIL', 'z@y.test'));
    assert_same("A=1\r\nADMIN_EMAIL=z@y.test\r\n", file_get_contents($f));
    unlink($f);
});

test('EnvFile: every line for the key agrees afterwards (the last one wins when read)', function () {
    $f = envfile_tmp("ADMIN_EMAIL=a@x.test\nB=1\nexport ADMIN_EMAIL = b@x.test\n");
    assert_true(EnvFile::set($f, 'ADMIN_EMAIL', 'c@x.test'));
    assert_same("ADMIN_EMAIL=c@x.test\nB=1\nexport ADMIN_EMAIL=c@x.test\n", file_get_contents($f));
    unlink($f);
});

test('EnvFile: a value that could add a line or change parsing is refused, and nothing changes', function () {
    $orig = "ADMIN_EMAIL=a@x.test\nAPP_KEY=k\n";
    $f = envfile_tmp($orig);
    foreach (["b@x.test\nAPP_KEY=evil", "b@x.test #c", 'b"@x.test', "b'@x.test", 'b$x@x.test', 'b`x`@x.test', 'b\\x@x.test', 'a b@x.test', ''] as $bad) {
        assert_false(EnvFile::set($f, 'ADMIN_EMAIL', $bad), 'refused: ' . json_encode($bad));
    }
    foreach (['admin_email', 'ADMIN-EMAIL', '1ADMIN', 'APP KEY'] as $badKey) {
        assert_false(EnvFile::set($f, $badKey, 'v'), 'key refused: ' . $badKey);
    }
    assert_same($orig, file_get_contents($f));
    unlink($f);
});

test('EnvFile: keeps the file\'s permissions; a missing file is reported, not created', function () {
    $f = envfile_tmp("ADMIN_EMAIL=a@x.test\n");
    chmod($f, 0640);
    assert_true(EnvFile::set($f, 'ADMIN_EMAIL', 'b@x.test'));
    assert_same(0640, fileperms($f) & 0777);
    unlink($f);
    $missing = sys_get_temp_dir() . '/bh-no-such-env-' . bin2hex(random_bytes(4));
    assert_false(EnvFile::set($missing, 'ADMIN_EMAIL', 'a@x.test'));
    assert_false(is_file($missing));
});
