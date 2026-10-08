# Basehim 1.2.39

## Marketplace

The marketplace is redesigned as a store: one page with an **Apps** tab and a
**Themes** tab, a grid of large cards, and more about each item at a glance.

### Tabs

- **Apps** and **Themes** sit side by side at the top. Opening the marketplace
  from Apps shows the Apps tab; opening it from Themes shows the Themes tab.
  The tabs are ordinary links, so the sidebar, the address and the Back
  button stay in step.

### Browsing

- **Grid of cards.** Each app card shows its icon large — up to 256px — then
  the name, developer and category, installs, download size, and the
  **Install** / **Update** / **Installed** / **Active** button with the
  version. Theme cards show the screenshot in place of the icon.
- **Featured spotlight.** On the first page, with no search or filter, up to
  two featured items get a large banner with their description, installs,
  version and category, an install button and **Details**.
- **Categories as chips** in a row under the search box, with counts. The
  heading changes to match the search, category or tag being shown, with the
  total number of results.
- **Tags** are now in the details window; clicking one shows everything with
  that tag, and the filter can be cleared from above the grid.
- Placeholder cards show while results load, instead of a spinner.
- Install counts are shortened (1.2K, 3.4M).
- On a phone: two app cards to a row, one theme card, one spotlight banner.

### Details window

- A larger icon, the name, who made it (linked) and the install state.
- A **stats strip**: installs, version, size and category.
- The full description, permissions (apps), tags, the date added, and the
  developer and company.

### Themes

- The theme in use is marked **In use**, and the marketplace no longer
  errors on an empty result set from the hub (`ThemeService::marketplaceBrowse`).

Files changed: `admin/views/partials/marketplace.php` (new — the shared page),
`admin/views/apps/marketplace.php`, `admin/views/themes/marketplace.php`
(both now include it), `admin/views/partials/item-modal.php`,
`app/Services/ThemeService.php`, `index.php`, `install.php`.
