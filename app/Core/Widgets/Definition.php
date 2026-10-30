<?php

declare(strict_types=1);

namespace App\Core\Widgets;

/**
 * The `frontend` part of a widget definition (1.2.93). Every key is optional,
 * so a simple widget needs none of them:
 *
 *   category  'content' | 'navigation' | 'search' | 'media' | 'social' | 'ads'
 *             | 'utility' | 'other' (default 'other'): groups the admin picker,
 *             and lets an area accept whole kinds of widget
 *   version   the definition's version ('1.0.0'); saved instances remember
 *             it, and `migrate` runs when it changes
 *   migrate   fn(array $settings, string $fromVersion): array
 *   data      fn(array $settings, array $context): array — the data provider;
 *             its result reaches the template as $data
 *   template  the default template: an absolute .php path inside the
 *             owner's own folder, or fn(array $vars): string. Themes override
 *             it with templates/widgets/{key}.php
 *   title     true: the framework prints the instance title with the area's
 *             title markup (default: true for template widgets, false for
 *             `render` widgets, which print their own)
 *   cache     seconds, or ['ttl' => seconds, 'vary' => ['path'|'url'] or
 *             fn(array $settings, array $context): ?string]: output cached for
 *             visitors who are not signed in (see FrontendWidgets). A vary
 *             callable returning null means "do not cache this time".
 *   areas     area keys this widget may be placed in (default: any)
 *   assets    ['css' => [urls], 'js' => [urls], 'inline_css' => '…',
 *             'inline_js' => '…'], printed once per page before the first
 *             instance of the widget, and never cached with its output
 *   requires  app slugs that must be active; otherwise the widget is shown
 *             as unavailable and not rendered
 *   aliases   old keys of this widget, so saved instances keep working
 *   preview   false: the admin shows no live preview (widgets that need the
 *             page around them)
 */
final class Definition
{
    public const CATEGORIES = [
        'content' => 'Content', 'navigation' => 'Navigation', 'search' => 'Search', 'media' => 'Media',
        'social' => 'Social', 'ads' => 'Ads', 'utility' => 'Utility', 'other' => 'Other',
    ];

    /** Longest cache lifetime, seconds. */
    public const MAX_TTL = 86400;

    public static function normalise(array $f, string $owner): array
    {
        $out = [];
        $cat = (string) ($f['category'] ?? 'other');
        $out['category'] = isset(self::CATEGORIES[$cat]) ? $cat : 'other';
        $ver = (string) ($f['version'] ?? '1.0.0');
        $out['version'] = preg_match('/^\d+(\.\d+){0,3}([.-][0-9A-Za-z.-]+)?$/', $ver) ? $ver : '1.0.0';

        foreach (['data', 'migrate'] as $k) {
            if (isset($f[$k])) {
                if (!is_callable($f[$k])) throw new \InvalidArgumentException("'frontend.{$k}' must be callable.");
                $out[$k] = $f[$k];
            }
        }

        if (isset($f['template'])) {
            $t = $f['template'];
            if (is_string($t)) {
                $real = realpath($t);
                if ($real === false || !is_file($real) || !str_ends_with($real, '.php')) {
                    throw new \InvalidArgumentException("'frontend.template' must be an existing .php file.");
                }
                if (!self::inOwnerFolder($real, $owner)) {
                    throw new \InvalidArgumentException("'frontend.template' must be inside the widget owner's own folder.");
                }
                $out['template'] = $real;
            } elseif (is_callable($t)) {
                $out['template'] = $t;
            } else {
                throw new \InvalidArgumentException("'frontend.template' must be a .php path or a callable.");
            }
        }
        $out['title'] = array_key_exists('title', $f) ? (bool) $f['title'] : isset($out['template']);

        $cache = $f['cache'] ?? 0;
        $ttl = is_array($cache) ? (int) ($cache['ttl'] ?? 0) : (int) $cache;
        $vary = [];
        if (is_array($cache) && isset($cache['vary'])) {
            $v = $cache['vary'];
            if (is_callable($v) && !is_string($v)) $vary = $v;
            else $vary = array_values(array_intersect(['url', 'path'], array_map('strval', (array) $v)));
        }
        $out['cache'] = ['ttl' => max(0, min(self::MAX_TTL, $ttl)), 'vary' => $vary];

        $out['areas'] = self::keys($f['areas'] ?? []);
        $out['requires'] = array_values(array_filter(array_map('strval', (array) ($f['requires'] ?? [])),
            fn($s) => (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $s)));
        $out['aliases'] = array_values(array_filter(array_map('strval', (array) ($f['aliases'] ?? []))));
        $out['preview'] = !array_key_exists('preview', $f) || (bool) $f['preview'];

        $assets = is_array($f['assets'] ?? null) ? $f['assets'] : [];
        $clean = ['css' => [], 'js' => [], 'inline_css' => '', 'inline_js' => ''];
        foreach (['css', 'js'] as $k) {
            foreach ((array) ($assets[$k] ?? []) as $url) {
                $url = (string) $url;
                if ($url !== '' && Schema::safeUrl($url) && !str_starts_with($url, '#')) $clean[$k][] = $url;
            }
        }
        $inline = (string) ($assets['inline_css'] ?? '');
        if (stripos($inline, '</style') !== false) throw new \InvalidArgumentException("'frontend.assets.inline_css' may not close the style element.");
        $clean['inline_css'] = $inline;
        $inlineJs = (string) ($assets['inline_js'] ?? '');
        if (stripos($inlineJs, '</script') !== false) throw new \InvalidArgumentException("'frontend.assets.inline_js' may not close the script element.");
        $clean['inline_js'] = $inlineJs;
        $out['assets'] = $clean;

        return $out;
    }

    /** Without callables or paths: safe for the API and the admin. */
    public static function publicPart(array $front): array
    {
        $out = $front;
        unset($out['data'], $out['migrate'], $out['template']);
        if (!is_array($out['cache']['vary'] ?? [])) $out['cache']['vary'] = ['custom'];
        $out['has_template'] = isset($front['template']);
        $out['has_data'] = isset($front['data']);
        return $out;
    }

    /** @return list<string> */
    private static function keys(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $k) {
            $k = strtolower(trim((string) $k));
            if (preg_match('/^[a-z0-9._-]{1,100}$/', $k)) $out[] = $k;
        }
        return array_values(array_unique($out));
    }

    /** A core template lives under app/; an app's in its folder; a theme's in its folder. */
    private static function inOwnerFolder(string $real, string $owner): bool
    {
        $root = defined('BASEHIM_ROOT') ? (string) realpath(BASEHIM_ROOT) : '';
        if ($root === '') return false;
        if ($owner === 'core') {
            return str_starts_with($real, $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
        }
        if (preg_match('/^(app|theme):([a-z0-9][a-z0-9_-]*)$/', $owner, $m)) {
            $dir = realpath($root . '/content/' . ($m[1] === 'app' ? 'apps' : 'themes') . '/' . $m[2]);
            return $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR);
        }
        return false;
    }
}
