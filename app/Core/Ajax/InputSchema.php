<?php

declare(strict_types=1);

namespace App\Core\Ajax;

/**
 * Declares and checks the input an AJAX action accepts.
 *
 * App\Core\Validator is kept for the forms that use it, but it cannot serve
 * here: it skips rule names it does not know (a typo silently disables a
 * check), it has no nested structures, it treats "5" as an integer, and it
 * cannot refuse fields nobody declared. Each of those is a hole in an endpoint
 * that takes JSON from a browser, so this is a separate, small checker.
 *
 * A schema maps field names to rules, either as a pipe string or as an array
 * (the array form is needed for nesting and for a regex, which may contain
 * "|"):
 *
 *     [
 *         'id'      => 'required|int|min:1',
 *         'title'   => 'string|max:255',
 *         'status'  => 'required|string|in:draft,published',
 *         'tags'    => ['type' => 'array', 'max' => 20, 'items' => 'string|max:50'],
 *         'seo'     => ['type' => 'object', 'fields' => ['title' => 'string|max:70']],
 *         'slug'    => ['type' => 'string', 'regex' => '/^[a-z0-9-]+$/'],
 *     ]
 *
 * Every field needs a type: string, int, number, bool, array (a list) or
 * object (named fields). Modifiers: required, nullable, min, max, in, email,
 * url, regex; and for containers items, fields and allow_unknown.
 *
 *  - min/max bound a string's length in characters, an array's item count,
 *    or a number's value.
 *  - required means present and neither null nor "". Use min:1 on an
 *    optional string to refuse "" only when it is sent.
 *  - Fields that are not declared are refused, at every level, unless the
 *    object sets allow_unknown. That refusal is what stops a client adding
 *    "author_id" or "role" to a request and having it written.
 *  - The result holds only declared fields that were sent, so a handler can
 *    tell "not sent" from "sent empty" and pass it straight to an update.
 *
 * Schemas are compiled once, when the action is registered. An unknown rule,
 * a missing type or a malformed bound throws then, rather than skipping a
 * check on every request afterwards.
 */
final class InputSchema
{
    private const TYPES = ['string', 'int', 'number', 'bool', 'array', 'object'];
    private const TYPE_ALIASES = ['integer' => 'int', 'boolean' => 'bool', 'float' => 'number', 'list' => 'array'];
    private const FLAGS = ['required', 'nullable', 'email', 'url', 'allow_unknown'];
    private const KEYS = ['type', 'required', 'nullable', 'min', 'max', 'in', 'email', 'url', 'regex', 'items', 'fields', 'allow_unknown'];
    private const MAX_DEPTH = 8;

    /** @var array<string,array> compiled field specs */
    private array $fields;

    private function __construct(array $fields, private bool $allowUnknown)
    {
        $this->fields = $fields;
    }

    /**
     * @param array<string,string|array> $schema
     * @throws \InvalidArgumentException on a malformed schema
     */
    public static function compile(array $schema, bool $allowUnknown = false): self
    {
        $compiled = [];
        foreach ($schema as $name => $spec) {
            $compiled[self::fieldName($name, '')] = self::compileSpec($spec, (string) $name, 1);
        }
        return new self($compiled, $allowUnknown);
    }

