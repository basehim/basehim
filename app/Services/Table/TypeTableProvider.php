<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableQuery;

/**
 * The list of an app-registered content type (1.2.94). One table is
 * registered per type, keyed content.type.{slug} (routes/tables.php); the
 * type is read from that key, so one provider serves them all. Before 1.2.94
 * every custom type's list used the Posts table and showed posts.
 */
final class TypeTableProvider extends PostTableProvider
{
    private string $current = '';

    protected function type(): string
    {
        return $this->current;
    }

    /**
     * Category and tag filters only for a type that uses them (1.2.97,
     * BH-080: a type without tags showed an empty Tags filter). Before the
     * table is known, both, as before.
     */
    public function filterKeys(): array
    {
        $keys = ['status', 'author_id', 'trashed'];
        if ($this->current === '') return array_merge($keys, ['category', 'tag']);
        try {
            $tax = \App\Core\Application::getInstance()->make(\App\Services\TaxonomyRegistry::class);
            foreach (['category', 'tag'] as $t) {
                if ($tax->appliesTo($t, $this->current)) $keys[] = $t;
            }
        } catch (\Throwable) {}
        return $keys;
    }

    /** Which table (and so which type) this provider serves. */
    public function forTable(string $key): void
    {
        $this->current = self::typeFromKey($key);
    }

    /** The table key for a type: dashes become underscores (table keys allow no dashes). */
    public static function keyFor(string $type): string
    {
        return 'content.type.' . str_replace('-', '_', $type);
    }

    /** The type a table key lists, or ''. */
    public static function typeFromKey(string $key): string
    {
        if (!str_starts_with($key, 'content.type.')) return '';
        $seg = substr($key, 13);
        try {
            foreach (\App\Core\Application::getInstance()->make(\App\Services\PostTypeRegistry::class)->custom() as $slug => $_) {
                if (str_replace('-', '_', $slug) === $seg) return $slug;
            }
        } catch (\Throwable) {}
        return '';
    }

    public function query(TableQuery $q): array
    {
        $this->current = self::typeFromKey($q->table);
        if ($this->current === '') return ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => $q->perPage, 'last_page' => 1]];
        return parent::query($q);
    }
}
