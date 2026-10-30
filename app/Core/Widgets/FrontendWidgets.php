<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Core\Cache;
use App\Core\HookRegistry;
use App\Core\Logger;
use App\Core\WidgetAreaRegistry;
use App\Core\WidgetRegistry;

/**
 * The public site's widgets (1.2.93): placing instances in areas, and
 * rendering them.
 *
 * Three separate things, kept apart:
 *   definitions  what a widget is — WidgetRegistry (core, apps, themes)
 *   instances    one configured copy of a widget — Placements (stored)
 *   areas        where instances go — WidgetAreaRegistry (themes, apps)
 *
 * Rendering one instance:
 *   1. the definition (following renamed keys), and whether it is available:
 *      registered for the frontend, the apps it requires active, enabled
 *   2. its settings: migrated if saved under an older version, then
 *      normalised against the schema (anything invalid falls back to its
 *      default, so a template never sees a value it did not expect)
 *   3. cached output, for visitors who are not signed in only
 *   4. the data provider, then the template: the theme's
 *      templates/widgets/{area}/{key}.php, the theme's
 *      templates/widgets/{key}.php, the definition's own template; a widget
 *      with no template uses its `render` callable, as before 1.2.93
 *   5. the title (template widgets) and the area's wrapper markup
 *   6. the widget's assets, once per page, never cached with its output
 * A failure at any step costs that one instance: it is logged, and the rest
 * of the area renders.
 *
 * The admin dashboard has its own widget framework (DashboardService); this
 * class does not touch it.
 */
final class FrontendWidgets
{
    /** Cache keys start with this, so one tag flush clears them all. */
    public const CACHE_TAG = 'widgets';

    /** @var array<string,bool> assets already printed on this page */
    private array $printed = [];

    /** @var array<string,true>|null */
    private ?array $activeApps = null;

    /**
     * @param array{user?:callable():?array, theme?:callable():string, theme_path?:callable():string,
     *              apps?:callable():array, app_active?:callable(string):bool, preview?:callable():bool, request?:callable():array} $env
     *        how to read the request around the widgets; each is optional
     */
    public function __construct(
        private WidgetRegistry $widgets,
        private WidgetAreaRegistry $areas,
        private Placements $placements,
        private ?Cache $cache = null,
        private ?Logger $logger = null,
        private ?HookRegistry $hooks = null,
        private array $env = [],
    ) {}

    public function placements(): Placements
    {
        return $this->placements;
    }

    // ── Definitions and availability ────────────────────────────────────────

    /** The full definition of a frontend widget, or null. */
    public function definition(string $key): ?array
    {
        $w = $this->widgets->get($key);
        return $w && in_array('frontend', $w['surfaces'], true) ? $w : null;
    }

    /**
     * Why an instance of $key cannot render, or null when it can.
     * Reasons are short sentences for the admin.
     */
    public function unavailable(string $key): ?string
    {
        $w = $this->widgets->get($key);
        if (!$w) return 'This widget is no longer installed. Its settings are kept, so it comes back if its app or theme is switched on again.';
        if (!in_array('frontend', $w['surfaces'], true)) return 'This widget cannot be shown on the public site.';
        $missing = [];
        foreach ($w['frontend']['requires'] ?? [] as $slug) {
            if (!$this->appActive($slug)) $missing[] = $slug;
        }
        if ($missing) return 'Needs the ' . implode(', ', $missing) . ' app' . (count($missing) > 1 ? 's' : '') . ' to be active.';
        return null;
    }

