# Basehim 1.2.5 — Preview works under every permalink structure

## The report

Preview returned 404 for a draft post that had a category assigned.

## The cause

The editor's Preview button links to `/posts/{slug}`. `PostController`
correctly shows a draft there to a signed-in editor, and then applies the
permalink rules: under **Category** or **Flat** permalinks, if the canonical
URL differs from `/posts/{slug}`, it issues a **301** to the canonical URL.

That canonical URL is served by `ResolveController`, which only ever served
published posts. So the draft was redirected to a URL that answered 404.

Reproduced against the unmodified code, as a signed-in editor:

| Permalinks | Category | Preview (`/posts/{slug}`) | Canonical URL |
| ---------- | -------- | ------------------------- | ------------- |
| Pretty     | either   | renders                   | —             |
| Category   | none     | renders                   | —             |
| Category   | assigned | 301 → `/{cat}/{slug}`     | **404**       |
| Flat       | either   | 301 → `/{slug}`           | **404**       |

The report covered the Category row. **Flat was broken for every draft,
categorised or not.** Without a category under Category permalinks, the
canonical URL *is* `/posts/{slug}`, so no redirect happened — which is why the
bug appeared to depend on the category.

## The fix

**`PostController` no longer redirects a preview.** A draft has no public
address to consolidate on, so it renders where the Preview button sent it.
Published posts redirect exactly as before.

**`ResolveController` serves previews too**, at `/{slug}` and `/{cat}/{slug}`,
through the same `canPreview()` check `PostController` uses. It adds the preview
badge and `noindex,nofollow`, the same as `PostController`. This matters beyond
tidiness: the old response was a **301**, which browsers cache permanently. An
editor who hit the bug has that redirect stored, and their browser will keep
going to the canonical URL. That URL now works.

**A redirect for a preview is a 302, never a 301.** A preview's address is not
permanent and must not be cached as if it were.

## Also fixed in the same code

**Unpublished slugs were detectable by anyone.** `ResolveController` applied
its permalink redirects before checking the post's status, so an anonymous
request for a draft's slug got a redirect where a missing slug gets a 404 —
enough to confirm that the draft existed. Visibility is now decided first: to
anyone who may not preview it, an unpublished post is a 404 on every URL.

**Previews counted as page views.** An editor checking a draft is not a reader;
previews no longer increment `view_count`.

## Verified

Every route a post can be reached by (`/posts/{slug}`, `/{cat}/{slug}`,
`/{slug}`) under all three permalink structures, for a categorised and an
uncategorised draft, as a signed-in editor and anonymously, plus a published
post as a control. The published post's responses are identical to before in
every structure.

End to end on a live install with Category permalinks: the edit screen's
Preview link for a categorised draft now opens the preview, where it previously
redirected to a 404.

## Files

    app/Http/Controllers/Web/PostController.php
    app/Http/Controllers/Web/ResolveController.php

No migration, no settings, no theme changes.

## Not changed

`app/Http/Controllers/Web/CategoryPostController.php` is not routed —
`/{cat}/{slug}` goes to `ResolveController::showCategoryPost()` — so it was left
alone. It is dead code, and would fatal if ever routed: it calls a
non-existent `Helpers::primaryCategorySlug()` and reads an undefined `$row`.
It should be deleted in a later release.
