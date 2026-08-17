# Basehim 1.1.0 — the Customizer

Appearance settings move into a new screen with a live preview, and themes can
declare options of their own.

## The Customizer

**Appearance → Customize.** Controls on the left, the real site in an iframe on
the right. A change is applied to the preview immediately and nothing is written
until Save.

Colours, sizes and fonts update **without a reload** — they are CSS custom
properties, so the preview sets one property and the change lands instantly.
That is what makes dragging a colour picker feel like anything at all. Options
that change the markup reload the frame instead, debounced, because reloading on
every keystroke of a site title is unusable.

**Nothing is stored for a preview.** Pending values travel by message and live in
the session for the frame to read. A preview never changes what a visitor sees,
and abandoning the screen leaves nothing behind.

The preview URL carries a token derived from the session, not a plain flag.
Preview mode injects styles into a page, so it is not something to leave
reachable by guessing a query string.

## Themes can declare options

A theme declares them in `theme.json`, and core does the rest:

    "customizer": {
      "colors": {
        "label": "Colours",
        "options": {
          "accent": { "type": "color", "label": "Accent", "default": "#e11d48" }
        }
      }
    }

Core renders the field, validates by declared type, stores the value, and emits
it as `--bh-accent` for the theme's stylesheet to use with `var()`. The theme
writes no admin code and no PHP in templates.

Nine types: `text`, `textarea`, `color`, `select`, `toggle`, `number`, `range`,
`image`, `url`, `font`.

**Theme values are stored per theme**, under `theme:{slug}`. Switching themes and
switching back does not lose the work — which is the single most irritating thing
about customisers that keep everything in one bucket.

### A theme cannot break this screen

Every declaration is checked. A malformed one is dropped with a line in the log
and the rest of the screen still works, so the site owner can always reach their
logo and their CSS. A theme also cannot overwrite a core section, or it could
hide the logo field and leave the owner stuck.

### Values are validated because they reach a stylesheet

A colour field that accepted arbitrary text would be a CSS injection, and CSS
injection is data exfiltration. So: colours must be hex, fonts may not contain
braces or semicolons, select values must be on the list the theme offered, URLs
must be URLs, numbers are clamped to their declared range, and custom CSS has
its closing tags escaped.

Anything rejected is **named in the response** rather than dropped quietly. A
setting that appears to save and then is not there is a miserable thing to debug.

## Appearance settings have moved

Logo, site icon, footer text and custom CSS are now in the Customizer, alongside
the site name and description. Settings → Appearance redirects there rather than
404ing, because it is the sort of URL people bookmark.

Existing values are untouched. Core options are still stored in the `appearance`
and `general` groups exactly as before, so **no migration runs** and nothing
needs re-entering.

## For theme authors

Themes echo one variable in `<head>`:

    <?= $customizer_head ?? '' ?>

That carries the theme's options as custom properties, the site's custom CSS,
and — inside a preview only — the script that applies pending changes. Both
bundled themes have been updated. A theme that does not echo it is simply not
customisable, rather than broken.

## One media picker, used everywhere

The picker was loaded by whichever layout happened to list it. The Customizer's
layout was written without it, so the Choose button on an image option did
nothing — the picker was simply not on the page.

The fix is not to add the line to another layout, because the next layout would
forget it too. Both layouts now include one shared partial that declares the
admin's common assets, so a screen cannot be built without them.

The picker also gained a **declarative binding**, so a field can be wired up
with markup alone:

    <div data-bh-media>
      <input type="hidden" data-bh-media-value>
      <div data-bh-media-preview></div>
      <button type="button" data-bh-media-pick>Choose</button>
      <button type="button" data-bh-media-clear>Remove</button>
    </div>

Core handles picking, the preview and clearing. The element fires a `bh:media`
event when the value changes, so a screen can react without knowing how any of
it works. The listener is delegated from the document, so markup added later —
a widget form, a repeating field — works without being registered.

The Customizer's image option uses this, and lost twenty-five lines of its own
picker code in the process. Any app that needs an image field can use the same
markup rather than writing its own.

