<?php

declare(strict_types=1);

/*
 * 1.2.103 — the update supply chain. Packages (core, apps, themes) are signed
 * by the publisher offline and the signature is verified before anything is
 * installed; the checksum is now required, not optional; the version inside a
 * core zip must match; protected paths can't be slipped past on extract; the
 * site key travels in a header; and an app upgrade that adds OR drops a
 * permission is caught.
 */

use App\Services\PackageSignature;
use App\Services\UpdateService;

function r103_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('signatures: a real detached signature over kind+slug+version+hash verifies; tamper, wrong version/kind and a bad key are refused', function () {
    if (!function_exists('sodium_crypto_sign_keypair')) { assert_true(true, 'sodium missing — skipped'); return; }
    $pair = sodium_crypto_sign_keypair();
    $pub  = 'bhpk1:' . base64_encode(sodium_crypto_sign_publickey($pair));
    $sec  = sodium_crypto_sign_secretkey($pair);

    $zip = tempnam(sys_get_temp_dir(), 'r103') . '.zip';
    file_put_contents($zip, 'package-bytes');
    $sha = hash_file('sha256', $zip);
    $keyId = substr(hash('sha256', sodium_crypto_sign_publickey($pair)), 0, 16);
    $sign = fn(string $k, string $s, string $v) => 'bhsig1:' . $keyId . ':' .
        base64_encode(sodium_crypto_sign_detached(PackageSignature::message($k, $s, $v, $sha), $sec));

    // No key trusted → not required, nothing to check against.
    assert_false(PackageSignature::required());
    assert_same(null, PackageSignature::check($zip, 'core', 'basehim', '1.2.103', ''));

    // Trust our key through the .env hook (Env reads the loaded .env).
    \App\Core\Env::set('UPDATE_TRUSTED_KEYS', $pub);
    assert_true(PackageSignature::required());

    assert_same(null, PackageSignature::check($zip, 'core', 'basehim', '1.2.103', $sign('core', 'basehim', '1.2.103')));
    assert_true(PackageSignature::check($zip, 'core', 'basehim', '1.2.104', $sign('core', 'basehim', '1.2.103')) !== null, 'version is bound');
    assert_true(PackageSignature::check($zip, 'app', 'basehim', '1.2.103', $sign('core', 'basehim', '1.2.103')) !== null, 'kind is bound');
    assert_true(PackageSignature::check($zip, 'core', 'basehim', '1.2.103', '') !== null, 'an unsigned package is refused once a key is trusted');

    // A different key: a valid signature from an untrusted key is refused.
    $other = sodium_crypto_sign_keypair();
    $oid = substr(hash('sha256', sodium_crypto_sign_publickey($other)), 0, 16);
    $osig = 'bhsig1:' . $oid . ':' . base64_encode(sodium_crypto_sign_detached(
        PackageSignature::message('core', 'basehim', '1.2.103', $sha), sodium_crypto_sign_secretkey($other)));
    assert_true(PackageSignature::check($zip, 'core', 'basehim', '1.2.103', $osig) !== null, 'an untrusted key is refused');

    \App\Core\Env::set('UPDATE_TRUSTED_KEYS', '');
    assert_false(PackageSignature::required(), 'clearing the key turns enforcement back off');
    unlink($zip);
});

test('signature format: only a well-formed bhsig1 string is accepted', function () {
    assert_same('', PackageSignature::clean('nope'));
    assert_same('', PackageSignature::clean('bhsig1:xyz:short'));
    $ok = 'bhsig1:' . str_repeat('a', 16) . ':' . str_repeat('A', 86) . '==';
    assert_same($ok, PackageSignature::clean($ok));
});

test('extract paths: traversal, absolute and protected paths are refused however they are spelled', function () {
    // Protected and unsafe → null.
    foreach (['.env', './.env', 'config/app.php', 'config//app.php', './config/app.php',
              'storage/x.php', './storage/./y.php', '.htaccess', '../evil', '/etc/passwd',
              'C:/win', "a\0b"] as $bad) {
        assert_same(null, UpdateService::entryPath($bad), $bad . ' must be refused');
    }
    // Ordinary files pass, normalised.
    assert_same('app/Core/Router.php', UpdateService::entryPath('app/Core/Router.php'));
    assert_same('index.php', UpdateService::entryPath('basehim/index.php', 'basehim/'));
    assert_same('admin/x.js', UpdateService::entryPath('./admin/x.js'));
});

test('update client: one hub() path with header auth and same-host https redirects; download streamed, checksum required, signature + version checked', function () {
    $u = r103_file('app/Services/UpdateService.php');
    assert_contains('public function hub(', $u);
    assert_contains("'X-Site-Key: ' . \$siteKey", $u);
    assert_contains('CURLOPT_FOLLOWLOCATION => false', $u, 'redirects are followed by hand, host-checked');
    assert_contains('$sameHost', $u);
    assert_contains('CURLOPT_FILE', $u, 'the download streams to a file, never into memory');
    assert_contains("return ['ok' => false, 'error' => 'The update service did not give a checksum", $u, 'checksum required');
    assert_contains("PackageSignature::check(\$zipPath, 'core', 'basehim', \$version, \$signature)", $u);
    assert_contains('$inside = $this->versionInsideZip($zipPath);', $u);
    assert_false(str_contains($u, 'http_build_query([\'key\' => $c[\'key\']'), 'the key is no longer put in the download URL');
});

test('marketplace client: apps and themes verify checksum + signature and bind the slug', function () {
    $a = r103_file('app/Services/AppService.php');
    assert_contains('public function marketplaceInstall(string $slug, string $expectedSha256 = \'\', string $version = \'\', string $signature = \'\')', $a);
    assert_contains("PackageSignature::check(\$tmp, 'app', \$slug", $a);
    assert_contains("return ['ok' => false, 'error' => 'The marketplace gave no checksum", $a);
    assert_contains('installFromZip(string $zipPath, string $source = \'upload\', string $expectedSlug = \'\')', $a);
    assert_contains("\$conn['svc']->hub(", $a);

    $t = r103_file('app/Services/ThemeService.php');
    assert_contains('public function marketplaceInstall(string $slug, string $version = \'\', string $signature = \'\')', $t);
    assert_contains("PackageSignature::check(\$tmp, 'theme', \$slug", $t);
    assert_contains('The downloaded theme\'s slug', $t, 'the theme must be the one asked for');
    assert_false(str_contains($t, 'sys_get_temp_dir() . \'/bh-theme-'), 'downloads no longer land in the shared temp dir');
});

test('permissions: an upgrade that adds OR drops a permission is caught; dropping all does not silently unrestrict', function () {
    $b = r103_file('app/Services/PermissionBroker.php');
    assert_contains('public function consentedDeclared(string $slug): array', $b);
    assert_contains("'consented_permissions' => json_encode(\$declared", $b, 'the declared set at consent is recorded');
    assert_contains('$this->declared($slug) !== [] || $this->consentedDeclared($slug) !== []', $b, 'stays enforced on an empty new declaration');

    $a = r103_file('app/Services/AppService.php');
    assert_contains('$baseline = $broker->consentedDeclared($slug);', $a);
    assert_contains('array_diff($declared, $baseline) !== [] || array_diff($baseline, $declared) !== []', $a);

    assert_true(is_file(dirname(__DIR__, 2) . '/database/migrations/023_app_consented_permissions.sql'));
});
