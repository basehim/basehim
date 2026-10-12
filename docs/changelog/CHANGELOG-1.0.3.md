# Basehim 1.0.3 — post.content on every route that renders a body

## The gap

`post.content` is the filter apps hook to transform a rendered post or page
body. Three controllers applied it. Two did not — and one of those was
`ResolveController`, which serves `/{slug}` and `/{cat}/{slug}`: the pretty and
category permalinks most posts actually use.

So an app filtering `post.content` worked on `/posts/my-post` and silently did
nothing on the canonical URL of the same post. Nothing failed, nothing logged;
the transformation simply did not happen on the URL visitors arrive at.

## Why it matters more than it looks

The Ad Inserter app worked around this with an output buffer: `ob_start()` on
every public request, capture the whole assembled page, re-query the post by
slug, find the raw body inside the rendered HTML with `strpos()`, and substitute
the filtered version.

That is what a missing filter call invites. It only worked when the content
format was `html`, bailed on any theme that did not echo the body verbatim, and
put a callback in front of every front-end response. A missing hook does not
stay a small problem — it becomes fragile machinery in every app that needs it.

## The fix

`post.content` is now applied in every controller that renders a **full body**:

    PostController          already did
    PageController          already did
    CategoryPostController  already did
    ResolveController       added — both /{slug} and /{cat}/{slug}
    HomeController          added — the static homepage only

`TaxonomyController`, `AuthorController` and `SearchController` are deliberately
untouched. They render lists of excerpts, not bodies, and filtering there would
inject an app's output once per row in an archive.

The filter runs before the row reaches the theme, and mutates the same array
that is passed on — verified by reading the order rather than assuming it.

## For app authors

A single `addFilter('post.content', ...)` now covers every route a post can be
served from, and every content format: core renders block and markdown bodies to
HTML at filter priority 5, so an app at the default priority always receives
real markup.

Ad Inserter 2.0.0 drops 140 lines of workaround because of this.
