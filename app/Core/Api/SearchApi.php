<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Search\SearchManager;
use App\Core\Search\SearchQuery;

/**
 * BH Search for apps (1.2.92): make an app's own records searchable, search
 * them fast at any size, and search the site.
 *
 *     // boot(): register a source (ids are namespaced: "{slug}.{name}")
 *     $this->api()->search()->source('products', [
 *         'label'     => 'Products',
 *         'public'    => true,                         // appears in site search
 *         'documents' => fn(int $after, int $limit) => $this->productDocs($after, $limit),
 *         'document'  => fn(int $id) => $this->productDoc($id),
 *         'count'     => fn() => $this->productCount(),
 *         'table'     => $this->table('products'),     // searched directly until indexed
 *         'columns'   => ['name', 'sku', 'description'],
 *         'hydrate'   => fn(array $ids, array $opts) => $this->productsById($ids),
 *     ]);
 *
 *     // after saving / deleting a product
 *     $this->api()->search()->touch('products', $id);
 *     $this->api()->search()->remove('products', $id);
 *
 *     // a list query on your own table, every word in any order
 *     $params = [];
 *     $where = $this->api()->search()->constrain('products', $q, 'p', $params);
 *     $rows = $this->db()->select("SELECT * FROM {$t} p WHERE {$where} LIMIT 20", $params);
 *
 *     // ranked results across sources
 *     $r = $this->api()->search()->query('blue shirt', ['sources' => ['products']]);
 *
 * A document: id (int), title, body, keywords, type, status, public (bool),
 * owner_id, date, url. See docs/SEARCH.md.
 *
 * Searching core's sources needs the matching read permission (content:
 * posts.read; media: media.read; comments: comments.read; users:
 * users.read), except public results (`'public' => true`), which anyone may
 * see. An app's own sources need none.
 */
class SearchApi extends Resource
{
    private function manager(): SearchManager
    {
        return $this->make(SearchManager::class);
    }

    /** The full id of one of this app's sources. */
    public function id(string $name): string
    {
        $name = strtolower(trim($name));
        return $this->slug . '.' . (preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $name) ? $name : 'invalid');
    }

    /** Register one of this app's sources (from boot()). */
    public function source(string $name, array $def): bool
    {
        return $this->attempt(fn() => $this->manager()->source($this->id($name), $def, $this->slug), false, 'source');
    }

    /** Index items again from the source's `document` callable. */
    public function touch(string $name, int ...$ids): void
    {
        $this->attempt(fn() => $this->manager()->touch($this->id($name), ...$ids), null, 'touch');
    }

    /** Index documents you built yourself. */
    public function put(string $name, array ...$docs): bool
    {
        return (bool) $this->attempt(fn() => $this->manager()->put($this->id($name), ...$docs), false, 'put');
    }

    public function remove(string $name, int ...$ids): void
    {
        $this->attempt(fn() => $this->manager()->remove($this->id($name), ...$ids), null, 'remove');
    }

    /** Build this source's index again from scratch, in the background. */
    public function rebuild(string $name): bool
    {
        return (bool) $this->attempt(fn() => $this->manager()->rebuild($this->id($name)), false, 'rebuild');
    }

    /** Remove this source from the index (onUninstall). */
    public function purge(string $name): int
    {
        return (int) $this->attempt(fn() => $this->manager()->purge($this->id($name)), 0, 'purge');
    }

    /** Whether the source's index is complete. */
    public function ready(string $name): bool
    {
        return (bool) $this->attempt(fn() => $this->manager()->ready($this->id($name)), false, 'ready');
    }

    /**
     * SQL condition for a query on this app's own table (see the class
     * comment). Returns '1=1' for a blank query, '1=0' on failure.
     */
    public function constrain(string $name, string $query, string $alias, array &$params, array $scope = []): string
    {
        try {
            return $this->manager()->constrain($this->id($name), $query, $alias, $params, $scope);
        } catch (\Throwable $e) {
            $this->log('Search constrain failed: ' . $e->getMessage(), [], 'warning');
            return '1=0';
        }
    }

    /**
     * Ranked results. `sources` takes this app's source names ("products")
     * and core's ids ("content", "media", …); the default is this app's own
     * sources. Options as SearchManager::query().
     */
    public function query(string $query, array $opts = []): array
    {
        $m = $this->manager();
        $all = $m->sources();
        $wanted = isset($opts['sources']) ? (array) $opts['sources'] : null;
        $ids = [];
        if ($wanted === null) {
            foreach ($all as $id => $s) if ($s->owner === $this->slug) $ids[] = $id;
        } else {
            foreach ($wanted as $w) {
                $w = (string) $w;
                $id = isset($all[$w]) ? $w : $this->id($w);
                if (isset($all[$id])) $ids[] = $id;
            }
        }
        $public = !empty($opts['public']);
        $ids = array_values(array_filter($ids, function (string $id) use ($all, $public): bool {
            $s = $all[$id];
            if ($s->owner === $this->slug) return true;
            if ($public && $s->isPublic()) return true;
            $perm = $id === 'content' ? 'posts.read' : $s->permission();
            return $perm === null ? true : $this->permittedFor($perm, $id);
        }));
        $empty = ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 10, 'last_page' => 1, 'query' => $query];
        if ($ids === []) return $empty;
        try {
            return $m->query($query, ['sources' => $ids] + $opts);
        } catch (\Throwable $e) {
            $this->log('Search query failed: ' . $e->getMessage(), [], 'warning');
            return $empty;
        }
    }

    /** The query parser, for matching in PHP: $this->api()->search()->parse($q)->matches($text). */
    public function parse(string $query): SearchQuery
    {
        return SearchQuery::parse($query);
    }

    private function permittedFor(string $permission, string $source): bool
    {
        try {
            if ($this->make(\App\Services\PermissionBroker::class)->allows($this->slug, $permission)) return true;
        } catch (\Throwable) {
            return true;
        }
        $this->log("Permission denied: '{$permission}' is required to search {$source}.", ['permission' => $permission], 'warning');
        return false;
    }
}
