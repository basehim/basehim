# Basehim 1.2.88

2026-10-25 · Patch release · installs on 1.2.87

Three fixes: images in the RSS feed, publication times you choose for new
posts, and the Comments screen's actions.

## RSS feed: rendered content, absolute URLs and an image per item

**Problem.** `/feed` printed each post's stored content as it was. Posts written
in the block editor went out as block JSON and posts written through the API
as Markdown, so readers showed raw code. Image and link paths were relative to
the site (`/uploads/…`), which nothing outside the site can resolve, and items
had no image element. Services that pin or share from a feed (Pinterest,
Flipboard, IFTTT, Zapier) found no image to use. `<author>` held a bare name,
which RSS reserves for an email address, and the language was `en_US` instead
of `en-us`. Seen on electronics360.org, whose newest item's `content:encoded`
was block JSON.

**Fix.** New `App\Services\FeedBuilder` (pure, unit-tested), used by
`FeedController`:

- each post's content goes through the `post.content` filter, exactly as on its
  page (blocks and Markdown become HTML; apps' additions are included);
- every `src`, `href`, `srcset`, `poster` and `data-src` is made absolute, and
  block-editor comments (`<!-- wp:… -->`) are removed;
- each item's image (the featured image, else the first JPEG, PNG, GIF, WebP or
  AVIF in the post; SVG and `data:` images are skipped) is added as
  `media:content` with type, width, height, title and alt text,
  `media:thumbnail`, an `enclosure` (with the file's real size when it is in
  `storage/uploads`) and a lead `<img>` in `description`;
- the author is `dc:creator`; categories and tags are `category` elements;
  the channel has `lastBuildDate`; the language is `en-us` form;
- the origin honours HTTPS behind a proxy (`X-Forwarded-Proto`), falling back
  to `APP_URL`, as canonical URLs do; links include a subfolder install's path;
- `]]>` inside content and characters XML 1.0 forbids can no longer break the
  document.

Files: `app/Services/FeedBuilder.php` (new),
`app/Http/Controllers/Web/FeedController.php`.

## Publishing: a new post can be dated earlier

**Problem.** Lists (home, categories, tags, authors, search, the feed) are
ordered by publication time, date and time. But a post that was not live yet
could only be published **Immediately** (at the moment Publish was pressed) or
**Scheduled** (future times only; the date field refused past days). A second
post written later and meant to be dated earlier the same day could not be:
it took the publishing moment, and so appeared above the first post instead of
below it. Only a post already live could be re-dated, with Change date.

**Fix.** The publish panel offers **Now · Schedule · Set date** for a post that
is not live. *Set date* takes any date and time in the site timezone: a time
that has passed publishes the post dated then, so it sorts where it belongs;
a future time schedules it. *Schedule* stays future-only. The button reads
Publish or Schedule to match, and the preview says "Publishes now, dated …".
Server side, `PostPublishing::resolve()` handles `publish_mode=date` for posts
that are not live the same way it already did for live ones (validation and
the 2038 limit unchanged). "Immediately" is now "Now" so three options fit in
the editor sidebar and Quick Edit.

`PostRepository::recent()` (the MCP resource list) ordered by `created_at`,
which is the same for every post imported in one go; it now orders by
publication time, then id.

Files: `app/Services/PostPublishing.php`, `admin/assets/js/post-fields.js`,
`admin/assets/js/post-publish.js` (comment), `app/Repositories/PostRepository.php`.

## Comments: Approve, Unapprove, Spam and Delete returned 404

**Problem.** Since the Comments screen became a Data Table, its row menu and
bulk bar submit to `POST /admin/comments/bulk`. `CommentController::bulk()`
was written for it, but the route was never registered, so every action ended
in a 404 and nothing could be moderated from the list (`?view=classic` still
worked).

**Fix.** The route is registered (before the `{id}` routes, under the
Comments area, so it needs `moderate_comments`). The handler takes each id
once, positive ids only, at most 500 per request. `CommentService::setStatus()`
stores only `approved`, `pending`, `spam` or `trash` (new `STATUSES`
constant) and does nothing when the status is unchanged.

Files: `routes/admin.php`, `app/Http/Controllers/Admin/CommentController.php`,
`app/Services/CommentService.php`.

## Tests

- `tests/Unit/FeedBuilderTest.php` (new): media elements, enclosure size,
  absolute URLs (src, href, srcset, poster, protocol-relative, relative),
  image fallback skipping SVG and `data:`, a block-editor post rendered by
  `BlockRenderer`, `]]>` and control characters, dates, language, summary.
- `tests/Unit/PostPublishingTest.php`: *Set date* on a new post (past →
  published and sorted below a later post; future → scheduled; invalid dates
  refused; scheduled post re-dated); the old "past date refused" expectation
  updated.
- `tests/js/post-publish.test.js`: Set date in the real panel (no minimum day,
  Publish vs Schedule, preview, Schedule keeps its minimum).
- `tests/Unit/CommentModerationTest.php` (new): every Data Table bulk endpoint
  on an admin screen is a registered POST route (fails without this fix), the
  route's controller and capability, the table's actions and filter statuses
  match the handler and service, unknown statuses refused.