    /** Why $areaKey does not accept $widgetKey, or null when it does. */
    public function refusal(string $areaKey, string $widgetKey, int $currentCount = -1): ?string
    {
        $area = $this->areas->get($areaKey);
        if (!$area) return 'This area is not part of the active theme.';
        $w = $this->definition($widgetKey);
        if (!$w) return 'This widget cannot be shown on the public site.';
        $front = $w['frontend'] ?? [];
        if (!empty($front['areas']) && !in_array($area['key'], $front['areas'], true)) {
            return '"' . $w['title'] . '" can only be placed in: ' . implode(', ', $front['areas']) . '.';
        }
        $supports = $area['supports'] ?? [];
        $keys = $area['widgets'] ?? [];
        if (($supports || $keys) && !in_array($w['key'], $keys, true) && !in_array($front['category'] ?? 'other', $supports, true)) {
            return '"' . $area['name'] . '" does not take this kind of widget.';
        }
        $max = (int) ($area['max_widgets'] ?? 0);
        if ($currentCount < 0) $currentCount = count($this->placements->area($area['key']));
        if ($max > 0 && $currentCount >= $max) return '"' . $area['name'] . '" holds at most ' . $max . ' widget' . ($max > 1 ? 's' : '') . '.';
        if ($currentCount >= Placements::MAX_PER_AREA) return 'An area holds at most ' . Placements::MAX_PER_AREA . ' widgets.';
        return null;
    }

    public function schema(array $def): Schema
    {
        return new Schema($def['fields'] ?? []);
    }

