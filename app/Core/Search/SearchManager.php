<?php

declare(strict_types=1);

namespace App\Core\Search;

use App\Core\Cache;
use App\Core\Database;
use App\Core\HookRegistry;
use App\Core\Search\Engine\FulltextEngine;
use App\Core\Search\Engine\LikeEngine;
use App\Core\Search\Engine\SearchEngine;
use App\Services\SettingService;

/**
 * BH Search (1.2.92): one search framework for core and apps.
 *
 * Sources register what can be searched (SearchSource). Indexed sources are
 * copied into one table, `search_index`, with a FULLTEXT index, so searching
 * costs in proportion to what matches, not to how much there is. The copy is
 * built in the background (scheduled task `core:search-index`, or Settings →
 * Search → Build now) and kept current by touch() / remove() on every save
 * and delete. Until a source's copy is complete, searches of it use the
 * source's own table, as before, so results are never partial.
 *
 *     $search = app(SearchManager::class);
 *     $search->constrain('media', $q, 'm', $params)   // SQL for a list query
 *     $search->query('pricing', ['public' => true])    // mixed results
 *     $search->touch('media', $id)                     // after a save
 *
 * Apps use the same thing through `$this->api()->search()`.
 */
final class SearchManager
{
    public const SETTINGS_GROUP = 'search';
    private const STATE_KEY = 'index_state';
    private const LOCK_FILE = 'search-build.lock';

    /** Ids that failed to index, kept per source for a retry. */
    private const MAX_RETRY = 1000;

    /** @var array<string, SearchSource> */
    private array $sources = [];
    private bool $coreRegistered = false;
    private ?SearchEngine $engine = null;
    private ?array $state = null;

    /** @var null|\Closure(SearchManager):void */
    private ?\Closure $registerCore;

