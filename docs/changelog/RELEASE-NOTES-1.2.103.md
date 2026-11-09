A security update for how your site receives updates, apps and themes.

- Every download must now carry a checksum, is verified before it is used, and
  has to match the version it claims to be — a download with no checksum, or
  the wrong contents, is refused instead of installed.
- Updates and marketplace downloads now only follow their connection to the
  update service itself, never off to another address.
- An app or theme from the marketplace can only replace itself, not some other
  app or theme already on your site.
- Your site's connection key is sent in a way that keeps it out of server logs.
- App permission changes are handled more carefully: if an app's update asks
  for more access, or drops its permissions, your site flags it for review
  instead of quietly allowing it.

After updating: nothing to do.
