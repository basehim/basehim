# Basehim 1.2.0 — the front-end contract

Core fired thirty-four hooks and not one of them was on the front end. An
analytics app had nowhere to put a tracking script, a consent banner had nowhere
to render, and a theme had no way to receive either — so every app that touched
the public site had to ask people to paste something into a template by hand.

## Two functions a theme calls

    <head>  … <?= bh_head() ?>  </head>
    …       <?= bh_footer() ?>  </body>

`bh_head()` emits the Customizer's CSS variables, the site's custom CSS, any
stylesheet an app registered, head scripts, and whatever apps contribute.
`bh_footer()` emits deferred scripts and footer contributions.

A theme that calls neither still works; it simply receives nothing.

## What an app writes instead of instructions

    $this->addFooter(fn() => '<script async src="…"></script>');
    $this->enqueueStyle('front', $this->asset('css/front.css'));
    $this->enqueueScript('front', $this->asset('js/front.js'), 10, inHead: false);

The theme never learns the app exists.

**A callback may echo or return.** Both are natural and neither is wrong, so
both work — an action listener that returns a string would otherwise produce
nothing, which looks exactly like the hook not firing.

**Handles make registration idempotent.** Two apps depending on the same library
produce one tag, not two. Priority orders the output rather than leaving it to
whichever app booted first.

**Asset URLs are validated.** They come from apps and are written into the page,
so `javascript:` and `data:` URLs are dropped rather than emitted.

**A failing app costs its own output.** Each listener is isolated and the
failure logged; the page still renders.

## Smaller helpers

- **`bh_body_class()`** — `is-home`/`is-inner`, the active theme's slug, and
  `is-customizer-preview`, so a theme stops reinventing "am I on the home page"
  and an app has something stable to target.
- **`bh_theme_option($key, $default)`** — a theme option, with the Customizer's
  pending value inside a preview. The Circuits DIY theme was carrying the same
  container closure in five partials; all of them are gone.
- **`bh_setting($group, $key, $default)`** — a site setting.
- **`bh_is_preview()`** — for a theme that wants to suppress something in the
  preview frame.

## Already present

Themes could already register widget areas and menu locations through
`theme.json`; `bootAreas()` has read them since the theme system was written.
Nothing was needed there. Both bundled themes and Circuits DIY now call
`bh_head()` and `bh_footer()`.
