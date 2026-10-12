# Basehim 1.2.17 — AI agent accessibility

Makes a site's public pages easy for AI agents to find, read and use, and
gives the owner one place to control it: **Settings → AI Agents**. Built to
pass the checks in Lighthouse's Agentic Browsing category (PageSpeed
Insights): llms.txt, the Agentic Resource Discovery catalog, and WebMCP form
coverage, registered tools and schema validity.

## What the site now publishes

| Address | What it is |
|---|---|
| `/robots.txt` | Previously a 404: the stored rules were never served. Now your rules, one group per AI crawler class you block, then `Sitemap:` and `Agentmap:` |
| `/llms.txt` | The site for language models (llmstxt.org): one H1, a summary, H2 sections of links — pages, latest articles, topics — and an Optional section |
| `/llms-full.txt` | Pages and recent articles in full, as plain text |
| `/.well-known/ard.json` | Agentic Resource Discovery manifest; the same at `/.well-known/ai-catalog.json`, the path Lighthouse 13.5 checks |
| `/ai/recent-articles.json` | Backs the `list_recent_articles` tool |

Every page (through `bh_head()`): `<link rel="ard">`, `<link rel="ai-catalog">`,
a link to llms.txt, the WebMCP origin-trial token when set; on the home page
schema.org `WebSite` data with a `SearchAction`.

## Crawler access

Three independent Allow/Block controls, because AI companies run separate
crawlers for separate jobs:

- **AI training crawlers** — GPTBot, ClaudeBot, anthropic-ai, Google-Extended,
  Applebot-Extended, CCBot, Bytespider, meta-externalagent, Amazonbot,
  cohere-training-data-crawler, Diffbot
- **AI search crawlers** — OAI-SearchBot, Claude-SearchBot, PerplexityBot,
  DuckAssistBot, YouBot
- **AI assistants browsing for a person** — ChatGPT-User, Claude-User,
  Perplexity-User, MistralAI-User, meta-externalfetcher

Crawler rules apply even with agent features off: blocking is a protection.

## WebMCP

- The comment form is a declarative tool (`post_comment`), not auto-submitted:
  the visitor reviews and sends it. Each field is described for agents; the
  anti-spam trap says it must stay empty.
- "Describe every form for agents" gives every other form a unique
  `toolname`/`tooldescription` and each named field a `toolparamdescription`
  (from its label, or "Words to search for" in an unlabelled search box).
  Search forms get `toolautosubmit`; others are filled in for the visitor.
  Forms with a password field are never exposed.
- Read-only tools registered through `document.modelContext`
  (`navigator.modelContext` in older builds): `list_recent_articles`,
  `list_topics`.
- A field for Chrome's WebMCP origin-trial token.

## For themes and apps

`bh_webmcp_form($name, $description, ['autosubmit' => bool])`,
`bh_webmcp_param($description)`, `bh_webmcp_tool([...])`, `bh_ai_on($key)`,
`bh_ai()`, and filters `ai.llms_sections`, `ai.catalog_entries`, `ai.robots`,
`ai.tools`. Invalid tools and catalog entries are left out rather than breaking
the rest. See docs/AI-AGENTS.md.

## Checked on basehim.com

- ARD manifest: 5 entries, no schema errors or warnings against
  ard-entry.schema.json (identifier pattern, exactly one of url/data, URI and
  date-time formats, 2–5 representative queries); every URL resolves;
  ai-catalog.json identical.
- llms.txt: one H1 as the first line, summary blockquote, 4 H2 sections,
  17 links, all resolving.
- WebMCP in Chromium: every form annotated, unique tool names, every required
  field named, every named field described; both tools registered with valid
  schemas and executed.
- Settings: saving, blocking a crawler class, and rejecting invalid JSON.
- Core's existing device-agent AgentService is untouched.

## Files

    app/Services/AiAccessService.php                 new
    app/Http/Controllers/Web/AiAccessController.php  new
    admin/views/settings/ai.php                      new — Settings → AI Agents
    docs/AI-AGENTS.md                                new
    bootstrap.php                                    helpers; bh_head/bh_footer; comment form annotated
    app/Http/Controllers/Admin/SettingController.php the tab and its saver
    admin/views/settings/_nav.php                    the tab
    routes/web.php, routes/admin.php                 routes
