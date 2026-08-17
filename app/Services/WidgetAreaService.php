<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\WidgetAreaRegistry;
use App\Core\WidgetRegistry;

/**
 * WidgetAreaService — the bridge between area *definitions* (WidgetAreaRegistry,
 * contributed by themes/apps) and the admin's *assignments* of concrete
 * widgets into those areas.
 *
 * Assignments are stored as one JSON settings row (group 'widgets', key 'areas'):
 *   { "sidebar": [ {"id":"w_ab12","widget":"core.recent-posts","settings":{...}}, ... ] }
 *
 * No new table is needed — this is site configuration, so it lives alongside the
 * other appearance settings and rides the same cache.
 */
class WidgetAreaService
{
    private const GROUP = 'widgets';
    private const KEY   = 'areas';

    public function __construct(
        private SettingService $settings,
        private WidgetAreaRegistry $areas,
        private WidgetRegistry $widgets,
        private ?\App\Core\Logger $logger = null
    ) {}

    /** @return array<int,array> registered area definitions. */
    public function definitions(): array
    {
        return $this->areas->all();
    }

    /** @return array<string,array<int,array>> all area→instances assignments. */
    public function assignments(): array
    {
        $raw = $this->settings->get(self::GROUP, self::KEY, []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /** @return array<int,array> the ordered instances placed in one area. */
    public function assignmentsFor(string $area): array
    {
        $all = $this->assignments();
        $list = $all[$area] ?? [];
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Append a frontend widget to an area. Returns the new instance, or null if
     * the area or widget is unknown, or the widget can't render on the frontend.
     */
    public function addWidget(string $area, string $widgetKey): ?array
    {
        if (!$this->areas->has($area)) return null;
        $widget = $this->widgets->get($widgetKey);
        if (!$widget || !in_array('frontend', $widget['surfaces'], true)) return null;

        $instance = [
            'id'       => $this->newId(),
            'widget'   => $widget['key'],
            'settings' => [],
        ];

        $all = $this->assignments();
        $all[$area] = $this->assignmentsFor($area);
        $all[$area][] = $instance;
        $this->save($all);

        return $instance;
    }

    public function updateWidget(string $area, string $id, array $settings): bool
    {
        $all = $this->assignments();
        $list = $this->assignmentsFor($area);
        $changed = false;
        foreach ($list as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['settings'] = $this->cleanSettings($settings);
                $changed = true;
                break;
            }
        }
        unset($item);
        if (!$changed) return false;
        $all[$area] = $list;
        $this->save($all);
        return true;
    }

    public function removeWidget(string $area, string $id): bool
    {
        $all = $this->assignments();
        $list = $this->assignmentsFor($area);
        $filtered = array_values(array_filter($list, fn($i) => ($i['id'] ?? '') !== $id));
        if (count($filtered) === count($list)) return false;
        $all[$area] = $filtered;
        $this->save($all);
        return true;
    }

    /** Move an instance one step up or down within its area. */
    public function moveWidget(string $area, string $id, string $dir): bool
    {
        $list = $this->assignmentsFor($area);
        $idx = null;
        foreach ($list as $i => $item) {
            if (($item['id'] ?? '') === $id) { $idx = $i; break; }
        }
        if ($idx === null) return false;

        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($list)) return false;

        [$list[$idx], $list[$swap]] = [$list[$swap], $list[$idx]];
        $all = $this->assignments();
        $all[$area] = array_values($list);
        $this->save($all);
        return true;
    }

    /** Reorder an area from an explicit list of instance ids (unknown ids ignored). */
    public function reorder(string $area, array $orderedIds): bool
    {
        $list = $this->assignmentsFor($area);
        if (!$list) return false;
        $byId = [];
        foreach ($list as $item) { $byId[$item['id'] ?? ''] = $item; }

        $new = [];
        foreach ($orderedIds as $id) {
            $id = (string) $id;
            if (isset($byId[$id])) { $new[] = $byId[$id]; unset($byId[$id]); }
        }
        // Anything the client didn't mention keeps its relative order at the end.
        foreach ($byId as $leftover) { $new[] = $leftover; }

        $all = $this->assignments();
        $all[$area] = $new;
        $this->save($all);
        return true;
    }

    /**
     * Render every widget assigned to an area to HTML, wrapped in the area's
     * markup. Returns '' when the area is unknown or empty, so a theme can call
     * this unconditionally. Never throws — a broken widget degrades to a comment.
     */
    public function render(string $area): string
    {
        $def = $this->areas->get($area);
        if (!$def) return '';

        $instances = $this->assignmentsFor($area);
        if (!$instances) return '';

        $out = '';
        foreach ($instances as $inst) {
            $key = (string) ($inst['widget'] ?? '');
            $widget = $this->widgets->get($key);
            if (!$widget || !in_array('frontend', $widget['surfaces'], true)) continue;

            $settings = is_array($inst['settings'] ?? null) ? $inst['settings'] : [];
            // Hand the theme's title wrappers to the widget so it can style its
            // own heading consistently with the area.
            $settings['__before_title'] = $def['before_title'];
            $settings['__after_title']  = $def['after_title'];

            /*
             * WidgetRegistry guards the render callback itself, but not the work
             * around it — reading the definition, building the wrapper. A failure
             * here would take the whole area, and with it the sidebar or footer
             * of every page. One widget failing should cost one widget.
             */
            try {
                $html = $this->widgets->render($key, $settings, 'frontend');
            } catch (\Throwable $e) {
                try {
                    $this->logger?->error(
                        'Widget "' . $key . '" failed in area "' . $area . '": ' . $e->getMessage()
                    );
                } catch (\Throwable) {}
                continue;   // skip this widget, keep the rest of the area
            }

            $wrapClass = trim('widget-' . preg_replace('/[^a-z0-9_-]/', '-', $key));
            $before = str_replace(['%1$s', '%2$s'], [htmlspecialchars((string) $inst['id'], ENT_QUOTES), $wrapClass], $def['before_widget']);
            $out .= $before . $html . $def['after_widget'];
        }

        if ($out === '') return '';

        $areaClass = trim('widget-area widget-area--' . $area . ' ' . $def['class']);
        return '<div class="' . htmlspecialchars($areaClass, ENT_QUOTES) . '">' . $out . '</div>';
    }

    /** True when an area exists and has at least one placed widget. */
    public function isActive(string $area): bool
    {
        return $this->areas->has($area) && count($this->assignmentsFor($area)) > 0;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function save(array $all): void
    {
        // Drop empty areas so the stored blob stays tidy.
        $all = array_filter($all, fn($v) => is_array($v) && count($v) > 0);
        $this->settings->set(self::GROUP, self::KEY, $all);
    }

    /** Keep only scalar settings and strip our injected render-time keys. */
    private function cleanSettings(array $settings): array
    {
        $out = [];
        foreach ($settings as $k => $v) {
            $k = (string) $k;
            if ($k === '' || str_starts_with($k, '__')) continue;
            if (is_scalar($v) || $v === null) $out[$k] = $v;
        }
        return $out;
    }

    private function newId(): string
    {
        return 'w_' . substr(bin2hex(random_bytes(5)), 0, 8);
    }
}
