Two fixes for apps.

- App updates no longer fail with a "Checksum mismatch" when more than one
  version is waiting. Updates now install the exact version shown and then
  refresh, so the next one appears right away.
- The admin sidebar shows each app's own icon next to its name, at the same
  size as the built-in icons (apps without an icon are unchanged).
