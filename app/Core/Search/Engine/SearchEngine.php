<?php

declare(strict_types=1);

namespace App\Core\Search\Engine;

use App\Core\Search\SearchQuery;

/**
 * Where indexed documents live and how they are matched (BH Search).
 *
 * Core ships two, both on the `search_index` table: FulltextEngine (MySQL /
 * MariaDB FULLTEXT, the default) and LikeEngine (any database; reads every
 * row of a source). An app can supply its own, an external search service
 * say, with the `search.engine` filter:
 *
 *     $this->addFilter('search.engine', fn($engine) => new MyEngine($engine));
 *
 * An external engine still has to answer constrain() with SQL: the simplest is
 * "{$idExpr} IN (…ids it found…)", capped at a sensible number.
 */
interface SearchEngine
{
    /** Short name shown in Settings → Search ("fulltext", "like", …). */
    public function name(): string;

    /** Whether this engine can run here (checked once and remembered). */
    public function available(): bool;

    /**
     * Add or replace documents of one source. $gen marks rows written by the
     * current build; see purgeBefore().
     *
     * @param list<array> $docs normalised documents (SearchIndexer::normalise)
     */
    public function put(string $source, array $docs, int $gen): void;

    /** @param list<int> $ids */
    public function remove(string $source, array $ids): void;

    /** Remove every document of a source, or only those older than $gen. */
    public function purge(string $source, ?int $beforeGen = null): int;

    /** How many documents of a source are stored. */
    public function count(string $source): int;

    /** The highest id stored for a source (0 when none), or null when the engine can't tell. */
    public function maxId(string $source): ?int;

    /**
     * SQL condition over $idExpr (for example "p.id") that keeps the rows of
     * $source matching $q. Placeholders go into $params. $scope narrows
     * further on stored fields: type, status, public, owner_id. $alsoIds are
     * ids that match too (rows newer than the index, found by the caller).
     */
    public function constrain(string $source, SearchQuery $q, string $idExpr, array &$params, array $scope = [], array $alsoIds = []): string;

    /**
     * A SELECT returning `object_id` for the documents of $source matching
     * $q (and $scope), or null when the engine can only answer constrain().
     * SearchManager runs it once per request and reuses the ids.
     */
    public function matchSql(string $source, SearchQuery $q, array &$params, array $scope = []): ?string;

    /**
     * Search stored documents of several sources.
     *
     * @param list<string> $sources
     * @param array{public?:bool, type?:string|list<string>, status?:string, owner_id?:int,
     *              source_types?:array<string, list<string>>,
     *              sort?:string, limit?:int, offset?:int, stem?:bool, weights?:array<string,float>} $opts
     * @return array{items: list<array{source:string, id:int, score:float, title:string, body:string,
     *              type:string, status:string, url:?string, date:?string}>, total: int}
     */
    public function search(SearchQuery $q, array $sources, array $opts = []): array;
}
