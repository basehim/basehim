<?php

declare(strict_types=1);

namespace App\Core\Search\Engine;

use App\Core\Search\LikeMatcher;
use App\Core\Search\SearchQuery;

/**
 * LIKE on `search_index`: works on every database (including ones without
 * FULLTEXT, or with Settings → Search set to "Basic"), and matches inside
 * words, but reads every row of the sources searched. Fine for thousands of
 * rows; the FULLTEXT engine is for more.
 */
final class LikeEngine extends DatabaseEngine
{
    public function name(): string { return 'like'; }

    public function available(): bool { return true; }

    protected function match(SearchQuery $q, array &$params, array &$scoreParams, bool $stem): array
    {
        $cond = LikeMatcher::condition($q, ['si.title', 'si.keywords', 'si.body'], $params);
        // Relevance: words found in the title count most.
        $score = [];
        foreach (array_slice($q->words(), 0, 4) as $w) {
            $k = self::key('ls');
            $scoreParams[$k] = '%' . LikeMatcher::escape($w) . '%';
            $score[] = "(CASE WHEN si.title LIKE :{$k} THEN 2 ELSE 0 END)";
        }
        return [$cond, $score ? '(1 + ' . implode(' + ', $score) . ')' : '1'];
    }
}
