<?php

declare(strict_types=1);

namespace App\Services\Fields;

use App\Core\Widgets\Schema;

/**
 * Custom fields for content types (1.2.94).
 *
 * An app declares fields for a type in groups; each group is one panel in
 * the editor. Fields use the settings schema shared with frontend widgets
 * (App\Core\Widgets\Schema: types, defaults, limits, show_if, capability,
 * private), so the same declaration draws the form, validates the save and
 * cleans what themes read.
 *
 * Values are stored in {post_meta} under the field's key. Keys are unique per
 * type: a second app declaring a key another app owns on the same type is
 * refused, so two apps can never write each other's data. Keys starting with
 * "_" are core's own and can't be declared.
 */
final class FieldRegistry
{
    /** @var array<string, list<array>> type => groups */
    private array $groups = [];

    /** @var list<array{app:string,type:string,reason:string}> */
    private array $rejected = [];

    /**
     * @param array $group title (panel heading, default "Fields"), description,
     *                     context ('sidebar', the default)
     */
    public function register(string $app, string $type, array $fields, array $group = []): bool
    {
        $type = strtolower(trim($type));
        $fail = function (string $why) use ($app, $type): bool {
            $this->rejected[] = ['app' => $app, 'type' => $type, 'reason' => $why];
            try {
                \App\Core\Application::getInstance()->make(\App\Services\AppLogger::class)
                    ->log($app, 'warning', "Fields for '{$type}' not registered: {$why}");
            } catch (\Throwable) {}
            return false;
        };
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $type)) return $fail('the content type key is not valid.');
        if (in_array($type, ['template'], true)) return $fail('templates have no custom fields.');

        $clean = [];
        $seen = [];
        foreach (array_values($fields) as $f) {
            if (!is_array($f)) continue;
            $key = (string) ($f['key'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key)) {
                return $fail("field key '" . substr($key, 0, 60) . "' must be lower-case letters, digits and _, starting with a letter.");
            }
            if (isset($seen[$key])) return $fail("field '{$key}' is declared twice.");
            $owner = $this->ownerOf($type, $key);
            if ($owner !== null && $owner !== $app) return $fail("field '{$key}' on '{$type}' belongs to " . ($owner ?: 'another app') . '.');
            $type_ = Schema::type($f);
            if (($f['type'] ?? 'text') !== $type_ && ($f['type'] ?? '') !== 'toggle') return $fail("field '{$key}' has an unknown type '" . (string) ($f['type'] ?? '') . "'.");
            $seen[$key] = true;
            $f['key'] = $key;
            $f['label'] = (string) ($f['label'] ?? ucfirst(str_replace('_', ' ', $key)));
            $f['type'] = $type_;
            $clean[] = $f;
        }
        if ($clean === []) return $fail('the group has no fields.');

        // Re-registering the same app's group (same title) replaces it.
        $title = trim((string) ($group['title'] ?? '')) ?: 'Fields';
        $id = $app . ':' . substr(md5($title), 0, 8);
        $this->groups[$type] = array_values(array_filter($this->groups[$type] ?? [], fn($g) => $g['id'] !== $id));
        $this->groups[$type][] = [
            'id'          => $id,
            'app'         => $app,
            'title'       => $title,
            'description' => (string) ($group['description'] ?? ''),
            'fields'      => $clean,
        ];
        return true;
    }

    /** @return list<array> groups for a type */
    public function groups(string $type): array
    {
        return $this->groups[$type] ?? [];
    }

    /** Every field of a type, in order. @return list<array> */
    public function fields(string $type): array
    {
        $out = [];
        foreach ($this->groups($type) as $g) foreach ($g['fields'] as $f) $out[] = $f;
        return $out;
    }

    public function has(string $type): bool
    {
        return !empty($this->groups[$type]);
    }

    public function schema(string $type): Schema
    {
        return new Schema($this->fields($type));
    }

    /** @return list<string> types that have fields */
    public function types(): array
    {
        return array_keys(array_filter($this->groups));
    }

    public function forgetOwner(string $app): int
    {
        $n = 0;
        foreach ($this->groups as $type => $groups) {
            $keep = array_values(array_filter($groups, fn($g) => $g['app'] !== $app));
            $n += count($groups) - count($keep);
            $this->groups[$type] = $keep;
        }
        return $n;
    }

    public function rejected(): array
    {
        return $this->rejected;
    }

    private function ownerOf(string $type, string $key): ?string
    {
        foreach ($this->groups[$type] ?? [] as $g) {
            foreach ($g['fields'] as $f) if ($f['key'] === $key) return $g['app'];
        }
        return null;
    }
}
