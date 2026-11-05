<?php

declare(strict_types=1);

/*
 * 1.2.99 — mail (BH-091 long lines, BH-092 Message-ID, BH-093 quoted From
 * names), the Customizer's identity options (tagline label, logo width, the
 * Site name / Site tagline switches, whole-image previews) and the data
 * table's row links by density. The row links run for real in
 * tests/js/quick-edit.test.js; the home page title is in
 * tests/Integration/Release1299Test.php.
 */

use App\Services\CustomizerService;
use App\Services\Mailer;

/** Mailer::buildMime() for one message. @return array{0:string[],1:string} */
function r99_mime(array $mail): array
{
    $m = new Mailer();
    $build = new ReflectionMethod($m, 'buildMime');
    return $build->invoke($m, $mail + [
        'to' => ['reader@example.org'], 'subject' => 'Hello', 'text' => 'Hello', 'html' => '<p>Hello</p>',
        'from' => 'noreply@example.org', 'from_name' => 'Northwind', 'reply_to' => null, 'headers' => [],
    ], false);
}

function r99_header(array $headers, string $name): ?string
{
    foreach ($headers as $h) if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
    return null;
}

/** The decoded text/html part of a built body. */
function r99_html_part(string $body): string
{
    preg_match("/Content-Type: text\\/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n(.*?)\r\n\r\n--/s", $body, $m);
    return quoted_printable_decode($m[1] ?? '');
}

test('BH-091: a long HTML body is sent quoted-printable; no line passes 998 (or 76) characters and it decodes back exactly', function () {
    $html = str_repeat('<p>Reset your password: <a href="https://example.org/reset?token=' . str_repeat('a', 64) . '">reset</a> — ünïcode</p>', 30);
    [, $body] = r99_mime(['html' => $html, 'text' => str_repeat('word ', 400)]);
    $longest = max(array_map('strlen', explode("\r\n", $body)));
    assert_true($longest <= 76, "longest line {$longest}");
    assert_not_contains('Content-Transfer-Encoding: 8bit', $body);
    assert_same($html, r99_html_part($body));
});

test('BH-091: a lone "." line is encoded, so sendmail without -i does not end the message there', function () {
    [, $body] = r99_mime(['html' => "<p>a</p>\n.\n<p>b</p>", 'text' => "a\n.\nb"]);
    assert_false((bool) preg_match('/^\.\r?$/m', $body), 'no line is a lone dot');
    assert_same("<p>a</p>\r\n.\r\n<p>b</p>", r99_html_part($body));
});

test('BH-092: every message has a Message-ID on the site\'s domain; an app\'s own is kept', function () {
    [$h] = r99_mime([]);
    assert_true((bool) preg_match('/^<[0-9a-f]{32}@[a-z0-9.-]+>$/', (string) r99_header($h, 'Message-ID')), (string) r99_header($h, 'Message-ID'));
    [$h2] = r99_mime([]);
    assert_true(r99_header($h, 'Message-ID') !== r99_header($h2, 'Message-ID'), 'unique');
    [$own] = r99_mime(['headers' => ['Message-ID: <booking-42@example.org>']]);
    assert_same(['Message-ID: <booking-42@example.org>'], array_values(array_filter($own, static fn($x) => stripos($x, 'message-id:') === 0)));
});

test('BH-093: From and Reply-To names with special characters are quoted; plain and non-ASCII names as before', function () {
    [$h] = r99_mime(['from_name' => 'Smith, Jones & "Co"', 'reply_to' => '"Doe, Jane" <jane@example.org>']);
    assert_same('"Smith, Jones & \\"Co\\"" <noreply@example.org>', r99_header($h, 'From'));
    assert_same('"Doe, Jane" <jane@example.org>', r99_header($h, 'Reply-To'));
    [$h] = r99_mime(['from_name' => 'Northwind Studio', 'reply_to' => 'help@example.org']);
    assert_same('Northwind Studio <noreply@example.org>', r99_header($h, 'From'));
    assert_same('help@example.org', r99_header($h, 'Reply-To'));
    [$h] = r99_mime(['from_name' => 'Zoë']);
    assert_same('=?UTF-8?B?' . base64_encode('Zoë') . '?= <noreply@example.org>', r99_header($h, 'From'));
    [$h] = r99_mime(['from_name' => 'Back\\slash (team)']);
    assert_same('"Back\\\\slash (team)" <noreply@example.org>', r99_header($h, 'From'));
});

test('BH-093: a line break in a name or subject cannot add a header', function () {
    [$h] = r99_mime(['from_name' => "Shop\r\nBcc: victim@example.org", 'subject' => "Hi\r\nBcc: victim@example.org"]);
    foreach ($h as $line) assert_false(stripos($line, 'Bcc:') === 0, $line);
    assert_same(1, count(array_filter($h, static fn($x) => stripos($x, 'From:') === 0)));
});