    /** Settings ready to render: migrated from an older version, then normalised. */
    public function settingsFor(array $def, array $inst): array
    {
        $settings = $inst['settings'] ?? [];
        $front = $def['frontend'] ?? [];
        $from = (string) ($inst['v'] ?? '');
        if (isset($front['migrate']) && $from !== ($front['version'] ?? '')) {
            $migrated = ($front['migrate'])($settings, $from === '' ? '0' : $from);
            if (is_array($migrated)) $settings = $migrated;
        }
        return $this->schema($def)->normalise($settings);
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /**
     * An area's widgets as HTML, in the area's wrapper. '' when the area is
     * unknown or nothing in it renders, so a theme can call this freely.
     *
     * $opts: 'class' (extra wrapper class), 'wrapper' (false: no outer div).
     */
    public function renderArea(string $areaKey, array $opts = []): string
    {
        try {
            $area = $this->areas->get($areaKey);
            if (!$area) return '';
            $out = '';
            foreach ($this->placements->area($area['key']) as $inst) {
                $out .= $this->renderInstance($area, $inst);
            }
            if ($out === '') return '';
            if (($opts['wrapper'] ?? true) === false) return $out;
            $cls = trim('widget-area widget-area--' . $area['key'] . ' ' . $area['class'] . ' ' . Placements::cleanClass((string) ($opts['class'] ?? '')));
            return '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . $out . '</div>';
        } catch (\Throwable $e) {
            $this->log('error', "Widget area \"{$areaKey}\" failed: " . $e->getMessage());
            return '';
        }
    }

    /** Whether an area would show anything: enabled, available instances. */
    public function hasWidgets(string $areaKey): bool
    {
        $area = $this->areas->get($areaKey);
        if (!$area) return false;
        foreach ($this->placements->area($area['key']) as $inst) {
            if (!$inst['disabled'] && $this->unavailable($inst['widget']) === null) return true;
        }
        return false;
    }

    /**
     * One instance, wrapped. '' when it is disabled, unavailable, or has
     * nothing to show. Never throws.
     *
     * $opts: 'preview' => true renders without the cache (the admin preview).
     */
    public function renderInstance(array $area, array $inst, array $opts = []): string
    {
        $key = (string) ($inst['widget'] ?? '');
        try {
            if (!empty($inst['disabled']) && empty($opts['preview'])) return '';
            if (($why = $this->unavailable($key)) !== null) {
                return '<!-- bh-widget "' . $this->attr($key) . '" not shown: unavailable -->';
            }
            $def = $this->widgets->get($key);
            $front = $def['frontend'] ?? [];
            $settings = $this->settingsFor($def, $inst);
            if (!empty($inst['hide_title'])) $settings['title'] = '';
            $context = $this->context($area, $inst);

            $body = null;
            $cacheKey = empty($opts['preview']) ? $this->cacheKey($def, $settings, $area, $inst, $context) : null;
            if ($cacheKey !== null) {
                $hit = $this->cache->get($cacheKey);
                if (is_string($hit)) $body = $hit;
            }
            if ($body === null) {
                $body = $this->body($def, $settings, $area, $inst, $context);
                if ($cacheKey !== null) $this->cache->set($cacheKey, $body, (int) $front['cache']['ttl']);
            }
            if ($this->hooks) {
                $body = (string) $this->hooks->applyFilters('widget.output', $body, $def['key'], $inst, $area['key']);
            }
            if (trim($body) === '') return '';

            $classes = trim('widget-' . preg_replace('/[^a-z0-9_-]/', '-', $def['key']) . ' ' . $inst['class']);
            $before = str_replace(['%1$s', '%2$s', '%3$s'],
                [$this->attr((string) $inst['id']), $this->attr($classes), $this->attr($def['key'])], $area['before_widget']);
            return $this->assets($def) . $before . $body . $area['after_widget'];
        } catch (\Throwable $e) {
            $this->log('error', "Widget \"{$key}\" (instance " . ($inst['id'] ?? '?') . ") failed in area \"{$area['key']}\": " . $e->getMessage());
            return '<!-- bh-widget "' . $this->attr($key) . '" failed to render -->';
        }
    }

    /** The widget's own markup: title + template, or its render callable. */
    private function body(array $def, array $settings, array $area, array $inst, array $context): string
    {
        $front = $def['frontend'] ?? [];
        $data = [];
        if (isset($front['data'])) {
            $data = ($front['data'])($settings, $context);
            if (!is_array($data)) $data = [];
        }

        $template = $this->templateFor($def, $area['key']);
        if ($template === null) {
            if (!is_callable($def['render'] ?? null)) return '';
            // Before 1.2.93 a widget printed its own title with these.
            $legacy = $settings;
            $legacy['__before_title'] = $area['before_title'];
            $legacy['__after_title'] = $area['after_title'];
            $legacy['__context'] = $context;
            $html = ($def['render'])($legacy, 'frontend');
            return is_string($html) ? $html : '';
        }

        $vars = [
            'settings' => $settings,
            'data'     => $data,
            'widget'   => WidgetRegistry::publicDefinition($def),
            'instance' => ['id' => $inst['id'], 'class' => $inst['class']],
            'area'     => ['key' => $area['key'], 'name' => $area['name'],
                           'before_title' => $area['before_title'], 'after_title' => $area['after_title']],
            'context'  => $context,
        ];
        $html = is_string($template) ? self::include($template, $vars) : self::buffer(fn() => $template($vars));
        if (trim($html) === '') return '';

        $title = '';
        if (!empty($front['title']) && trim((string) ($settings['title'] ?? '')) !== '') {
            $title = $area['before_title'] . htmlspecialchars(trim((string) $settings['title']), ENT_QUOTES, 'UTF-8') . $area['after_title'];
        }
        return $title . $html;
    }

    /**
     * Which template renders $def in $areaKey: a .php path, a callable, or
     * null for the widget's render callable.
     */
    public function templateFor(array $def, string $areaKey): string|callable|null
    {
        $dir = $this->themePath();
        if ($dir !== '') {
            $names = [$def['key']];
            // Core widgets may be overridden by their short name too: recent-posts.php.
            if (str_starts_with($def['key'], 'core.')) $names[] = substr($def['key'], 5);
            // The area's own folder first, then the general one.
            foreach (["templates/widgets/{$areaKey}/", 'templates/widgets/'] as $folder) {
                foreach ($names as $name) {
                    $f = $dir . '/' . $folder . $name . '.php';
                    if (is_file($f)) return $f;
                }
            }
        }
        return $def['frontend']['template'] ?? null;
    }

    /** A template callable: what it returns, or what it prints. */
    private static function buffer(callable $fn): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $ret = $fn();
            $printed = (string) ob_get_clean();
            return is_string($ret) && $ret !== '' ? $printed . $ret : $printed;
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
    }

