# Basehim 1.1.4 — Circuits DIY renders

A fix for 1.1.3. The theme's templates called `$this->include()`, which is the
admin view engine's method. Inside a theme template `$this` is the ThemeService,
which has no such method, so every page threw:

    Call to undefined method App\Services\ThemeService::include()

Themes are given a `$partial()` closure instead:

    <?php $partial('header'); ?>
    <?php $partial('card', ['post' => $post]); ?>

It merges the template's own data automatically, so only an override needs
passing. Both bundled themes already used it; I wrote the new theme against the
admin's convention by mistake and did not render it before shipping.

**All six templates are now rendered and checked**, rather than only
syntax-checked: home, single, archive, search, page and 404. The home page was
verified to contain its cards, titles, authors, formatted view counts, excerpts,
pagination, footer and stylesheet — and the pagination window was checked at page
150 of 293, where it correctly offers 1, 149, 150, 151 and 293 with two gaps.

The 1.0.6 theme isolation is what turned this into a legible message naming the
file and line rather than a blank page.
