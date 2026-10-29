<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * SQL LIKE conditions for a SearchQuery over a list of columns: every word in
 * at least one column, phrases as their words in order, exclusions in none.
 * Matches inside words. Reads every row it is given, so it is the fallback
 * (before an index is built, or on a database without FULLTEXT), not the
 * normal path for big tables.
 */
final class LikeMatcher
{
    private static int $seq = 0;

    /**
     * @param list<string> $columns already qualified and checked by the caller
     */
    public static function condition(SearchQuery $q, array $columns, array &$params): string
    {
        if ($q->matchesNothing() || $columns === []) return '1=0';
        if ($q->isBlank()) return '1=1';
        $and = [];
        foreach ($q->terms() as $t) $and[] = self::contains($t, $columns, $params);
        foreach ($q->phrases() as $words) $and[] = self::phrase($words, $columns, $params);
        foreach ($q->excluded() as $t) $and[] = self::lacks($t, $columns, $params);
        return $and === [] ? '1=1' : '(' . implode(' AND ', $and) . ')';
    }

    /** The word appears in at least one column. */
    public static function contains(string $word, array $columns, array &$params): string
    {
        return self::anyColumn($columns, '%' . self::escape($word) . '%', $params);
    }

    /** The words appear in this order (with anything between) in one column. */
    public static function phrase(array $words, array $columns, array &$params): string
    {
        return self::anyColumn($columns, '%' . implode('%', array_map([self::class, 'escape'], $words)) . '%', $params);
    }

    /** The word appears in no column (NULL counts as not appearing). */
    public static function lacks(string $word, array $columns, array &$params): string
    {
        return 'NOT ' . self::anyColumn($columns, '%' . self::escape($word) . '%', $params, true);
    }

    /** One placeholder per use: native prepares refuse a repeated name (HY093). */
    private static function anyColumn(array $columns, string $like, array &$params, bool $nullSafe = false): string
    {
        $or = [];
        foreach ($columns as $c) {
            $key = 'lk' . (++self::$seq);
            $params[$key] = $like;
            $or[] = $nullSafe ? "COALESCE({$c}, '') LIKE :{$key}" : "{$c} LIKE :{$key}";
        }
        return '(' . implode(' OR ', $or) . ')';
    }

    public static function escape(string $s): string
    {
        return addcslashes($s, '\\%_');
    }
}
