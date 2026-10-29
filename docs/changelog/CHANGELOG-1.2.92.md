# Basehim 1.2.92

2026-10-29 · Patch release · installs on 1.2.91

BH Search: one search framework for core and apps, fast at millions of
items. It powers site search, every admin list and the media library. Also
fixes the CLI migration runner.

## Why

Search had grown one implementation per place, each with its own rules:

- **Admin lists of posts and pages** used FULLTEXT on the posts table. That
  matched only word beginnings, dropped words under three letters and
  stopwords, and couldn't be shared.
- **Media, comments and users** used one `LIKE '%whole phrase%'`. So
  `digitalocean pricing` couldn't find "DigitalOcean Droplet Pricing", and
  `snipaste 2026` couldn't find `Snipaste_2026-10-27.png` (BH-071, reported on
  the live site). It also read every row on every search.
- **The Apps list** matched in PHP with `str_contains`.
- **Site search** covered posts only (never pages), newest first, with no
  relevance or snippet.
- **Apps** had nothing to build on.

## BH Search

`App\Core\Search` (see [docs/SEARCH.md](../SEARCH.md)):

- **`SearchQuery`**: one query syntax everywhere.
  - Every word must match, in any order.
  - `"phrases"` and `-exclusions`.
  - Punctuation (`- _ . /`) splits words.
  - Case and accents are ignored; any script works.
  - Matching in PHP for in-memory lists, escaped snippets and highlights, and
    light stemming for site search.
- **`SearchSource`**: what can be searched.
  - Indexed sources page through their rows with `documents($after, $limit)`.
  - Callback sources are searched in PHP.
- **`search_index`** (migration 022): one table with one FULLTEXT index over
  title, body and keywords. No existing table is altered.
- **Engines**:
  - `FulltextEngine` (MySQL / MariaDB, the default): every word is a required
    prefix term. Words below the server's minimum token size, and stopwords,
    are checked with LIKE within the rows FULLTEXT found, so they never
    silently match nothing. Both were verified on MariaDB 11.4: `+the*` and
    `+ai*` alone return no rows.
  - `LikeEngine`: any database; matches inside words.
  - An app can supply another engine with the `search.engine` filter.
- **`SearchManager`**: the registry, `constrain()` for list queries,
  `query()` for ranked mixed results, `touch()` / `remove()` / `put()`, and
  the background build.
  - The build is the scheduled task `core:search-index`: 8 seconds a minute,
    resumable, with an flock against overlap.
  - Until a source's build is complete, its searches use its own table, as
    before 1.2.92, so results are never partial.
  - Rows written behind core's back (raw SQL, imports) are found at once
    through a primary-key range check, and indexed by the next background
    run.
  - Failed index writes never fail the save. The ids are retried by the next
    background run.
- **`CoreSources`**:
  - `content`: posts, pages and app content types. Public when published,
    text taken from HTML, Markdown or block JSON without running block code,
    with slug and term names as keywords.
  - `media`, `comments` and `users`.
- **`SiteSearch`**: `/search`, `/api/v1/search` and
  `api()->posts()->search()`. Results are best match first across the content
  types and app sources chosen in Settings → Search (posts and pages by
  default).
- **`api()->search()`** for apps: `source()`, `touch()`, `put()`, `remove()`,
  `rebuild()`, `purge()`, `ready()`, `constrain()`, `query()` and `parse()`.
  Ids are namespaced `{slug}.{name}`. Core sources need their read permission
  unless only public results are asked for.

Wired in:

- **Repositories and services keep the index current:**
  - `PostRepository`: create, update, publish of a scheduled post, trash,
    restore, delete, empty trash, terms.
  - `MediaRepository`, `CommentService`.
  - `UserRepository`, `AuthorService`.
- **Lists search through `constrain()`:** `PostRepository::paginate()` and
  `search()`, `MediaRepository`, `CommentService::paginate()` and
  `UserRepository::paginate()`.
