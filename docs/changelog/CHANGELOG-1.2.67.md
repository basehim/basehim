# Basehim 1.2.67

This release modernises the Media Library. One shared media picker now pages
through the whole library from the server, with Load More (1, 2). The
library screen gets a Grid view and a List view; the List view is the shared
Data Table (3–5). Delete and upload checks are tightened (6, 7). Every
operation goes through the existing AJAX gateway.

- **No database changes.** The existing `idx_created` and `idx_mime` indexes
  cover the new month filter and the sort tie-breaker.
- **Unchanged:** attachment IDs, file names, URL formats, and the item that
  `onSelect` receives.
- **Installs on 1.2.66.**

## What changed

1. **`media.list` pages reliably, and its filters are checked.**
   - **Stable order.** Every sort now ends with `id`. Before, a library with
     many files uploaded in the same second (an import, a bulk upload) could
     repeat or skip files between pages.
   - **`meta.has_more`** says whether another page exists.
   - **`type`** must be one of `image`, `svg`, `video`, `audio`, `document`.
     A raw MIME prefix is still accepted by the REST API and by
     `MediaService::paginate()`, but not here.
   - **New `types`** is a comma list for pickers that accept several groups.
     A `type` inside that list narrows to it; a `type` outside it matches
     nothing. Before, it matched everything.
   - **New `month`** (`YYYY-MM`) is a month in the site's timezone, turned
     into UTC bounds on `created_at`, so it uses the index.
   - **Search is literal.** `%` and `_` are escaped.
   - **Counts:** the type counts come with page 1 only. With `months=1`,
     page 1 also lists the months that have uploads. A Load More page no
     longer pays for either.
   - **New `media.get`** returns one item, with `can.edit` and `can.delete`
     from the same rules the server applies.
   - **Code:** the WHERE clause is built in one place,
     `MediaRepository::where()`. The type groups and sorts are constants
     (`TYPE_GROUPS`, `SORTS`), never request text.