    /** A template file, in a scope of its own: only $vars, plus $e for escaping. */
    private static function include(string $__file, array $__vars): string
    {
        $render = static function () use ($__file, $__vars): void {
            extract($__vars, EXTR_SKIP);
            $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            require $__file;
        };
        $level = ob_get_level();
        ob_start();
        try {
            $render();
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
    }

    /**
     * Only for visitors who are not signed in, outside previews, on GET, and
     * when the widget asks for it. Everything the output depends on is in
     * the key; content changes clear the whole tag (see flush()).
     */
    private function cacheKey(array $def, array $settings, array $area, array $inst, array $context): ?string
    {
        $c = $def['frontend']['cache'] ?? ['ttl' => 0];
        if (!$this->cache || (int) ($c['ttl'] ?? 0) <= 0) return null;
        if ($context['user'] !== null || $context['preview'] || $context['method'] !== 'GET') return null;
        $vary = $c['vary'] ?? [];
        $extra = '';
        if (is_callable($vary)) {
            $extra = $vary($settings, $context);
            if ($extra === null) return null;
            $extra = (string) $extra;
        } else {
            if (in_array('url', $vary, true)) $extra .= '|' . $context['url'];
            elseif (in_array('path', $vary, true)) $extra .= '|' . $context['path'];
        }
        $parts = [
            $def['key'], $def['frontend']['version'] ?? '', $settings, $inst['id'], $inst['class'],
            $context['theme'], $area['key'], $area['before_title'], $area['after_title'],
            $this->templateFor($def, $area['key']) === null ? 'r' : 't:' . (is_string($t = $this->templateFor($def, $area['key'])) ? $t . @filemtime($t) : 'fn'),
            $extra,
        ];
        return self::CACHE_TAG . '.' . sha1(serialize($parts));
    }

    /** Clear every cached widget output. Runs on content, term, comment, media and widget changes. */
    public function flush(): void
    {
        try { $this->cache?->flushTag(self::CACHE_TAG); } catch (\Throwable) {}
    }

    /** The widget's assets, the first time it appears on this page. */
    private function assets(array $def): string
    {
        $a = $def['frontend']['assets'] ?? [];
        if (isset($this->printed[$def['key']])) return '';
        $this->printed[$def['key']] = true;
        $out = '';
        foreach ($a['css'] ?? [] as $url) {
            if (isset($this->printed['css:' . $url])) continue;
            $this->printed['css:' . $url] = true;
            $out .= '<link rel="stylesheet" href="' . $this->attr($url) . '">';
        }
        // Inline code is printed once per page even when two widgets share it.
        if (($a['inline_css'] ?? '') !== '' && !isset($this->printed['ic:' . md5($a['inline_css'])])) {
            $this->printed['ic:' . md5($a['inline_css'])] = true;
            $out .= '<style data-bh-widget="' . $this->attr($def['key']) . '">' . $a['inline_css'] . '</style>';
        }
        foreach ($a['js'] ?? [] as $url) {
            if (isset($this->printed['js:' . $url])) continue;
            $this->printed['js:' . $url] = true;
            $out .= '<script src="' . $this->attr($url) . '" defer></script>';
        }
        if (($a['inline_js'] ?? '') !== '' && !isset($this->printed['ij:' . md5($a['inline_js'])])) {
            $this->printed['ij:' . md5($a['inline_js'])] = true;
            $out .= '<script data-bh-widget="' . $this->attr($def['key']) . '">' . $a['inline_js'] . '</script>';
        }
        return $out;
    }

    /** What a data provider and template may know about the request. */
    private function context(array $area, array $inst): array
    {
        $req = $this->call('request', null);
        if (!is_array($req)) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $req = ['method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), 'url' => $uri];
        }
        $url = (string) ($req['url'] ?? '/');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return [
            'area'     => $area['key'],
            'instance' => (string) $inst['id'],
            'user'     => $this->call('user', null),
            'theme'    => (string) $this->call('theme', ''),
            'preview'  => (bool) $this->call('preview', false),
            'method'   => strtoupper((string) ($req['method'] ?? 'GET')),
            'url'      => $url,
            'path'     => rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            'base'     => $base,
        ];
    }

    // ── Changes (each one under the placements row lock) ────────────────────

    /**
     * Place a new instance of $widgetKey in $areaKey at $position (end when
     * null). Settings start from the schema's defaults plus $settings.
     */
    public function add(string $areaKey, string $widgetKey, array $settings = [], ?int $position = null, ?callable $can = null): array
    {
        $area = $this->areas->get($areaKey);
        if (!$area) throw new WidgetError('That widget area does not exist in the active theme.', [], 404);
        $def = $this->definition($widgetKey);
        if (!$def) throw new WidgetError('That widget does not exist or cannot be shown on the public site.', [], 404);
        if (($why = $this->unavailable($def['key'])) !== null) throw new WidgetError($why);

        $schema = $this->schema($def);
        $values = $schema->defaults();
        if ($settings) {
            [$values, $errors] = $schema->validate($settings + $values, $values, $can ?? fn() => true);
            if ($errors) throw new WidgetError('Some settings need attention.', $errors);
        }
        $inst = ['id' => Placements::newId(), 'widget' => $def['key'], 'settings' => $values,
                 'v' => $def['frontend']['version'] ?? '', 'class' => '', 'hide_title' => false, 'disabled' => false];

        $this->placements->change(function (array $all) use ($area, $def, $inst, $position) {
            $list = $all[$area['key']] ?? [];
            if (($why = $this->refusal($area['key'], $def['key'], count($list))) !== null) throw new WidgetError($why);
            $pos = $position === null ? count($list) : max(0, min(count($list), $position));
            array_splice($list, $pos, 0, [$inst]);
            $all[$area['key']] = $list;
            return $all;
        });
        $this->changed('added', $inst['id']);
        return $inst;
    }

    /**
     * Save an instance's settings and options. $input holds the settings
     * fields; $options may carry 'class', 'hide_title', 'disabled'.
     *
     * @throws WidgetError 422 with errors by field, 404 for an unknown instance
     */
    public function save(string $id, array $input, array $options = [], ?callable $can = null): array
    {
        $found = $this->placements->find($id);
        if (!$found) throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
        $def = $this->widgets->get($found[2]['widget']);
        if (!$def) throw new WidgetError('This widget is no longer installed, so its settings cannot be changed.', [], 404);

        // Validate against the settings as they are (migrated), so a field the
        // user may not change keeps its value.
        $current = $this->settingsFor($def, $found[2]);
        [$values, $errors] = $this->schema($def)->validate($input, $current, $can ?? fn() => true);
        if ($errors) throw new WidgetError('Some settings need attention.', $errors);

        $saved = null;
        $this->placements->change(function (array $all) use ($id, $values, $options, $def, &$saved) {
            foreach ($all as $a => $list) {
                foreach ($list as $i => $inst) {
                    if ($inst['id'] !== $id) continue;
                    $inst['settings'] = $values;
                    $inst['v'] = $def['frontend']['version'] ?? '';
                    if (array_key_exists('class', $options)) $inst['class'] = Placements::cleanClass((string) $options['class']);
                    if (array_key_exists('hide_title', $options)) $inst['hide_title'] = self::truthy($options['hide_title']);
                    if (array_key_exists('disabled', $options)) $inst['disabled'] = self::truthy($options['disabled']);
                    $all[$a][$i] = $saved = $inst;
                    return $all;
                }
            }
            throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
        });
        $this->changed('saved', $id);
        return $saved;
    }

    /** Change only the options (enable/disable, hide title, CSS class). */
    public function setOptions(string $id, array $options): array
    {
        $saved = null;
        $this->placements->change(function (array $all) use ($id, $options, &$saved) {
            foreach ($all as $a => $list) {
                foreach ($list as $i => $inst) {
                    if ($inst['id'] !== $id) continue;
                    if (array_key_exists('class', $options)) $inst['class'] = Placements::cleanClass((string) $options['class']);
                    if (array_key_exists('hide_title', $options)) $inst['hide_title'] = self::truthy($options['hide_title']);
                    if (array_key_exists('disabled', $options)) $inst['disabled'] = self::truthy($options['disabled']);
                    $all[$a][$i] = $saved = $inst;
                    return $all;
                }
            }
            throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
        });
        $this->changed('saved', $id);
        return $saved;
    }

    public function remove(string $id): void
    {
        $this->placements->change(function (array $all) use ($id) {
            foreach ($all as $a => $list) {
                foreach ($list as $i => $inst) {
                    if ($inst['id'] !== $id) continue;
                    array_splice($all[$a], $i, 1);
                    return $all;
                }
            }
            throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
        });
        $this->changed('removed', $id);
    }

    /** Move an instance to $areaKey (any area: the same or another) at $position. */
    public function move(string $id, string $areaKey, int $position): void
    {
        $area = $this->areas->get($areaKey);
        if (!$area) throw new WidgetError('That widget area does not exist in the active theme.', [], 404);
        $this->placements->change(function (array $all) use ($id, $area, $position) {
            $moving = null;
            foreach ($all as $a => $list) {
                foreach ($list as $i => $inst) {
                    if ($inst['id'] !== $id) continue;
                    $moving = $inst;
                    if ($a !== $area['key'] && ($why = $this->refusal($area['key'], $inst['widget'], count($all[$area['key']] ?? []))) !== null) {
                        throw new WidgetError($why);
                    }
                    array_splice($all[$a], $i, 1);
                    break 2;
                }
            }
            if ($moving === null) throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
            $list = $all[$area['key']] ?? [];
            array_splice($list, max(0, min(count($list), $position)), 0, [$moving]);
            $all[$area['key']] = $list;
            return $all;
        });
        $this->changed('moved', $id);
    }

    /** Put an area's instances in this order; ids not listed keep their order at the end. */
    public function order(string $areaKey, array $ids): void
    {
        $this->placements->change(function (array $all) use ($areaKey, $ids) {
            $list = $all[$areaKey] ?? [];
            $byId = [];
            foreach ($list as $inst) $byId[$inst['id']] = $inst;
            $new = [];
            foreach ($ids as $id) {
                $id = (string) $id;
                if (isset($byId[$id])) { $new[] = $byId[$id]; unset($byId[$id]); }
            }
            $all[$areaKey] = array_merge($new, array_values($byId));
            return $all;
        });
        $this->changed('ordered', $areaKey);
    }

    /** A copy of an instance, placed right after it. */
    public function duplicate(string $id): array
    {
        $copy = null;
        $this->placements->change(function (array $all) use ($id, &$copy) {
            foreach ($all as $a => $list) {
                foreach ($list as $i => $inst) {
                    if ($inst['id'] !== $id) continue;
                    if (($why = $this->refusal($a, $inst['widget'], count($list))) !== null && $this->areas->has($a)) {
                        throw new WidgetError($why);
                    }
                    $copy = $inst;
                    $copy['id'] = Placements::newId();
                    array_splice($all[$a], $i + 1, 0, [$copy]);
                    return $all;
                }
            }
            throw new WidgetError('That widget is no longer placed. Reload the page.', [], 404);
        });
        $this->changed('duplicated', $copy['id']);
        return $copy;
    }

    /** Settings with private fields left out: what the public API may show. */
    public function publicSettings(array $inst): array
    {
        $def = $this->widgets->get((string) ($inst['widget'] ?? ''));
        $settings = is_array($inst['settings'] ?? null) ? $inst['settings'] : [];
        if (!$def) return [];
        $out = [];
        foreach ($def['fields'] ?? [] as $f) {
            if (!empty($f['private']) || Schema::type($f) === 'html') continue;
            if (array_key_exists($f['key'], $settings)) $out[$f['key']] = $settings[$f['key']];
        }
        return $out;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function changed(string $what, string $id): void
    {
        $this->flush();
        try { $this->hooks?->doAction('widgets.changed', $what, $id); } catch (\Throwable $e) {
            $this->log('warning', 'widgets.changed listener failed: ' . $e->getMessage());
        }
    }

    private function appActive(string $slug): bool
    {
        $fn = $this->env['app_active'] ?? null;
        if (is_callable($fn)) {
            try { return (bool) $fn($slug); } catch (\Throwable) { return false; }
        }
        if ($this->activeApps === null) {
            $this->activeApps = [];
            foreach ((array) $this->call('apps', []) as $s) $this->activeApps[(string) $s] = true;
        }
        return isset($this->activeApps[$slug]);
    }

    private function themePath(): string
    {
        return (string) $this->call('theme_path', '');
    }

    private function call(string $name, mixed $default): mixed
    {
        $fn = $this->env[$name] ?? null;
        if (!is_callable($fn)) return $default;
        try { return $fn(); } catch (\Throwable) { return $default; }
    }

    private function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || in_array(strtolower(trim((string) (is_scalar($v) ? $v : ''))), ['1', 'true', 'on', 'yes'], true);
    }

    private function log(string $level, string $message): void
    {
        try { $this->logger?->log($level, '[widgets] ' . $message); } catch (\Throwable) {}
    }
}
