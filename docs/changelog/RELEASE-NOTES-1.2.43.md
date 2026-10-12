Security update. Install on every site.

- Fixed: any signed-in account, even a subscriber, could edit, unpublish or
  delete any post or page through the REST API, change categories and upload
  files. The API now checks the same permissions as the admin.
- Fixed: the setup wizard could be run again on an installed site.
- API keys are now limited to their scopes on the REST API, drafts are no
  longer publicly listable, and a partial update no longer wipes the post.
- Updates and marketplace downloads now verify TLS certificates.
- Browser sessions need a CSRF token to change things through the API, and
  other websites can no longer use a signed-in admin's cookies (cross-origin
  access is limited to the site and any origins in CORS_ALLOWED_ORIGINS).
- Fixed "Create API key" failing on MySQL/MariaDB strict mode (database
  update runs automatically).
- Smaller fixes: local dev server serves /.well-known, safer installer
  retries, .env parsing and pagination fixes.

API integrations: a key needs the scope for what it does (for example
posts:write to create posts). See CHANGELOG-1.2.43.md for details.
