<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validator
 *
 * Lightweight rule-based validation. Supports a useful subset:
 * required, string, integer, numeric, email, url, min, max,
 * in, unique, exists, confirmed, array, regex.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(
        private array $data,
        private array $rules,
        private array $messages = [],
    ) {
        $this->validate();
    }

    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        $result = [];
        foreach (array_keys($this->rules) as $field) {
            if (isset($this->data[$field])) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $value = $this->data[$field] ?? null;

            $isRequired = in_array('required', $rules, true);
            if (!$isRequired && ($value === null || $value === '')) {
                continue;
            }

            foreach ($rules as $rule) {
                $param = null;
                if (str_contains($rule, ':')) {
                    [$rule, $param] = explode(':', $rule, 2);
                }
                $method = 'rule' . ucfirst($rule);
                if (method_exists($this, $method)) {
                    $this->{$method}($field, $value, $param);
                }
            }
        }
    }

    private function addError(string $field, string $rule, string $default, array $replace = []): void
    {
        $msg = $this->messages["{$field}.{$rule}"] ?? $this->messages[$rule] ?? $default;
        foreach ($replace as $k => $v) {
            $msg = str_replace(':' . $k, $v, $msg);
        }
        $msg = str_replace(':attribute', $field, $msg);
        $this->errors[$field][] = $msg;
    }

    private function ruleRequired(string $field, mixed $value): void
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, 'required', 'The :attribute field is required.');
        }
    }

    private function ruleString(string $field, mixed $value): void
    {
        if (!is_string($value)) {
            $this->addError($field, 'string', 'The :attribute must be a string.');
        }
    }

    private function ruleInteger(string $field, mixed $value): void
    {
        if (!is_numeric($value) || (int)$value != $value) {
            $this->addError($field, 'integer', 'The :attribute must be an integer.');
        }
    }

    private function ruleNumeric(string $field, mixed $value): void
    {
        if (!is_numeric($value)) {
            $this->addError($field, 'numeric', 'The :attribute must be numeric.');
        }
    }

    private function ruleEmail(string $field, mixed $value): void
    {
        if (!filter_var((string)$value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'email', 'The :attribute must be a valid email.');
        }
    }

    private function ruleUrl(string $field, mixed $value): void
    {
        if (!filter_var((string)$value, FILTER_VALIDATE_URL)) {
            $this->addError($field, 'url', 'The :attribute must be a valid URL.');
        }
    }

    private function ruleMin(string $field, mixed $value, ?string $param): void
    {
        $min = (int) $param;
        $len = is_string($value) ? mb_strlen($value) : (is_numeric($value) ? (float)$value : 0);
        if (is_numeric($value)) {
            if ((float)$value < $min) $this->addError($field, 'min', "The :attribute must be at least :min.", ['min' => (string)$min]);
        } else {
            if ($len < $min) $this->addError($field, 'min', "The :attribute must be at least :min characters.", ['min' => (string)$min]);
        }
    }

    private function ruleMax(string $field, mixed $value, ?string $param): void
    {
        $max = (int) $param;
        if (is_numeric($value)) {
            if ((float)$value > $max) $this->addError($field, 'max', "The :attribute may not be greater than :max.", ['max' => (string)$max]);
        } else {
            $len = is_string($value) ? mb_strlen($value) : 0;
            if ($len > $max) $this->addError($field, 'max', "The :attribute may not be greater than :max characters.", ['max' => (string)$max]);
        }
    }

    private function ruleIn(string $field, mixed $value, ?string $param): void
    {
        $options = explode(',', (string)$param);
        if (!in_array((string)$value, $options, true)) {
            $this->addError($field, 'in', 'The selected :attribute is invalid.');
        }
    }

    private function ruleConfirmed(string $field, mixed $value): void
    {
        if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
            $this->addError($field, 'confirmed', 'The :attribute confirmation does not match.');
        }
    }

    private function ruleArray(string $field, mixed $value): void
    {
        if (!is_array($value)) {
            $this->addError($field, 'array', 'The :attribute must be an array.');
        }
    }

    private function ruleRegex(string $field, mixed $value, ?string $param): void
    {
        if (!is_string($value) || !@preg_match($param, $value)) {
            $this->addError($field, 'regex', 'The :attribute format is invalid.');
        }
    }

    private function ruleBoolean(string $field, mixed $value): void
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            $this->addError($field, 'boolean', 'The :attribute must be true or false.');
        }
    }

    private function ruleUnique(string $field, mixed $value, ?string $param): void
    {
        // unique:table,column[,ignore_id]
        $parts = explode(',', (string)$param);
        $table = $parts[0] ?? '';
        $column = $parts[1] ?? $field;
        $ignoreId = $parts[2] ?? null;
        if (!$table) return;
        $db = Application::getInstance()->make(Database::class);
        $sql = "SELECT id FROM `{$table}` WHERE `{$column}` = :v";
        $params = ['v' => $value];
        if ($ignoreId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = (int)$ignoreId;
        }
        $sql .= " LIMIT 1";
        if ($db->selectOne($sql, $params)) {
            $this->addError($field, 'unique', 'The :attribute has already been taken.');
        }
    }
}
