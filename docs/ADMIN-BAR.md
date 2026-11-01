# The frontend admin bar

Since 1.2.96. Signed-in people who manage a site get a bar on its public
pages. From the bar they can edit the page they are on, create content,
search everything they may edit, see what needs attention, and use whatever
installed apps add. Visitors never see it. A visitor's page loads no admin
bar code at all, and no session is started for them.

It is core's, not an app's, and it works with every theme. The bar is added
to the finished page, the same way core adds SEO tags and the preview badge,
so a theme needs nothing to support it.

- [Display modes](#display-modes)
- [What's in it](#whats-in-it)
- [Settings](#settings)
- [Each user's choices](#each-users-choices)
- [For apps: `$this->api()->adminBar()`](#for-apps)
- [Components](#components)
- [Conditions](#conditions)
- [The page's context](#the-pages-context)
- [Panels](#panels)
- [Lifecycle and hooks](#lifecycle-and-hooks)
- [For themes](#for-themes)
- [Security](#security)
- [Performance](#performance)
- [Limits](#limits)

## Display modes

| Mode | What it looks like |
|---|---|
| **Top bar** (default) | A slim bar fixed across the top. Menus on the left (the site, this page, New, apps), tools on the right (apps' status menus, search, notifications, display, account). The page moves down by the bar's height. A theme's fixed or sticky header at the top is moved down with it. |
| **Floating button** | A round button in a corner that opens a panel with the same menus, stacked. It takes no room on the page. It can be dragged to another corner, which is remembered. |
| **Off** | Nothing at all: no markup, styles or scripts. Off can be chosen for the whole site, for some roles, or by a user for themselves. |

On phones (782 px wide or narrower) the top bar shows icons only. Its
dropdowns open as full-width sheets with 44 px rows. Settings → Admin bar can
switch phones to the floating button, or show nothing there.

A user can also tuck the top bar away (a small tab stays at the top), or hide
it until the next page.

## What's in it

Core's sections are registered through the same API as apps' components. Each
link is shown only to users who can open the screen it leads to. This is
checked with the admin's own rules (`AdminAreaPolicy`, including a site's
`admin.area_policy` filter) and, for posts, `PostEditorService::canEdit()`.

| Section | Contents |
|---|---|
| **Site** (the site's name) | Dashboard, Posts, Pages, Media, Comments (with the pending count), Menus, Widgets, Customize, Themes, Apps, Users, Settings, Updates (with the count), System |
| **This page** | Depends on the page; see below |
| **New** | Post, page, each app content type the user can create, category, media, user |
| **Search** | Quick search (also <kbd>Ctrl</kbd>+<kbd>K</kbd> / <kbd>⌘</kbd>+<kbd>K</kbd>): posts, pages and app content types the user may edit, media, users, categories and tags, and apps' sources |
| **Notifications** | Core and app updates, comments waiting for approval, failing scheduled tasks, security warnings (debug mode or no HTTPS on a public site), and apps' notices |
| **Display** | Top bar or floating button, button position, tuck away, which optional sections show, turn off, hide until the next page |
| **Account** | Profile, sign-in and security, dashboard, sign out |

**This page's menu**:

| Page | Menu |
|---|---|
| A post, page or item of an app's content type | **Edit post** (or page, event…): edit in the editor, **Quick edit** (title, address, status, author, parent, order, comments, using the same checks and conflict protection as the admin lists), the featured image, the type's categories, tags and taxonomies, its comments, all items, new item, and a **Details** panel (status, author, dates, views, comments, words and reading time, address, ID). Someone who can't edit it sees the details only. |
| A category, tag or app taxonomy term | **Edit category**, all categories, its posts in the admin, and a Details panel |
| An app content type's archive | Its admin list, and New |
| An author's page | Edit profile (your own) or Edit user |
| The home page | Customize, what the home page shows (Settings → Reading), menus and widgets. A static home page also gets the page's own menu. |
| Search results | What was searched and how many results, and the same search in the admin's Posts and Pages |
| A missing page (404) | Why: the address, and whether something has it but isn't published (a draft, or in the trash), with a link to edit it or to create a page |
| An app's page | The app's admin screen and its logs |

Apps add their own menus, and items and panels in core's menus.

## Settings

Settings → Admin bar (group `toolbar`). Every value is checked against its
list of options, on save and when read.

| Setting | Options | Default |
|---|---|---|
| Show the admin bar on the site | on, off | on |
| How it is shown | top bar, floating button | top bar |
| Floating button position | top left, top right, bottom left, bottom right | bottom right |
| On phones | the same, a floating button, nothing | the same |
| Who sees it | roles; none ticked = everyone who can manage something | none ticked |
| This page's menu, Quick search, Notifications, Keyboard shortcut | on, off | on |
| Colours | dark, light, follow the device | dark |
| Spacing | comfortable, compact | comfortable |
| Menu labels | icons and labels, icons only | icons and labels |
| Show the site's name, Show the user's picture | on, off | on |
| Top bar height | 32–48 px | 40 px |
| Floating button size | 40–56 px | 48 px |
| Panel width | 280–440 px | 360 px |
| Dropdown alignment | under the item, right-aligned | under the item |
| Open menus on | click; hover (mouse) and click (touch) | click |
| Hide the top bar | never; after 5, 10 or 30 seconds without use | never |
| Remember a tucked-away bar | on, off | on |
| Users may choose | display mode, button position, optional sections | all three |

"Everyone who can manage something" means anyone holding any of
`edit_posts`, `edit_pages`, `upload_media`, `moderate_comments`,
`manage_taxonomies`, `manage_menus`, `manage_users`, `manage_themes`,
`manage_apps`, `manage_settings` or `manage_options`, through their role, a
custom role or a per-user grant. On a default install that is every role
except Subscriber.

Menus always close with <kbd>Escape</kbd> or a click elsewhere, and always
work with the keyboard. These aren't settings.

## Each user's choices

A user's choices are stored in `users.meta` under `admin_bar`:

- display mode: top bar, floating button or off
- button position
- whether the top bar is tucked away
- hidden optional sections: search, notifications, and apps' menus

They can be changed from the Display menu, and the mode also from the
profile page. The profile is how a user turns the bar back on.

Only what the site allows under "Users may choose" is used or accepted.

## For apps

```php
public function boot(): void
{
    $bar = $this->api()->adminBar();

    // A menu of your own
    $bar->menu(['id' => 'analytics', 'label' => 'Analytics', 'icon' => 'chart-bar', 'capability' => 'edit_posts', 'priority' => 60]);
    $bar->item(['id' => 'report', 'parent' => 'analytics', 'label' => 'Full report', 'href' => '/admin/my-analytics']);
    $bar->panel(['id' => 'today', 'parent' => 'analytics', 'label' => 'This page today', 'context' => ['single'],
        'data' => fn($ctx, $user) => ['rows' => [['label' => 'Views', 'value' => $this->views($ctx->contentId())]]]]);
    $bar->indicator(['id' => 'live', 'parent' => 'analytics', 'type' => 'count', 'value' => fn() => $this->visitorsNow()]);

    // An item and a panel in core's page menu, on posts and pages
    $bar->item(['id' => 'seo', 'parent' => 'context', 'label' => 'Edit SEO', 'context' => ['post', 'page'],
        'capability' => 'manage_seo', 'href' => '/admin/my-seo']);

    // A notice for the bell, and a quick-search source
    $bar->notifications(['id' => 'alerts', 'capability' => 'manage_options', 'items' => fn($user) => $this->alerts()]);
    $bar->search(['id' => 'orders', 'label' => 'Orders', 'capability' => 'access_app:my-shop',
        'search' => fn(string $q, array $user, int $limit) => $this->findOrders($q, $limit)]);
}
```

- **Ids** are your app's. `analytics` becomes `{your-slug}.analytics`, so two
  apps can't clash. An id another owner already holds is refused.
- **Parents** without a dot are yours, unless they are one of core's menus:
  `site`, `context` (the page's own menu), `new`, `search`,
  `notifications`, `user`, `display`. A parent with a dot names another
  app's component (`other-app.menu`).
- **Return values**: every method returns `false` when a definition is
  refused, and the reason goes to your app's log.
- **Callables** run on the server, only for users who may see the
  component, and get the page's context and the user. A callable that throws
  hides that component for that request and is logged once. The rest of the
  bar is unaffected.
- **Cleanup**: registrations last for the request. When your app is switched
  off they are gone, and when it fails to boot core takes them back
  (`forgetOwner`). `remove($id)` takes one away.

Four complete example apps are in
[docs/examples/admin-bar](examples/admin-bar): **page-stats** (a menu with a
live panel, a list and a badge), **seo-check** (a score panel and an item in
core's page menu), **comment-queue** (a count badge, a panel and a notice)
and **site-health** (a status badge on the right, a panel, notices and a
search source).

## Components

Common keys:

| Key | Meaning |
|---|---|
| `id` | Required. Lower-case letters, digits, `-`, `_` |
| `priority` | Order within its place; lower first (default 50). Core's menus use 0 (site), 10 (this page), 20 (New); on the right 10–40 |
| `capability` | A capability, or a list (all required) |
| `roles` | Only these roles |
| `context` | Only on these pages; see [Conditions](#conditions) |
| `visible` | `fn(AdminBarContext $ctx, array $user): bool`, for anything else, such as one of your settings |

**menu** — a top-level entry.

| Key | |
|---|---|
| `label` | Required |
| `icon` | A Heroicons name |
| `href` | Where it goes when it has nothing under it |
| `region` | `main` (left, default) or `end` (right, for status menus) |
| `title` | A tooltip |
| `items` | `fn($ctx, $user): list` of item definitions, built when the bar is drawn (at most 50) |

A menu with no link, no items and no panels is not shown. Apps' menus can
be hidden by each user from the Display menu.

**item** — an entry in a dropdown. Keys: `parent` (required), `label`
(required), `icon`, `href`, `target` (`_blank`), `meta` (a second line),
`group` (a heading; items with the same group are listed together), `title`,
`items`. An item with items or panels under it opens in place, up to three
levels deep. Links are paths on the site (`/admin/...`) or http(s)
addresses; anything else is refused.

**panel** — live information at the top of a dropdown, loaded when the
dropdown opens, with a loading state, an error state and a Refresh button.
Keys: `parent`, `label` (its heading), `data` (required:
`fn($ctx, $user): array`, see [Panels](#panels)), and `refresh` (false
hides the button).

**indicator** — a badge on a menu or item.

| Key | |
|---|---|
| `parent` | Required: the menu or item |
| `type` | `count` (a number; 0 shows nothing), `status` (a word with a colour) or `text` |
| `value` | Required: `fn($ctx, $user)` returning the value, or `['value' => …, 'tone' => …, 'label' => …]` |
| `tone` | `neutral`, `info`, `success`, `warning` or `danger` (counts default to danger) |
| `label` | Read out after the value by screen readers ("5 comments waiting") |
| `lazy` | `true` (default): loaded after the page, in one request for all badges. `false`: computed with the page |

**notifications** — `items` is required: `fn(array $user, AdminBarContext $ctx)`
returning a list of `['id', 'title', 'text', 'tone', 'url', 'time']`. Notices
are sorted most urgent first, with at most 30 shown. The bell's count covers
notices with tone info, warning or danger.

**search** — `label` is required (it heads the group), plus `icon`, and
`search`: `fn(string $query, array $user, int $limit)` returning a list of
`['title', 'url', 'meta', 'icon']`. Queries are at least two characters.
Return only what this user may open: the bar shows what you return.

## Conditions

`context` lists the pages a component appears on. Any match shows it:

| Condition | Matches |
|---|---|
| `single` | any post, page or item |
| `post`, `page`, `event`… | an item of that content type |
| `home` | the home page (a static one too) |
| `archive` | any archive; `archive:author`, `archive:event` (a type's archive) |
| `taxonomy:category` | a term page of that taxonomy |
| `search` | search results |
| `404` | a missing page |
| `app` | an app's page; `app:shop` for one app's pages and routes |
| `route:shop.*` | routes by name (`*` at the end matches a prefix) |

Conditions are checked on the server for every request and every AJAX call,
together with `capability`, `roles` and `visible`.

## The page's context

Callables get an `App\Core\AdminBar\AdminBarContext`. It is built from what
core rendered (the post, term, author or search it loaded), never from the
address:

| Method | |
|---|---|
| `type()` | `single`, `home`, `archive`, `search`, `404`, `app`, `route` |
| `contentType()`, `contentId()` | an item's type and id |
| `postStatus()`, `authorId()`, `isPreview()` | |
| `archiveKind()` | `taxonomy`, `post_type`, `author` |
| `taxonomy()`, `termId()` | a term page |
| `search()` | the search query |
| `isHome()`, `path()`, `status()` | |
| `route()`, `app()` | the matched route's name (or pattern) and its app |
| `matches([...])` | the conditions above |
| `get($key)`, `toArray()` | everything |

Read the post or term again with its id if you need more. Don't take
anything else from the request.

## Panels

A panel's `data` returns any of these (anything else is dropped; every
string is shown as text):

```php
return [
    'text'    => 'A paragraph.',
    'score'   => ['value' => 82, 'max' => 100, 'label' => 'SEO score', 'tone' => 'success'],
    'rows'    => [['label' => 'Views', 'value' => '1,204', 'tone' => '', 'url' => '/admin/...']],
    'list'    => [['title' => 'Title length is good', 'meta' => '…', 'tone' => 'success', 'url' => '…']],
    'actions' => [['label' => 'Full report', 'url' => '/admin/my-analytics']],
    'empty'   => 'Shown when there is nothing else.',
];
```

There is no HTML: panels look the same in every theme, and an app's data
can't put markup into the page.

## Lifecycle and hooks

| Hook | When |
|---|---|
| `admin_bar.register` (action, `$ctx`, `$user`) | Once per request, after core's sections, when the bar is first built. For components that depend on the page or the user: `$this->addAction('admin_bar.register', fn($ctx, $user) => …, 10, 2)` |
| `admin_bar.show` (filter, `true`, `$ctx`, `$user`) | Return `false` to leave the bar out of a page |

A page or route can also opt out with the response header
`X-Basehim-Admin-Bar: off`. Core removes the header before sending.

In the browser, `window.BasehimAdminBar` has `openSearch()`, `open(id)`,
`close()` and `refresh()` (reloads the visible panels).

## For themes

A theme needs nothing. The bar adds itself before `</body>`, gives
`<html>` the class `has-bh-admin-bar`, and in top-bar mode sets
`--bhab-push` (the bar's height) on `<html>`. It also moves a fixed or
sticky header at the very top of the page down by that height. A theme that
places such a header differently can use the class and the variable.

From `boot.php`, `bh_admin_bar()` gives the same API as apps, with ids under
`theme.`:

```php
bh_admin_bar()->item(['id' => 'hero', 'parent' => 'context', 'label' => 'Edit the hero', 'context' => ['home'],
                      'capability' => 'manage_themes', 'href' => '/admin/customize']);
```

The bar's styles are scoped to `#bh-admin-bar` and reset the few things
themes commonly set on buttons, links and lists. It sits above the page at
`z-index: 2147482000`.

## Security

- **Visibility is not permission.** Every component is checked on the
  server for each request. Every action a link leads to is checked again by
  its own screen or AJAX action, so a user only sees what they could do
  anyway.
- **Signed context.** The AJAX actions (`admin.bar_panel`,
  `admin.bar_indicators`, `admin.bar_notifications`, `admin.bar_search`,
  `admin.bar_url`, `admin.bar_prefs`) take the page's context as a
  descriptor signed with a key from the user's session. A changed
  descriptor, or one from another session, is refused (`CONTEXT_INVALID`).
  The post or term is read again from the database. A request for a
  component the user can't see on that page is answered as if it didn't
  exist (404), and its callables never run.
- **CSRF**: POSTs (`admin.bar_prefs`, Quick edit) carry the session's token
  through `Basehim.ajax`, which fetches it when needed. No token is printed
  in the page. Sign out posts the token too.
- **Escaping**: every label, link and attribute is escaped on the server,
  and the script writes text with `textContent`. Links must be site paths or
  http(s) addresses. Panel data is plain text in a fixed shape.
- **No actions for apps' scripts**: `action` (Quick edit, sign out, display
  choices) is core's. An app's component is a link, a panel or a badge, and
  anything it changes goes through the app's own AJAX actions with their own
  checks.
- **Private pages**: a page with a bar is sent with
  `Cache-Control: private, no-store` and `Vary: Cookie`, so no shared cache
  keeps a copy with someone's bar in it.
- **CSP**: the bar's scripts get the page's nonce like every other script
  (Settings → Security).

## Performance

- **Visitors**: one `isset()` on the session cookie, then nothing: no
  session, no query, no markup, no assets.
- **Signed-in users**: one query for the user (the admin makes the same
  one), the bar's own checks, and one stylesheet and one script (about 20 KB
  and 30 KB before compression), plus the AJAX client. They load at the end
  of the page and are cached by the browser.
- **After the page**: at idle, one request for all lazy badges and one for
  notices. Notices are kept for two minutes in the tab's `sessionStorage`,
  so moving between pages doesn't ask again.
- **On use only**: panels load when their dropdown opens, once per page
  (Refresh asks again), and search runs as the user types (debounced).
- **No polling.**
- **Server side**: core's search goes through BH Search, so it is fast at any
  size. Badges and notices are never cached across users.

## Limits

- No **revisions** or **duplicate**: Basehim doesn't have them yet, so the
  bar doesn't offer them.
- **Quick edit** changes the title, address, status, author, parent, order
  and comments. Categories, tags and the publish date are changed in the
  editor; the dialog links there.
- **Analytics** in core are the view counter only. Richer numbers are an
  app's job (see the page-stats example).
- **Dragging** the floating button snaps it to the nearest corner. Free
  positions aren't kept.
- Apps' components can't run code in the browser. They link, show panels
  and badges, and use their own AJAX actions on their own screens.
- **Post pages** still start a session for visitors, for the comment form's
  token (BH-083). Other public pages don't.
