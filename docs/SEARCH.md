# BH Search

Basehim 1.2.92 has one search framework for core and apps. The site's
search page, every list in the admin, the media library, the search API and
apps all search the same way, through the same index:

- **Every word, any order.** `digitalocean pricing` finds "DigitalOcean
  Droplet Pricing".
- **Phrases and exclusions.** `"per-second billing"`, `pricing -hetzner`.
- **Word breaks in names.** Hyphens, underscores and dots split words, so
  `snipaste 2026` finds `Snipaste_2026-10-27.png`. `DigitalOcean` also
  indexes as `digital ocean`.
- **Case and accents** don't matter (`cafe` finds `Café`). Any script works:
  Urdu, Arabic, Cyrillic.
- **Fast at any size.** Searching costs what matches, not how much there is.
  On MariaDB 11.4 with a million posts, a specific search takes milliseconds
  and a common word a fraction of a second (see Performance below).

## How it works

```
 posts ─┐                                   ┌─ admin lists   (constrain)
 media ─┤  touch()/remove()  ┌────────────┐ ├─ media library (constrain)
 comments ├─────────────────▶│search_index│─┼─ site search   (query)
 users ─┤  background build  │  FULLTEXT  │ ├─ /api/v1/search
 apps  ─┘                    └────────────┘ └─ apps          (api()->search())
```

- **Sources** say what can be searched. Core registers `content` (posts, pages
  and app content types), `media`, `comments` and `users`. Apps register
  their own.
- **The index** is one table, `search_index`, with one FULLTEXT index over
  title, body and keywords. Nothing else in the database changes.
- **Building.** After an update the index is empty. The scheduled task
  `core:search-index` fills it a few seconds at a time, every minute.
  Settings → Search → **Build now** does it while you watch. Until a source is
  complete, its searches use its own table, exactly as before 1.2.92, so
  results are never partial.
- **Keeping current.** Core's repositories call `touch()` on save and
  `remove()` on delete. A failure never stops the save: the id is kept and
  retried by the next background run.
- **Engines.** FULLTEXT (MySQL / MariaDB, the default) or text match (LIKE,
  any database; Settings → Reading → Search method). An app can supply its own
  engine, an external search service for example, with the `search.engine`
  filter.

## Query syntax

| Typed | Means |
|---|---|
| `red shirt` | both words, anywhere, any order |
| `"red shirt"` | the words together, in this order |
| `shirt -red` | "shirt" but not "red" |
| `%` / `--` | nothing: punctuation alone matches nothing |

Words match the start of longer words (`price` finds `prices`). Site search
also matches word forms: `pricing` finds `price` and `priced` (English
suffixes on words of six letters or more).

FULLTEXT has two blind spots, both handled for you. Words shorter than the
server's minimum (3 letters: `ai`, `go`) and stopwords (`the`, `about`,
`com`) are checked with LIKE inside the rows the other words found. A query
made only of such words reads every row of the source. It is the one slow
case.

## For apps: `$this->api()->search()`

### Make your records searchable

```php
public function boot(): void
{
    $t = $this->table('products');
    $this->api()->search()->source('products', [
        'label'     => 'Products',
        'public'    => true,                    // its public items appear in site search
        'documents' => fn(int $after, int $limit) => array_map([$this, 'doc'],
            $this->db()->select("SELECT * FROM {$t} WHERE id > :a ORDER BY id LIMIT {$limit}", ['a' => $after])),
        'document'  => fn(int $id) => ($r = $this->db()->selectOne("SELECT * FROM {$t} WHERE id = :i", ['i' => $id])) ? $this->doc($r) : null,
        'count'     => fn() => (int) $this->db()->selectOne("SELECT COUNT(*) c FROM {$t}")['c'],
        // Searched directly until the index is built:
        'table'     => $t,
        'columns'   => ['name', 'sku', 'description'],
        // Full rows for results:
        'hydrate'   => fn(array $ids, array $opts) => $this->productsById($ids),
    ]);
}

public function doc(array $r): array
{
    return [
        'id'       => (int) $r['id'],           // required, a positive integer
        'title'    => $r['name'],
        'body'     => strip_tags($r['description']),
        'keywords' => [$r['sku'], $r['brand']], // extra words to match
        'type'     => 'product',                 // your own sub-type, for scope filters
        'status'   => $r['status'],
        'public'   => $r['status'] === 'live',   // visible to anyone now
        'date'     => $r['created_at'],
        'url'      => '/shop/' . $r['slug'],
    ];
}
```

Source ids are namespaced: `products` registered by app `shop` is
`shop.products`.

`documents($after, $limit)` must return documents with **increasing ids**
greater than `$after`. That is how the background build pages through a
source of any size.

### Keep it current

```php
$this->api()->search()->touch('products', $id);   // after a save: re-reads document($id)
$this->api()->search()->remove('products', $id);  // after a delete
$this->api()->search()->put('products', $doc);    // index a document you built
$this->api()->search()->rebuild('products');      // start over (also: bump 'version' in the definition)
$this->api()->search()->purge('products');        // onUninstall()
```

