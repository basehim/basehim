# AI agents

Basehim makes a site's public pages easy for AI agents to find, read and use.
Everything is controlled from **Settings → AI Agents**, and themes and apps can
take part through a few helpers.

## What core publishes

| Address | What it is |
|---|---|
| `/robots.txt` | Your rules, then a block per AI crawler class you blocked, then `Sitemap:` (and `Agentmap:` if you turn it on — off by default, because robots.txt checkers flag it as unknown) |
| `/llms.txt` | The site summarised for language models ([llmstxt.org](https://llmstxt.org)): one H1, a summary, H2 sections of links, an Optional section |
| `/llms-full.txt` | Pages and recent articles in full, as plain text |
| `/.well-known/ard.json` | [Agentic Resource Discovery](https://github.com/ards-project/ard-spec) manifest; the same document at `/.well-known/ai-catalog.json` |
| `/ai/recent-articles.json` | Backs the `list_recent_articles` tool |

`bh_head()` adds `<link rel="ard">`, `<link rel="ai-catalog">`, a link to
llms.txt, the WebMCP origin-trial token when one is set, and — on the home
page — schema.org `WebSite` data with a `SearchAction`. `bh_footer()` registers
the WebMCP tools and describes the page's forms. A theme that calls both (it
should) needs nothing else.

## Crawler classes

| Setting | Crawlers |
|---|---|
| AI training crawlers | GPTBot, ClaudeBot, anthropic-ai, Google-Extended, Applebot-Extended, CCBot, Bytespider, meta-externalagent, Amazonbot, cohere-training-data-crawler, Diffbot |
| AI search crawlers | OAI-SearchBot, Claude-SearchBot, PerplexityBot, DuckAssistBot, YouBot |
| AI assistants browsing for a person | ChatGPT-User, Claude-User, Perplexity-User, MistralAI-User, meta-externalfetcher |

## WebMCP

[WebMCP](https://github.com/webmachinelearning/webmcp) lets an agent in the
visitor's browser use a page through named tools. Two kinds:

**Declarative — forms.** Core's comment form is annotated, and with "Describe
every form for agents" on, a small script gives every other form a
`toolname`/`tooldescription` and each named field a `toolparamdescription`
(from its label). Search forms get `toolautosubmit`; others are filled in for
the visitor to review and send. Forms with a password field are skipped.

Write the attributes yourself for better descriptions:

```php
<form action="/newsletter"<?= bh_webmcp_form('subscribe_newsletter', 'Subscribe an email address to the weekly newsletter.') ?>>
    <input type="email" name="email" required<?= bh_webmcp_param('Email address to subscribe') ?>>
</form>
```

`bh_webmcp_form($name, $description, ['autosubmit' => true])` — only for
harmless, read-only forms such as search. Both helpers return `''` while
WebMCP is off, so they are safe to leave in a theme.

**Imperative — tools.** Registered through `document.modelContext`
(`navigator.modelContext` in older builds) on every page. Core's:
`list_recent_articles`, `list_topics`. Add your own from an app:

```php
bh_webmcp_tool([
    'name'        => 'check_stock',
    'description' => 'Check whether a product is in stock.',
    'inputSchema' => ['type' => 'object',
                      'properties' => ['sku' => ['type' => 'string', 'description' => 'Product code']],
                      'required' => ['sku']],
    'annotations' => ['readOnlyHint' => true],
    'endpoint'    => '/shop/stock.json',   // same-origin GET; input → query string; JSON back
]);
```

Or `'result' => [...]` instead of `'endpoint'` for a fixed answer. A tool that
does not validate (name, description, object `inputSchema` whose `required`
names exist, exactly one of endpoint/result, same-origin endpoint) is left
out rather than breaking the others. Keep tools few — Lighthouse warns above
a recommended number — and mark anything with real-world effect
`consequentialHint: true`.

While WebMCP is a Chrome origin trial, it only runs on registered sites: put
your token in Settings → AI Agents.

## Filters

| Filter | Value |
|---|---|
| `ai.llms_sections` | `[['title' => …, 'text' => …, 'links' => [[title, url, notes], …]], …]` |
| `ai.catalog_entries` | ARD entries; invalid ones are dropped |
| `ai.robots` | the robots.txt text |
| `ai.tools` | tool definitions, as for `bh_webmcp_tool()` |

## Checking

PageSpeed Insights and Chrome DevTools' Lighthouse include an "Agentic
Browsing" category: llms.txt, the ARD catalog, WebMCP form coverage,
registered tools and schema validity. The settings screen links to it.
