# Basehim 1.2.7 — root pages, default category, one slug namespace

## A. Pages live at the site root

`Helpers::postUrl()` already gave pages `/{slug}`, but six places still wrote
`/page/{slug}`: the View links on four admin screens, the menu builder (which
**saves** the URL into the menu), and the route itself.

**`/page/{slug}` returned 500 on every page.** `PageController` built its
canonical URL from `Helpers::postUrl($row)`, and `$row` does not exist in that
method — a `TypeError` under `strict_types` for any page without a manual
canonical URL. Menus built with the menu builder link to `/page/{slug}`, so on
an affected site the main navigation's page links were broken.

Now:

- `/page/{slug}` answers **301** to `/{slug}` (302 for a preview), keeping the
  query string, so old links, bookmarks and search results still work.
- The admin View/Preview links and the menu builder use each item's canonical
  URL — posts too, which the builder offered as `/posts/{slug}`.
- Migration `010` rewrites menu items stored as `/page/{slug}` to `/{slug}`.
- `/{slug}` checks pages before posts.

**One exception:** a page whose slug a route owns (`search`, `feed`, an app's
prefix) cannot be served at `/{slug}` — the route answers first — so it keeps
`/page/{slug}` and renders there. New content never gets such a slug (C). A
legacy post in the same position under flat permalinks keeps `/posts/{slug}`.

## B. Every post has a category

Under category permalinks a post without a category fell back to
`/posts/{slug}`.

- A post saved with no category — new, or with every category unticked — is
  filed under the **default category**.
- **Settings → Writing → Default Post Category** chooses it. Unset, it is
  "Uncategorized", which the installer creates; if it has been deleted it is
  created again.
- Pages never get a category.
- Migration `010` files every existing uncategorised post under Uncategorized
  (trashed ones too, so a restored post is filed). Their old `/posts/{slug}`
  addresses 301 to `/uncategorized/{slug}`.

## C. One slug namespace for posts and pages

The unique index is `(type, slug)`, so a post and a page could share a slug,
and `/{slug}` then showed either one.

- Slugs are now unique across posts **and** pages: `about-us`, then
  `about-us-2`, `about-us-3`, whichever type comes next. Other content types
  keep their own namespace.
- **Slugs a route has claimed are skipped** — "Search" becomes `search-2`. The
  list is read from the live router (`Router::reservedSegments()`), so app
  routes count as well (`bh-analytics`, `oauth`, …), and a newly installed
  app's routes are protected automatically. `Helpers::reservedSlugs()` exposes
  it.
- Choosing a slug and saving the row happen under a MySQL named lock, so two
  saves at the same moment cannot both take the same free slug. No database
  index covers a cross-type clash, so this is what enforces it.
- Slugs are capped at 190 characters before a suffix, inside the 200-character
  column.

## D. Trash reserves a slug; permanent deletion frees it

This already held within one type; it now holds across posts and pages. A
slug stays taken while its content is in the Trash, so restoring it can never
collide; once permanently deleted, the slug is available again.

**Permanent deletion left category and tag counts too high.** The deleted
post's `post_term` rows went with it through the foreign key, but
`attachTerms()` maintains `terms.count` by hand and nothing lowered it. Every
emptied Trash inflated the counts for good (one live site's "Blog" showed 3
posts for 2). `forceDelete()` and `emptyTrash()` now subtract first, and
migration `010` recounts every term.

## Migration

`database/migrations/010_root_pages_default_category.sql`. Every statement is
idempotent — it may run twice, because the updater and the System page record
migrations under different names (`010_…` and `010_….sql`). Dry-run on a live
database inside a transaction, twice over: the second run changed nothing.

## Verified on a live install

- `/page/blog` and `/page/about`: 500 before, 301 to `/blog` and `/about` now.
- `/posts/api` 301 to `/uncategorized/api`; sitemap has no `/page/` or
  `/posts/` URLs.
- Slugs: page/post/page with one title → `-`, `-2`, `-3`; a post given a
  page's slug gets a suffix; re-saving a post with its own slug keeps it;
  "Search", "Feed" and an app route get `-2`.
- Trash: slug held while trashed, kept on restore, freed on permanent delete.
- Category: new post → Uncategorized; choosing Blog replaces it; unticking all
  brings it back; pages get none.
- Counts: up on create, down on permanent delete and on Empty Trash; no drift.
- Admin: View links on both lists and both editors, the Writing setting, the
  menu builder; a legacy page slugged `search` renders at `/page/search` while
  `/search` stays the search page.

## Files

    app/Core/Router.php                               reservedSegments()
    app/Core/Helpers.php                              postUrl() pages/reserved; reservedSlugs()
    app/Repositories/PostRepository.php               slugTaken(), slug lock, category helpers, term counts
    app/Services/PostService.php                      global slugs, lock, default category
    app/Http/Controllers/Web/PageController.php       /page/{slug} → /{slug}; $row fixed
    app/Http/Controllers/Web/ResolveController.php    pages first at /{slug}
    app/Http/Controllers/Admin/MenuController.php     canonical URLs in the builder
    admin/views/posts/edit.php                        View/Preview link
    admin/views/posts/index.php                       View links
    admin/views/pages/edit.php                        View link
    admin/views/pages/index.php                       View links
    admin/views/settings/writing.php                  Default Post Category
    database/migrations/010_root_pages_default_category.sql
