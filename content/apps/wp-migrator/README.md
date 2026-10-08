# WordPress Migrator for Basehim

An app that migrates an entire WordPress site to Basehim. Supports both
WXR export files and direct MySQL database connections, and runs as a
batched wizard so even very large sites complete without PHP timeouts.

---

## What it imports

- **Users & authors** — with a default password you set (users reset on first login)
- **Categories & tags** — including parent/child hierarchy
- **Posts & pages** — with content, excerpt, slug, status, dates, author, comment settings
- **Custom fields / post meta** — anything not starting with `_` (WP-internal)
- **SEO meta** — automatically detects Yoast SEO and Rank Math meta keys and maps them onto Basehim's `seo_meta` table
- **Featured images** — preserves the `_thumbnail_id` relationship
- **Inline media** — downloads images from `wp-content/uploads/` to Basehim's storage
- **Comments** — including parent/child threading and approval status
- **Menus** — full menu structure (MySQL source only — WXR doesn't include menus)
- **301 redirects** — automatically creates redirects from old WordPress URLs so search engines and external links keep working

## Installation

1. Go to **Admin → Apps** in your Basehim site.
2. Click **Upload app** and pick `wp-migrator.zip`.
3. Once it appears in the list, click **Activate**.

A new **WP Migrator** item will appear in the sidebar.

## Running a migration

Open **WP Migrator** from the sidebar. The wizard has three steps:

### 1. Choose source

- **WXR file** — In your WordPress site, go to *Tools → Export*, select "All content", and download the `.xml` file. Upload it here. Best when you don't have DB access or your old host is gone. Files up to **500 MB** are supported — the file is uploaded in small background chunks, so it isn't limited by the server's normal `upload_max_filesize`/`post_max_size` setting, and the WXR is parsed with a streaming reader so memory use stays low even on very large exports.
- **Direct MySQL** — Connect read-only credentials to a live WP database. Faster, brings menus, more accurate for large sites.

### 2. Pick what to import

Every entity type is on by default. Uncheck anything you want to skip. If you're re-running, leave them all on — the importer is idempotent (looks up by ID map, no duplicates).

### Media filter

Under **Media** you can choose which attachments are downloaded:

- **Types** — images, video, audio, documents, archives, other files.
- **Originals only** — skips thumbnails and resized copies (`photo-300x200.jpg`, `photo@2x.jpg`), downloads the original upload instead of WordPress's `-scaled` copy, and links resized images inside posts to the full image instead of downloading each size. A file whose real name ends in `-WIDTHxHEIGHT` (e.g. `banner-1920x1080.jpg`) is treated as a copy too — add it back by unticking this option or renaming it.
- **Exclude file names** — comma-separated wildcards, e.g. `*-150x150.*, *.webp`.
- **Max file size** — per file, 1–512 MB (default 25).
- **Advanced** — allow SVG (can contain scripts; trusted sources only) and allow downloads from private network addresses (only when the old site is on localhost or your LAN).

Files are stored under WordPress's own `YYYY/MM` folder, with an extension from an allow-list and the MIME type detected from the file itself. Skipped and failed files are listed in the log.

### 3. Set user options

- **Default password** — Used for all imported users. Leave blank to auto-generate: the generated password is shown once when the migration starts and written to the job log, then removed from the job when the migration ends. Users should change this on first login.
- **Default role** — Role assigned to imported users.

Click **Start migration**. The wizard runs in small batches (~25 records at a time), updating the progress bar after each batch. You can leave the page open or close it — re-opening the app page resumes the loop where it left off.

## What happens behind the scenes

Migration steps run in this order (each must complete before the next starts):

1. `users` → creates Basehim users, records `wp_id → basehim_id` in the ID map
2. `taxonomies` → categories + tags, then a second pass fixes parent links
3. `media` → downloads each attachment, rehosts under `/uploads/YYYY/MM/`
4. `posts` → posts + pages, attaches categories/tags, copies postmeta, writes SEO meta
5. `featured_media` → looks up `_thumbnail_id` and links the post's featured image
6. `comments` → creates comments, then a second pass fixes reply-threading
7. `menus` → builds menus from `nav_menu_item` records (MySQL only)
8. `redirects` → for every imported post, computes old/new URLs and inserts a 301
9. `rewrite_content` → walks each post's HTML and replaces old `<img src>` and absolute internal links with new Basehim URLs

## Logs

The progress panel shows the job log live; after the run the **Last migration** panel shows its status, counts and log, with a **Download** link for the whole log. Every line is also written to **Admin → Apps → Logs** (wp-migrator), and warnings/errors to the core log.

## After migration

- **Login** — users can log in with their original username/email and the default password you set
- **Old URLs** — visit any old WordPress URL on your new site, and the app issues a 301 to the new path
- **Re-running** — safe to run again with the same settings; existing entities are updated, not duplicated
- **Reset** — the **Reset migration data** button (top right) wipes the ID map, job history, and redirects so you can start completely fresh (not while a migration is running)

## Troubleshooting

**"Could not read source: ..."** — for WXR, the file is corrupted or not valid XML. For MySQL, check credentials and that the user has read access to the `wp_*` tables.

**"Unknown or expired upload session"** — the chunked upload took longer than 12 hours, or `storage/cache` was cleared mid-upload. Re-select the file and try again.

**File larger than 500 MB** — this is a hard cap on WXR imports. For sites with very large exports, use the Direct MySQL source instead — no upload needed, and it’s faster anyway.

**Media downloads fail with "private or reserved address"** — the old site resolves to a local/LAN address. Tick *Allow downloads from private network addresses* under Media → Advanced.

**Media downloads fail** — your server can't reach the old site. Check that `curl` is enabled and that the old site is online. Failed media URLs are logged but don't abort the migration; the inline `<img>` will keep its old URL.

**"users" step shows 0 imported** — every WP user already exists in Basehim (matched by email). Check the **Users** admin page; their IDs are still in the ID map, so posts will still be assigned correctly.

**Some posts went to author 1** — the WP `dc:creator` field (a username) didn't match any imported user. Check the migration log for `failed to create user X` warnings.

**No menus** — you used a WXR file. WXR exports don't include menu structure. Use the Direct MySQL source if menus matter.

## Files created by this app

- Tables: `app_wpmig_idmap`, `app_wpmig_jobs`, `app_wpmig_redirects`
- Uploads (during media import): `storage/uploads/YYYY/MM/*`
- Temporary WXR cache: `storage/cache/wpmig_*.xml` (auto-cleaned after migration completes)

Uninstalling the app drops the three app tables. Imported posts, users, and media stay where they are — they're regular Basehim records now.

## Requirements

- PHP 8.1+
- Extensions: `pdo_mysql`, `simplexml`, `xmlreader`, `curl`, `mbstring`, `fileinfo`
- The `db.raw` app permission (declared in `app.json`; approve it under Admin → Apps)
- Writable `storage/uploads` and `storage/cache` directories

## Image links

Every WordPress upload URL in imported content is rewritten to the imported
file — including the resized copies WordPress makes (`photo-1024x683.jpg`),
`-scaled` originals, every `srcset` candidate, and gallery `data-*` attributes.
`[gallery]` and `[caption]` shortcodes become HTML. A URL that cannot be mapped
is left as it was, never turned into a path that does not exist.

**Repair image links** (below the wizard) applies the same fixes to posts that
are already on the site — for migrations made with 1.2.0 or earlier. Preview it
first; it changes nothing until you choose Repair.

Requests for `/wp-content/uploads/...` are redirected to the imported file.

