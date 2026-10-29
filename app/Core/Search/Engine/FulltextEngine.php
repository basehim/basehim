<?php

declare(strict_types=1);

namespace App\Core\Search\Engine;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Search\LikeMatcher;
use App\Core\Search\SearchQuery;

/**
 * MySQL / MariaDB FULLTEXT on `search_index` (BH Search's default engine).
 *
 * Each word becomes a required prefix term (`+pric*`), so matching every word
 * in any order uses the FULLTEXT index and costs in proportion to the rows
 * that match, not to the size of the table. Relevance is the server's own
 * (BM25-like) score; titles count more because the indexer repeats them in
 * `keywords`.
 *
 * FULLTEXT cannot see two kinds of word: ones shorter than the server's
 * minimum token size (3 by default: "ai", "go") and stopwords ("the",
 * "about"). Those are checked with LIKE on the rows the other words already
 * found. A query made only of such words is answered with LIKE alone, which
 * reads every row of the source; it is the one slow case, and rare.
 */
final class FulltextEngine extends DatabaseEngine
{
    private const CONFIG_KEY = 'search.ft.config';
    private const FAILED_KEY = 'search.ft.unavailable';

    private ?bool $available = null;
    private ?array $config = null;

    public function __construct(Database $db, private ?Cache $cache = null)
    {
        parent::__construct($db);
    }

    public function name(): string { return 'fulltext'; }

    public function available(): bool
    {
        if ($this->available !== null) return $this->available;
        try {
            if ($this->cache?->get(self::FAILED_KEY)) return $this->available = false;
        } catch (\Throwable) {}
        try {
            $this->db->selectOne(
                "SELECT 1 AS ok FROM {search_index} WHERE MATCH(title, body, keywords) AGAINST('+basehimprobe*' IN BOOLEAN MODE) LIMIT 1"
            );
            return $this->available = true;
        } catch (\Throwable $e) {
            // Remembered for an hour, so every search does not fail first.
            try { $this->cache?->set(self::FAILED_KEY, 1, 3600); } catch (\Throwable) {}
            error_log('Basehim search: FULLTEXT unavailable, using LIKE: ' . $e->getMessage());
            return $this->available = false;
        }
    }

    /** Forget the remembered probe result (after a database change). */
    public function reset(): void
    {
        $this->available = null;
        $this->config = null;
        try { $this->cache?->delete(self::FAILED_KEY); $this->cache?->delete(self::CONFIG_KEY); } catch (\Throwable) {}
    }

    /** @return array{min:int, stopwords:array<string,true>} */
    public function config(): array
    {
        if ($this->config !== null) return $this->config;
        try {
            $hit = $this->cache?->get(self::CONFIG_KEY);
            if (is_array($hit) && isset($hit['min'], $hit['stopwords'])) return $this->config = $hit;
        } catch (\Throwable) {}

        $min = 3;
        $stop = [];
        try {
            $v = $this->db->selectOne('SELECT @@innodb_ft_min_token_size AS m, @@innodb_ft_enable_stopword AS e, @@innodb_ft_server_stopword_table AS t');
            $min = max(1, (int) ($v['m'] ?? 3));
            if ((int) ($v['e'] ?? 1) === 1) {
                $table = (string) ($v['t'] ?? '');
                $rows = [];
                if ($table !== '' && preg_match('#^([A-Za-z0-9_$]+)/([A-Za-z0-9_$]+)$#', $table, $m)) {
                    try { $rows = $this->db->select("SELECT value FROM `{$m[1]}`.`{$m[2]}`"); } catch (\Throwable) {}
                } else {
                    $rows = $this->db->select('SELECT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD');
                }
                foreach ($rows as $r) $stop[mb_strtolower((string) $r['value'])] = true;
            }
        } catch (\Throwable) {
            // Unknown server: assume the defaults and a short common list.
            foreach (['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'] as $w) {
                $stop[$w] = true;
            }
        }
        $this->config = ['min' => $min, 'stopwords' => $stop];
        try { $this->cache?->set(self::CONFIG_KEY, $this->config, 86400); } catch (\Throwable) {}
        return $this->config;
    }

    private function quote(string $s): string
    {
        if (!preg_match('/^[\p{L}\p{N}\p{M}+*\- ]*$/u', $s)) throw new \InvalidArgumentException('Unexpected FULLTEXT expression.');
        return "'" . $s . "'";
    }

    /** Whether FULLTEXT can find this word. */
    public function indexable(string $word): bool
    {
        $c = $this->config();
        return mb_strlen($word, 'UTF-8') >= $c['min'] && !isset($c['stopwords'][$word]);
    }

    protected function match(SearchQuery $q, array &$params, array &$scoreParams, bool $stem): array
    {
        $ft = [];
        $like = [];
        $cols = ['si.title', 'si.keywords', 'si.body'];

        foreach ($q->terms() as $t) {
            $use = $stem ? SearchQuery::stem($t) : $t;
            if (!$this->indexable($use)) $use = $t;
            if ($this->indexable($use)) {
                $ft[] = '+' . $use . '*';
            } else {
                $like[] = LikeMatcher::contains($t, $cols, $params);
            }
        }
        foreach ($q->phrases() as $words) {
            foreach ($words as $w) {
                if ($this->indexable($w)) $ft[] = '+' . $w . '*';
            }
            $like[] = LikeMatcher::phrase($words, $cols, $params);
        }
        $ft = array_values(array_unique($ft));

        $not = [];
        foreach ($q->excluded() as $t) {
            if ($ft !== [] && $this->indexable($t)) {
                $not[] = '-' . $t . '*';
            } else {
                $like[] = LikeMatcher::lacks($t, $cols, $params);
            }
        }

        if ($ft === []) {
            // Only short words or stopwords: LIKE alone.
            return ['(' . implode(' AND ', $like ?: ['1=1']) . ')', '1'];
        }
        // A literal, not a placeholder: the server evaluates a MATCH once
        // when the same expression is in WHERE and the select list, which
        // halves the cost for common words. The expression holds only
        // letters, digits, marks and the operators added here, and is quoted.
        $expr = implode(' ', array_merge($ft, $not));
        $match = 'MATCH(si.title, si.body, si.keywords) AGAINST(' . $this->quote($expr) . ' IN BOOLEAN MODE)';
        $cond = $like !== [] ? '(' . $match . ' AND ' . implode(' AND ', $like) . ')' : $match;
        return [$cond, $match];
    }
}
