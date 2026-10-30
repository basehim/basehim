<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\WidgetAreaRegistry;
use App\Core\WidgetRegistry;
use App\Core\Widgets\Definition;
use App\Core\Widgets\FrontendWidgets;
use App\Core\Widgets\Schema;
use App\Core\Widgets\WidgetError;

/**
 * Appearance → Widgets (1.2.93): the screen's state and every change it makes.
 *
 * Every action needs manage_apps and stands in for /admin/widgets, the same
 * as the page. Each change answers with the new state, so the screen never
 * drifts from what is saved, even when two people edit at once.
 */
final class WidgetActions
{
    public function __construct(
        private FrontendWidgets $widgets,
        private WidgetRegistry $registry,
        private WidgetAreaRegistry $areas,
        private \App\Core\Database $db,
    ) {}

    public function state(AjaxContext $ctx): array
    {
        return $this->payload($ctx);
    }

    public function add(AjaxContext $ctx): array
    {
        $inst = $this->run(fn() => $this->widgets->add(
            (string) $ctx->input('area'), (string) $ctx->input('widget'), [],
            $ctx->has('position') ? (int) $ctx->input('position') : null, fn(string $c) => $ctx->can($c)
        ));
        return $this->payload($ctx) + ['added' => $inst['id']];
    }

    public function save(AjaxContext $ctx): array
    {
        $options = [];
        foreach (['class', 'hide_title', 'disabled'] as $k) if ($ctx->has($k)) $options[$k] = $ctx->input($k);
        $settings = $this->settingsInput($ctx);
        $this->run(fn() => $this->widgets->save((string) $ctx->input('id'), $settings, $options, fn(string $c) => $ctx->can($c)));
        return $this->payload($ctx);
    }

    public function options(AjaxContext $ctx): array
    {
        $options = [];
        foreach (['class', 'hide_title', 'disabled'] as $k) if ($ctx->has($k)) $options[$k] = $ctx->input($k);
        $this->run(fn() => $this->widgets->setOptions((string) $ctx->input('id'), $options));
        return $this->payload($ctx);
    }

    public function remove(AjaxContext $ctx): array
    {
        $this->run(fn() => $this->widgets->remove((string) $ctx->input('id')));
        return $this->payload($ctx);
    }

    public function move(AjaxContext $ctx): array
    {
        $this->run(fn() => $this->widgets->move((string) $ctx->input('id'), (string) $ctx->input('area'), (int) $ctx->input('position')));
        return $this->payload($ctx);
    }

    public function order(AjaxContext $ctx): array
    {
        $area = (string) $ctx->input('area');
        if (!$this->areas->has($area)) throw AjaxException::notFound('That widget area does not exist in the active theme.');
        $this->widgets->order($area, (array) $ctx->input('ids', []));
        return $this->payload($ctx);
    }

    public function duplicate(AjaxContext $ctx): array
    {
        $copy = $this->run(fn() => $this->widgets->duplicate((string) $ctx->input('id')));
        return $this->payload($ctx) + ['added' => $copy['id']];
    }

    /**
     * The instance rendered with the settings in the form, unsaved. Invalid
     * values fall back to their defaults here; saving reports them.
     */
    public function preview(AjaxContext $ctx): array
    {
        $found = $this->widgets->placements()->find((string) $ctx->input('id'));
        if (!$found) throw AjaxException::notFound('That widget is no longer placed. Reload the page.');
        [$areaKey, , $inst] = $found;
        $area = $this->areas->get($areaKey);
        $def = $this->registry->get($inst['widget']);
        if (!$area || !$def) {
            return ['html' => '', 'note' => 'This widget cannot be previewed: its area or the widget itself is not active.'];
        }
        if (empty($def['frontend']['preview'])) {
            return ['html' => '', 'note' => 'This widget needs the page around it, so it has no preview here. Open the site to see it.'];
        }
        if ($ctx->has('settings')) {
            $settings = $this->settingsInput($ctx);
            [$values] = $this->widgets->schema($def)->validate($settings, $this->widgets->settingsFor($def, $inst), fn(string $c) => $ctx->can($c));
            $inst['settings'] = $values;
            $inst['v'] = $def['frontend']['version'] ?? '';
        }
        if ($ctx->has('hide_title')) $inst['hide_title'] = in_array((string) $ctx->input('hide_title'), ['1', 'true'], true);
        $inst['disabled'] = false;
        return ['html' => $this->widgets->renderInstance($area, $inst, ['preview' => true]), 'note' => ''];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** The form's values: a JSON object of field => value. */
    private function settingsInput(AjaxContext $ctx): array
    {
        $raw = (string) $ctx->input('settings', '{}');
        $data = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw AjaxException::invalid('The settings could not be read. Reload the page and try again.', ['settings' => ['Must be a JSON object.']]);
        }
        return $data;
    }

