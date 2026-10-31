<?php

declare(strict_types=1);

namespace App\Services\Fields;

use App\Core\Database;

/**
 * Reading and saving custom field values (1.2.94). See FieldRegistry.
 *
 * Values live in {post_meta}, one row per field: strings as they are, lists
 * as JSON (is_json = 1). Reading always goes through the type's schema, so a
 * template gets every declared field, of its declared type, with its default
 * when nothing was saved, and never a value the schema doesn't allow.
 *
 * Lists of posts read their fields in one query: prime() loads them for a set
 * of ids, and values()/get() then use what was loaded.
 */
final class FieldService
{
    /** @var array<int, array<string,mixed>> post id => raw stored values */
    private array $loaded = [];

    public function __construct(private Database $db, private FieldRegistry $registry) {}

    public function registry(): FieldRegistry
    {
        return $this->registry;
    }

    /** Load the stored values of many posts in one query. @param int[] $ids */
    public function prime(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0 && !isset($this->loaded[$i]))));
        if ($ids === []) return;
        foreach ($ids as $id) $this->loaded[$id] = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->db->select(
                'SELECT post_id, meta_key, meta_value, is_json FROM {post_meta} WHERE post_id IN (' . implode(',', $chunk) . ") AND LEFT(meta_key, 1) <> '_' ORDER BY id"
            );
            foreach ($rows as $r) {
                $v = (int) $r['is_json'] ? json_decode((string) $r['meta_value'], true) : $r['meta_value'];
                $this->loaded[(int) $r['post_id']][(string) $r['meta_key']] = $v;
            }
        }
    }

    /**
     * A post's field values, as its type's schema reads them. Fields of
     * other types or apps that are switched off are not included.
     *
     * @param array $post a posts row (needs id and type)
     * @param bool  $public true: leave out fields marked private
     */
    public function values(array $post, bool $public = false): array
    {
        $type = (string) ($post['type'] ?? '');
        $id = (int) ($post['id'] ?? 0);
        if (!$this->registry->has($type)) return [];
        if ($id > 0 && !isset($this->loaded[$id])) $this->prime([$id]);
        $values = $this->registry->schema($type)->normalise($this->loaded[$id] ?? []);
        if ($public) {
            foreach ($this->registry->fields($type) as $f) if (!empty($f['private'])) unset($values[$f['key']]);
        }
        return $values;
    }

    /** One field's value, or $default when the type doesn't declare it. */
    public function get(array $post, string $key, mixed $default = null): mixed
    {
        $v = $this->values($post);
        return array_key_exists($key, $v) ? $v[$key] : $default;
    }

    /**
     * Validate and save submitted values. Only declared fields are touched;
     * a field missing from $input keeps its saved value unless $complete
     * (a full editor form, where an unchecked box or empty list is absent).
     * Invalid values are not saved; their messages are returned by field key.
     *
     * @param callable(string):bool $can the saving user's capability check
     * @return array<string,string> errors
     */
    public function save(array $post, array $input, callable $can, bool $complete = true): array
    {
        $type = (string) ($post['type'] ?? '');
        $id = (int) ($post['id'] ?? 0);
        if ($id <= 0 || !$this->registry->has($type)) return [];
        $schema = $this->registry->schema($type);
        $current = $this->values($post);
        $given = $input;
        if (!$complete) $input = $input + $current;
        [$values, $errors] = $schema->validate($input, $current, $can);
        if (!$complete) {
            // A partial save answers only for the fields it sent, and writes only those.
            $errors = array_intersect_key($errors, $given);
            $values = array_intersect_key($values, $given);
        }

        $this->db->transaction(function () use ($id, $values, $errors, $schema) {
            foreach ($values as $key => $value) {
                if (isset($errors[$key])) continue;      // keeps the saved value
                $this->db->execute('DELETE FROM {post_meta} WHERE post_id = :p AND meta_key = :k', ['p' => $id, 'k' => $key]);
                $isJson = is_array($value);
                $this->db->insert('post_meta', [
                    'post_id' => $id, 'meta_key' => $key,
                    'meta_value' => $isJson ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                    'is_json' => $isJson ? 1 : 0,
                ]);
            }
        });
        unset($this->loaded[$id]);
        return $errors;
    }

    /** Required fields that are empty: what still needs filling before publishing. @return list<string> labels */
    public function missingRequired(array $post): array
    {
        $type = (string) ($post['type'] ?? '');
        if (!$this->registry->has($type)) return [];
        $values = $this->values($post);
        $schema = $this->registry->schema($type);
        $out = [];
        foreach ($this->registry->fields($type) as $f) {
            if (empty($f['required']) || !$schema->visible($f, $values)) continue;
            $v = $values[$f['key']] ?? null;
            if ($v === '' || $v === null || $v === [] || ($v === 0 && in_array($f['type'], ['terms', 'posts', 'media'], true))) $out[] = $f['label'];
        }
        return $out;
    }

    /** Forget loaded values (after an outside write). */
    public function forget(?int $id = null): void
    {
        if ($id === null) $this->loaded = []; else unset($this->loaded[$id]);
    }
}
