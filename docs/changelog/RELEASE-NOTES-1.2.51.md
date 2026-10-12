Security release: app admin pages, MCP permissions, SVG uploads, comment links and REST API responses are locked down. Recommended for every site.

- App admin pages (Analytics, Cloudhim, WP Migrator and any app's own screens) now open only for accounts with access to that app: administrators, and anyone you grant it under Users → Access. Until now any signed-in account, a subscriber included, could open them by URL.
- AI agents connected over MCP now act with their owner's role. An author's or contributor's token can no longer edit, publish or trash other people's posts or read their drafts; a contributor's new posts are saved for review; comment, user and settings tools need the matching permission; and keys of suspended or deleted accounts stop working.
- SVG files that contain script (or anything else that could run code) are refused at upload, and SVGs are served with a policy that blocks script, including ones uploaded before this update.
- A commenter's website must be a normal http(s) link, and a failed comment post can only send the visitor back to a page on your site.
- The REST API no longer returns passwords and keys from Settings (they read "[redacted]"), shows other people's email and role only to user managers, and no longer includes error details in server-error responses.
- Fixed: WP Migrator imports failed at the media/content step because four of its files were missing; changing a profile photo now clears the cache.

For app developers: adminGet()/adminPost() routes now require access_app:{slug}; open a path to more roles with the admin.area_policy filter. Scopes your app adds to MCP are granted to administrators unless you map them with the new mcp.scope_capabilities filter.

Optional, Apache sites: the updater never edits .htaccess. To apply the SVG policy to files Apache serves directly from storage/uploads, add this inside the <IfModule mod_headers.c> section:

```
<FilesMatch "\.svgz?$">
    Header set Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox"
</FilesMatch>
```

No database changes.
