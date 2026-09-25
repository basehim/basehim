# Basehim 1.2.9 — author archives and the author box

## Author archives

`/author/{slug}` existed and worked, but nothing linked to it, there was no
way to switch it off, and its address was the author's **login name**:
`/author/basehim` published half of the admin login to anyone guessing
passwords.

Now:

- **The address comes from the display name, never the login name**:
  `/author/john-doe`. The slug is stored in `users.meta` as `author_slug`,
  made on first use, unique across users, and stable when the display name
  changes. When the display name *is* the login name ("basehim"), the slug
  gets the user's id (`basehim-1`) so the login name still does not appear.
- **Authors can change it** — *Author page address* on their profile, and on
  the user edit screen. Invalid input is refused with a message; a taken slug
  gets a number.
- **Old `/author/{username}` links are not redirected.** A redirect would
  reveal which login belongs to which author.
- **Only authors with a published post have a page.** Anyone else — a
  subscriber, an editor who has not published — is a 404, so the pages cannot
  be used to list a site's accounts.
- **The template receives a public profile only**: display name, bio, slug,
  URL, avatar URL, post count. It used to receive the whole user row,
  **including the email address and the password hash**; any theme printing
  `$author` would have exposed them. `$author['username']` now holds the
  public slug, so templates that print "@username" keep working without
  showing the login name — the bundled archive templates did exactly that.
- Canonical URL and description for search engines.
- **Listed in the sitemap**, one entry per author with a published post.

## Settings → Reading

Two new switches, both on by default:

- **Author archive pages** — off: `/author/*` is a 404, author links
  disappear from themes using `bh_author_url()`, and the sitemap drops them.
- **Author box on posts** — off: `bh_author_box()` returns nothing.

**Fixed: "Discourage search engines" could never be switched off.** An
unticked checkbox sends nothing, and the settings screen only saves fields it
receives, so once ticked it stayed on for good — silently keeping the site out
of search engines. Every checkbox on the page now sends an explicit 0 when
unticked, as the Discussion tab already did.

## The author box, in core

```php
<?= bh_author_box($post) ?>
```

Avatar (the uploaded picture, else initials — no email-derived service is
used), name linked to the archive, bio, and "View all N posts". Themed like
`bh_comment_form()`: `bh-author-box__*` classes, a default stylesheet at zero
specificity emitted once per page, `'styles' => false`, and `class`,
`avatar_class`, `name_class`, `bio_class`, `link_class` for the theme's own.
Also `title`, `title_tag`, `show_bio`, `show_link`, `link_text` (`%d` is the
post count), `avatar_size`, and the `author_box.html` filter.

Also `bh_author_url($post)` — the archive link, or empty when archives are off
or the author has none — and `bh_author($post)`, the public profile.
Documented in `docs/THEME-DEVELOPMENT.md` under **Authors**.

## Bundled themes

`default` and `dark-night`: the byline links to the author's archive, and the
author box follows the post in each theme's own classes.

**The bundled author bio never appeared.** Both templates showed it only if
`$post['author_bio']` was set, and posts are loaded with the author's name and
username but never the bio, so the block was dead in both. It is replaced by
the author box.

`default`'s author archive also shows the author's avatar and post count, and
post lists link author names to their archives.

## Verified on a live install

- `/author/basehim-1`: 200, 7 posts, canonical URL; no email, password hash or
  bare login name anywhere in the page.
- 404: the old `/author/basehim`, a user without posts, an unknown slug, a
  malformed slug.
- Slugs: a clash gets `-2`; unusable input refused; changing the address on
  the profile moves the page and the old address 404s.
- Settings, through the real form: both switches off — archive 404, sitemap
  without authors, box and link empty; back on — all restored; "Discourage
  search engines" saved as off for the first time.
- Author box: link, initials, post count, stylesheet once.
- Both bundled themes: byline link, author box in theme classes, archive with
  all posts and no login name.

## Files

    app/Services/AuthorService.php                     new: slugs, lookup, public profile, settings
    app/Http/Controllers/Web/AuthorController.php      public slug; public profile; 404 rules
    app/Http/Controllers/Web/SitemapController.php     author pages
    bootstrap.php                                      bh_author_box(), bh_author_url(), bh_author()
    admin/views/settings/reading.php                   author switches; checkbox fix
    admin/views/profile/index.php                      author page address
    admin/views/users/edit.php                         author page address
    app/Http/Controllers/Admin/ProfileController.php   save it
    app/Http/Controllers/Admin/UserController.php      save it
    content/themes/default/templates/single.php        author link and box
    content/themes/default/templates/archive.php       author header, author links
    content/themes/dark-night/templates/single.php     author link and box
    docs/THEME-DEVELOPMENT.md                          Authors

No migration: the slug lives in the existing `users.meta` column.

## For theme authors

Add `<?= bh_author_box($post) ?>` after the post content. On a site that may
not run 1.2.9 yet, guard it with `function_exists('bh_author_box')`.
