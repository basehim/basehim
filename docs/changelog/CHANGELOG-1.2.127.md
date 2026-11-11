# Basehim 1.2.127

A documentation release: the README gets four screenshots. No code or database
changes; sites that install it see no difference.

## README screenshots

### Why

The README described the dashboard, the installer and the MCP screen in words
only. People deciding whether to try Basehim want to see it first.

### Change

The images are hosted in the basehim.com media library, so the repository and
the release zip carry no binary files:

- **At the top:** the dashboard.
- **Quick start:** the installer's server check, with a line on what setup
  asks for.
- **After it:** the dashboard on a phone, taken from a live Basehim site.
- **MCP server:** Admin → API → MCP Server.

Each image has alt text saying what it shows.

## Files

- `README.md`
- `index.php`: version 1.2.127.

## Tests

- `tests/Unit/Release12127Test.php`: every README screenshot is an https
  address under `www.basehim.com/uploads/` with alt text of at least ten
  characters (the shields.io and CI badges are left out).
