# Basehim 1.2.126

Four fixes found while building a demo site for the README screenshots. No
database changes.

## A second menu with the same name was a server error

### Why

Menu slugs are unique in the database, and `MenuService::create()` took the
slug from the name without checking it. The installer's starter content
already makes a "Main Menu". Creating another menu with that name failed with a
duplicate-key error, and every caller answered with a 500:

- the REST API (`POST /api/v1/menus`);
- the Menus screen;
- the MCP `save_menu` tool.

### Fix

`MenuService` makes the slug unique itself:

- **A slug taken from the name** gets a number when it is in use:
  `main-menu`, then `main-menu-2`, `main-menu-3`.
- **A slug asked for explicitly** that another menu already uses is refused,
  with a message naming that menu, on create and on update. Keeping a menu's
  own slug is fine.
- **An empty name** is refused too.

How each caller reports a refusal:

- **REST API:** 422 with the message (create and update).
- **Menus screen:** an error notice, and the form stays.
- **MCP `save_menu`:** already caught these errors and answers "… Nothing was
  saved."

## The REST API ignored `published_at`

### Why

`PostController::extractData()` passed `title`, `content`, `status` and a few
other fields to `PostService`, but not `published_at`. An API client could
therefore neither backdate a post nor schedule one: the date was dropped
without an error. `scheduled` was also refused as a status.

### Fix

- `published_at` is passed through, and `null` clears it. It takes ISO 8601
  with an offset (`2026-11-20T09:00:00Z`), or `Y-m-d H:i:s`, read as UTC like
  every stored time.
- A value that is not a date is a 422: *"published_at must be a date and
  time, …"*.
- `status: "scheduled"` is accepted:
  - It needs a `published_at`, sent with the request or already on the post;
    without one it is a 422.
  - A date already past publishes straight away, as in the editor.
- Without publish rights, `scheduled` becomes `pending` (review), the same
  rule as `published`.
- Applies to `/posts` and `/pages`.

## The dashboard's growth badge read "+999%"

### Why

The badge shows growth over the last 30 days against what came before. On a
young site, where only one post is older than 30 days, it capped at "+999%",
which looks like a real figure.

### Fix

Above 999% the badge shows the count: "+10 new".

## The editor sidebar cut off "Blocks (visual)"

### Why

The sidebar's label column was 96 px of its 280 px. That left the controls too
narrow, so the Editor select read "Blocks (visua".

### Fix

The label column is 72 px, enough for the longest label ("Comments"). The
select is now 161 px and shows its whole text.

## Files

- `app/Services/MenuService.php`
- `app/Http/Controllers/Api/MenuController.php`
- `app/Http/Controllers/Admin/MenuController.php`
- `app/Http/Controllers/Api/PostController.php`
- `app/Services/Dashboard/CoreDashboardWidgets.php`
- `admin/assets/css/block-editor.css`
- `index.php` (version)

## Tests

- **`tests/Unit/Release12126Test.php`** (3 tests):
  - the badge text: +120%, exactly +999%, "+10 new", and none;
  - the editor label width;
  - the menu checks in place.
- **`tests/Integration/Release12126Test.php`** (3 tests, over HTTP):
  - **Menus:** two menus named "Twin menu" become `twin-menu` and
    `twin-menu-2`. Asking for a taken slug is a 422, on create and on
    update; keeping a menu's own slug is fine.
  - **Post dates:**
    - `published_at` backdates a post and can change its date on its own;
    - `scheduled` with a future date stays scheduled, and keeps its date when
      only the title changes;
    - `scheduled` without a date is a 422, and so is a date that isn't one.
  - **Roles:** a contributor's `scheduled` becomes `pending`.
- **Full suite with the database (MariaDB):** 775 passed, 0 failed, 1 skipped.
- **In a browser:**
  - The editor's Editor select shows "Blocks (visual)" in full.
  - On the Menus screen, a second "Main Menu" becomes `main-menu-2` with
    "Menu created.", where before it was a server error.