2. **One media picker, with Load More** (`admin/assets/js/media.js`).
   - **Before:** `BasehimMedia.openPicker()` loaded the 60 newest files and
     nothing else, so older files could not be picked anywhere: the featured
     image, the customizer's image fields, the logo and site icon, or the
     block editor. It also ignored `multiple`.
   - **Loading:** the picker reads 40 files at a time through `media.list`.
     **Load more** asks for the next page.
     - Only one request runs at a time. Repeated clicks send one request.
     - A new search or filter starts at page 1. Any answer still on its way
       for the old list is dropped when it arrives.
     - Files already on screen are never added twice.
     - The end of the list says so. An error shows the message and **Retry**.
   - **Options:** `types`, `multiple`, `selected` (ids already selected;
     fetched with `media.get` if they are not on page 1), `title`,
     `confirmLabel`, `onSelectMany` and `onCancel`.
     - `onSelect(item)` receives the same media row as before. With
       `multiple`, it is called once for each file, so existing callers work
       unchanged.
   - **New filters:** type and month.
   - **Accessibility:** `role=dialog`, `aria-modal`, focus kept inside the
     dialog and returned on close, arrow keys between cards, and
     `aria-busy` while loading.
   - **Uploading** in the picker puts the new file first and selects it. A
     file the picker cannot use is reported, not selected.
   - **Callers moved to it:**
     - Post and page featured image (`types: 'image/*'`, with the current one
       selected; the preview uses the medium size).
     - Customizer image options (`data-bh-media-types`, which a theme can set
       with an option's `types`).
     - The logo and favicon fields of the older Appearance settings view.
     - Every block editor picker, typed by the block (image, video, audio,
       cover). The gallery can take several files at once.
   - **Avatar editor:** its own library list gets the same stale-answer and
     double-click guards, plus Retry.
   - **`data-bh-media` fields:** take `data-bh-media-types`,
     `data-bh-media-title` and an optional `[data-bh-media-id]` input.
   - **Icons:** `icons.js` gains the icons these screens draw (`magnifying-glass`,
     `film`, `cloud-arrow-up` and others). The picker's search icon was
     blank before.

3. **The Media Library screen** (`admin/views/media/index.php`,
   `media-library.js`).
   - **One toolbar for both views:** type pills with counts, upload month,
     search and sort.
   - **Grid** (natural shapes or squares) loads 60 at a time with Load More,
     with the same guards as the picker.
   - **List** is the Data Table `media.library` (4).
   - **New states:** loading, empty (with Upload Media), "no matches" (with
     Clear filters), and error (with Try again).
   - **Details dialog** (both views): edit the title, alt text, caption and
     description, copy the URL, open, download and delete.
     - Fields are read-only for a file you may not edit.
     - Delete only appears when you may delete the file.
     - Focus is kept inside the dialog and returned to the opener on close.
   - **Grid bulk delete** sends at most 500 IDs per request and reports each
     file.
   - **Fixed:** the bulk bar and the search's clear button were always
     visible. Their `display` beat `.hidden`.
   - **Controller:** `MediaController::index` no longer queries a page it did
     not use. The upload hint shows the site's own size limit and file types
     (Settings → Media), not the config defaults.

4. **`media.library` Data Table** (`routes/tables.php`,
   `App\Services\Table\MediaTableProvider`).
   - **Columns:** thumbnail (the generated thumbnail, never the original),
     file (opens the details dialog), type, size, dimensions, uploaded, and
     actions (details, copy URL).
   - **Server:** paging and sorting run on the server. The type and month
     filters are checked against the column's `in` set and
     `MediaService::monthBounds()`.
   - **Row menu:** View (the file, in a new tab) and Delete.
   - **Phones:** the shared compact cards.
   - **Apps:** add columns with `datatable.columns.media.library`.

5. **Data Table: page events, stale answers, page 1 on filter change**
   (`datatable.js`, `partials/datatable.php`).
   - **Additive events on the table root:**
     - `bh:dt-query` (search, filters, sort) and `bh:dt-reload`, dispatched
       by the page;
     - `bh:dt-loaded`, fired after every load;
     - `bh:dt-bulk`, cancelable, fired before a bulk form is posted.
   - **`bulkEvents`:** a new partial option for pages that run bulk actions
     themselves. See docs/DATA-TABLE.md.
   - **Fixed for every table:**
     - An answer to an older request is dropped, so a slow response to an
       earlier search no longer replaces the newer one.
     - Changing the status filter now goes back to page 1.

6. **Delete reports each file, and never calls a half-done delete a success.**
   - **Order:** `MediaService::deleteWithReport()` removes the database row
     first, then the original and its generated sizes.
     - Before, the files went first, so a failed row delete left a library
       entry pointing at nothing.
     - A file that cannot be removed from disk is reported as `partial`. The
       row is gone, the file names are logged, and it counts as `failed`.
   - **`delete()`** keeps its boolean.
   - **`media.delete` results:** it now returns
     `results: [{id, status}]`, where status is `deleted`, `missing`,
     `denied`, `partial` or `failed`. The counts are unchanged.
     - Repeating a request is harmless: files already gone are `missing`.
     - The action now has a rate limit (60 a minute).
   - **Generated sizes:** only bare file names stored in `sizes` are
     unlinked, so a stored size entry can never point outside the item's
     folder.

7. **Uploads: a floor under Settings → Media, and content that must match
   its name.**
   - **`MediaService::NEVER_ALLOWED`:** PHP in all its extensions, CGI and
     shell scripts, executables, `.htaccess`, `.ini`, HTML, JavaScript and
     `.svgz` are refused, even when typed into the allowed list. Most servers
     serve `storage/uploads` straight from disk, where a `.php` would run.
     `allowedTypes()` leaves them out too, so the upload hint never offers
     them.
   - **`MediaService::contentProblem()`** checks the detected MIME type:
     - Content that is a program, script or web page is refused whatever its
       name.
     - A raster image extension (`.jpg`, `.png`, `.gif`, `.webp`, `.avif`,
       `.bmp`, `.ico`) must hold a raster image. A PNG saved as `.jpg` is
       still accepted.
   - **Size** is measured on the received file, not the browser's claim.
   - **Errors:** PHP upload error codes become plain sentences.
   - **New `MediaRejected`** (a `RuntimeException`) carries
     MediaService's messages. The admin upload route and the REST API show
     only those. Any other failure, such as a database error naming tables
     or paths, is logged, and the browser gets "The file could not be saved."
     Before, every exception message was returned.

## Files

Changed:
- **Media and Data Table views and scripts:**
  - `admin/views/media/index.php`;
  - `admin/assets/js/media-library.js`, `media.js`, `datatable.js`,
    `icons.js`, `avatar-editor.js`, `block-editor.js`;
  - `admin/assets/css/media-picker.css`;
  - `admin/views/partials/datatable.php`.
- **Picker callers:** `admin/views/posts/edit.php`,
  `admin/views/pages/edit.php`, `admin/views/customizer/index.php`,
  `admin/views/settings/appearance.php`.
- **Server:**
  - `app/Repositories/MediaRepository.php`;
  - `app/Services/MediaService.php`;
  - `app/Http/Controllers/Ajax/MediaActions.php`;
  - `app/Http/Controllers/Admin/MediaController.php`;
  - `app/Http/Controllers/Api/MediaController.php`;
  - `routes/ajax.php`, `routes/tables.php`.
- **Docs:** `docs/ADMIN-UI.md` (the media picker), `docs/DATA-TABLE.md` (page
  events, `media.library`).
- **Tests:** `tests/Integration/EditorActionsTest.php` (media.delete's new
  `results`).
- **Release:** `CHANGELOG.md`, `index.php` (1.2.67).

New:
- `app/Services/MediaRejected.php`,
  `app/Services/Table/MediaTableProvider.php`.
- `tests/Integration/MediaLibraryTest.php`.
- `docs/changelog/CHANGELOG-1.2.67.md`, `RELEASE-NOTES-1.2.67.md`.

## Tests

`php tests/run.php` with a database (Dolt): 262 passed, 2 skipped, 1 failed.
The failure is the pre-existing `editor: a double click … creates one post`,
a Dolt full-text limitation that 1.2.65 and 1.2.66 share.

New (`MediaLibraryTest`, 9 tests):
- **Paging:** 25 files with the same timestamp, read 7 at a time, come back
  exactly once each. `has_more` matches `last_page`, and counts come on
  page 1 only.
- **Filters:**
  - `types`, a `type` inside and outside them, and `month`;
  - `%` and `_` searched literally;
  - a raw MIME prefix, unknown types, bad months, SQL in `month` and `sort`,
    and `per_page` over 100 all refused (422);
  - the month list.
- **Access:**
  - signed out is 401;
  - subscriber and contributor are 403;
  - a bad CSRF token deletes nothing.
- **`media.get`:** the `can` flags for your own and others' files; unknown
  ids are 404.
- **`media.delete`:**
  - results per file (deleted, denied, missing);
  - repeating is harmless;
  - only-others' is 403;
  - more than 500 IDs is 422.
- **Delete order:** an uploaded file's row, original and sizes are all gone.
- **Uploads:**
  - an HTML page named `.jpg` and text named `.png` are refused; a PNG named
    `.jpg` is accepted;
  - `.php`, `.phtml`, `.html` and `.htaccess` are refused even when the
    setting lists them;
  - `allowedTypes()` leaves them out.
- **The table:**
  - totals, type and month filters, size sort;
  - an unknown type is ignored;
  - a subscriber gets 403.
- **The screen** includes the table, and `GET /admin/media/json` still
  answers.

Checked in Chromium, on a site with 132 files and then 5,130:
- **Grid:**
  - 60 files, then 120 after three quick clicks on Load More (one request),
    with no duplicates;
  - "All 132 files shown" at the end;
  - search, "No matches" with Clear filters, and the month filter.
- **Details:** save, Escape closes.
- **List:**
  - 20 rows, with the type pill driving the table;
  - a file name opens the dialog;
  - bulk Delete runs over AJAX: the page stays, the table reloads and the
    count drops by 2.
- **Picker from the post editor:**
  - "Featured image" with the image and SVG types;
  - 40 files, then everything after Load More (131 unique);
  - type filter, a fast search sequence that shows the final search's
    results;
  - Set featured image stores the id;
  - reopening shows it selected.
- **Picker options:**
  - multiple selection across two pages calls `onSelect` four times;
  - keyboard selection works;
  - an empty video list shows its empty state;
  - a forced 500 error shows the message and Retry, and Retry recovers.
- **Customizer:** the logo field opens "Choose logo" and stores the original
  URL.
- **Uploads:** a valid PNG uploads; an HTML file named `.jpg` is refused with
  its reason.
- **Avatar editor:** library, search and Load More, with no duplicates.
- **Phones (390px):** grid, list cards and picker, with no sideways
  scrolling.
- **Speed, 5,130 files:** `media.list` takes 35–120 ms for page 1, page 100,
  a search and a month; the table takes 60 ms.
