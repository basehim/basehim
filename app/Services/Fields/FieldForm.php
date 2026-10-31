<?php

declare(strict_types=1);

namespace App\Services\Fields;

use App\Core\Widgets\Schema;

/**
 * The editor's custom-field panels (1.2.94): one sidebar panel per group,
 * drawn from the schema. Inputs are named fields[key]; a hidden _fields
 * marker says the panels were on the page, so a save from a form without
 * them never clears values. show_if is applied by admin/assets/js/post-fields-custom.js.
 */
final class FieldForm
{
    /**
     * @param list<array> $groups  FieldRegistry::groups($type)
     * @param array       $values  FieldService::values($post) (or defaults for a new post)
     * @param callable(string):bool $can
     */
    public static function panels(array $groups, array $values, callable $can, array $termOptions = []): string
    {
        if ($groups === []) return '';
        $out = '<input type="hidden" name="_fields" value="1">';
        foreach ($groups as $g) {
            $id = 'bhe-fg-' . substr(md5($g['id']), 0, 8);
            $out .= '<section class="bhe-panel is-open" data-bhe-panel="fields-' . self::e(substr(md5($g['id']), 0, 8)) . '" data-bhe-fields>'
                . '<button type="button" class="bhe-panel__head" aria-expanded="true" aria-controls="' . $id . '"><span>' . self::e($g['title']) . '</span>'
                . (function_exists('icon') ? icon('chevron-up', 'bhe-icon bhe-panel__chev') : '') . '</button>'
                . '<div class="bhe-panel__body" id="' . $id . '">';
            if ($g['description'] !== '') $out .= '<p class="bhe-help">' . self::e($g['description']) . '</p>';
            foreach ($g['fields'] as $f) {
                $out .= self::field($f, $values[$f['key']] ?? ($f['default'] ?? null), $can, $termOptions);
            }
            $out .= '</div></section>';
        }
        return $out;
    }

