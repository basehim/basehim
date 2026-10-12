# Basehim 1.2.18 — Lighthouse fixes for ai-catalog.json and robots.txt

Two problems PageSpeed Insights reported after 1.2.17.

## "ai-catalog.json schema is invalid"

Lighthouse 13.5 validates `/.well-known/ai-catalog.json` against the
*predecessor* schema of Agentic Resource Discovery (`ai-catalog.schema.json`,
as ARD stood in June 2026 — see Lighthouse issue #17251), not the current
v0.91 entry schema. That schema allows only `displayName`, `identifier`,
`documentationUrl`, `logoUrl` and `trustManifest` inside `host`, and 1.2.17's
`host` also carried `url` — enough to fail the whole manifest.

`host` is now `{ "displayName": "<site title>" }`. `host.identifier` is left
out on purpose: it is meant to be a verifiable identity such as `did:web:…`,
which a site does not publish by default.

Entries — including extra entries from Settings → AI Agents and from apps —
are also made to satisfy that schema's hard rules: `representativeQueries`
trimmed to five and dropped if fewer than two, `metadata` limited to plain
values, an invalid `trustManifest` dropped.

Checked with a validator built from `ai-catalog.schema.json`: 1.2.17's
manifest fails with `host: must NOT have additional property "url"` (the
error Lighthouse reports); 1.2.18's is valid.

## "robots.txt is not valid — Unknown directive: Agentmap"

`Agentmap:` is ARD's robots.txt directive, but Lighthouse's robots.txt check
(and most robots.txt validators) accept only the standard directives. It is
now an option — **Add an Agentmap line for the discovery catalog**, under
Crawler access — and off by default. Agents still find the catalog through
each page's `rel="ard"` / `rel="ai-catalog"` links and the well-known paths.

## Files

    app/Services/AiAccessService.php                 host; entry rules; Agentmap opt-in
    app/Http/Controllers/Admin/SettingController.php saves the new option
    admin/views/settings/ai.php                      the option
    docs/AI-AGENTS.md                                robots.txt note
