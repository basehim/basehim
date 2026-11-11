# Basehim 1.2.124

Three core fixes from the basehim.com audit:

- only static files are served from `content/`;
- the installer and System → Health use one requirements list;
- MCP tools for menus, themes, theme options and settings.

No database changes.

## Only static files from content/

### Why

`content/` holds theme templates, app source, manifests and notes. The web
server sent any of those that wasn't `.php` as it was. On basehim.com:

- `single.php.bak2` showed the template's source;
- `content/apps/basehim-devtools.zip` downloaded the app;
- `theme.json` and `app.json` were public.

A test on the live server (LiteSpeed) confirmed it: in a folder without the
new rules, `a.php.bak`, `a.zip`, `a.md`, `a.json` and a file with no extension
all answered 200.

### Fix

**`App\Core\Http\PublicFiles`** is the one list of what may be sent from
`content/`: stylesheets, scripts, source maps, images, fonts, audio and
video. The last extension decides, so `header.php.bak` and `style.css.orig`
are refused. Dotfiles are never public.

The same list is used in three places:

- **`content/.htaccess`** (new): deny everything, then allow the list.
  - The root `.htaccess` is never replaced by an update, so the rules live in
    a file updates do deliver.
  - A test checks that its pattern equals `PublicFiles::htaccessPattern()`.
  - On LiteSpeed, in a test folder with this file:
    - `.css`, `.PNG` and `sub/b.js` answered 200;
    - `.php.bak`, `.json`, `.zip`, `.md`, `.php`, `.js.orig` and a file with
      no extension answered 403;
    - missing files still reach Basehim (404), so app assets served through
      PHP keep working.
- **`server.php`** (PHP's built-in server): the same check for files under
  `/content/`.
- **`AppAssetController`**: it used a blocklist of script extensions, so
  `app.js.bak` was sent. It now sends only types in the list, with their
  Content-Type from it, and the old `mimeFor()` is gone.

The README says what nginx needs, with an example `location` block.

## One requirements list

### Why

The installer and System → Health kept separate lists, and they disagreed:

- The installer required `openssl` and `fileinfo`; Health called them
  recommended.
- Neither checked `zip`, which updates (`UpdateService`) and installing
  themes and apps (`ThemeService`, `AppService`) need.
- Health checked `content/uploads` for writing. Uploads are stored in
  `storage/uploads`.
- Health showed a PHP older than 8.1 as amber, while the installer refuses
  it.

### Fix

**`App\Core\Requirements`** holds:

- the minimum PHP (8.1) and the recommended versions (8.2 or 8.3);
- each extension with its label, whether it's required, and what it's for.

| Extension | Required | For |
|---|---|---|
| pdo_mysql | yes | the database |
| mbstring | yes | text in every language |
| openssl | yes | secure connections: updates, the marketplace, email over TLS |
| fileinfo | yes | checking the type of uploads |
| gd | recommended | image sizes |
| curl | recommended | updates, the marketplace, apps that call other sites |
| zip | recommended | installing updates, themes and apps from the dashboard |

`json` isn't listed: it has been part of PHP itself since 8.0 and can't be
turned off.

**Installer:**

- builds its PHP and extension rows from the list;
- shows required items first;
- each missing item says what it's for and how to enable it.

**Health:**

- reads the same list;
- below a missing or amber item, says what it affects;
- checks `storage/uploads` and `content` for writing (`content` is amber,
  as in the installer);
- shows PHP below 8.1 as failing.

The health hint sits under the whole row, so on phones it uses the full
width.

## MCP tools for menus, themes and settings

### Why

An agent could read menus, themes and settings but change them only by
running PHP through the Dev Tools app.

### Tools

| Tool | Scope | Capability | Does |
|---|---|---|---|
| `get_menu` | menus:read | manage_menus | one menu as a tree, by id, slug or location |
| `save_menu` | menus:write | manage_menus | create or change a menu: name, slug, location, and all items at once |
| `list_themes` | themes:read | manage_themes | installed themes and the active one |
| `activate_theme` | themes:write | manage_themes | switch theme; the reply names the previous one |
| `get_theme_options` | themes:read | manage_themes | Customize options with type, allowed values and current value |
| `update_theme_options` | themes:write | manage_themes | change options through `CustomizerService::save()` |
| `update_settings` | settings:write | manage_settings | change one settings group through `SettingsSaver` |

**`list_menus`** now also returns the active theme's locations.

**`save_menu`:**

- Items replace the menu's items in one transaction:
  `MenuService::replaceItems()`.
- The whole tree is checked first:
  - every item needs a title;
  - addresses must be a path, a `#fragment`, `?query`, http(s), `mailto:` or
    `tel:`, so `javascript:` and `data:` are refused;
  - at most 3 levels, the same as the Menus screen;
  - at most 300 items.
- A bad item is named ("Item 2.1 …") and nothing is saved.
- A location the theme doesn't declare is refused, with the list.
- A location holds one menu, as themes read it. Assigning it moves it from
  any other menu, and the reply says so.

**`update_settings`:**

- Only `general`, `reading`, `writing`, `discussion`, `seo` and `appearance`
  can be changed.
- Security, sign-in, email, permalinks (which rewrite `.htaccess`), AI
  access and media types stay with a person in the dashboard.
- It refuses:
  - the administration email, because it can change an account;
  - credentials;
  - values that aren't text, a number or true/false.
- The saver stores only fields the screen has. The reply lists each key as
  saved, unchanged or refused, with the reason.

Changes are written to the activity log.

### Scopes

- New API key scopes `themes:read` and `themes:write`.
- The MCP OAuth connector now offers `settings:write`, `menus:read`,
  `menus:write`, `themes:read` and `themes:write`, each mapped to its
  capability.
- Connectors authorised before this release keep the scopes they were
  given. To use the new tools, reconnect, which shows the consent screen
  again.

### One list of core tools

- `callTool()`'s "known but not permitted" check reads the tool definitions,
  instead of a hand-kept list of 25 names.
- API → MCP Server builds its tool table from the same definitions: the
  name, the first sentence of the description, and the scope.
  - The page said "Twenty-five tools" and was missing tools. It now shows
    the count (32).
  - On phones each summary sits under its tool name, so the table no
    longer scrolls sideways.

## Menus screen and REST fixes

- **Menu locations:**
  - Before, the Menus screen offered a fixed Primary / Footer / Sidebar,
    whatever the theme declared. A theme's own location (basehim.com's
    "Docs Sidebar") couldn't be chosen, and saving such a menu's settings
    cleared its location.
  - Both forms now offer `ThemeService::menuLocations()`: the theme's
    `menu_locations`, falling back to Primary and Footer.
  - A menu whose location the theme doesn't declare keeps it, labelled "(not
    used by the active theme)".