    public function __construct(
        private Database $db,
        private SettingService $settings,
        private ?Cache $cache = null,
        private ?HookRegistry $hooks = null,
        ?\Closure $registerCore = null,
        private string $lockDir = '',
    ) {
        $this->registerCore = $registerCore;
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /** Register (or replace) a source. Returns false and logs on a bad definition. */
    public function source(string $id, array $def, string $owner = 'core'): bool
    {
        try {
            $this->sources[$id] = new SearchSource($id, $def, $owner);
            return true;
        } catch (\InvalidArgumentException $e) {
            error_log('Basehim search: ' . $e->getMessage());
            return false;
        }
    }

    /** Drop every source an app registered (it was switched off mid-request). */
    public function forgetOwner(string $owner): int
    {
        $n = 0;
        foreach ($this->sources as $id => $s) {
            if ($s->owner === $owner) { unset($this->sources[$id]); $n++; }
        }
        return $n;
    }

    /** @return array<string, SearchSource> */
    public function sources(): array
    {
        $this->registerCoreSources();
        return $this->sources;
    }

    public function get(string $id): ?SearchSource
    {
        return $this->sources()[$id] ?? null;
    }

    private function registerCoreSources(): void
    {
        if ($this->coreRegistered) return;
        $this->coreRegistered = true;
        if ($this->registerCore) {
            try { ($this->registerCore)($this); } catch (\Throwable $e) { error_log('Basehim search: core sources: ' . $e->getMessage()); }
        }
    }

    // ------------------------------------------------------------------
    // Engine
    // ------------------------------------------------------------------

    /** The engine in use: an app's (filter `search.engine`), FULLTEXT, or LIKE. */
    public function engine(): SearchEngine
    {
        if ($this->engine !== null) return $this->engine;
        $mode = 'auto';
        try { $mode = (string) $this->settings->get('reading', 'search_mode', 'auto'); } catch (\Throwable) {}
        $ft = new FulltextEngine($this->db, $this->cache);
        $engine = ($mode !== 'like' && $ft->available()) ? $ft : new LikeEngine($this->db);
        if ($this->hooks) {
            try {
                $custom = $this->hooks->applyFilters('search.engine', $engine);
                if ($custom instanceof SearchEngine && $custom->available()) $engine = $custom;
            } catch (\Throwable $e) {
                error_log('Basehim search: search.engine filter failed: ' . $e->getMessage());
            }
        }
        return $this->engine = $engine;
    }

    /** Use another engine for the rest of this request (tests, CLI tools). */
    public function useEngine(?SearchEngine $engine): void
    {
        $this->engine = $engine;
    }

    // ------------------------------------------------------------------
    // Build state
    // ------------------------------------------------------------------

    /**
     * Build state per source, read straight from the settings table (not the
     * settings cache: it changes every batch while an index is built).
     *
     * @return array<string, array{gen:int, cursor:int, ready:bool, version:int, done:int, started:?string, finished:?string, retry?:list<int>}>
     */
    private function states(bool $fresh = false): array
    {
        if ($this->state !== null && !$fresh) return $this->state;
        $v = null;
        try {
            $row = $this->db->selectOne(
                'SELECT setting_value FROM {settings} WHERE setting_group = :g AND setting_key = :k',
                ['g' => self::SETTINGS_GROUP, 'k' => self::STATE_KEY]
            );
            $v = $row ? json_decode((string) $row['setting_value'], true) : [];
        } catch (\Throwable) {}
        return $this->state = is_array($v) ? $v : [];
    }

    /** Change the stored state under a row lock, so the builder and a failed save never overwrite each other. */
    private function mutateStates(callable $fn): void
    {
        try {
            $new = $this->settings->update(self::SETTINGS_GROUP, self::STATE_KEY,
                fn($cur) => $fn(is_array($cur) ? $cur : []), [], false);
            $this->state = is_array($new) ? $new : [];
        } catch (\Throwable $e) {
            error_log('Basehim search: could not save index state: ' . $e->getMessage());
        }
    }

    private function stateOf(SearchSource $s): array
    {
        $st = $this->states()[$s->id] ?? null;
        if (!is_array($st) || (int) ($st['version'] ?? 0) !== $s->version()) {
            $prevGen = is_array($st) ? (int) ($st['gen'] ?? 0) : 0;
            $st = ['gen' => $prevGen + 1, 'cursor' => 0, 'ready' => false, 'version' => $s->version(),
                'done' => 0, 'started' => null, 'finished' => null];
        }
        return $st;
    }

    /** Whether a source's index is complete and can answer searches. */
    public function ready(string $id): bool
    {
        $s = $this->get($id);
        if ($s === null || !$s->indexed()) return false;
        $st = $this->states()[$id] ?? null;
        return is_array($st) && !empty($st['ready']) && (int) ($st['version'] ?? 0) === $s->version();
    }

    /** Start building a source's index again (Rebuild). Searches use the source's own table meanwhile. */
    public function rebuild(string $id): bool
    {
        $s = $this->get($id);
        if ($s === null || !$s->indexed()) return false;
        $this->mutateStates(function (array $all) use ($id, $s): array {
            $all[$id] = ['gen' => (int) ($all[$id]['gen'] ?? 0) + 1, 'cursor' => 0, 'ready' => false,
                'version' => $s->version(), 'done' => 0, 'started' => gmdate('Y-m-d H:i:s'), 'finished' => null];
            return $all;
        });
        return true;
    }

    // ------------------------------------------------------------------
    // Keeping the index current
    // ------------------------------------------------------------------

    /**
     * Index these items again from their source (after a save). Never throws:
     * a failure is logged and the ids are retried by the background task.
     */
    public function touch(string $id, int ...$ids): void
    {
        $s = $this->get($id);
        if ($s === null || !$s->indexed() || $ids === []) return;
        $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
        try {
            $docs = [];
            $gone = [];
            foreach ($ids as $i) {
                $d = $s->document($i);
                if ($d === null) $gone[] = $i; else $docs[] = $this->normalise($d);
            }
            $engine = $this->engine();
            $this->forgetSets();
            if ($docs) $engine->put($s->id, $docs, (int) $this->stateOf($s)['gen']);
            if ($gone) $engine->remove($s->id, $gone);
        } catch (\Throwable $e) {
            error_log("Basehim search: indexing {$id} #" . implode(',', $ids) . ' failed: ' . $e->getMessage());
            $this->remember($id, $ids);
        }
    }

    /** Put documents the caller built itself (apps that index without a `document` callable). */
    public function put(string $id, array ...$docs): bool
    {
        $s = $this->get($id);
        if ($s === null || !$s->indexed() || $docs === []) return false;
        try {
            $this->forgetSets();
            $this->engine()->put($s->id, array_map([$this, 'normalise'], $docs), (int) $this->stateOf($s)['gen']);
            return true;
        } catch (\Throwable $e) {
            error_log("Basehim search: indexing {$id} failed: " . $e->getMessage());
            $this->remember($id, array_map(fn($d) => (int) ($d['id'] ?? 0), $docs));
            return false;
        }
    }

    /** Take items out of the index (after a permanent delete). Never throws. */
    public function remove(string $id, int ...$ids): void
    {
        if ($ids === []) return;
        try {
            $this->forgetSets();
            $this->engine()->remove($id, $ids);
        } catch (\Throwable $e) {
            error_log("Basehim search: removing {$id} #" . implode(',', $ids) . ' failed: ' . $e->getMessage());
            $this->remember($id, $ids);
        }
    }

    /** Remove a whole source from the index (an app uninstalled). */
    public function purge(string $id): int
    {
        try {
            $n = $this->engine()->purge($id);
            $this->mutateStates(function (array $all) use ($id): array { unset($all[$id]); return $all; });
            return $n;
        } catch (\Throwable $e) {
            error_log("Basehim search: purging {$id} failed: " . $e->getMessage());
            return 0;
        }
    }

    private function remember(string $id, array $ids): void
    {
        $s = $this->get($id);
        if ($s === null) return;
        $this->mutateStates(function (array $all) use ($id, $ids, $s): array {
            $st = $all[$id] ?? $this->stateOf($s);
            $st['retry'] = array_slice(array_values(array_unique(array_merge((array) ($st['retry'] ?? []), array_map('intval', $ids)))), -self::MAX_RETRY);
            $all[$id] = $st;
            return $all;
        });
    }

    /**
     * Normalised document: every field present and bounded. Titles are
     * repeated in keywords so a match in the title ranks higher.
     */
    public function normalise(array $d): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($d['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $title = mb_substr($title, 0, 500, 'UTF-8');
        $body = (string) ($d['body'] ?? '');
        if (mb_strlen($body, 'UTF-8') > TextExtractor::MAX_BODY) $body = mb_substr($body, 0, TextExtractor::MAX_BODY, 'UTF-8');
        $kw = $d['keywords'] ?? '';
        $kw = is_array($kw) ? implode(' ', array_map('strval', array_filter($kw, 'is_scalar'))) : (string) $kw;
        $keywords = trim($title . ' ' . TextExtractor::words($title, $kw));
        $keywords = mb_substr($keywords, 0, 10000, 'UTF-8');
        $date = $d['date'] ?? null;
        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', (string) $date)) {
            $ts = strtotime((string) $date);
            $date = $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
        }
        $owner = isset($d['owner_id']) && (int) $d['owner_id'] > 0 ? (int) $d['owner_id'] : null;
        $url = isset($d['url']) && $d['url'] !== '' ? mb_substr((string) $d['url'], 0, 500, 'UTF-8') : null;
        return [
            'id' => (int) ($d['id'] ?? 0),
            'title' => $title,
            'body' => $body,
            'keywords' => $keywords,
            'type' => mb_substr((string) ($d['type'] ?? ''), 0, 60, 'UTF-8'),
            'status' => mb_substr((string) ($d['status'] ?? ''), 0, 30, 'UTF-8'),
            'public' => !empty($d['public']),
            'owner_id' => $owner,
            'date' => $date !== null ? (string) $date : null,
            'url' => $url,
        ];
    }

    // ------------------------------------------------------------------
    // Building
    // ------------------------------------------------------------------

    /**
     * Index what is still missing, for up to $seconds. Run by the scheduled
     * task every minute and by Settings → Search → Build now. Safe to run
     * from two places at once: the second one returns at once.
     *
     * @return array{ran:bool, sources:array<string, array>}
     */
    public function build(float $seconds = 8.0, ?string $only = null): array
    {
        $lock = $this->lock();
        if ($lock === false) return ['ran' => false, 'sources' => $this->status()];
        $deadline = microtime(true) + max(0.5, $seconds);
        try {
            $this->states(true);
            foreach ($this->sources() as $s) {
                if (!$s->indexed() || ($only !== null && $s->id !== $only)) continue;
                $this->retryFailed($s);
                $st = $this->stateOf($s);
                if (!empty($st['ready'])) {
                    $this->catchUp($s, (int) $st['gen'], $deadline);
                    continue;
                }
                if ($st['started'] === null) $st['started'] = gmdate('Y-m-d H:i:s');
                while (microtime(true) < $deadline) {
                    $docs = $s->documents((int) $st['cursor'], $s->batch());
                    $norm = [];
                    $last = (int) $st['cursor'];
                    foreach ($docs as $d) {
                        $id = (int) ($d['id'] ?? 0);
                        if ($id <= $last) continue;   // ids must increase: stop a source that loops
                        $last = $id;
                        $norm[] = $this->normalise($d);
                    }
                    if ($norm) $this->engine()->put($s->id, $norm, (int) $st['gen']);
                    $st['cursor'] = $last;
                    $st['done'] = (int) $st['done'] + count($norm);
                    if (count($docs) < $s->batch() || $norm === []) {
                        // Finished: anything not written by this build is gone from the source.
                        $this->engine()->purge($s->id, (int) $st['gen']);
                        $st['ready'] = true;
                        $st['finished'] = gmdate('Y-m-d H:i:s');
                    }
                    $superseded = false;
                    $this->mutateStates(function (array $all) use ($s, &$st, &$superseded): array {
                        $prev = $all[$s->id] ?? [];
                        if ((int) ($prev['gen'] ?? 0) > (int) $st['gen']) { $superseded = true; return $all; }   // a Rebuild started meanwhile
                        if (isset($prev['retry'])) $st['retry'] = $prev['retry'];
                        $all[$s->id] = $st;
                        return $all;
                    });
                    if ($superseded || !empty($st['ready'])) break;
                }
                if (microtime(true) >= $deadline) break;
            }
        } catch (\Throwable $e) {
            error_log('Basehim search: building the index failed: ' . $e->getMessage());
        } finally {
            $this->unlock($lock);
        }
        return ['ran' => true, 'sources' => $this->status()];
    }

    /** Index rows added behind core's back (newer than the highest indexed id). */
    private function catchUp(SearchSource $s, int $gen, float $deadline): void
    {
        $after = $this->engine()->maxId($s->id);
        if ($after === null) return;
        while (microtime(true) < $deadline) {
            $docs = $s->documents($after, $s->batch());
            $norm = [];
            foreach ($docs as $d) {
                $id = (int) ($d['id'] ?? 0);
                if ($id <= $after) continue;
                $after = $id;
                $norm[] = $this->normalise($d);
            }
            if ($norm) $this->engine()->put($s->id, $norm, $gen);
            if (count($docs) < $s->batch() || $norm === []) return;
        }
    }

    private function retryFailed(SearchSource $s): void
    {
        $ids = (array) ($this->states()[$s->id]['retry'] ?? []);
        if ($ids === []) return;
        $this->mutateStates(function (array $all) use ($s): array { if (isset($all[$s->id])) $all[$s->id]['retry'] = []; return $all; });
        $this->touch($s->id, ...array_map('intval', $ids));
    }

    /** @return resource|false|null null when locking is not possible here */
    private function lock(): mixed
    {
        $dir = $this->lockDir !== '' ? $this->lockDir : sys_get_temp_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fh = @fopen(rtrim($dir, '/') . '/' . self::LOCK_FILE, 'c');
        if ($fh === false) return null;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        return $fh;
    }

    private function unlock(mixed $fh): void
    {
        if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
    }

    /**
     * Per source: label, owner, kind, whether ready, how many documents are
     * indexed and how many there are.
     *
     * @return array<string, array>
     */
    public function status(): array
    {
        $out = [];
        $states = $this->states();
        foreach ($this->sources() as $s) {
            $st = $states[$s->id] ?? [];
            $indexed = null;
            if ($s->indexed()) {
                try { $indexed = $this->engine()->count($s->id); } catch (\Throwable) {}
            }
            $out[$s->id] = [
                'id' => $s->id, 'label' => $s->label, 'owner' => $s->owner,
                'kind' => $s->indexed() ? 'indexed' : 'callback',
                'public' => $s->isPublic(),
                'ready' => $s->indexed() ? $this->ready($s->id) : true,
                'indexed' => $indexed,
                'total' => $s->indexed() ? $s->count() : null,
                'done' => (int) ($st['done'] ?? 0),
                'started' => $st['started'] ?? null,
                'finished' => $st['finished'] ?? null,
                'failed' => count((array) ($st['retry'] ?? [])),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Searching
    // ------------------------------------------------------------------

    /**
     * SQL condition limiting a list query on a source's own table to rows
     * matching $q. Uses the index when the source's index is ready, its own
     * table otherwise. $alias is the table alias of the outer query ('' for
     * none); the source's `id_column` (default "id") is matched.
     *
     * Returns '1=1' for a blank query and '1=0' when nothing can match.
     *
     * @param array $scope stored-field filters for the index: type, status, public, owner_id
     */
    public function constrain(string $id, string|SearchQuery $query, string $alias, array &$params, array $scope = []): string
    {
        $q = SearchQuery::parse($query);
        if ($q->isBlank()) return '1=1';
        if ($q->matchesNothing()) return '1=0';
        $s = $this->get($id);
        if ($s === null) return '1=0';
        if (!preg_match('/^[A-Za-z0-9_]*$/', $alias)) throw new \InvalidArgumentException('Bad table alias.');
        $idCol = (string) $s->get('id_column', 'id');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $idCol)) $idCol = 'id';
        $idExpr = ($alias !== '' ? $alias . '.' : '') . $idCol;

        if ($this->ready($id)) {
            try {
                $set = $this->matchSet($s, $q, $scope);
                if ($set !== null) {
                    if (isset($set['table'])) return "{$idExpr} IN (SELECT bh_t.id FROM `{$set['table']}` bh_t)";
                    return $set['ids'] === [] ? '1=0' : "{$idExpr} IN (" . implode(',', $set['ids']) . ')';
                }
                $own = [];
                $sql = $this->engine()->constrain($id, $q, $idExpr, $own, $scope, $this->newerMatches($s, $q, $scope));
                $params += $own;
                return $sql;
            } catch (\Throwable $e) {
                error_log("Basehim search: {$id} index query failed, using the table: " . $e->getMessage());
            }
        }
        return $s->fallbackCondition($q, $alias, $params, $scope) ?? '1=0';
    }

    /** Up to this many matching ids are written into the SQL; more go into a temporary table. */
    private const INLINE_IDS = 5000;

    /** @var array<string, array{ids?:list<int>, table?:string}> match sets of this request */
    private array $sets = [];
    private ?bool $tempTables = null;

    /**
     * The ids matching $q in a source, worked out once per request (1.2.92).
     * A list page, its total and the media type counts each run the same
     * search; evaluating FULLTEXT once instead of three times matters for
     * common words on large sites. Small sets are inlined; large ones are
     * kept in a temporary table for this connection. Null when the engine
     * can't do this or temporary tables aren't allowed: the caller then uses
     * the plain subquery.
     *
     * @return null|array{ids?:list<int>, table?:string}
     */
    private function matchSet(SearchSource $s, SearchQuery $q, array $scope): ?array
    {
        $key = $s->id . "\0" . $q->raw() . "\0" . json_encode($scope);
        if (isset($this->sets[$key])) return $this->sets[$key];
        if ($this->tempTables === false || $this->db->inTransaction()) return null;
        $params = [];
        $sql = $this->engine()->matchSql($s->id, $q, $params, $scope);
        if ($sql === null) return null;
        $newer = $this->newerMatches($s, $q, $scope);
        $table = 'bh_search_set_' . bin2hex(random_bytes(6));
        try {
            $this->db->execute("CREATE TEMPORARY TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY)");
            $this->tempTables = true;
        } catch (\Throwable $e) {
            $this->tempTables = false;   // not allowed on this host: use subqueries
            return null;
        }
        try {
            $this->db->execute("INSERT IGNORE INTO `{$table}` (id) {$sql}", $params);
            foreach (array_chunk($newer, 500) as $chunk) {
                $this->db->execute("INSERT IGNORE INTO `{$table}` (id) VALUES (" . implode('),(', $chunk) . ')');
            }
            $n = (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
            if ($n <= self::INLINE_IDS) {
                $ids = array_map('intval', array_column($this->db->select("SELECT id FROM `{$table}` ORDER BY id"), 'id'));
                $this->db->execute("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
                return $this->sets[$key] = ['ids' => $ids];
            }
            return $this->sets[$key] = ['table' => $table];
        } catch (\Throwable $e) {
            try { $this->db->execute("DROP TEMPORARY TABLE IF EXISTS `{$table}`"); } catch (\Throwable) {}
            throw $e;
        }
    }

    /** Forget this request's match sets (after the index changed). */
    private function forgetSets(): void
    {
        foreach ($this->sets as $set) {
            if (isset($set['table'])) { try { $this->db->execute("DROP TEMPORARY TABLE IF EXISTS `{$set['table']}`"); } catch (\Throwable) {} }
        }
        $this->sets = [];
    }

    /**
     * Rows of the source's own table newer than anything indexed (written
     * behind core's back: an import, raw SQL) that match, so lists never miss
     * them while the background task catches up. Uses the primary key range,
     * so it costs nothing when there are none. At most 1,000.
     *
     * @return list<int>
     */
    private function newerMatches(SearchSource $s, SearchQuery $q, array $scope): array
    {
        $table = (string) $s->get('table', '');
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        try {
            $max = $this->engine()->maxId($s->id);
            if ($max === null) return [];
            $prefix = $this->db->prefix();
            $from = ($prefix !== '' && str_starts_with($table, $prefix)) ? "`{$table}`" : '{' . $table . '}';
            $idCol = (string) $s->get('id_column', 'id');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $idCol)) return [];
            $params = ['bh_max' => $max];
            $cond = $s->fallbackCondition($q, 't', $params, $scope);
            if ($cond === null) return [];
            return array_map('intval', array_column($this->db->select(
                "SELECT t.{$idCol} AS id FROM {$from} t WHERE t.{$idCol} > :bh_max AND {$cond} ORDER BY t.{$idCol} LIMIT 1000",
                $params
            ), 'id'));
        } catch (\Throwable $e) {
            error_log("Basehim search: newer rows of {$s->id}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search several sources at once, best match first (site search, an app's
     * own search page, a global admin search).
     *
     * Options:
     *   sources   list of source ids (default: every public source when public, else every source)
     *   public    true: only documents anyone may see (site search)
     *   type, status, owner_id   stored-field filters
     *   source_types              ['content' => ['post', 'page']]: types per source
     *   page, per_page            (1, 10; per_page at most 100)
     *   sort      'relevance' (default) or 'newest'
     *   stem      match word variants ("pricing" → "price"); default true when public
     *   hydrate   attach each source's full row as `item` (default true)
     *
     * @return array{items: list<array>, total:int, page:int, per_page:int, last_page:int, query:string}
     */
    public function query(string|SearchQuery $query, array $opts = []): array
    {
        $q = SearchQuery::parse($query);
        $page = max(1, (int) ($opts['page'] ?? 1));
        $per = max(1, min(100, (int) ($opts['per_page'] ?? 10)));
        $public = array_key_exists('public', $opts) ? (bool) $opts['public'] : false;
        $result = ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $per, 'last_page' => 1, 'query' => $q->raw()];
        if ($q->isBlank() || $q->matchesNothing()) return $result;

        $all = $this->sources();
        $ids = isset($opts['sources']) ? array_values(array_intersect(array_map('strval', (array) $opts['sources']), array_keys($all)))
            : array_keys(array_filter($all, fn(SearchSource $s) => !$public || $s->isPublic()));
        if ($public) $ids = array_values(array_filter($ids, fn($i) => $all[$i]->isPublic()));
        if ($ids === []) return $result;

        $scope = array_intersect_key($opts, ['type' => 1, 'status' => 1, 'owner_id' => 1, 'source_types' => 1]);
        if ($public) $scope['public'] = true;
        $sort = ($opts['sort'] ?? 'relevance') === 'newest' ? 'newest' : 'relevance';
        $stem = (bool) ($opts['stem'] ?? $public);
        $offset = ($page - 1) * $per;

        $indexed = [];
        $unready = [];
        $callback = [];
        foreach ($ids as $i) {
            $s = $all[$i];
            if (!$s->indexed()) $callback[] = $s;
            elseif ($this->ready($i)) $indexed[] = $i;
            else $unready[] = $s;
        }

        // In-memory sources and sources whose index is still being built are
        // searched directly; they are expected to be small, and are merged
        // into the first pages only.
        $extra = [];
        if ($offset < 500) {
            foreach ($callback as $s) {
                try {
                    foreach ($s->searchCallback($q, $opts + ['limit' => $offset + $per]) as $r) {
                        $extra[] = $this->resultFromCallback($s, $r);
                    }
                } catch (\Throwable $e) {
                    error_log("Basehim search: source {$s->id} failed: " . $e->getMessage());
                }
            }
            foreach ($unready as $s) {
                foreach ($this->directSearch($s, $q, $scope, $offset + $per) as $r) $extra[] = $r;
            }
            // Rows newer than the index (written behind core's back), until the background task catches up.
            foreach ($indexed as $i) {
                $newer = $this->newerMatches($all[$i], $q, $scope);
                if ($newer) foreach ($this->resultsFor($all[$i], $newer, $q, $scope) as $r) $extra[] = $r;
            }
        }

        $weights = [];
        foreach ($indexed as $i) $weights[$i] = $all[$i]->weight();
        $found = ['items' => [], 'total' => 0];
        if ($indexed !== []) {
            try {
                $found = $this->engine()->search($q, $indexed, $scope + [
                    'sort' => $sort, 'stem' => $stem, 'weights' => $weights,
                    'limit' => $extra ? $offset + $per : $per, 'offset' => $extra ? 0 : $offset,
                ]);
            } catch (\Throwable $e) {
                error_log('Basehim search: query failed: ' . $e->getMessage());
            }
        }

        $items = $found['items'];
        $total = (int) $found['total'] + count($extra);
        if ($extra) {
            $items = array_merge($items, $extra);
            usort($items, $sort === 'newest'
                ? fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''))
                : fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
            $items = array_slice($items, $offset, $per);
        }

        if ($opts['hydrate'] ?? true) $items = $this->hydrate($items, $public);
        foreach ($items as &$it) {
            $it['snippet'] = $q->snippet((string) ($it['body'] ?? ''));
            $it['title_html'] = $q->highlight((string) ($it['title'] ?? ''));
            unset($it['body']);
        }
        unset($it);

        $result['items'] = array_values($items);
        $result['total'] = $total;
        $result['last_page'] = max(1, (int) ceil($total / $per));
        return $result;
    }

    /** Full rows from each source's `hydrate`; items whose row is gone (or no longer public) are dropped. */
    private function hydrate(array $items, bool $public): array
    {
        $by = [];
        foreach ($items as $it) if (!isset($it['item'])) $by[$it['source']][] = (int) $it['id'];
        $rows = [];
        foreach ($by as $src => $ids) {
            $s = $this->get($src);
            if ($s === null || $s->get('hydrate') === null) continue;
            try { $rows[$src] = $s->hydrate($ids, ['public' => $public]); } catch (\Throwable $e) {
                error_log("Basehim search: hydrating {$src} failed: " . $e->getMessage());
            }
        }
        $out = [];
        foreach ($items as $it) {
            if (!isset($it['item']) && isset($rows[$it['source']])) {
                $row = $rows[$it['source']][(int) $it['id']] ?? null;
                if ($row === null) continue;   // deleted, or not visible any more
                $it['item'] = $row;
            }
            $out[] = $it;
        }
        return $out;
    }

    private function resultFromCallback(SearchSource $s, array $r): array
    {
        return [
            'source' => $s->id, 'id' => is_int($r['id'] ?? null) ? $r['id'] : (string) ($r['id'] ?? ''),
            'score' => (float) ($r['score'] ?? 1.0) * $s->weight(),
            'title' => (string) ($r['title'] ?? ''), 'body' => (string) ($r['body'] ?? ''),
            'type' => (string) ($r['type'] ?? ''), 'status' => (string) ($r['status'] ?? ''),
            'url' => isset($r['url']) ? (string) $r['url'] : null, 'date' => $r['date'] ?? null,
        ] + (isset($r['item']) ? ['item' => $r['item']] : []);
    }

    /** A source not yet indexed, searched in its own documents (small sites, first minutes after an update). */
    private function directSearch(SearchSource $s, SearchQuery $q, array $scope, int $limit): array
    {
        $table = (string) $s->get('table', '');
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        // Core names ("posts") get the site's prefix; an app passes $this->table(), which has it already.
        $prefix = $this->db->prefix();
        $from = ($prefix !== '' && str_starts_with($table, $prefix)) ? "`{$table}`" : '{' . $table . '}';
        $params = [];
        $cond = $s->fallbackCondition($q, 't', $params, $scope);
        if ($cond === null) return [];
        $idCol = (string) $s->get('id_column', 'id');
        try {
            $ids = array_map('intval', array_column($this->db->select(
                "SELECT t.{$idCol} AS id FROM {$from} t WHERE {$cond} ORDER BY t.{$idCol} DESC LIMIT " . max(1, min(500, $limit)),
                $params
            ), 'id'));
        } catch (\Throwable $e) {
            error_log("Basehim search: direct search of {$s->id} failed: " . $e->getMessage());
            return [];
        }
        return $this->resultsFor($s, $ids, $q, $scope);
    }

    /** Results built from a source's own documents, filtered by the scope. */
    private function resultsFor(SearchSource $s, array $ids, SearchQuery $q, array $scope): array
    {
        $out = [];
        foreach (array_slice($ids, 0, 500) as $i) {
            $d = $s->document((int) $i);
            if ($d === null) continue;
            $d = $this->normalise($d);
            if (!empty($scope['public']) && !$d['public']) continue;
            if (isset($scope['type']) && $scope['type'] !== '' && !in_array($d['type'], (array) $scope['type'], true)) continue;
            if (isset($scope['source_types'][$s->id]) && !in_array($d['type'], (array) $scope['source_types'][$s->id], true)) continue;
            $score = 1.0;
            foreach ($q->words() as $w) if (mb_stripos($d['title'], $w) !== false) $score += 2;
            $out[] = ['source' => $s->id, 'id' => $d['id'], 'score' => $score * $s->weight(), 'title' => $d['title'],
                'body' => $d['body'], 'type' => $d['type'], 'status' => $d['status'], 'url' => $d['url'], 'date' => $d['date']];
        }
        return $out;
    }
}
