A secure AJAX gateway for apps and themes, and a media library security fix.

- New: one AJAX endpoint (/ajax) for browser code. Core checks the session,
  security token, origin, permissions, rate limit and input on every request,
  so apps no longer write those checks themselves. Docs: docs/APP-AJAX.md.
- Security: authors could edit or delete other users' files in the media
  library. Now only their own; editors can still fix alt text and captions on
  any image; deleting someone else's file needs the Delete media permission.
- Security: the form security token is renewed at every sign-in and sign-out.
- The media library tells you when some selected files were not deleted.
- One small database table is added automatically (rate limits).

After updating: a browser tab left open from before a sign-in may need a
reload. Sites behind a proxy or Cloudflare should list it in TRUSTED_PROXIES
(.env) so rate limits count real visitors.
