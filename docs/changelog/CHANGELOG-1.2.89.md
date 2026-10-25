# Basehim 1.2.89

2026-10-25 · Patch release · installs on 1.2.88

## Author pages: ProfilePage without mainEntity

**Problem.** Core marks an author archive (`/author/{slug}`) as a schema.org
`ProfilePage` in its JSON-LD, but never said whose profile it is. Google
requires `mainEntity` on a ProfilePage, and its Rich Results test reported
*Missing field "mainEntity"* as a critical issue, so author pages were not
eligible for profile rich results. Seen on circuits-diy.com.

**Fix.** `SeoHeadService` adds a `Person` node to an author page's graph and
links it from the ProfilePage's `mainEntity`. The Person is built from the
public profile `AuthorController` already passes to the theme
(`AuthorService::publicProfile()`), so no email, role or login name can reach
it:

- `@id` (`{author URL}#person`), `name`, `url`;
- `identifier`: the author's public slug;
- `description`: the bio on one line, when there is one;
- `image`: the uploaded avatar, absolute, when there is one;
- `agentInteractionStatistic`: the number of published posts, as a
  `WriteAction` counter.

Empty fields are left out rather than sent empty. A post's `BlogPosting`
author now carries the same `@id`, so search engines see one person across
the site instead of a separate author per article.

Files: `app/Services/SeoHeadService.php`.

## Tests

`tests/Unit/SeoProfilePageTest.php` (new) builds the real head for an author
page: the ProfilePage's `mainEntity` points at the Person, every field above
is present and absolute, a minimal author still validates, no account details
are copied even when passed, and other page types get no Person. The two
mainEntity tests fail on 1.2.88.
