Fixes the Circuits DIY theme, which failed to render any page in 1.1.3.

The theme used the wrong method to include its own partials, so every page
showed "index could not be rendered" instead of the site. All six page types —
home, post, category, search, static page and not-found — now render correctly.

Nothing else has changed. If you are not using the Circuits DIY theme, this
update does not affect you.
