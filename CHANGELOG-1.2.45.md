# Basehim 1.2.45

Two changes that move work from themes into core: **SEO and social tags**, and
**timezones**. Both work with every theme straight away, including themes
written before this release.

## SEO and social tags are core's job

A new SEO service (`App\Services\SeoHeadService`) writes every page's
search and sharing tags, the same way for every theme:

| Group | Tags |
|---|---|
| Title | `<title>`, from the post's SEO title or the title format |
| Description | `<meta name="description">` |
| Canonical | `<link rel="canonical">`, absolute, `?page=N` kept past page one |
| Robots | the post's own setting; `noindex` on search results, 404s and previews; `max-image-preview:large` |
| Open Graph | `og:*` plus `article:published_time`, `modified_time`, `section`, `tag` on posts |
| X (Twitter) | `twitter:card`, title, description, image, `twitter:site` |
| JSON-LD | one `@graph`: Organization, WebSite (with search box), WebPage, BlogPosting, BreadcrumbList |
| Generator | `<meta name="generator" content="Basehim CMS">` |

- **Themes print none of these any more.** When an older theme still does,
  its copies are removed from the finished page, so search engines and social
  networks see one consistent set. Tags apps add through `bh_head()` are
  never touched.
- The share image is the post's SEO social image, then its featured image,
  then a new site-wide default.
- **Settings → SEO** has a master switch, a switch per group and per JSON-LD
  type, a home page title and description, the title format (`%title%`,
  `%site%`, `%tagline%`, `%sep%`, `%page%`) and separator, default description
  and share image, X handle, "keep search results out of search engines",
  "allow large image previews" and "remove SEO tags printed by the theme".
  Several of these fields existed before but nothing used them.
- **Apps and themes can change everything:** filters `seo.enabled`,
  `seo.groups`, `seo.title`, `seo.description`, `seo.canonical`,
  `seo.robots`, `seo.image`, `seo.jsonld`, `seo.head`; per page from a
  template with `bh_seo()->set()`, `->addJsonLd()`, `->disable()`,
  `->handOff()`; and in `theme.json`, `"seo": {"jsonld": false}` (or
  `"seo": false`) for a theme that must print a group itself.
- **Themes can have a `boot.php`**, run once per request, to register filters.
- The WebSite JSON-LD the AI-access feature printed is now part of the SEO
  graph (its old setting is the default for the new one).
- Fixed: checkboxes on settings screens could be switched on but never off
  (an unticked box posts nothing). Settings → SEO sends an explicit 0, so
  "Generate XML sitemap" can be turned off now.
- `default` and `dark-night` no longer print their own tags.

**After updating:** a theme that set its own home page title or description
(basehim.com's theme does) now shows the site title and tagline on the home
page until you fill in **Settings → SEO → Home page**. A theme that added its
own JSON-LD (a `SoftwareApplication`, say) should move it to a `seo.jsonld`
filter in `boot.php`. See `docs/THEME-DEVELOPMENT.md`.

## Timezones

- **Settings → General → Timezone is a list now** (every timezone, grouped by
  region, with its current offset), with the local time shown beside it.
  Before, it was a text box whose value nothing read: every date was UTC.
- New **date format**, **time format** and **week starts on** settings.
- Every time is stored in UTC and shown in the site's timezone: the admin
  (post and page lists, comments, users, profile, API keys, updates, media
  library, marketplace, activity log), the bundled themes, and the SEO dates.
  Changing the timezone changes all of them at once.
- **For themes and apps:** `bh_date()`, `bh_time()`, `bh_datetime()`,
  `bh_time_ago()`, `bh_time_tag()`, `bh_iso8601()`, `bh_local_time()`,
  `bh_to_utc()`, `bh_now()`, `bh_timezone()`, and `App\Core\Time` with the
  same and more. `Helpers::formatDate()` and `Helpers::timeAgo()` now use the
  site timezone.
- **API:** `GET /api/v1/time` (timezone, offset, formats, now),
  `GET /api/v1/timezones`, `GET /api/v1/time/convert?value=…&to=local|utc`.
- **Admin scripts:** `BasehimTime.format()`, `.date()`, `.time()`, `.ago()`
  use the site timezone, not the browser's.

### Stored times made consistent

PHP always wrote times in UTC, but database connections used the server's
own timezone. So on a host not set to UTC, one table could hold both UTC and
server-local times, and checks like "has this reset link expired" were off
by the server's offset. Every connection now uses UTC.

Migration `013_utc_timestamps` repairs the existing values that would
otherwise read shifted (post publish and delete times, last sign-in, API-key
times). It changes nothing on a server already set to UTC, skips anything it
can't convert, never touches `updated_at`, and isn't run on fresh installs.

`APP_TIMEZONE` in `.env` is now only the starting value for the site timezone
until one is saved in Settings → General.

## Documentation

`docs/THEME-DEVELOPMENT.md` is new in the package: the theme guide, updated
with "SEO and social tags", "Dates and times", the boot file and a checklist
for moving an older theme over.

Files: new `app/Core/Time.php`, `app/Services/SeoHeadService.php`,
`app/Http/Controllers/Api/TimeController.php`, `admin/assets/js/time.js`,
`database/migrations/013_utc_timestamps.sql`, `docs/THEME-DEVELOPMENT.md`;
changed core, admin views, settings screens and both bundled themes.
