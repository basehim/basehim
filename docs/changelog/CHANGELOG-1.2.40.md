# Basehim 1.2.40

## Marketplace

App cards are laid out as rows instead of tiles:

- **Icon on the left, details on the right.** The icon is 120px. Beside it:
  a "Featured" mark when it applies, the name, developer and category,
  installs and download size, and two lines of the description.
- The **Install** / **Update** / **Installed** / **Active** button and the
  version sit under the details, in the same column.
- Up to three cards to a row on wide screens, one on a phone.
- Loading placeholders match the new shape.

Theme cards are unchanged (screenshot on top).

Files changed: `admin/views/partials/marketplace.php`, `index.php`,
`install.php`.
