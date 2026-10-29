<?php

declare(strict_types=1);

namespace App\Core\Search\Engine;

use App\Core\Database;
use App\Core\Search\SearchQuery;

/**
 * Storage shared by the two built-in engines: the `search_index` table.
 * Subclasses decide how a query matches (match()).
 */
abstract class DatabaseEngine implements SearchEngine
{
    /** Rows per INSERT when writing many documents. */
    private const WRITE_CHUNK = 100;

    private static int $seq = 0;

    public function __construct(protected Database $db) {}

    /**
     * The condition (and relevance expression) for $q on alias si. The
     * condition's placeholders go into $params, the score's into
     * $scoreParams: a statement may only carry placeholders it uses.
     *
     * @return array{0:string, 1:string} [condition, score expression]
     */
    abstract protected function match(SearchQuery $q, array &$params, array &$scoreParams, bool $stem): array;

    protected static function key(string $base): string
    {
        return $base . (++self::$seq);
    }

    public function put(string $source, array $docs, int $gen): void
    {
        foreach (array_chunk($docs, self::WRITE_CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $i => $d) {
                $rows[] = "(:s{$i}, :o{$i}, :ty{$i}, :st{$i}, :pu{$i}, :ow{$i}, :ti{$i}, :bo{$i}, :kw{$i}, :ur{$i}, :da{$i}, :ge{$i})";
                $params += [
                    "s{$i}" => $source, "o{$i}" => (int) $d['id'], "ty{$i}" => $d['type'], "st{$i}" => $d['status'],
                    "pu{$i}" => $d['public'] ? 1 : 0, "ow{$i}" => $d['owner_id'], "ti{$i}" => $d['title'],
                    "bo{$i}" => $d['body'], "kw{$i}" => $d['keywords'], "ur{$i}" => $d['url'], "da{$i}" => $d['date'],
                    "ge{$i}" => $gen,
                ];
            }
            $this->db->execute(
                'INSERT INTO {search_index} (source, object_id, type, status, is_public, owner_id, title, body, keywords, url, item_date, gen)
                 VALUES ' . implode(', ', $rows) . '
                 ON DUPLICATE KEY UPDATE type = VALUES(type), status = VALUES(status), is_public = VALUES(is_public),
                    owner_id = VALUES(owner_id), title = VALUES(title), body = VALUES(body), keywords = VALUES(keywords),
                    url = VALUES(url), item_date = VALUES(item_date), gen = GREATEST(gen, VALUES(gen))',
                $params
            );
        }
    }