    private static function field(array $f, mixed $value, callable $can, array $termOptions): string
    {
        $key = $f['key'];
        $id = 'bhe-field-' . $key;
        $name = 'fields[' . $key . ']';
        $type = Schema::type($f);
        $cap = $f['capability'] ?? null;
        $locked = is_string($cap) && $cap !== '' && !$can($cap);
        $dis = $locked ? ' disabled' : '';
        $req = !empty($f['required']) ? ' required aria-required="true"' : '';
        $help = (string) ($f['help'] ?? '');
        $desc = $help !== '' ? ' aria-describedby="' . $id . '-help"' : '';
        $showIf = !empty($f['show_if']) && is_array($f['show_if']) ? ' data-show-if="' . self::e(json_encode($f['show_if'])) . '"' : '';
        $label = self::e($f['label']) . (!empty($f['required']) ? ' <span class="bhe-req" aria-hidden="true">*</span>' : '');
        $num = static fn(string $k) => isset($f[$k]) && is_numeric($f[$k]) ? ' ' . $k . '="' . self::e((string) $f[$k]) . '"' : '';

        switch ($type) {
            case 'checkbox':
                $control = '<input type="hidden" name="' . self::e($name) . '" value="0"' . $dis . '>'
                    . '<label class="bhe-check"><input type="checkbox" id="' . $id . '" name="' . self::e($name) . '" value="1"'
                    . ((string) $value === '1' ? ' checked' : '') . $dis . $desc . '> ' . $label . '</label>';
                $label = null;
                break;
            case 'textarea':
            case 'html':
                $control = '<textarea id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" rows="' . ($type === 'html' ? 6 : 3) . '"'
                    . $num('maxlength') . $req . $dis . $desc . ($type === 'html' ? ' spellcheck="false" style="font-family:ui-monospace,monospace;font-size:13px"' : '') . '>'
                    . self::e((string) $value) . '</textarea>';
                break;
            case 'select':
            case 'radio':
                $control = '<select id="' . $id . '" name="' . self::e($name) . '" class="bhe-input"' . $req . $dis . $desc . '>';
                foreach (Schema::options($f) as $v => $l) {
                    $control .= '<option value="' . self::e((string) $v) . '"' . ((string) $value === (string) $v ? ' selected' : '') . '>' . self::e($l) . '</option>';
                }
                $control .= '</select>';
                break;
            case 'multiselect':
            case 'terms':
                $opts = $type === 'terms' ? ($termOptions[(string) ($f['taxonomy'] ?? 'category')] ?? []) : Schema::options($f);
                $chosen = array_map('strval', is_array($value) ? $value : ($value ? [$value] : []));
                if ($type === 'terms' && empty($f['multiple'])) {
                    $control = '<select id="' . $id . '" name="' . self::e($name) . '" class="bhe-input"' . $req . $dis . $desc . '><option value="">—</option>';
                    foreach ($opts as $v => $l) $control .= '<option value="' . self::e((string) $v) . '"' . (in_array((string) $v, $chosen, true) ? ' selected' : '') . '>' . self::e($l) . '</option>';
                    $control .= '</select>';
                    break;
                }
                $control = '<input type="hidden" name="' . self::e($name) . '[]" value=""' . $dis . '>'
                    . '<div class="bhe-checklist" role="group" aria-labelledby="' . $id . '-label">';
                foreach ($opts as $v => $l) {
                    $control .= '<label class="bhe-check"><input type="checkbox" name="' . self::e($name) . '[]" value="' . self::e((string) $v) . '"'
                        . (in_array((string) $v, $chosen, true) ? ' checked' : '') . $dis . '> ' . self::e($l) . '</label>';
                }
                if ($opts === []) $control .= '<p class="bhe-help">Nothing to choose from yet.</p>';
                $control .= '</div>';
                $label = '<span class="bhe-label" id="' . $id . '-label">' . $label . '</span>';
                return '<div class="bhe-field" data-field="' . self::e($key) . '"' . $showIf . '>' . $label . $control . self::foot($id, $help, $locked) . '</div>';
            case 'number':
                $control = '<input type="number" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" inputmode="decimal" value="' . self::e((string) $value) . '"'
                    . $num('min') . $num('max') . ' step="' . self::e((string) ($f['step'] ?? 1)) . '"' . $req . $dis . $desc . '>';
                break;
            case 'media':
                $control = '<div class="bhe-media-field"><input type="number" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" min="0" value="' . self::e((string) ((int) $value ?: '')) . '"'
                    . ' placeholder="Media ID"' . $req . $dis . $desc . ' data-bhe-media-input>'
                    . ($locked ? '' : '<button type="button" class="bhe-btn bhe-btn--secondary bhe-btn--small" data-bhe-media-pick="' . $id . '">Choose…</button>') . '</div>';
                break;
            case 'posts':
                $v = is_array($value) ? implode(', ', $value) : ((int) $value ?: '');
                $control = '<input type="text" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" inputmode="numeric" value="' . self::e((string) $v) . '"'
                    . ' placeholder="Post IDs, separated by commas"' . $req . $dis . $desc . '>';
                break;
            case 'color':
                $control = '<input type="color" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input bhe-input--color" value="' . self::e((string) ($value ?: '#000000')) . '"' . $dis . $desc . '>';
                break;
            case 'url':
                $control = '<input type="url" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" value="' . self::e((string) $value) . '" placeholder="https://"' . $req . $dis . $desc . '>';
                break;
            case 'date':
                $control = '<input type="date" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" value="' . self::e((string) $value) . '"' . $req . $dis . $desc . '>';
                break;
            default:
                $control = '<input type="text" id="' . $id . '" name="' . self::e($name) . '" class="bhe-input" value="' . self::e((string) $value) . '"'
                    . $num('maxlength') . $req . $dis . $desc . '>';
        }
        $labelHtml = $label === null ? '' : '<label class="bhe-label" for="' . $id . '">' . $label . '</label>';
        return '<div class="bhe-field" data-field="' . self::e($key) . '"' . $showIf . '>' . $labelHtml . $control . self::foot($id, $help, $locked) . '</div>';
    }

    private static function foot(string $id, string $help, bool $locked): string
    {
        $out = $help !== '' ? '<p class="bhe-help" id="' . $id . '-help">' . self::e($help) . '</p>' : '';
        if ($locked) $out .= '<p class="bhe-help">Your role can\'t change this field; it keeps its saved value.</p>';
        return $out;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