### Search your own table

`constrain()` gives a condition for your own SQL. It uses the index once it
is built, and your `columns` until then:

```php
$params = [];
$where = $this->api()->search()->constrain('products', $q, 'p', $params);
$rows = $this->db()->select("SELECT p.* FROM {$t} p WHERE p.status = 'live' AND {$where}
                             ORDER BY p.created_at DESC LIMIT 20", $params);
```

### Ranked results

```php
$r = $this->api()->search()->query('blue shirt', [
    'sources'  => ['products'],      // default: this app's sources
    'page'     => 1, 'per_page' => 10,
    'sort'     => 'relevance',       // or 'newest'
    'public'   => true,              // only items anyone may see
]);
foreach ($r['items'] as $it) {
    // source, id, score, title, title_html, snippet (HTML, matches in <mark>),
    // url, date, type, status, item (your hydrated row)
}
```

Core's sources can be searched too: `'sources' => ['content', 'media']`.
This needs the matching read permission (`posts.read`, `media.read`,
`comments.read`, `users.read`), except with `'public' => true`.

### In-memory lists

For a short list that lives in PHP (tools, settings pages), use a callback
source. It has no index:

```php
$this->api()->search()->source('tools', [
    'label'  => 'Tools',
    'public' => true,
    // Return results: id (int or string), title, body, url; optional score, date, item.
    'search' => fn(\App\Core\Search\SearchQuery $q, array $opts) => array_values(array_map(
        fn($t) => ['id' => $t['id'], 'title' => $t['name'], 'body' => $t['description'], 'url' => $t['url']],
        array_filter($this->toolList(), fn($t) => $q->matches($t['name'] . ' ' . $t['description'])))),
]);
```

`$this->api()->search()->parse($q)->matches($text)` uses the same rules
anywhere.

## For themes

`templates/search.php` receives:

- `$posts`: post rows, as always. Each now has `search_snippet` (HTML, the
  matching passage with `<mark>`) and `search_title` (the title with
  `<mark>`). Both are escaped: print them as they are.
- `$results`: every result, apps' included: `source`, `title`, `title_html`,
  `url`, `snippet`, `date` and `item` (the source's row).
- `$meta`: `total`, `page`, `last_page`, `per_page`.

Results come best match first, unless Settings → Search says newest first.
Themes that only show `$posts` keep working and simply don't show apps'
results.

## Settings → Search

- **Search index**: each source, how much is indexed, Build now and Rebuild.
- **Site search**: which content types and app sources `/search` covers (posts
  and pages by default), and the order.
- The engine is chosen under Settings → Reading → Search method.

## Performance

MariaDB 11.4.5 (built from source, as on the reference site), 1,000,000
posts of 60–140 words with their index rows, 1 GB InnoDB buffer pool, 2 CPU
cores. Median of five runs, each a new request.

| Query | Matches | Admin list: 20 rows + total | Site search: 10 rows + total |
|---|---:|---:|---:|
| `kubernetes` (rare word) | 181 | 9 ms | 3 ms |
| `droplet pricing` (two words, any order) | 79 | 16 ms | 11 ms |
| `"pricing droplet"` (phrase) | 41 | 21 ms | 16 ms |
| `the backup` (stopword + word) | 5,993 | 218 ms | 203 ms |
| `pricing -server` (exclusion) | 18,874 | 336 ms | 220 ms |
| `pricing` (word in 2% of posts) | 19,862 | 301 ms | 260 ms |
| `ai pricing` (2-letter word + word) | 189 | 426 ms | 402 ms |
| `server` (word in 5% of posts) | 50,091 | 7.1 s | 3.0 s |

Before 1.2.92, a LIKE search of the same posts took 35–38 seconds for any
query. Indexing runs at about 1,500 documents a second in bulk, and
re-indexing one saved item takes about 1 ms.

What sets the cost is how many items match, not how many there are.
InnoDB's FULLTEXT spends roughly 30–50 µs on each matching document, so a
single word found in tens of thousands of items takes seconds. Two things
keep that cost down:

- **One evaluation per request.** A list page evaluates the match once and
  reuses the ids for the rows, the total and the media type counts: a temporary
  table for large sets, an inline list for small ones.
- **One pass for site search.** It reads the rows and the total together
  (`COUNT(*) OVER ()`).

For sites where very common words must also be instant, the `search.engine`
filter takes a dedicated search server.

## Limits

- Words match from their start (`ocean` does not find `DigitalOcean`, but
  the split `digital ocean` keyword does). The text-match engine matches
  anywhere, but reads every row.
- Languages written without spaces (Chinese, Japanese, Thai) are tokenized by
  the database as whole runs. Use text match for those sites.
- Ids must be positive integers.
- The index holds copies of user emails (for the Users list), in the same
  database as the users table.
