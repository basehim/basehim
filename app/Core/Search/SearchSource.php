<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * One kind of searchable thing: posts, media, an app's products (BH Search).
 *
 * Two kinds:
 *
 * - **indexed** (`documents` given): rows kept in the shared search index.
 *   Core fills it from `documents` in the background, and keeps it current
 *   when `SearchManager::touch()` / `remove()` are called on save and delete.
 *   Any number of rows.
 * - **callback** (`search` given): searched in PHP each time, for small
 *   in-memory lists (installed apps, tools). No index.
 *
 * Definition keys (all optional unless said):
 *
 *     label        'Products'
 *     documents    fn(int $afterId, int $limit): list<array>   — indexed sources (required)
 *     document     fn(int $id): ?array                          — one document; null = gone
 *     count        fn(): int                                    — how many there are (progress)
 *     table, id_column, columns
 *                  the source's own table, its id column and the columns to
 *                  search with LIKE until the index is built
 *     fallback     fn(SearchQuery $q, string $alias, array &$params, array $scope): string
 *                  custom SQL condition used until the index is built
 *     public       true: its public documents appear in site search
 *     weight       relevance multiplier in mixed results (1.0)
 *     hydrate      fn(list<int> $ids, array $opts): array<int, array> — full rows for results
 *     search       fn(SearchQuery $q, array $opts): list<array> — callback sources
 *     batch        rows per background batch (500)
 *     version      bump to rebuild this source's index (1)
 *     permission   app permission needed to query it through api()->search()
 *
 * A document is an array:
 *
 *     id        int, required          title     string
 *     body      string (plain text)    keywords  string|list<string>
 *     type      string (sub-type)      status    string
 *     public    bool (visible to anyone now; site search shows only these)
 *     owner_id  ?int                   date      ?string 'Y-m-d H:i:s' UTC
 *     url       ?string
 */
final class SearchSource
{
    public readonly string $id;
    public readonly string $owner;
    public readonly string $label;
    private array $def;

    public function __construct(string $id, array $def, string $owner = 'core')
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.:-]{0,99}$/', $id)) {
            throw new \InvalidArgumentException("Invalid search source id '{$id}'.");
        }
        if (!isset($def['documents']) && !isset($def['search'])) {
            throw new \InvalidArgumentException("Search source '{$id}' needs 'documents' (indexed) or 'search' (callback).");
        }
        foreach (['documents', 'document', 'count', 'fallback', 'hydrate', 'search'] as $k) {
            if (isset($def[$k]) && !is_callable($def[$k])) {
                throw new \InvalidArgumentException("Search source '{$id}': '{$k}' must be callable.");
            }
        }
        $this->id = $id;
        $this->owner = $owner;
        $this->label = (string) ($def['label'] ?? $id);
        $this->def = $def;
    }

    public function indexed(): bool { return isset($this->def['documents']); }

    public function isPublic(): bool { return !empty($this->def['public']); }

    public function weight(): float { return max(0.01, (float) ($this->def['weight'] ?? 1.0)); }

    public function batch(): int { return max(1, min(5000, (int) ($this->def['batch'] ?? 500))); }

    public function version(): int { return max(1, (int) ($this->def['version'] ?? 1)); }

    public function permission(): ?string
    {
        return isset($this->def['permission']) ? (string) $this->def['permission'] : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->def[$key] ?? $default;
    }

    /** @return list<array> */
    public function documents(int $afterId, int $limit): array
    {
        return array_values((array) ($this->def['documents'])($afterId, $limit));
    }

    /**
     * One document. Without a `document` callable, the first document after
     * $id - 1 is used if it is this one.
     */
    public function document(int $id): ?array
    {
        if (isset($this->def['document'])) {
            $d = ($this->def['document'])($id);
            return is_array($d) ? $d : null;
        }
        if (!$this->indexed()) return null;
        $d = $this->documents($id - 1, 1)[0] ?? null;
        return is_array($d) && (int) ($d['id'] ?? 0) === $id ? $d : null;
    }

    public function count(): ?int
    {
        if (!isset($this->def['count'])) return null;
        try { return max(0, (int) ($this->def['count'])()); } catch (\Throwable) { return null; }
    }

    /**
     * Full rows for results, keyed by id. $opts['public'] is true for site
     * search: a source should then return only rows anyone may see.
     *
     * @return array<int, array>
     */
    public function hydrate(array $ids, array $opts = []): array
    {
        if (!isset($this->def['hydrate']) || $ids === []) return [];
        return (array) ($this->def['hydrate'])($ids, $opts);
    }

    /** @return list<array> */
    public function searchCallback(SearchQuery $q, array $opts): array
    {
        if (!isset($this->def['search'])) return [];
        return array_values((array) ($this->def['search'])($q, $opts));
    }

    /**
     * SQL condition on the source's own table, for use before its index is
     * built: the `fallback` callable, or every word in any of `columns`.
     * Returns null when the source has neither.
     */
    public function fallbackCondition(SearchQuery $q, string $alias, array &$params, array $scope): ?string
    {
        if (isset($this->def['fallback'])) {
            return (string) ($this->def['fallback'])($q, $alias, $params, $scope);
        }
        $columns = (array) ($this->def['columns'] ?? []);
        if ($columns === []) return null;
        $prefix = $alias !== '' ? $alias . '.' : '';
        $cols = [];
        foreach ($columns as $c) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $c)) $cols[] = $prefix . $c;
        }
        return $cols === [] ? null : LikeMatcher::condition($q, $cols, $params);
    }
}
