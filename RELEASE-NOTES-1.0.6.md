A fault in a theme no longer takes down the whole site.

Four places where an error in theme code returned a server error on every page
have been fixed, so a failure now costs only the part that failed:

- **A broken template** showed a 500 on every page. It now shows a plain page,
  and the site and admin stay reachable. Signed-in administrators see the file
  and line number so they know what to fix; visitors see only a short message.
- **A broken 404 template** turned every missing page into a server error.
- **A broken partial** took the entire site — the header and footer are
  partials — and leaked a half-rendered page while doing it. It now costs just
  that partial.
- **One failing widget** took the whole sidebar or footer. It now costs one
  widget.

Every failure is logged with the file and line, so there is something to read
rather than a blank screen.

Not covered: an endless loop in theme code is a timeout rather than an error,
and no error handling catches it. That needs a time limit around theme code and
will come with the work on third-party themes.
