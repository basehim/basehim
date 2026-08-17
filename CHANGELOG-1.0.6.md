# Basehim 1.0.6 — a broken theme no longer breaks the site

Four places where a fault in theme code took the whole site rather than the part
that failed.

Themes are the part of an installation most likely to be edited by hand, often
directly on a live server. When one breaks it should cost the section that
broke, not every page — and the site owner should be told which file to fix
rather than being left with a blank screen.

## A template that threw returned a 500 on every page

`ThemeService::render()` deliberately rethrows so the caller can decide what to
do, and nothing did. A typo in `single.php` was a server error on every post,
with the only clue in a log the site owner has no reason to open.

The failure is now caught, logged with the file and line, and answered with a
plain page. The site stays up and the admin stays reachable.

**Signed-in administrators see the file and line**, because they are the ones
who can fix it. Everyone else sees a short message: a stack trace on a public
page tells an attacker the directory layout and the framework version.

The response is a 500 rather than a 200, because something genuinely is broken
and a search engine should not record an error page as the post's content.

## The 404 template could take every missing page with it

The worst case, since it is the page shown when something has already gone
wrong. A failure there turned every missing page into a server error. It now
falls back to plain markup.

## A partial took the whole site, and leaked half a page while doing it

`renderPartial()` had no error handling at all. A throw propagated — and left
`ob_start()` unclosed, so whatever the partial had already echoed leaked into
the response. The page came out half-rendered with nothing to explain why.

The header and the footer are partials, so this was every page on the site.

A partial that fails now costs that partial. The buffer is closed properly and
the reason is logged.

## One failing widget took the whole area

`WidgetRegistry` already guarded the render callback, but not the loop around
it, so a failure between widgets took the sidebar or footer of every page. One
widget failing now costs one widget.

## Not covered

An endless loop in theme code is a timeout, not an exception, and no amount of
error handling catches it. Guarding against that needs a time budget around
theme code, which is a larger change and belongs with the work to make
third-party themes installable.
