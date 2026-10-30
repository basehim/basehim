<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Core\WidgetRegistry;

/**
 * Settings schema for frontend widgets (1.2.93).
 *
 * A widget declares its settings as a list of fields (WidgetRegistry
 * 'fields'). The same list draws the admin form, validates what is saved and
 * normalises what is rendered, so no widget writes its own form or checks.
 *
 *   ['key' => 'count', 'label' => 'Number of posts', 'type' => 'number',
 *    'default' => 5, 'min' => 1, 'max' => 20]
 *
 * Field keys: key, label, type, default, required, help, options (array or
 * callable, for select / multiselect / radio), min, max, step, maxlength,
 * taxonomy (terms), multiple (terms, posts), show_if (['field' => value or
 * [values]]), capability (who may change it; others keep the saved value),
 * private (true: never shown by the public API).
 *
 * Types: text, textarea, html, number, checkbox (alias toggle), select,
 * multiselect, radio, url, color, date, terms, posts, media.
 *
 * Values, after validation: text-like types are strings; number an int (or
 * float when step is fractional); checkbox '1' or '0' (strings, as widgets
 * have always read them); multiselect, terms (multiple) and posts lists of
 * strings / ints; media an int (0 = none).
 */
final class Schema
{
    public const TYPES = ['text', 'textarea', 'html', 'number', 'checkbox', 'toggle', 'select', 'multiselect',
        'radio', 'url', 'color', 'date', 'terms', 'posts', 'media'];

    /** Longest text value kept, by type. */
    private const MAX_LEN = ['text' => 300, 'textarea' => 5000, 'html' => 100000, 'url' => 2000];

    /** @param list<array> $fields as WidgetRegistry normalised them */
    public function __construct(private array $fields) {}