    private function run(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (WidgetError $e) {
            if ($e->status === 404) throw AjaxException::notFound($e->getMessage());
            $fields = [];
            foreach ($e->errors as $k => $msg) $fields['settings.' . $k] = [$msg];
            throw AjaxException::invalid($e->getMessage(), $fields);
        }
    }

    /** Everything the screen draws: areas (with their instances) and the widgets that can be placed. */
    private function payload(AjaxContext $ctx): array
    {
        $placed = $this->widgets->placements()->all();
        $areas = [];
        foreach ($this->areas->all() as $a) {
            $areas[] = [
                'key' => $a['key'], 'name' => $a['name'], 'description' => $a['description'], 'active' => true,
                'max' => (int) ($a['max_widgets'] ?? 0), 'supports' => $a['supports'] ?? [], 'widgets' => $a['widgets'] ?? [],
                'instances' => array_map(fn($i) => $this->instance($i), $placed[$a['key']] ?? []),
            ];
        }
        // Areas of a theme that is no longer active keep their widgets; they
        // are listed so nothing seems lost, and widgets can be moved out.
        foreach ($placed as $key => $list) {
            if ($this->areas->has($key)) continue;
            $areas[] = [
                'key' => $key, 'name' => ucwords(str_replace(['-', '_', '.'], ' ', $key)), 'active' => false,
                'description' => 'Not shown: the active theme has no area with this key. These widgets are kept and come back if you switch to a theme that has it.',
                'max' => 0, 'supports' => [], 'widgets' => [],
                'instances' => array_map(fn($i) => $this->instance($i), $list),
            ];
        }

        $available = [];
        foreach ($this->registry->definitions('frontend') as $w) {
            $available[] = [
                'key' => $w['key'], 'title' => $w['title'], 'description' => $w['description'], 'icon' => $w['icon'],
                'category' => $w['frontend']['category'] ?? 'other', 'source' => $w['source'],
                'areas' => $w['frontend']['areas'] ?? [], 'unavailable' => $this->widgets->unavailable($w['key']),
            ];
        }
        usort($available, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        return ['areas' => $areas, 'widgets' => $available, 'categories' => Definition::CATEGORIES,
                'forms' => $this->forms($placed, $ctx)];
    }

    private function instance(array $i): array
    {
        $def = $this->registry->get($i['widget']);
        $title = trim((string) ($i['settings']['title'] ?? ''));
        return [
            'id' => $i['id'], 'widget' => $def['key'] ?? $i['widget'], 'name' => $def['title'] ?? $i['widget'],
            'icon' => $def['icon'] ?? 'puzzle-piece', 'label' => $title,
            'settings' => $def ? $this->widgets->settingsFor($def, $i) : $i['settings'],
            'class' => $i['class'], 'hide_title' => $i['hide_title'], 'disabled' => $i['disabled'],
            'unavailable' => $this->widgets->unavailable($i['widget']),
        ];
    }

    /** The settings form of every widget that is placed, by widget key. */
    private function forms(array $placed, AjaxContext $ctx): array
    {
        $keys = [];
        foreach ($placed as $list) foreach ($list as $i) $keys[$this->registry->resolveKey($i['widget'])] = true;
        $out = [];
        foreach (array_keys($keys) as $key) {
            $def = $this->registry->get($key);
            if (!$def || !in_array('frontend', $def['surfaces'], true)) continue;
            $fields = [];
            $defaults = $this->widgets->schema($def)->defaults();
            foreach ($def['fields'] as $f) {
                $type = Schema::type($f);
                $field = ['key' => $f['key'], 'label' => $f['label'], 'type' => $type,
                          'help' => (string) ($f['help'] ?? ''), 'required' => !empty($f['required']),
                          'default' => $defaults[$f['key']] ?? ''];
                foreach (['min', 'max', 'step', 'maxlength'] as $k) if (isset($f[$k]) && is_numeric($f[$k])) $field[$k] = $f[$k] + 0;
                if (!empty($f['show_if']) && is_array($f['show_if'])) $field['show_if'] = $f['show_if'];
                if (!empty($f['multiple'])) $field['multiple'] = true;
                $cap = $f['capability'] ?? null;
                if (is_string($cap) && $cap !== '' && !$ctx->can($cap)) $field['locked'] = true;
                if (in_array($type, ['select', 'multiselect', 'radio'], true)) {
                    $field['options'] = [];
                    foreach (Schema::options($f) as $v => $l) $field['options'][] = [(string) $v, $l];
                } elseif ($type === 'terms') {
                    $field['options'] = $this->termOptions((string) ($f['taxonomy'] ?? 'category'));
                }
                $fields[] = $field;
            }
            $out[$key] = $fields;
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private function termOptions(string $taxonomy): array
    {
        try {
            $rows = $this->db->select(
                'SELECT t.id, t.name FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = :tax ORDER BY t.name LIMIT 300',
                ['tax' => $taxonomy]
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map(fn($r) => [(string) $r['id'], html_entity_decode((string) $r['name'], ENT_QUOTES)], $rows);
    }
}