- **The Apps list** uses `SearchQuery::matches()`.
- **`AppService` rollback** drops a failed app's sources.
- **`PostTypeRegistry`** accepts `searchable => false` for content types to
  keep out of site search.

## Settings → Search

- **Each source:** how much is indexed and its status, with **Build now** (runs
  4-second steps until done, progress in place) and **Rebuild** (asks first).
- **What site search covers:** content types and app sources, and the order
  (best match or newest).
- **The query syntax**, explained.
- AJAX actions `search.status`, `search.build` and `search.rebuild`
  (`manage_options`).

## Admin tables

`datatable.js` keeps the search, page, sort and filters in the address bar
(`history.replaceState`). The server already rendered the first page from
those parameters, so a reload, Back from an edit screen, or a shared link
shows the same rows. Only for a page's single table with its own search box;
the media library is left alone.

## Themes

`templates/search.php` also receives `$results` (every result, apps'
included), and each row of `$posts` has `search_snippet` and `search_title`
(escaped HTML with `<mark>`). Themes that ignore them work as before, now
best match first and with pages included.

## Other fix

**BH-072, `database/migrate.php` (CLI) could not record a migration.** It
inserted no `applied_at`, which the `migrations` table install.php creates has
no default for. On strict servers every CLI migration failed after running.
The admin updater (`MigrationService`) was not affected.

## Compatibility

- **New table and task:** `search_index` (migration 022) and the scheduled
  task `core:search-index`. The index holds a plain-text copy of the
  searchable text: roughly the size of your posts' text, plus media,
  comments and users.
- **Search semantics:** searches now require every word in any order,
  everywhere. Punctuation in a query separates words and is never a LIKE
  wildcard, so `100%_off` searches for `100` and `off`.
- **Site search and `/api/v1/search`** include published pages by default,
  ordered by relevance. Settings → Search switches pages off or restores
  newest first.
- **Until the index is built** (the first minutes after updating, longer on
  very large sites), everything searches as before.

## Tests

- **`tests/Unit/SearchFrameworkTest.php`** (8 tests):
  - the query syntax and PHP matching;
  - escaped snippets, highlights and stemming;
  - text extraction from HTML, Markdown and blocks;
  - LIKE placeholders and escaping;
  - source validation;
  - the FULLTEXT expressions (short words and stopwords go to LIKE; score
    placeholders kept separate).
- **`tests/Integration/SearchFrameworkTest.php`** (7 tests, over HTTP):
  - Build now and its permissions;
  - the posts list: word order, exclusion, phrase, short word, stopword,
    punctuation;
  - the save paths: create, update, trash, and site search after trash;
  - site search: relevance, pages, drafts, snippets, settings;
  - media, comments and users;
  - an app's own source: fallback before indexing, `constrain()`, `query()`,
    `touch()`, public items in site search;
  - rows written behind core's back.
- **Updated:**
  - `tests/Unit/Release1291Test.php`: word splitting via `SearchQuery`;
  - `tests/Integration/ReliabilityP2Test.php` (CR-16): the LIKE path now
    searches each word; wildcards still never come from the query.

Full suite:

- **MariaDB 11.4.5:** 621 passed, 2 failed, 0 skipped.
- **Dolt:** 616 passed, 2 failed, 5 skipped.

The two failures in each are the stylesheet checks of BH-047.

Browser, on the local site:

- Settings → Search on desktop and phone;
- Build now and Rebuild;
- the posts list keeping its search across a reload;
- media library search;
- site search on the Cloudhim theme.

9/9 checks passed, with no console errors.

## Performance

MariaDB 11.4.5 (built from source, as on the reference site), 1,000,000
posts of 60–140 words with their index rows, 1 GB InnoDB buffer pool, 2 CPU
cores. Median of five runs, each a new request. Tool:
`tools-dev/search-bench.php`, `tools-dev/search-bench-manager.php`.

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