/** A CustomizerService with saved values and no theme options. */
function r99_customizer(array $saved = [], array $supports = ['custom-logo', 'logo-width', 'header-text']): CustomizerService
{
    $settings = new class($saved) extends \App\Services\SettingService {
        public function __construct(private array $saved) {}
        public function getGroup(string $group): array { return []; }
        public function get(string $group, string $key, $default = null) { return $this->saved[$group . '.' . $key] ?? $default; }
    };
    $themes = new class($supports) extends \App\Services\ThemeService {
        public function __construct(private array $sup) {}
        public function activeManifest(): array { return ['supports' => $this->sup]; }
        public function activeSlug(): string { return 'default'; }
    };
    return new CustomizerService($settings, $themes);
}

test('customizer: Site identity has Site tagline (BH-098), Logo width, and Site name / Site tagline switches on by default', function () {
    $opts = r99_customizer()->sections()['identity']['options'];
    assert_same(['site_title', 'tagline', 'logo_url', 'logo_width', 'show_site_title', 'show_tagline', 'favicon_url'], array_keys($opts));
    assert_same('Site tagline', $opts['tagline']['label']);
    assert_same(['range', 0, 400, 'px', 'Auto'], [$opts['logo_width']['type'], $opts['logo_width']['min'], $opts['logo_width']['max'], $opts['logo_width']['unit'], $opts['logo_width']['zero_label']]);
    assert_same(['toggle', 'Site name', true, 'Show in the header'], [$opts['show_site_title']['type'], $opts['show_site_title']['label'], $opts['show_site_title']['default'], $opts['show_site_title']['heading']]);
    assert_same(['toggle', 'Site tagline', true], [$opts['show_tagline']['type'], $opts['show_tagline']['label'], $opts['show_tagline']['default']]);
});

test('customizer: the logo width becomes --bh-logo-width (and the height auto); Auto adds nothing; custom CSS is never a variable', function () {
    assert_same('', r99_customizer(['appearance.custom_css' => 'a{color:red}'])->cssVariables(), 'nothing chosen: the theme\'s own size');
    assert_same(':root{--bh-logo-width: 160px;--bh-logo-height: auto}', r99_customizer(['appearance.logo_width' => 160])->cssVariables());
    // The preview: a pending value wins, 0 goes back to the theme's size, and out-of-range is clamped.
    $cz = r99_customizer(['appearance.logo_width' => 160]);
    assert_same('', $cz->cssVariablesFor(['identity.logo_width' => '0']));
    assert_same(':root{--bh-logo-width: 400px;--bh-logo-height: auto}', $cz->cssVariablesFor(['identity.logo_width' => '9000']));
});

test('customizer: switches use core\'s switch at the right of the label; the image preview shows the whole picture', function () {
    $root = dirname(__DIR__, 2);
    $view = (string) file_get_contents($root . '/admin/views/customizer/index.php');
    assert_contains('class="bh-switch__input"', $view);
    assert_not_contains('<span>Enabled</span>', $view);
    $css = (string) file_get_contents($root . '/admin/assets/css/customizer.css');
    assert_true((bool) preg_match('/\.bh-cz__thumb img \{[^}]*position: absolute;[^}]*object-fit: contain;/s', $css), 'image laid over the box and contained');
    assert_contains('.bh-cz__field--switch { display: flex;', $css);
    $js = (string) file_get_contents($root . '/admin/assets/js/media.js');
    assert_contains("'All images'", $js, 'a logo picker says it lists images');
});

test('default and Dark Night themes honour the switches and size the logo with the variables', function () {
    $root = dirname(__DIR__, 2);
    foreach (['default/templates/partials/header.php', 'dark-night/templates/partials/header.php'] as $f) {
        $php = (string) file_get_contents($root . '/content/themes/' . $f);
        assert_contains('$show_site_title ?? true', $php, $f);
        assert_contains('$show_tagline ?? true', $php, $f);
    }
    assert_contains('width: var(--bh-logo-width, auto); height: var(--bh-logo-height, 2.25rem)', (string) file_get_contents($root . '/content/themes/default/assets/theme.css'));
    assert_contains('width: var(--bh-logo-width, auto); height: var(--bh-logo-height, 36px)', (string) file_get_contents($root . '/content/themes/dark-night/assets/dark-night.css'));
});

test('data table: Comfortable hides the ⋮ column and shows the row links inside each row; Compact the reverse; the ⋮ is darker and larger', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/datatable.css');
    assert_contains('.bh-dt[data-density="compact"] .bh-dt-rowlinks { display: none; }', $css);
    assert_contains('.bh-dt-table tbody tr:hover .bh-dt-rowlinks', $css, 'the row\'s own hover shows them');
    assert_false(str_contains($css, '(hover: none) { .bh-dt-rowlinks'), 'never shown on every row at once');
    assert_contains('.bh-dt:not([data-density="compact"]) .bh-dt-table .bh-dt-rowactions { display: none; }', $css);
    assert_contains('.bh-dt-table tbody tr:focus-within .bh-dt-rowlinks', $css, 'keyboard users see them too');
    assert_contains('.bh-dt-actbtn { color: #475569;', $css);
    assert_contains('.bh-dt-actbtn svg { width: 1.25rem; height: 1.25rem; }', $css);
});
