# Basehim 1.2.6 — comment counts, and a comment form in core

Two reports about comments, and several causes behind them.

## Comment counts did not show

### Posts at their real address never showed their comments

`ResolveController` serves `/{category}/{slug}` and `/{slug}` — the canonical
URL of every post under **Category** and **Flat** permalinks. It rendered with
`'comments' => []` and `'comments_count' => 0`, hard-coded.

So on those sites no post ever showed its comments or a count at its canonical
address. Approving a comment changed nothing a visitor could see, and a theme's
"Comments (N)" heading stayed at zero. Confirmed on a live install: a post with
four approved comments rendered "Discussion" with no count and no comments.

It now loads the approved comments exactly as `PostController` does. The same
post renders "Discussion (4)" with all four.

### There was no badge for new comments

The admin sidebar could show a count on exactly one item, Updates. Comments had
none, so a new comment waiting for moderation was invisible until someone
opened the Comments screen.

**Comments now carries a badge** with the number awaiting moderation, hidden at
zero, with a tooltip. It refreshes every 60 seconds while the tab is visible,
and immediately when you return to the tab, from a new endpoint:

    GET /admin/comments/pending.json   →  {"pending": 3}

It sits under `/admin/comments`, so it requires `moderate_comments` like the
Comments screen itself. An author receives 403, and sees no badge.

Moderating reloads the page, so the badge is always current after approve, spam
or delete.

**Badges are now generic.** Any sidebar item can name a badge
(`'badge' => 'my-key'`), and an app supplies its number through the new
`admin.badge` filter, which receives `(int $count, string $key)`.

### The count did not update after posting

A successful comment submission now returns the post's approved total as
`count` in the JSON response, and the standard form (below) updates any element
marked `data-bh-comment-count` in place.

## Signed-in members were asked for their name and email

### The server required them

`CommentController::store()` enforced "Name and email are required" **before**
it looked up the signed-in user. A member had to type both again, and a theme
could not hide those fields without breaking commenting for members.

The account is now resolved first. **A signed-in, active member always comments
as their account** — display name (or username) and email, linked by
`author_id` — whatever the form sends. A name, email or website typed into an
old form is ignored, so a theme that still shows the fields cannot let a member
post as someone else. A suspended account is treated as a guest. Guests are
validated exactly as before.

### Core now provides the form

```php
<?= bh_comment_form($post) ?>
```

One call, anywhere a template has the post:

- Guests get name, email and (optionally) website fields, marked required
  according to Settings → Discussion.
- Members see "Commenting as *name*" and no identity fields.
- Carries everything the handler checks: CSRF token, post id, reply target and
  the spam honeypot.
- Submits without reloading. A comment held for moderation shows the message in
  place; one published immediately reloads the page at that comment, so it
  appears in the theme's own markup.
- Replies: any element with `data-bh-reply data-id=… data-name=…` makes the form
  a reply to that comment.
- Returns the closed text when comments are off for the site or the post, or the
  post is not published.
- Themeable: `form_class`, `input_class`, `textarea_class`, `button_class` and
  others put the theme's own classes on each element; the default stylesheet is
  at zero specificity (`:where()`), so theme rules always win, and
  `'styles' => false` drops it.
- Events and filters: `bh:comment-posted` (cancelable) on the form;
  `comment_form.args` and `comment_form.html` filters for apps.

Also `bh_comment_count($post)` for the approved count anywhere.

The stylesheet and script are emitted once per page, however many forms.

Documented in `docs/THEME-DEVELOPMENT.md`, under **Comments**.

### Bundled themes

`default` and `dark-night` now use `bh_comment_form()`, styled in their own
classes, in place of about 90 lines each of hand-written form and script. Both
mark their count with `data-bh-comment-count`.

**The `default` theme never showed a Reply button.** Its comment renderer is a
closure that tested `$comments_open` without importing it, so the variable was
always undefined. Fixed; one Reply button per comment now renders.

## Verified

On a live install:

- Sidebar badge as an administrator (shown, correct count and tooltip) and as an
  author (no badge; the endpoint answers 403).
- Seven submission cases through the real handler: member with no fields,
  member with forged name/email/website, guest with fields missing (required
  and optional), guest with fields, suspended account, comments disabled.
  Comments were held for moderation and notifications were off, so nothing was
  published or emailed; every test comment was removed.
- The form for guests, members, a closed post, a draft, comments disabled, a
  second form on the same page, theme classes, and hostile arguments.
- Full-page renders of both bundled themes as a guest and as a member.
- The form script's behaviour: held, published, rejected, reply and cancel.

## Files

    app/Services/CommentService.php                    approvedCount(), pendingCount()
    app/Http/Controllers/Web/ResolveController.php     comments on canonical URLs
    app/Http/Controllers/Web/CommentController.php     account identity first; count in response
    app/Http/Controllers/Admin/CommentController.php   pending.json
    routes/admin.php                                   route for pending.json
    admin/views/layouts/app.php                        generic badges; comments badge + refresh
    bootstrap.php                                      bh_comment_form(), bh_comment_count()
    content/themes/default/templates/single.php        core form; Reply buttons fixed
    content/themes/dark-night/templates/single.php     core form
    docs/THEME-DEVELOPMENT.md                          Comments section

No migration, no new settings.

## For theme authors

A theme with its own comment form keeps working: the server now ignores the
identity fields for members. To hide those fields, replace the form with
`<?= bh_comment_form($post) ?>`. On a site that might not have 1.2.6 yet, guard
it: `function_exists('bh_comment_form')`.