    /** @return list<array> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $key): ?array
    {
        foreach ($this->fields as $f) if (($f['key'] ?? '') === $key) return $f;
        return null;
    }

    public static function type(array $f): string
    {
        $t = (string) ($f['type'] ?? 'text');
        if ($t === 'toggle') return 'checkbox';
        return in_array($t, self::TYPES, true) ? $t : 'text';
    }

    /** Default values for every field. */
    public function defaults(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            $out[$f['key']] = $this->clean($f, $f['default'] ?? null, true);
        }
        return $out;
    }

    /**
     * Saved (or default) values made safe to render: every field present,
     * every value of its type and within its limits. Anything invalid falls
     * back to the field's default; unknown keys are dropped. Never fails.
     */
    public function normalise(array $saved): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            $k = $f['key'];
            $v = array_key_exists($k, $saved) ? $saved[$k] : ($f['default'] ?? null);
            $ok = true;
            $clean = $this->clean($f, $v, true, $ok);
            $out[$k] = $ok ? $clean : $this->clean($f, $f['default'] ?? null, true);
        }
        return $out;
    }

    /**
     * Check submitted values for saving.
     *
     * Unknown keys are dropped. A field the user may not change (capability)
     * keeps its current value. Fields hidden by show_if keep what they had.
     *
     * @param callable(string):bool $can capability check for the saving user
     * @return array{0: array, 1: array<string,string>} [values, errors by field key]
     */
    public function validate(array $input, array $current, callable $can): array
    {
        $values = [];
        $errors = [];
        $currentNorm = $this->normalise($current);
        foreach ($this->fields as $f) {
            $k = $f['key'];
            $cap = $f['capability'] ?? null;
            if (is_string($cap) && $cap !== '' && !$can($cap)) {
                $values[$k] = $currentNorm[$k];
                continue;
            }
            $type = self::type($f);
            $present = array_key_exists($k, $input);
            $raw = $present ? $input[$k] : ($type === 'checkbox' ? '0' : ($type === 'multiselect' || !empty($f['multiple']) ? [] : null));
            $ok = true;
            $clean = $this->clean($f, $raw, false, $ok, $msg);
            if (!$ok) {
                $errors[$k] = $msg ?: 'This value is not valid.';
                $values[$k] = $currentNorm[$k];
                continue;
            }
            if (!empty($f['required']) && $this->isEmpty($clean, $type) && $this->visible($f, $input + $currentNorm)) {
                $errors[$k] = 'This is required.';
            }
            $values[$k] = $clean;
        }
        return [$values, $errors];
    }

    /** Whether a field's show_if condition holds for these values. */
    public function visible(array $f, array $values): bool
    {
        $cond = $f['show_if'] ?? null;
        if (!is_array($cond) || $cond === []) return true;
        foreach ($cond as $field => $want) {
            $have = $values[$field] ?? null;
            $wants = is_array($want) ? array_map('strval', $want) : [(string) $want];
            if (is_array($have)) {
                if (!array_intersect(array_map('strval', $have), $wants)) return false;
            } elseif (!in_array((string) $have, $wants, true)) {
                return false;
            }
        }
        return true;
    }

    /** Options of a choice field as value => label (callables are called here, when needed). */
    public static function options(array $f): array
    {
        return WidgetRegistry::fieldOptions($f);
    }

    /** Empty for "required": nothing typed or chosen. 0 is a value for numbers, nothing for pickers. */
    private function isEmpty(mixed $v, string $type): bool
    {
        if ($v === '' || $v === null || $v === []) return true;
        return $v === 0 && in_array($type, ['terms', 'posts', 'media'], true);
    }

    /**
     * One value cleaned to its field's type.
     *
     * $lenient (rendering): clamp numbers into range and cut long text
     * instead of failing. Strict (saving): out-of-range or unknown choices
     * fail with a message.
     */
    private function clean(array $f, mixed $v, bool $lenient, bool &$ok = true, ?string &$msg = null): mixed
    {
        $ok = true;
        $msg = null;
        $type = self::type($f);
        switch ($type) {
            case 'checkbox':
                if (is_bool($v)) return $v ? '1' : '0';
                $s = strtolower(trim((string) (is_scalar($v) ? $v : '')));
                return in_array($s, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';

            case 'number':
                if ($v === null || $v === '' || !is_scalar($v)) {
                    $d = $f['default'] ?? null;
                    if ($v === null || $v === '') return is_numeric($d) ? $this->num($f, $d) : '';
                    $ok = false; $msg = 'Enter a number.'; return '';
                }
                if (!is_numeric($v)) { $ok = false; $msg = 'Enter a number.'; return ''; }
                $n = $this->num($f, $v);
                $min = isset($f['min']) && is_numeric($f['min']) ? $f['min'] + 0 : null;
                $max = isset($f['max']) && is_numeric($f['max']) ? $f['max'] + 0 : null;
                if ($min !== null && $n < $min) { if ($lenient) return $this->num($f, $min); $ok = false; $msg = "Enter {$min} or more."; return $n; }
                if ($max !== null && $n > $max) { if ($lenient) return $this->num($f, $max); $ok = false; $msg = "Enter {$max} or less."; return $n; }
                return $n;

            case 'select':
            case 'radio':
                $s = is_scalar($v) ? (string) $v : '';
                $opts = self::options($f);
                if ($opts === [] || array_key_exists($s, $opts)) return $s;
                if ($s === '' && !$lenient && empty($f['required'])) return '';
                $ok = false; $msg = 'Choose one of the options.';
                return '';

            case 'multiselect':
                $list = $this->list($v);
                $opts = self::options($f);
                $out = [];
                foreach ($list as $item) {
                    if ($opts !== [] && !array_key_exists($item, $opts)) {
                        if ($lenient) continue;
                        $ok = false; $msg = 'Choose from the options.'; return [];
                    }
                    if (!in_array($item, $out, true)) $out[] = $item;
                }
                return $out;

            case 'terms':
            case 'posts':
                $ids = [];
                foreach ($this->list($v) as $item) {
                    if (!ctype_digit($item) || (int) $item < 1) {
                        if ($lenient) continue;
                        $ok = false; $msg = 'Choose from the list.'; return [];
                    }
                    if (!in_array((int) $item, $ids, true)) $ids[] = (int) $item;
                }
                $ids = array_slice($ids, 0, 200);
                if (empty($f['multiple'])) return $ids[0] ?? 0;
                return $ids;

            case 'media':
                if ($v === null || $v === '' || $v === 0 || $v === '0') return 0;
                if (is_scalar($v) && ctype_digit((string) $v)) return (int) $v;
                if ($lenient) return 0;
                $ok = false; $msg = 'Choose a file from the media library.';
                return 0;

            case 'url':
                $s = trim(is_scalar($v) ? (string) $v : '');
                if ($s === '') return '';
                if (mb_strlen($s) > self::MAX_LEN['url'] || !self::safeUrl($s)) {
                    if ($lenient) return '';
                    $ok = false; $msg = 'Enter a web address starting with https://, http:// or /.';
                    return '';
                }
                return $s;

            case 'color':
                $s = trim(is_scalar($v) ? (string) $v : '');
                if ($s === '' || preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $s)) return strtolower($s);
                if ($lenient) return '';
                $ok = false; $msg = 'Enter a colour like #2563eb.';
                return '';

            case 'date':
                $s = trim(is_scalar($v) ? (string) $v : '');
                if ($s === '') return '';
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
                if ($d && $d->format('Y-m-d') === $s) return $s;
                if ($lenient) return '';
                $ok = false; $msg = 'Enter a date as YYYY-MM-DD.';
                return '';

            default: // text, textarea, html
                $s = is_scalar($v) ? (string) $v : '';
                if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
                if ($type === 'text') $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
                else $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s) ?? '';
                $max = (int) ($f['maxlength'] ?? self::MAX_LEN[$type] ?? 300);
                if (mb_strlen($s) > $max) {
                    if ($lenient) return mb_substr($s, 0, $max);
                    $ok = false; $msg = "Use at most {$max} characters.";
                    return mb_substr($s, 0, $max);
                }
                return $s;
        }
    }

    private function num(array $f, mixed $v): int|float
    {
        $step = $f['step'] ?? 1;
        return (is_numeric($step) && floor((float) $step) != (float) $step) ? (float) $v : (int) round((float) $v);
    }

    /** @return list<string> */
    private function list(mixed $v): array
    {
        if (is_string($v)) {
            $t = trim($v);
            if ($t !== '' && $t[0] === '[') { $d = json_decode($t, true); if (is_array($d)) $v = $d; }
            else $v = $t === '' ? [] : explode(',', $t);
        }
        if (is_int($v)) $v = [$v];
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $item) if (is_scalar($item) && trim((string) $item) !== '') $out[] = trim((string) $item);
        return $out;
    }

    /** http(s) or site-relative, nothing else (no javascript:, data:, protocol-relative). */
    public static function safeUrl(string $s): bool
    {
        if (str_starts_with($s, '//')) return false;
        if ($s[0] === '/' || $s[0] === '#' || $s[0] === '?') return !preg_match('/[\x00-\x1F]/', $s);
        return (bool) preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $s);
    }
}