    public function remove(string $source, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->db->execute(
                'DELETE FROM {search_index} WHERE source = :s AND object_id IN (' . implode(',', $chunk) . ')',
                ['s' => $source]
            );
        }
    }

    public function purge(string $source, ?int $beforeGen = null): int
    {
        if ($beforeGen === null) {
            return $this->db->execute('DELETE FROM {search_index} WHERE source = :s', ['s' => $source]);
        }
        return $this->db->execute('DELETE FROM {search_index} WHERE source = :s AND gen < :g', ['s' => $source, 'g' => $beforeGen]);
    }

    public function count(string $source): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM {search_index} WHERE source = :s', ['s' => $source])['c'] ?? 0);
    }

    public function maxId(string $source): ?int
    {
        return (int) ($this->db->selectOne('SELECT MAX(object_id) AS m FROM {search_index} WHERE source = :s', ['s' => $source])['m'] ?? 0);
    }

    public function matchSql(string $source, SearchQuery $q, array &$params, array $scope = []): ?string
    {
        if ($q->isBlank() || $q->matchesNothing()) return null;
        $s = self::key('sq_src');
        $params[$s] = $source;
        $unused = [];
        [$cond] = $this->match($q, $params, $unused, false);
        $where = ["si.source = :{$s}", $cond];
        $this->scopeWhere($scope, $where, $params);
        return 'SELECT si.object_id FROM {search_index} si WHERE ' . implode(' AND ', $where);
    }

    public function constrain(string $source, SearchQuery $q, string $idExpr, array &$params, array $scope = [], array $alsoIds = []): string
    {
        if ($q->matchesNothing()) return '1=0';
        if ($q->isBlank()) return '1=1';
        $s = self::key('sq_src');
        $params[$s] = $source;
        $unused = [];
        [$cond] = $this->match($q, $params, $unused, false);
        $where = ["si.source = :{$s}", $cond];
        $this->scopeWhere($scope, $where, $params);
        // A derived table, not a correlated IN: the match is evaluated once
        // and joined by primary key, whatever the outer query looks like.
        // Rows newer than the index join the same subquery, so the plan stays
        // "match first, then the table by primary key".
        $also = '';
        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $alsoIds), fn($i) => $i > 0))), 0, 1000) as $id) {
            $also .= ' UNION ALL SELECT ' . $id;
        }
        return "{$idExpr} IN (SELECT m.object_id FROM (SELECT si.object_id FROM {search_index} si WHERE "
            . implode(' AND ', $where) . $also . ') m)';
    }

    public function search(SearchQuery $q, array $sources, array $opts = []): array
    {
        $empty = ['items' => [], 'total' => 0];
        if ($sources === [] || $q->isBlank() || $q->matchesNothing()) return $empty;
        $params = [];
        $scoreParams = [];
        [$cond, $score] = $this->match($q, $params, $scoreParams, (bool) ($opts['stem'] ?? false));

        $in = [];
        foreach (array_values($sources) as $i => $src) {
            $k = self::key('ss');
            $params[$k] = (string) $src;
            $in[] = ':' . $k;
        }
        $where = ['si.source IN (' . implode(', ', $in) . ')', $cond];
        $this->scopeWhere($opts, $where, $params);
        $whereSql = implode(' AND ', $where);

        // Per-source weights multiply the relevance score.
        $weights = array_filter((array) ($opts['weights'] ?? []), fn($w) => (float) $w !== 1.0);
        $scoreSql = $score;
        if ($weights) {
            $case = [];
            foreach ($weights as $src => $w) {
                $k = self::key('sw');
                $scoreParams[$k] = (string) $src;
                $case[] = "WHEN :{$k} THEN " . number_format((float) $w, 4, '.', '');
            }
            $scoreSql = "({$score}) * (CASE si.source " . implode(' ', $case) . ' ELSE 1 END)';
        }

        $limit = max(1, min(200, (int) ($opts['limit'] ?? 10)));
        $offset = max(0, min(100000, (int) ($opts['offset'] ?? 0)));
        $order = ($opts['sort'] ?? 'relevance') === 'newest'
            ? 'si.item_date DESC, si.id DESC'
            : 'score DESC, si.item_date DESC, si.id DESC';

        $select = "si.source, si.object_id, si.type, si.status, si.title, si.body, si.url, si.item_date, {$scoreSql} AS score";
        if ($this->windowFunctions()) {
            // One pass: the rows and the total together, the match evaluated once.
            $rows = $this->db->select(
                "SELECT {$select}, COUNT(*) OVER () AS bh_total FROM {search_index} si WHERE {$whereSql}
                  ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
                $params + $scoreParams
            );
            $total = $rows ? (int) $rows[0]['bh_total']
                : ($offset > 0 ? (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM {search_index} si WHERE {$whereSql}", $params)['c'] ?? 0) : 0);
        } else {
            $total = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM {search_index} si WHERE {$whereSql}", $params)['c'] ?? 0);
            if ($total === 0 || $offset >= $total) return ['items' => [], 'total' => $total];
            $rows = $this->db->select(
                "SELECT {$select} FROM {search_index} si WHERE {$whereSql} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
                $params + $scoreParams
            );
        }
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'source' => (string) $r['source'], 'id' => (int) $r['object_id'], 'score' => (float) $r['score'],
                'title' => (string) $r['title'], 'body' => (string) ($r['body'] ?? ''), 'type' => (string) $r['type'],
                'status' => (string) $r['status'], 'url' => $r['url'] !== null ? (string) $r['url'] : null,
                'date' => $r['item_date'] !== null ? (string) $r['item_date'] : null,
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    private ?bool $window = null;

    /** MariaDB 10.2+ and MySQL 8+ have window functions; checked once per request. */
    protected function windowFunctions(): bool
    {
        if ($this->window !== null) return $this->window;
        try {
            $this->db->selectOne('SELECT COUNT(*) OVER () AS c FROM (SELECT 1 AS x) t');
            return $this->window = true;
        } catch (\Throwable) {
            return $this->window = false;
        }
    }

    /** Stored-field filters: public, type, status, owner_id. */
    private function scopeWhere(array $scope, array &$where, array &$params): void
    {
        if (array_key_exists('public', $scope) && $scope['public'] !== null) {
            $where[] = 'si.is_public = ' . ($scope['public'] ? '1' : '0');
        }
        foreach (['type' => 'si.type', 'status' => 'si.status'] as $key => $col) {
            if (!isset($scope[$key]) || $scope[$key] === '' || $scope[$key] === []) continue;
            $vals = array_values(array_unique(array_map('strval', (array) $scope[$key])));
            $ph = [];
            foreach ($vals as $v) {
                $k = self::key('sc');
                $params[$k] = $v;
                $ph[] = ':' . $k;
            }
            $where[] = $col . (count($ph) === 1 ? ' = ' . $ph[0] : ' IN (' . implode(', ', $ph) . ')');
        }
        if (isset($scope['owner_id']) && (int) $scope['owner_id'] > 0) {
            $where[] = 'si.owner_id = ' . (int) $scope['owner_id'];
        }
        // Types per source: ['content' => ['post', 'page']] limits that source
        // to those types and leaves the other sources alone.
        foreach ((array) ($scope['source_types'] ?? []) as $src => $types) {
            $types = array_values(array_unique(array_map('strval', (array) $types)));
            $ks = self::key('sts');
            $params[$ks] = (string) $src;
            if ($types === []) { $where[] = "si.source <> :{$ks}"; continue; }
            $ph = [];
            foreach ($types as $t) { $k = self::key('stt'); $params[$k] = $t; $ph[] = ':' . $k; }
            $where[] = "(si.source <> :{$ks} OR si.type IN (" . implode(', ', $ph) . '))';
        }
    }
}