    /** True when the schema declares no fields: the action takes no input. */
    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The top-level fields, for documentation (the API reference screen):
     * ['id' => 'int, required', 'title' => 'string', …].
     *
     * @return array<string,string>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->fields as $name => $spec) {
            $out[(string) $name] = (string) ($spec['type'] ?? 'mixed') . (!empty($spec['required']) ? ', required' : '');
        }
        return $out;
    }

    /**
     * Check $input and return only the declared, validated values.
     *
     * @param bool $fromQuery values came from a query string, where everything
     *        is a string: digits may stand for an int, "true"/"1" for a bool.
     * @throws AjaxException VALIDATION_FAILED (422) with per-field messages
     */
    public function validate(mixed $input, bool $fromQuery = false): array
    {
        if ($input === null) $input = [];
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw AjaxException::invalid('The request data must be an object.', ['data' => ['Must be an object.']]);
        }
        $errors = [];
        $out = $this->checkObject($input, $this->fields, $this->allowUnknown, '', $fromQuery, $errors);
        if ($errors !== []) {
            throw AjaxException::invalid('Some fields are not valid.', $errors);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Compilation
    // ------------------------------------------------------------------

    private static function fieldName(int|string $name, string $parent): string
    {
        $name = (string) $name;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Invalid field name '{$parent}{$name}' in an AJAX input schema.");
        }
        return $name;
    }

    private static function compileSpec(string|array $spec, string $path, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException("AJAX input schema is nested too deeply at '{$path}'.");
        }
        $spec = is_string($spec) ? self::parseRuleString($spec, $path) : $spec;

        foreach (array_keys($spec) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException("Unknown rule '{$key}' for '{$path}' in an AJAX input schema.");
            }
        }

        $type = $spec['type'] ?? null;
        $type = is_string($type) ? (self::TYPE_ALIASES[$type] ?? $type) : $type;
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Field '{$path}' needs a type (string, int, number, bool, array or object).");
        }

        $c = [
            'type'          => $type,
            'required'      => (bool) ($spec['required'] ?? false),
            'nullable'      => (bool) ($spec['nullable'] ?? false),
            'min'           => null,
            'max'           => null,
            'in'            => null,
            'email'         => (bool) ($spec['email'] ?? false),
            'url'           => (bool) ($spec['url'] ?? false),
            'regex'         => null,
            'items'         => null,
            'fields'        => null,
            'allow_unknown' => (bool) ($spec['allow_unknown'] ?? false),
        ];

        foreach (['min', 'max'] as $bound) {
            if (!array_key_exists($bound, $spec)) continue;
            $v = $spec[$bound];
            if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                throw new \InvalidArgumentException("'{$bound}' for '{$path}' must be a number.");
            }
            $c[$bound] = $type === 'number' ? (float) $v : (int) $v;
        }
        if ($c['min'] !== null && $c['max'] !== null && $c['min'] > $c['max']) {
            throw new \InvalidArgumentException("'min' is greater than 'max' for '{$path}'.");
        }

        if (array_key_exists('in', $spec)) {
            $in = $spec['in'];
            if (!is_array($in) || $in === []) {
                throw new \InvalidArgumentException("'in' for '{$path}' must be a non-empty list.");
            }
            if (!in_array($type, ['string', 'int', 'number'], true)) {
                throw new \InvalidArgumentException("'in' is only for string and number fields ('{$path}').");
            }
            // A number field's list must hold numbers. Before 1.2.46 'number|in:low,high'
            // compiled, then threw a TypeError (500) on the first request.
            if ($type !== 'string') {
                foreach ($in as $v) {
                    if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                        throw new \InvalidArgumentException("'in' for '{$path}' must list numbers.");
                    }
                    if ($type === 'int' && (float) $v != (int) $v) {
                        throw new \InvalidArgumentException("'in' for '{$path}' must list whole numbers.");
                    }
                }
            }
            // Values from a pipe string are strings; make a number list compare as numbers.
            $c['in'] = array_values(array_map(fn($v) => match ($type) {
                'int'    => (int) $v,
                'number' => $v + 0,
                default  => $v,
            }, $in));
        }

        if (($c['email'] || $c['url']) && $type !== 'string') {
            throw new \InvalidArgumentException("'email' and 'url' are only for string fields ('{$path}').");
        }

        if (array_key_exists('regex', $spec)) {
            if ($type !== 'string' || !is_string($spec['regex'])) {
                throw new \InvalidArgumentException("'regex' for '{$path}' must be a pattern on a string field.");
            }
            set_error_handler(static fn() => true);
            try {
                $valid = preg_match($spec['regex'], '') !== false;
            } finally {
                restore_error_handler();
            }
            if (!$valid) {
                throw new \InvalidArgumentException("'regex' for '{$path}' is not a valid pattern.");
            }
            $c['regex'] = $spec['regex'];
        }

        if ($type === 'array') {
            if (!isset($spec['items'])) {
                throw new \InvalidArgumentException("Array field '{$path}' needs 'items' to say what it holds.");
            }
            $c['items'] = self::compileSpec($spec['items'], $path . '[]', $depth + 1);
        } elseif (isset($spec['items'])) {
            throw new \InvalidArgumentException("'items' is only for array fields ('{$path}').");
        }

        if ($type === 'object') {
            $fields = $spec['fields'] ?? [];
            if (!is_array($fields)) {
                throw new \InvalidArgumentException("'fields' for '{$path}' must be an array.");
            }
            $c['fields'] = [];
            foreach ($fields as $name => $sub) {
                $c['fields'][self::fieldName($name, $path . '.')] = self::compileSpec($sub, $path . '.' . $name, $depth + 1);
            }
        } elseif (isset($spec['fields'])) {
            throw new \InvalidArgumentException("'fields' is only for object fields ('{$path}').");
        }

        return $c;
    }

    /** 'required|string|max:255|in:a,b' -> ['required' => true, 'type' => 'string', 'max' => 255, 'in' => ['a','b']] */
    private static function parseRuleString(string $rules, string $path): array
    {
        $spec = [];
        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $alias = self::TYPE_ALIASES[$name] ?? $name;
            if (in_array($alias, self::TYPES, true)) {
                if (isset($spec['type'])) {
                    throw new \InvalidArgumentException("Field '{$path}' declares two types.");
                }
                $spec['type'] = $alias;
            } elseif (in_array($name, self::FLAGS, true)) {
                $spec[$name] = true;
            } elseif ($name === 'min' || $name === 'max') {
                if ($param === null || !is_numeric($param)) {
                    throw new \InvalidArgumentException("'{$name}' for '{$path}' needs a number, like {$name}:10.");
                }
                $spec[$name] = $param;
            } elseif ($name === 'in') {
                if ($param === null || $param === '') {
                    throw new \InvalidArgumentException("'in' for '{$path}' needs values, like in:a,b.");
                }
                $spec['in'] = explode(',', $param);
            } else {
                // regex lives only in the array form, because a pattern may contain "|".
                throw new \InvalidArgumentException("Unknown rule '{$name}' for '{$path}' in an AJAX input schema.");
            }
        }
        return $spec;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private function checkObject(array $input, array $fields, bool $allowUnknown, string $prefix, bool $fromQuery, array &$errors): array
    {
        $out = [];
        if (!$allowUnknown) {
            foreach (array_keys($input) as $key) {
                if (!isset($fields[(string) $key])) {
                    // The name is echoed back only if it is a plain identifier,
                    // so a crafted key cannot inject markup into a client that
                    // renders field errors carelessly.
                    $label = preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $key) ? (string) $key : '(invalid name)';
                    $errors[$prefix . $label][] = 'This field is not accepted.';
                }
            }
        }
        foreach ($fields as $name => $spec) {
            $path = $prefix . $name;
            if (!array_key_exists($name, $input)) {
                if ($spec['required']) $errors[$path][] = 'This field is required.';
                continue;
            }
            $value = $input[$name];
            if ($value === null) {
                if ($spec['nullable'] && !$spec['required']) {
                    $out[$name] = null;
                } else {
                    $errors[$path][] = $spec['required'] ? 'This field is required.' : 'This field cannot be null.';
                }
                continue;
            }
            if ($spec['required'] && $value === '') {
                $errors[$path][] = 'This field is required.';
                continue;
            }
            $ok = true;
            $clean = $this->checkValue($value, $spec, $path, $fromQuery, $errors, $ok);
            if ($ok) $out[$name] = $clean;
        }
        return $out;
    }

    private function checkValue(mixed $value, array $spec, string $path, bool $fromQuery, array &$errors, bool &$ok): mixed
    {
        $fail = function (string $msg) use (&$errors, $path, &$ok) { $errors[$path][] = $msg; $ok = false; };

        switch ($spec['type']) {
            case 'string':
                if (!is_string($value)) { $fail('Must be a string.'); return null; }
                // JSON bodies are always UTF-8, but a query string can carry
                // any bytes; letting them through broke the JSON response.
                if (!mb_check_encoding($value, 'UTF-8')) { $fail('Must be valid UTF-8 text.'); return null; }
                $len = mb_strlen($value, 'UTF-8');
                if ($spec['min'] !== null && $len < $spec['min']) $fail("Must be at least {$spec['min']} characters.");
                if ($spec['max'] !== null && $len > $spec['max']) $fail("Must be at most {$spec['max']} characters.");
                if ($spec['in'] !== null && !in_array($value, $spec['in'], true)) $fail('Is not one of the allowed values.');
                if ($spec['email'] && !filter_var($value, FILTER_VALIDATE_EMAIL)) $fail('Must be a valid email address.');
                if ($spec['url'] && !self::isHttpUrl($value)) $fail('Must be a valid http or https URL.');
                if ($spec['regex'] !== null && preg_match($spec['regex'], $value) !== 1) $fail('Has an invalid format.');
                return $value;

            case 'int':
                if ($fromQuery && is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) $value = (int) $value;
                if (!is_int($value)) { $fail('Must be a whole number.'); return null; }
                $this->checkRange((float) $value, $spec, $fail);
                if ($spec['in'] !== null && !in_array($value, $spec['in'], true)) $fail('Is not one of the allowed values.');
                return $value;

            case 'number':
                if ($fromQuery && is_string($value) && is_numeric($value)) $value = $value + 0;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) { $fail('Must be a number.'); return null; }
                $this->checkRange((float) $value, $spec, $fail);
                if ($spec['in'] !== null && !in_array($value, $spec['in'], false)) $fail('Is not one of the allowed values.');
                return $value;

            case 'bool':
                if ($fromQuery && is_string($value)) {
                    $map = ['1' => true, 'true' => true, '0' => false, 'false' => false];
                    if (array_key_exists(strtolower($value), $map)) $value = $map[strtolower($value)];
                }
                if (!is_bool($value)) { $fail('Must be true or false.'); return null; }
                return $value;

            case 'array':
                if (!is_array($value) || ($value !== [] && !array_is_list($value))) { $fail('Must be a list.'); return null; }
                $n = count($value);
                if ($spec['min'] !== null && $n < $spec['min']) $fail("Must have at least {$spec['min']} items.");
                if ($spec['max'] !== null && $n > $spec['max']) { $fail("Must have at most {$spec['max']} items."); return null; }
                $out = [];
                foreach ($value as $i => $item) {
                    $itemPath = $path . '.' . $i;
                    if ($item === null) {
                        if ($spec['items']['nullable']) { $out[] = null; continue; }
                        $errors[$itemPath][] = 'Cannot be null.'; $ok = false; continue;
                    }
                    $itemOk = true;
                    $clean = $this->checkValue($item, $spec['items'], $itemPath, $fromQuery, $errors, $itemOk);
                    if ($itemOk) $out[] = $clean; else $ok = false;
                }
                return $out;

            case 'object':
                if (!is_array($value) || ($value !== [] && array_is_list($value))) { $fail('Must be an object.'); return null; }
                $before = count($errors);
                $out = $this->checkObject($value, $spec['fields'], $spec['allow_unknown'], $path . '.', $fromQuery, $errors);
                if (count($errors) !== $before) $ok = false;
                return $out;
        }
        $fail('Has an unsupported type.');
        return null;
    }

    private function checkRange(float $v, array $spec, callable $fail): void
    {
        if ($spec['min'] !== null && $v < $spec['min']) $fail("Must be at least {$spec['min']}.");
        if ($spec['max'] !== null && $v > $spec['max']) $fail("Must be at most {$spec['max']}.");
    }

    /** http(s) only: FILTER_VALIDATE_URL alone accepts javascript: and data: URLs. */
    private static function isHttpUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }
}
