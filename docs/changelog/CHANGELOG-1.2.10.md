# Basehim 1.2.10 — category widget: layout, dropdown, true counts

## A. Post counts laid out properly

The Categories and Tags widgets printed the count as a bare
`<span class="widget-count">` with no styling, so it ran into the name. No
theme styled it either.

Core now ships default styles for these widgets:

    Cloud Infrastructure                15
    Cloud Security                      15
    Cloud Costs                          6

- Name on the left, count on the right, the count in a small pill with
  tabular digits so the column lines up.
- A long name wraps; its count stays level with the first line.
- Every rule is wrapped in `:where()`, so it has zero specificity: any rule a
  theme writes for the same element wins, and a theme that already styles
  these widgets is unaffected.
- Emitted once per page, however many widgets.

## B. Dropdown

New **Display as** setting: **List** or **Dropdown**. With **Show post
counts**, that gives all four:

1. List
2. List with post counts
3. Dropdown
4. Dropdown with post counts — "Docs (5)"

Choosing an entry in the dropdown opens its archive. The dropdown has a
screen-reader label, and without JavaScript the plain list is shown instead.
The Tags widget gets the same options.

**Existing widgets are unchanged.** Widgets saved before this release have no
Display setting and render as a list, exactly as before; the post-count
setting keeps its meaning.

## Counts now match what visitors find

The widget showed the term's stored count, which includes drafts, private and
trashed posts. On a live site, "Blog" showed 2 with 1 published post, and
"Uncategorized" 4 with 1. The widget now counts published posts, in one
grouped query per widget, and **Hide ones with no posts** hides terms whose
only posts are unpublished — their archives would be empty.

## 1.2.2 files shipped again

At least one site was found running Basehim 1.2.9 without the files the 1.2.2
patch delivered — most likely it was deployed or restored from a copy made
before 1.2.2. The 1.2.2 Customizer fixes were therefore missing there.

This release includes those files again, unchanged from 1.2.2:

    app/Services/CustomizerService.php
    admin/views/customizer/index.php
    admin/assets/css/customizer.css

(`app/Core/Application.php`, the fourth 1.2.2 file, is in this release anyway,
built on the 1.2.2 version.) A site that already has them receives identical
files; a site that lacks them is repaired.

## Verified on a live install

- All four display modes, and a widget saved before this release.
- Counts against the database: published posts only.
- Dropdown in Chromium: choosing an entry opens its archive; the placeholder
  and unrelated selects do nothing.
- Layout in Chromium, in a theme that styles its sidebar and in one that
  styles nothing: names at the left edge, counts flush right, equal widths.
- Styles and script emitted once per page.

## Files

    app/Core/Application.php                   widget: display option, counts, default styles
    app/Services/CustomizerService.php         1.2.2, re-shipped
    admin/views/customizer/index.php           1.2.2, re-shipped
    admin/assets/css/customizer.css            1.2.2, re-shipped
