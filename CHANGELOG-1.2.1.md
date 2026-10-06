# Basehim 1.2.1 — theme development guide

`docs/THEME-DEVELOPMENT.md`: the manifest, templates and the data they receive,
Customizer options, menus, widgets, the front-end helpers, and what happens when
a theme breaks.

Every API claim in it was checked against the code rather than written from
memory — all fifteen named functions exist, the ten option types match
`CustomizerService::TYPES` exactly, the widget-area wrapper keys match what
`WidgetAreaRegistry` reads, and every documented template variable is one a
controller actually passes.

Two things that check corrected:

**A missing template falls back to `404.php`, not `index.php`.** I had written
the opposite. It matters: a theme shipped without `archive.php` serves a
not-found page on every category, and the cause is not obvious from the symptom.

**Widget areas do accept the wrapper markup** — `before_widget`, `after_title`
and the rest. `bootAreas()` passes the whole declaration through, so widgets can
inherit a theme's own styling instead of each inventing its own.

The guide also records the two mistakes that cost the most time while the
Circuits DIY theme was being built, so the next theme author does not repeat
them: `$partial()` rather than `$this->include()`, and `$meta` rather than a
`$pagination` variable that has never existed.

## Stale documentation removed

The README still documented the desktop agent endpoints, the `AgentService`, and
linked `docs/AGENT-API.md` — all removed from core in 1.0.5. Following any of it
led nowhere.

References to AI agents connecting over MCP are untouched; that is a different
thing and still accurate.
