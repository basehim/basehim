# Basehim 1.2.94

2026-10-31 · Patch release · installs on 1.2.93

Content types registered by apps now work end to end; apps can add
taxonomies and custom fields; the editor gets a permalink line and a preview
that works on phones and for unsaved posts. No database changes:
taxonomies use the existing `{taxonomies}` table, fields the existing
`{post_meta}` table.

## Why

`registerPostType()` existed since 1.x, but only the admin's create and edit
screens worked. An audit with a probe app on 1.2.93 found:

- **BH-074:** the type's admin list used the Posts table, so Events listed
  posts; new events could not be found again. Edit links pointed to
  `/admin/events/…`.
- **BH-075:** items were linked at `/{slug}`, which only resolves pages and
  posts: every link (editor, search results, sitemap) was a 404. The sitemap
  listed every published row of every type, non-public types included.
- **BH-076:** no custom fields. `post_meta` existed but nothing could
  declare, edit, save or read fields.
- **BH-077:** `supports` and `taxonomies` were stored and ignored; apps
  couldn't register taxonomies. `PostTypeRegistry::all()` read a config key
  that doesn't exist (`cms.post_types`), so it never listed post and page.
- **BH-078:** no template hierarchy, archive or query helper for themes; REST
  and MCP covered posts and pages only.
- **BH-079:** on phones the editor had no Preview (hidden by
  `bhe-hide-mobile`, not in the ⋮ menu) and the slug sat behind an unlabeled
  icon; on any device a new post had no Preview until saved.

## Content types

- `PostTypeRegistry`: `rewrite` (URL prefix; default key + "s"; `false` for
  none), `has_archive`, `per_page`. Prefixes that are core paths or another
  type's are refused (`rejected()`). New `byPrefix()`, `hasUrls()`,
  `supports()`. Types that don't list `supports` keep every panel, as before.
  `all()` reads `cms.content_types`.
- `Helpers::postUrl()`: `/{rewrite}/{slug}` for app types, `''` for types
  without public pages (callers leave the link out).
- `Router::content()`: core GET routes tried after apps' routes and before
  the `/{slug}` catch-alls, so an app serving the same paths keeps them.
  `routes/web.php` registers `/{rewrite}` and `/{rewrite}/{slug}` for each
  public type, and `/{rewrite}/{term}` for each public app taxonomy.
- `Web\ContentTypeController`: item (preview for editors, view count,
  `post.content` filter, comments when supported) and archive.
- `RendersTheme::renderTheme()` takes template candidates
  (`['single-event', 'single']`), sets the current post for `bh_field()`, and
  passes `$fields`. Preview permission uses the type's capability family.
- Sitemap and site search: only post, page and public types with a prefix.
- Admin list: a `content.type.{key}` table per type (`TypeTableProvider`,
  `TypeQuickEdit`; `TableActions` passes the table key to editors that ask
  with `forTable()`), with columns following `supports` and taxonomies; title
  and row links fixed.
- Comments are refused on app types that don't support them.

## Taxonomies

`TaxonomyRegistry` (new): core's from config plus apps' via
`App::registerTaxonomy()`. A type's `taxonomies` list attaches existing
ones. The `{taxonomies}` row is created or updated on first registration
(cached per database: `Database::identity()`, new). Sidebar entries under
each type; term pages at `/{rewrite}/{term}` (`taxonomy-{key}.php`, else
`archive.php`). `PostEditorService::hasCategories()` / `hasTags()` read the
registry; new `termOptions()`, `termIdsIn()`.

## Custom fields

`App::registerFields($type, $fields, $group)` → `Fields\FieldRegistry`
(keys per type, owned by one app, `_` reserved). Fields use
`Core\Widgets\Schema`. `Fields\FieldService` reads (`values()`, `get()`,
batch `prime()`) and saves (`save()`, invalid values keep their stored value,
partial saves touch only given keys), `missingRequired()`.
`Fields\FieldForm` draws the editor panels (`fields[key]`, `_fields` marker).
Saved by the editor form (`PostController::saveFields()`, error flash names
the fields), the AJAX draft save (`fields` JSON input, `field_errors` in the
answer), and `api()->content($type)->saveFields()` / `fields()`.

## Themes, REST, MCP

Helpers in `bootstrap.php`: `bh_field()`, `bh_fields()`, `bh_field_rows()`,
`bh_url()`, `bh_query()`, `bh_terms()`. The default theme shows
`bh_field_rows()` on item pages and labels type and taxonomy archives.
`GET /api/v1/content/{type}` and `/{slug}` (public types, read-only, with
`url` and public `fields`); `fields` in `GET /api/v1/posts|pages/{slug}` and
MCP `get_post` / `get_page`.

## Editor

`admin/views/posts/edit.php`: panels follow `supports` (featured image,
excerpt, discussion) and the type's public flag (SEO); category, tag and app
taxonomy panels per type; field panels; a permalink line under the title
(address, inline slug editing kept in step with the sidebar Slug, Preview /
View). `block-editor.js`: Preview / View in the ⋮ menu; `openPreview()` saves
a draft first when there are unsaved changes or no id (the tab is opened in
the click, so popup blockers allow it); `saveDraftAjax()` returns a promise;
`BasehimEditor.saveDraft()` / `preview()`. CSS in `block-editor.css`.

## App base class

Two protected methods: `registerTaxonomy()`, `registerFields()`. Searched
538 app files on three sites (Cloudhim, pngculture, staging): no app declares
either name.

## Compatibility

- A page whose slug equals a type's prefix is now at `/page/{slug}` (as for
  any route).
- An app's own routes win over the new type routes; an app serving its
  type's URLs itself can set `'rewrite' => false`.
- Custom-type URLs changed from `/{slug}` (404) to `/{prefix}/{slug}`.
- Sitemap: templates and non-public types are no longer listed.
