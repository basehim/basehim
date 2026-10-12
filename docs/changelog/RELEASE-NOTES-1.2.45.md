SEO tags and timezones are handled by core now.

- Core writes every page's SEO and sharing tags (title, description, canonical,
  robots, Open Graph, X card, JSON-LD structured data, generator) the same
  way for every theme, and removes duplicates that older themes print.
- Settings → SEO: switch the whole thing or each tag group on or off; set the
  home page title and description, title format, default share image and X
  handle. Apps and themes can change any tag through filters.
- Settings → General: the timezone is a dropdown, with date and time formats.
  Every date in the admin and on the site follows it.
- Stored times are now consistent UTC on every host; a one-time database
  update repairs older values (no change on servers already set to UTC).
- New for themes and apps: bh_date() and related helpers, the
  /api/v1/time endpoints, and docs/THEME-DEVELOPMENT.md.

After updating: if your theme set a custom home page title or description,
enter it under Settings → SEO → Home page.