- **REST menu items:**
  - `POST /menus/{id}/items` passed `label` to a service that stores `title`,
    so every call failed. `PUT /menu-items/{id}` ignored `label`.
  - Both take `title` now, with `label` still accepted.
  - `parent_id` must belong to the menu.
  - `target` is `_self` or `_blank`.

## Files

- **New:**
  - `app/Core/Http/PublicFiles.php`
  - `app/Core/Requirements.php`
  - `content/.htaccess`
- **Changed:**
  - `server.php`, `install.php`, `index.php` (version)
  - `app/Http/Controllers/Web/AppAssetController.php`
  - `app/Services/SystemInfoService.php`, `admin/views/system/index.php`
  - `app/Http/Controllers/Api/McpController.php`
  - `app/Services/MenuService.php`, `app/Services/ThemeService.php`
  - `app/Http/Controllers/Admin/MenuController.php`,
    `admin/views/menus/index.php`, `admin/views/menus/edit.php`
  - `app/Http/Controllers/Api/MenuController.php`
  - `app/Services/ApiKeyService.php`, `app/Services/McpOAuthService.php`,
    `app/Http/Controllers/Api/OAuthController.php`
  - `app/Http/Controllers/Admin/ApiController.php`,
    `admin/views/api/mcp.php`
  - `admin/assets/css/tailwind.min.css` (`sm:table-cell`,
    `whitespace-normal`)
  - `README.md`

## Tests

- **`tests/Unit/Release12124Test.php`** (6 tests):
  - what is public, by extension;
  - `.htaccess`, `server.php` and app assets share the list;
  - the requirements list, and the installer and Health reading it;
  - menu trees are validated whole, and URLs are checked;
  - the Menus screen uses the theme's locations, and REST stores the title;
  - the new tools, scopes and capability map, the groups that can't be
    written, and the generated admin table.
- **`tests/Integration/Release12124Test.php`** (5 tests, over HTTP):
  - `save_menu`:
    - builds a tree and moves the location;
    - a `javascript:` item leaves the menu unchanged;
    - an unknown location is refused;
    - an author's token is refused.
  - themes:
    - list, switch and switch back;
    - `../config` is refused;
    - an editor's token is refused.
  - theme options: a valid key saves, an unknown one is skipped.
  - `update_settings`:
    - saves a screen field;
    - reports an unknown key;
    - applies the screen's checks;
    - refuses the admin email;
    - refuses the security group;
    - refuses an editor.
  - REST: an item posted with `label` is stored.
- **Updated:** none. The installer test's "GD (image sizes)" label is kept.
- **Full suite with the database:** 807 passed, 2 failed. The 2 are the known
  BH-047 stylesheet checks (leftover files in this checkout).
- **In a browser** (1280 and 390 wide), with PHP running without `zip`:
  - installer server check;
  - System → Health;
  - Menus list and edit (Docs Sidebar location);
  - API → MCP Server.
- **`server.php`:**
  - `.php.bak2`, `.php`, `.zip`, `.md`, `theme.json`, `app.json`, `.js.bak`
    and `.htaccess` under `content/` answer 403;
  - `.css` and app `.js` answer 200;
  - missing files answer 404.
