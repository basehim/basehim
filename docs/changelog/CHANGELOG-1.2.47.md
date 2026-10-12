# Basehim 1.2.47

The block editor saves drafts and handles tags without reloading. Every
hand-written AJAX call left in the admin now goes through the AJAX gateway.
It also fixes a **security hole**: three admin areas had no permission rule.

## Security: admin areas with no permission rule

Any signed-in user, an author or a contributor included, could use three
admin areas, because the area map had no rule for them:

| Area | What they could do | Now needs |
|---|---|---|
| `/admin/customize` | Change the site title, tagline, logo, favicon, **custom CSS** and widget areas, live on the public site | `manage_themes` |
| `/admin/terms/{id}` | Rename or delete any category or tag | `manage_taxonomies` |
| `/admin/menu-items/{id}/delete` | Delete menu items | `manage_menus` |

A new test reads `routes/admin.php` and fails if any signed-in admin route has
no rule and is not listed as open on purpose (dashboard, profile, logout), so
this cannot happen again unnoticed.

## Block editor

**Save draft without leaving the page.** Save draft (and Ctrl+S on a draft)
saves through the gateway (`posts.save_draft`). A loader appears next to the
button ("Saving draft…", then a green "Draft saved"), and "Saved 11:47 am"
stays in the header.
- On a new post, the first save creates it. The address becomes the post's
  edit URL, Publish then updates that same post, and a Preview link appears.
- A double click saves once (idempotency key).
- It only ever leaves a post as a draft or pending review. A published,
  scheduled or private post is changed with Update, never by a draft save.
- If an app has added its own named field to the editor form, or the post
  is over 1 MB, the form is posted as before, so nothing is lost.

**Tags.** The tag box is now chips with typeahead:
- Typing suggests existing tags, starting-with matches first, each with how
  many posts use it. Enter or a comma adds the highlighted suggestion or the
  typed name. Pasting "a, b, c" adds three.
- On a saved post, adding or removing an existing tag is saved at once.
- A name that is not a tag yet shows as **(new)** and is **created when the
  post is published**. Until then it is kept with the post, so drafts that
  are abandoned leave no stray tags. A contributor's new tags are created
  when an editor publishes the post. A name that matches an existing tag
  in any case ("arduino") uses that tag.
- **Most used** and **Recently used** tags are listed under the box, one
  click to add. "Recently used" means your own recent posts, or everyone's
  if you have none yet.
- Names with no Latin letters (Urdu, Arabic) are matched by name, not by
  their empty slug, so they no longer collide.

**One loader everywhere.** The "Loading original…" pill from the media
library is now a shared component, `bhLoader()` / `.bh-loader`
(`admin/assets/css/ui.css`, docs/ADMIN-UI.md). The editor and the media
library use it. Use it wherever the admin waits on the server.

## AJAX: the remaining hand-written calls moved to the gateway

| Screen | Was | Now |
|---|---|---|
| Editor, switching visual ↔ HTML / Markdown | `POST /admin/posts/editor/render` | `posts.render` |
| Editor, Patterns tab | `GET /admin/posts/editor/templates` | `posts.templates` |
| Editor, widget block preview | `POST /admin/widgets/render` | `widgets.render` |
| Media library, picker, avatar editor | `GET /admin/media/json` | `media.list` |
| Media library delete (one / bulk) | `/admin/media/{id}/delete`, `/bulk-delete` | `media.delete` |
| Settings → Media → Regenerate | `/admin/settings/media/regenerate-batch` | `media.regenerate_batch` |
| Customizer save / preview | `/admin/customize/save`, `/draft` | `customizer.save`, `customizer.draft` |
| Menu builder | `/admin/menus/{id}/items…` | `menus.items_add`, `.item_update`, `.item_remove`, `.reorder` |
| User activity | `/admin/users/{id}/activity.json` | `users.activity` |
| System → Logs | `/admin/system/log` | `system.log` |
| Comments badge (every page) | `/admin/comments/pending.json` | `comments.pending_count` |

The old routes stay for anything else that calls them. Three kinds of call
were not moved, on purpose:
- uploads, because the gateway takes JSON only;
- the updater;
- the marketplace installers.

The updater and the installers replace files while they run, so they keep the
routes they were tested with.

**New gateway option `area`.** An action names the admin screen it serves
(`'area' => '/admin/media'`). The user then also needs whatever that area
requires, including rules a site changed with the `admin.area_policy` filter.
All the actions above use it.

Answers whose text is not valid UTF-8 (an old log line) now carry U+FFFD
instead of failing with a 500.

## Fixed

| # | Problem | Fix |
|---|---|---|
| 50 | The widget block preview used `/admin/widgets/render`, which needs `manage_apps`, so authors and editors only ever saw "Preview failed" | `widgets.render` needs `edit_posts` |
| 51 | Every editor save rewrote all SEO columns, erasing an og:title, og:description or social image set any other way | Only fields that were sent are written |
| 52 | A category or tag deleted in another tab made the next save of a post fail with a 500 | Unknown term IDs are ignored |
| 53 | The menu builder did not check that an item belonged to the menu, and accepted `javascript:` and `data:` links (also `java\tscript:`) | Both checked, in the gateway actions and the old routes |
| 54 | Media library deletes used the browser's own `confirm()` | The admin's dialog |

## For developers

- `PostEditorService` holds the editor's permission rules and the pending-tag
  logic for the form and the AJAX actions alike.
- `AdminAreaPolicy::requiredFor($path)` returns the capability an admin path
  needs.
- The editor emits `saved` (`BasehimEditor.on('saved', fn)`) and
  `bh:post-saved` on `document` after each AJAX save. See APP-DEVELOPMENT.md.
- Docs: APP-AJAX.md (the `area` option, free-form objects, every core action),
  ADMIN-UI.md (Loader), APP-DEVELOPMENT.md (Save draft and app panels).

No database changes.
