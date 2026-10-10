<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\HookRegistry;

/**
 * AI agent accessibility for the public site.
 *
 * Builds everything an AI agent reads or uses on a Basehim site:
 *
 *   /robots.txt                  crawler rules, with separate policies for AI
 *                                training crawlers, AI search crawlers and AI
 *                                assistants browsing for a person
 *   /llms.txt, /llms-full.txt    the site summarised for language models
 *                                (https://llmstxt.org)
 *   /.well-known/ard.json        Agentic Resource Discovery manifest; also
 *   /.well-known/ai-catalog.json served at its predecessor's path
 *   WebMCP                       the site's forms annotated as agent tools,
 *                                plus read-only tools registered in script
 *
 * All of it is controlled from Settings → AI Agents (group "ai"). Themes
 * and apps extend it through bootstrap helpers (bh_webmcp_form(),
 * bh_webmcp_param(), bh_webmcp_tool()) and these filters:
 *
 *   ai.llms_sections      array of ['title' => …, 'links' => [[title, url, notes]], 'text' => …]
 *   ai.catalog_entries    array of ARD entries
 *   ai.robots             the robots.txt text
 *   ai.tools              array of tool definitions (see tools())
 */
final class AiAccessService
{
    /** Crawlers that collect content to train AI models. */
    public const TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'Amazonbot', 'cohere-training-data-crawler', 'Diffbot'];
    /** Crawlers that index pages for AI search answers. */
    public const SEARCH_BOTS = ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'DuckAssistBot', 'YouBot'];
    /** Assistants that open a page because a person asked them to. */
    public const USER_BOTS = ['ChatGPT-User', 'Claude-User', 'Perplexity-User', 'MistralAI-User', 'meta-externalfetcher'];

    private const DEFAULTS = [
        'enabled'            => '1',
        // robots.txt
        'robots_enabled'     => '1',
        'ai_training'        => 'allow',
        'ai_search'          => 'allow',
        'ai_user'            => 'allow',
        'robots_agentmap'    => '0',
        // llms.txt
        'llms_enabled'       => '1',
        'llms_intro'         => '',
        'llms_posts'         => '30',
        'llms_pages'         => '1',
        'llms_categories'    => '1',
        'llms_extra'         => '',
        'llms_full'          => '1',
        'llms_full_limit'    => '50',
        // Agentic Resource Discovery
        'catalog_enabled'    => '1',
        'catalog_queries'    => '',
        'catalog_extra'      => '',
        // WebMCP
        'webmcp_enabled'     => '1',
        'webmcp_forms'       => '1',
        'webmcp_comments'    => '1',
        'tool_recent'        => '1',
        'tool_categories'    => '1',
        'webmcp_token'       => '',
        // Structured data
        'jsonld_website'     => '1',
    ];

    /** @var array<string,string>|null */
    private ?array $cfg = null;

    private Application $app;

    /**
     * Always the running application. (Asking the container for an
     * Application parameter would build a new, empty one with no database.)
     */
    public function __construct()
    {
        $this->app = Application::getInstance();
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    /** Every setting, saved values over defaults. */
    public function settings(): array
    {
        if ($this->cfg !== null) return $this->cfg;
        $saved = [];
        try { $saved = $this->app->make(SettingService::class)->getGroup('ai'); } catch (\Throwable) {}
        $cfg = self::DEFAULTS;
        foreach ($saved as $k => $v) if (array_key_exists($k, $cfg)) $cfg[$k] = is_scalar($v) ? (string) $v : '';
        return $this->cfg = $cfg;
    }

    public static function defaults(): array { return self::DEFAULTS; }

    public function on(string $key): bool
    {
        $c = $this->settings();
        if ($key !== 'enabled' && ($c['enabled'] ?? '1') !== '1') return false;
        return ($c[$key] ?? '0') === '1';
    }

    public function get(string $key): string { return (string) ($this->settings()[$key] ?? ''); }

    /** Forget cached settings (after a save). */
    public function reset(): void { $this->cfg = null; }

    // ── Site facts ────────────────────────────────────────────────────────────

    /** https://example.com (plus the install's base path), no trailing slash. */
    public function origin(): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) ?: 'localhost';
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return ($https ? 'https' : 'http') . '://' . $host . $base;
    }

    /** The host name without port, as an ARD publisher segment: [A-Za-z0-9.-]+ */
    public function publisher(): string
    {
        $host = strtolower((string) parse_url($this->origin(), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $host = preg_replace('/[^a-z0-9.\-]/', '', $host);
        return $host !== '' ? $host : 'localhost';
    }

    public function siteName(): string
    {
        $s = $this->app->make(SettingService::class);
        $name = trim((string) $s->get('general', 'site_title', ''));
        return $name !== '' ? $name : $this->publisher();
    }

    public function tagline(): string
    {
        $s = $this->app->make(SettingService::class);
        $t = trim((string) $s->get('general', 'tagline', ''));
        if ($t === '') $t = trim((string) $s->get('seo', 'default_meta_description', ''));
        return $t;
    }

    private function db(): Database { return $this->app->make(Database::class); }

    private function hooks(): ?HookRegistry
    {
        try { return $this->app->make(HookRegistry::class); } catch (\Throwable) { return null; }
    }

    private function filter(string $name, mixed $value): mixed
    {
        $h = $this->hooks();
        if (!$h || !method_exists($h, 'applyFilters')) return $value;
        try { return $h->applyFilters($name, $value); } catch (\Throwable) { return $value; }
    }

    private function sitemapOn(): bool
    {
        $s = $this->app->make(SettingService::class);
        return (bool) $s->get('seo', 'generate_sitemap', $s->get('seo', 'enable_sitemap', true));
    }

    /** Published posts or pages, newest first. */
    public function content(string $type, int $limit): array
    {
        if ($limit < 1) return [];
        $limit = min($limit, 2000);
        return $this->db()->select(
            "SELECT id, type, slug, title, excerpt, content, content_format, published_at, updated_at
               FROM {posts}
              WHERE type = :t AND status = 'published' AND deleted_at IS NULL
              ORDER BY " . ($type === 'page' ? 'title ASC' : 'published_at DESC, id DESC') . "
              LIMIT {$limit}",
            ['t' => $type]
        );
    }

    /** Categories that have published posts, busiest first. */
    public function categories(): array
    {
        try {
            return $this->db()->select(
                "SELECT t.slug, t.name, t.description, COUNT(DISTINCT p.id) AS posts
                   FROM {terms} t
                   JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category'
                   JOIN {post_term} pt ON pt.term_id = t.id
                   JOIN {posts} p ON p.id = pt.post_id AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL
               GROUP BY t.id, t.slug, t.name, t.description
               ORDER BY posts DESC, t.name ASC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function postUrl(array $p): string { return $this->origin() . Helpers::postUrl($p); }

    /** Plain text of a post: rendered blocks or HTML, tags removed, whitespace tidied. */
    public function plainText(array $p, int $max = 0): string
    {
        $raw = (string) ($p['content'] ?? '');
        $fmt = (string) ($p['content_format'] ?? '');
        try {
            if ($fmt === 'blocks' || (ltrim($raw) !== '' && ltrim($raw)[0] === '{')) {
                $raw = BlockRenderer::render($raw, $this->hooks() ?? new HookRegistry());
            } elseif ($fmt === 'markdown') {
                $raw = Markdown::toHtml($raw);
            }
        } catch (\Throwable) {}
        return $this->htmlToText($raw, $max);
    }

    /** Readable text from HTML: block ends become line breaks, headings keep a marker. */
    public function htmlToText(string $html, int $max = 0): string
    {
        $html = preg_replace('#<(script|style|noscript|iframe|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', fn($m) => "\n\n" . str_repeat('#', max(2, (int) $m[1])) . ' ' . trim(strip_tags($m[2])) . "\n\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|ul|ol|blockquote|pre|figure|table|tr|h[1-6])>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[[a-z_]+\d*\]/i', '', $text) ?? $text;          // leftover shortcodes such as [post_start1]
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
        if ($max > 0 && mb_strlen($text) > $max) $text = rtrim(mb_substr($text, 0, $max), " ,.;:\n") . '…';
        return $text;
    }

    /** A one-line summary for a link list. */
    public function summary(array $p, int $max = 160): string
    {
        $s = trim(html_entity_decode(strip_tags((string) ($p['excerpt'] ?? '')), ENT_QUOTES, 'UTF-8'));
        if ($s === '') $s = str_replace("\n", ' ', preg_replace('/^#+ .*$/m', '', $this->plainText($p, $max * 2)) ?? '');
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        if (mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max), " ,.;:") . '…';
        return $s;
    }

    private static function md(string $s): string
    {
        // Text inside a markdown link label: no brackets or line breaks.
        return trim(str_replace(['[', ']', "\n", "\r"], ['(', ')', ' ', ''], $s));
    }

    // ── llms.txt ─────────────────────────────────────────────────────────────

    /**
     * /llms.txt — the format of https://llmstxt.org: one H1 (the site's name),
     * a blockquote summary, optional prose, then H2 sections of markdown link
     * lists. An "Optional" section holds what an agent can skip when short of
     * context.
     */
    public function llmsTxt(): string
    {
        $c = $this->settings();
        $o = $this->origin();
        $out = '# ' . $this->siteName() . "\n\n";
        $tag = $this->tagline();
        $out .= '> ' . ($tag !== '' ? $tag : 'Articles and pages published on ' . $this->publisher() . '.') . "\n\n";
        $intro = trim($c['llms_intro']);
        if ($intro !== '') $out .= $intro . "\n\n";

        $sections = [];
        if ($c['llms_pages'] === '1') {
            $links = [];
            foreach ($this->content('page', 50) as $p) $links[] = [$p['title'], $this->postUrl($p), $this->summary($p, 120)];
            if ($links) $sections[] = ['title' => 'Pages', 'links' => $links];
        }
        $n = max(0, (int) $c['llms_posts']);
        if ($n > 0) {
            $links = [];
            foreach ($this->content('post', $n) as $p) $links[] = [$p['title'], $this->postUrl($p), $this->summary($p)];
            if ($links) $sections[] = ['title' => 'Latest articles', 'links' => $links];
        }
        if ($c['llms_categories'] === '1') {
            $links = [];
            foreach ($this->categories() as $t) {
                $links[] = [html_entity_decode((string) $t['name'], ENT_QUOTES), $o . '/category/' . rawurlencode((string) $t['slug']), $t['posts'] . ' article' . ((int) $t['posts'] === 1 ? '' : 's')];
            }
            if ($links) $sections[] = ['title' => 'Topics', 'links' => $links];
        }
        $sections = (array) $this->filter('ai.llms_sections', $sections);

        foreach ($sections as $sec) {
            if (empty($sec['title'])) continue;
            $out .= '## ' . self::md((string) $sec['title']) . "\n\n";
            if (!empty($sec['text'])) $out .= trim((string) $sec['text']) . "\n\n";
            foreach ((array) ($sec['links'] ?? []) as $l) {
                [$title, $url, $notes] = array_pad(array_values((array) $l), 3, '');
                if ($url === '') continue;
                $out .= '- [' . self::md((string) $title) . '](' . $url . ')' . ($notes !== '' ? ': ' . self::md((string) $notes) : '') . "\n";
            }
            $out .= "\n";
        }

        $extra = trim($c['llms_extra']);
        if ($extra !== '') $out .= $extra . "\n\n";

        // What an agent can skip when it is short of context.
        $opt = [];
        if ($this->on('llms_full')) $opt[] = ['Full text of the site', $o . '/llms-full.txt', 'every page and recent article as plain text'];
        $opt[] = ['RSS feed', $o . '/feed', 'the newest articles'];
        if ($this->sitemapOn()) $opt[] = ['Sitemap', $o . '/sitemap.xml', 'every public URL'];
        $out .= "## Optional\n\n";
        foreach ($opt as [$t, $u, $n2]) $out .= '- [' . $t . '](' . $u . '): ' . $n2 . "\n";
        return rtrim($out) . "\n";
    }

    /** /llms-full.txt — pages and recent articles in full, as plain markdown text. */
    public function llmsFullTxt(): string
    {
        $c = $this->settings();
        $out = '# ' . $this->siteName() . "\n\n";
        $tag = $this->tagline();
        if ($tag !== '') $out .= '> ' . $tag . "\n\n";
        $out .= 'Source: ' . $this->origin() . "/\n\n";
        $items = array_merge($this->content('page', 50), $this->content('post', max(1, min(500, (int) $c['llms_full_limit']))));
        foreach ($items as $p) {
            $out .= '## ' . self::md((string) $p['title']) . "\n\n";
            $out .= 'URL: ' . $this->postUrl($p) . "\n";
            if (!empty($p['published_at']) && $p['type'] === 'post') $out .= 'Published: ' . bh_date((string) $p['published_at'], 'Y-m-d') . "\n";
            $out .= "\n" . $this->plainText($p, 20000) . "\n\n";
        }
        return rtrim($out) . "\n";
    }

    // ── robots.txt ───────────────────────────────────────────────────────────

    /**
     * /robots.txt — the site's own rules (Settings → AI Agents, stored as
     * seo.robots_txt), then one group per AI crawler class the owner blocked,
     * then Sitemap: and Agentmap: lines.
     */
    public function robotsTxt(): string
    {
        $c = $this->settings();
        $s = $this->app->make(SettingService::class);
        $rules = trim(str_replace("\r", '', (string) $s->get('seo', 'robots_txt', "User-agent: *\nAllow: /\nDisallow: /admin/")));
        if ($rules === '') $rules = "User-agent: *\nAllow: /";
        if (!preg_match('/^\s*user-agent\s*:/im', $rules)) $rules = "User-agent: *\n" . $rules;
        $out = $rules . "\n";

        $groups = [
            'ai_training' => ['AI training crawlers', self::TRAINING_BOTS],
            'ai_search'   => ['AI search crawlers', self::SEARCH_BOTS],
            'ai_user'     => ['AI assistants opening pages for a person', self::USER_BOTS],
        ];
        foreach ($groups as $key => [$label, $bots]) {
            if (($c[$key] ?? 'allow') !== 'block') continue;
            $out .= "\n# " . $label . ": blocked in Settings → AI Agents\n";
            foreach ($bots as $b) $out .= 'User-agent: ' . $b . "\n";
            $out .= "Disallow: /\n";
        }

        $o = $this->origin();
        $out .= "\n";
        if ($this->sitemapOn()) $out .= 'Sitemap: ' . $o . "/sitemap.xml\n";
        // Agentmap is ARD's robots.txt directive, but crawlers' robots.txt parsers
        // (and Lighthouse's robots.txt check) treat any non-standard line as an
        // error, so it is opt-in. Agents find the catalog through the page's
        // rel="ard" link and /.well-known/ard.json without it.
        if ($this->on('catalog_enabled') && $this->on('robots_agentmap')) $out .= 'Agentmap: ' . $o . "/.well-known/ard.json\n";
        return (string) $this->filter('ai.robots', rtrim($out) . "\n");
    }

    // ── Agentic Resource Discovery ───────────────────────────────────────────

    /** A valid ARD identifier: urn:air:<publisher>:<namespace>:<name>. */
    public function urn(string $namespace, string $name): string
    {
        $clean = fn($s) => trim(preg_replace('/[^a-z0-9._-]+/', '-', strtolower($s)) ?? '', '-') ?: 'item';
        return 'urn:air:' . $this->publisher() . ':' . $clean($namespace) . ':' . $clean($name);
    }

    /** Sample questions an agent might bring to this site: 2–5, as ARD recommends. */
    public function representativeQueries(): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $this->get('catalog_queries'))))));
        if (count($lines) >= 2) return array_slice($lines, 0, 5);
        $q = $lines;
        foreach (array_slice($this->categories(), 0, 3) as $t) $q[] = 'find articles about ' . strtolower(html_entity_decode((string) $t['name'], ENT_QUOTES)) . ' on ' . $this->siteName();
        $q[] = 'what has ' . $this->siteName() . ' published recently';
        $q[] = 'summarise the content of ' . $this->publisher();
        return array_slice(array_values(array_unique($q)), 0, 5);
    }

    /**
     * The manifest served at /.well-known/ard.json and /.well-known/ai-catalog.json:
     * {"entries": [ARD entry, …]} — each with identifier, displayName, type and
     * exactly one of url/data, per the ARD entry schema.
     */
    public function catalog(): array
    {
        $o = $this->origin();
        $name = $this->siteName();
        $updated = $this->lastUpdated();
        $queries = $this->representativeQueries();
        $entries = [];
        if ($this->on('llms_enabled')) {
            $entries[] = [
                'identifier' => $this->urn('content', 'llms-txt'),
                'displayName' => $name . ' — site summary for AI',
                'type' => 'text/markdown',
                'url' => $o . '/llms.txt',
                'description' => 'An llms.txt summary of ' . $name . ': what the site covers, its pages, latest articles and topics, each linked.',
                'representativeQueries' => $queries,
                'tags' => ['llms.txt', 'content'],
                'updatedAt' => $updated,
            ];
            if ($this->on('llms_full')) {
                $entries[] = [
                    'identifier' => $this->urn('content', 'llms-full-txt'),
                    'displayName' => $name . ' — full text',
                    'type' => 'text/markdown',
                    'url' => $o . '/llms-full.txt',
                    'description' => 'The pages and recent articles of ' . $name . ' in full, as plain text.',
                    'representativeQueries' => $queries,
                    'tags' => ['llms.txt', 'full-text'],
                    'updatedAt' => $updated,
                ];
            }
        }
        $entries[] = [
            'identifier' => $this->urn('feed', 'rss'),
            'displayName' => $name . ' — RSS feed',
            'type' => 'application/rss+xml',
            'url' => $o . '/feed',
            'description' => 'The newest articles published on ' . $name . '.',
            'representativeQueries' => ['what is new on ' . $name, 'latest articles from ' . $this->publisher()],
            'tags' => ['feed', 'news'],
            'updatedAt' => $updated,
        ];
        if ($this->sitemapOn()) {
            $entries[] = [
                'identifier' => $this->urn('index', 'sitemap'),
                'displayName' => $name . ' — sitemap',
                'type' => 'application/xml',
                'url' => $o . '/sitemap.xml',
                'description' => 'Every public URL on ' . $name . '.',
                'representativeQueries' => ['list every page on ' . $this->publisher(), 'find all articles published on ' . $name],
                'tags' => ['sitemap'],
            ];
        }
        $tools = $this->tools();
        if ($this->on('webmcp_enabled') && $tools) {
            $entries[] = [
                'identifier' => $this->urn('webmcp', 'tools'),
                'displayName' => $name . ' — in-page agent tools (WebMCP)',
                'type' => 'application/json',
                'data' => ['tools' => array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema'], 'annotations' => $t['annotations'] ?? []], $tools)],
                'description' => 'Read-only tools any page of ' . $name . ' registers for in-browser AI agents through WebMCP.',
                'capabilities' => array_map(fn($t) => $t['name'], $tools),
                'representativeQueries' => $queries,
                'tags' => ['webmcp'],
            ];
        }
        foreach ($this->customEntries() as $e) $entries[] = $e;
        $entries = (array) $this->filter('ai.catalog_entries', $entries);
        $entries = array_values(array_filter(array_map(fn($e) => $this->normalizeEntry((array) $e), $entries)));
        return [
            'specVersion' => '1.0',
            // ai-catalog.schema.json (what Lighthouse validates) allows only
            // displayName, identifier, documentationUrl, logoUrl and trustManifest
            // in host, and identifier is meant to be verifiable (did:web and the
            // like), which a site does not publish by default: displayName only.
            'host' => ['displayName' => $name],
            'entries' => $entries,
        ];
    }

    /**
     * Keep an entry valid against the ARD entry schema, or drop it: identifier
     * matching ^urn:air:[a-zA-Z0-9.-]+(:[a-zA-Z0-9._-]+)+$, a displayName, a
     * type, exactly one of url (absolute) / data (object), and a date-time
     * updatedAt if any.
     */
    public function normalizeEntry(array $e): ?array
    {
        $id = (string) ($e['identifier'] ?? '');
        if (!preg_match('/^urn:air:[a-zA-Z0-9.-]+(:[a-zA-Z0-9._-]+)+$/', $id)) return null;
        if (trim((string) ($e['displayName'] ?? '')) === '' || trim((string) ($e['type'] ?? '')) === '') return null;
        $hasUrl = isset($e['url']) && $e['url'] !== '';
        $hasData = isset($e['data']) && is_array($e['data']);
        if ($hasUrl === $hasData) return null;                  // exactly one
        if ($hasUrl) {
            $u = (string) $e['url'];
            if ($u[0] === '/') $u = $this->origin() . $u;
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) return null;
            $e['url'] = $u;
            unset($e['data']);
        } else {
            unset($e['url']);
            if ($e['data'] === []) $e['data'] = new \stdClass();
        }
        foreach (['representativeQueries', 'capabilities', 'tags'] as $k) {
            if (isset($e[$k])) $e[$k] = array_values(array_map('strval', array_filter((array) $e[$k], 'is_scalar')));
        }
        // ai-catalog.schema.json (what Lighthouse validates) makes these hard
        // rules: 2–5 representative queries, and metadata values that are
        // plain scalars. Trim to five; drop a list of fewer than two.
        if (isset($e['representativeQueries'])) {
            $e['representativeQueries'] = array_slice($e['representativeQueries'], 0, 5);
            if (count($e['representativeQueries']) < 2) unset($e['representativeQueries']);
        }
        if (isset($e['metadata'])) {
            $meta = array_filter((array) $e['metadata'], fn($v) => is_scalar($v) || $v === null);
            if ($meta) $e['metadata'] = $meta; else unset($e['metadata']);
        }
        if (isset($e['trustManifest']) && (!is_array($e['trustManifest']) || !is_string($e['trustManifest']['identity'] ?? null))) unset($e['trustManifest']);
        if (isset($e['updatedAt'])) {
            $ts = strtotime((string) $e['updatedAt']);
            if ($ts) $e['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z', $ts); else unset($e['updatedAt']);
        }
        return $e;
    }

    /** Extra entries the owner added as JSON (one entry or an array of them). */
    public function customEntries(): array
    {
        $raw = trim($this->get('catalog_extra'));
        if ($raw === '') return [];
        $v = json_decode($raw, true);
        if (!is_array($v)) return [];
        return array_is_list($v) ? $v : [$v];
    }

    private function lastUpdated(): string
    {
        try {
            $r = $this->db()->selectOne("SELECT MAX(updated_at) AS u FROM {posts} WHERE status = 'published' AND deleted_at IS NULL");
            if (!empty($r['u'])) return gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $r['u']));
        } catch (\Throwable) {}
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    // ── WebMCP tools ─────────────────────────────────────────────────────────

    /**
     * Tools registered on every public page through document.modelContext
     * (navigator.modelContext in older builds). Each is
     *   name, description, inputSchema (JSON Schema, type object),
     *   annotations (readOnlyHint, untrustedContentHint, consequentialHint),
     *   and how to run it: 'endpoint' (a same-origin GET URL; the tool's
     *   input becomes query parameters and the JSON response is returned) or
     *   'result' (a fixed value returned as is).
     * Apps add their own through bh_webmcp_tool() or the ai.tools filter.
     */
    public function tools(): array
    {
        if (!$this->on('webmcp_enabled')) return [];
        $o = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $tools = [];
        if ($this->on('tool_recent')) {
            $tools[] = [
                'name' => 'list_recent_articles',
                'description' => 'List the most recent articles published on ' . $this->siteName() . ', newest first, with title, URL, date, topic and a short summary. Optionally only one topic.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'How many articles to return (1-20). Default 10.'],
                        'topic' => ['type' => 'string', 'description' => 'Only articles in this topic: its slug, as listed by list_topics. Leave out for all topics.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'untrustedContentHint' => true],
                'endpoint' => $o . '/ai/recent-articles.json',
            ];
        }
        if ($this->on('tool_categories')) {
            $topics = array_map(fn($t) => ['slug' => (string) $t['slug'], 'name' => html_entity_decode((string) $t['name'], ENT_QUOTES), 'articles' => (int) $t['posts'], 'url' => $this->origin() . '/category/' . rawurlencode((string) $t['slug'])], array_slice($this->categories(), 0, 100));
            if ($topics) {
                $tools[] = [
                    'name' => 'list_topics',
                    'description' => 'List the topics (categories) of ' . $this->siteName() . ' with how many articles each has and its page URL.',
                    'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                    'annotations' => ['readOnlyHint' => true],
                    'result' => ['topics' => $topics],
                ];
            }
        }
        if (function_exists('bh_webmcp_tools_registered')) foreach (\bh_webmcp_tools_registered() as $t) $tools[] = $t;
        $tools = (array) $this->filter('ai.tools', $tools);
        $valid = [];
        $seen = [];
        foreach ($tools as $t) {
            $t = $this->validTool((array) $t);
            if ($t && !isset($seen[$t['name']])) { $seen[$t['name']] = true; $valid[] = $t; }
        }
        return $valid;
    }

    /**
     * A tool definition WebMCP will accept, or null: a name of letters, digits,
     * _ - . (1–64), a description, an object inputSchema whose required names
     * all exist in properties, and one way to run it.
     */
    public function validTool(array $t): ?array
    {
        $name = (string) ($t['name'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $name)) return null;
        if (trim((string) ($t['description'] ?? '')) === '') return null;
        $schema = $t['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
        if (!is_array($schema) || ($schema['type'] ?? '') !== 'object') return null;
        $props = $schema['properties'] ?? [];
        if ($props instanceof \stdClass) $props = [];
        if (!is_array($props)) return null;
        foreach ($props as $k => $p) if (!is_string($k) || !is_array($p) || empty($p['type'])) return null;
        if (isset($schema['required'])) {
            if (!is_array($schema['required'])) return null;
            foreach ($schema['required'] as $r) if (!array_key_exists((string) $r, $props)) return null;
        }
        $schema['properties'] = $props ?: new \stdClass();
        if (empty($t['endpoint']) === !array_key_exists('result', $t)) return null;   // exactly one way to run
        if (!empty($t['endpoint']) && !preg_match('#^/#', (string) $t['endpoint'])) return null; // same origin only
        $ann = [];
        foreach (['readOnlyHint', 'untrustedContentHint', 'consequentialHint'] as $k) if (isset($t['annotations'][$k])) $ann[$k] = (bool) $t['annotations'][$k];
        $out = ['name' => $name, 'description' => (string) $t['description'], 'inputSchema' => $schema];
        if ($ann) $out['annotations'] = $ann;
        if (!empty($t['endpoint'])) $out['endpoint'] = (string) $t['endpoint'];
        else $out['result'] = $t['result'];
        return $out;
    }

    /** Recent published articles for the list_recent_articles tool. */
    public function recentArticles(int $limit, string $topic): array
    {
        $limit = max(1, min(20, $limit ?: 10));
        $where = "p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL";
        $params = [];
        if ($topic !== '') {
            $where .= " AND EXISTS (SELECT 1 FROM {post_term} pt JOIN {terms} t ON t.id = pt.term_id
                                     JOIN {taxonomies} x ON x.id = t.taxonomy_id AND x.slug = 'category'
                                    WHERE pt.post_id = p.id AND t.slug = :topic)";
            $params['topic'] = $topic;
        }
        $rows = $this->db()->select("SELECT p.id, p.type, p.slug, p.title, p.excerpt, p.content, p.content_format, p.published_at FROM {posts} p WHERE {$where} ORDER BY p.published_at DESC, p.id DESC LIMIT {$limit}", $params);
        return array_map(function ($p) {
            $cat = '';
            try { $cat = (string) Helpers::lookupPrimaryCategory((int) $p['id']); } catch (\Throwable) {}
            return [
                'title' => (string) $p['title'],
                'url' => $this->postUrl($p),
                'published' => $p['published_at'] ? gmdate('Y-m-d', strtotime((string) $p['published_at'])) : null,
                'topic' => $cat ?: null,
                'summary' => $this->summary($p, 200),
            ];
        }, $rows);
    }

    // ── Page markup ──────────────────────────────────────────────────────────

    /** For bh_head(): origin-trial token, discovery links, WebSite structured data. */
    public function headMarkup(): string
    {
        if (!$this->on('enabled')) return '';
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $h = '';
        $token = trim($this->get('webmcp_token'));
        if ($this->on('webmcp_enabled') && $token !== '') $h .= '<meta http-equiv="origin-trial" content="' . $e($token) . '">' . "\n";
        if ($this->on('catalog_enabled')) {
            $h .= '<link rel="ard" type="application/json" href="' . $e($base . '/.well-known/ard.json') . '">' . "\n";
            $h .= '<link rel="ai-catalog" type="application/json" href="' . $e($base . '/.well-known/ai-catalog.json') . '">' . "\n";
        }
        if ($this->on('llms_enabled')) $h .= '<link rel="alternate" type="text/markdown" title="llms.txt" href="' . $e($base . '/llms.txt') . '">' . "\n";
        // The WebSite structured data moved to the SEO service (1.2.45), which
        // prints one @graph per page; Settings → SEO → JSON-LD controls it. It
        // is only printed here when the SEO service is switched off entirely.
        $seoOn = true;
        try { $seoOn = Application::getInstance()->make(\App\Services\SeoHeadService::class)->enabled(); } catch (\Throwable) {}
        if (!$seoOn && $this->on('jsonld_website') && $this->isFrontPage()) {
            $o = $this->origin();
            $data = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => $o . '/',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $o . '/search?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
            if ($this->tagline() !== '') $data['description'] = $this->tagline();
            $h .= '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
        }
        return $h;
    }

    private function isFrontPage(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        return rtrim($path, '/') === rtrim($base, '/');
    }

    /** For bh_footer(): registers the WebMCP tools and annotates forms. */
    public function footerMarkup(): string
    {
        if (!$this->on('webmcp_enabled')) return '';
        $cfg = [
            'tools' => $this->tools(),
            'forms' => $this->on('webmcp_forms'),
            'site' => $this->siteName(),
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return '<script id="bh-webmcp">' . self::footerScript($json) . '</script>' . "\n";
    }

    /**
     * The in-page script. Registers each tool with document.modelContext (or
     * navigator.modelContext) when the browser has it, and gives every form
     * that lacks them the declarative attributes WebMCP reads:
     * toolname / tooldescription on the form, toolparamdescription on each
     * named field. Forms with a password field are left alone: an agent should
     * not be handed a sign-in form as a tool.
     */
    private static function footerScript(string $json): string
    {
        return <<<JS
(function(){var C={$json};var mc=document.modelContext||navigator.modelContext;
function run(t,a){if(t.result!==undefined)return Promise.resolve(JSON.stringify(t.result));var u=new URL(t.endpoint,location.href);Object.keys(a||{}).forEach(function(k){if(a[k]!==undefined&&a[k]!==null&&a[k]!=='')u.searchParams.set(k,a[k]);});return fetch(u.toString(),{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){return r.text();});}
if(mc&&typeof mc.registerTool==='function'){C.tools.forEach(function(t){var d={name:t.name,description:t.description,inputSchema:t.inputSchema,execute:function(a){return run(t,a);}};if(t.annotations)d.annotations=t.annotations;try{var p=mc.registerTool(d);if(p&&p.catch)p.catch(function(){});}catch(e){}});}
if(!C.forms)return;
function txt(el){return el?String(el.textContent||'').replace(/\s+/g,' ').trim():'';}
function slug(s){return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+\$/g,'').slice(0,40);}
function where(f){if(f.closest('header,[role=banner]'))return 'header';if(f.closest('nav,[role=navigation],dialog,[role=dialog],aside'))return 'menu';if(f.closest('footer,[role=contentinfo]'))return 'footer';return '';}
var used={};Array.prototype.forEach.call(document.querySelectorAll('form[toolname]'),function(f){used[f.getAttribute('toolname')]=1;});
function uniq(n){var b=n,i=2;while(used[n]){n=b+'_'+i++;}used[n]=1;return n;}
function label(f,el){if(el.id){var l=f.ownerDocument.querySelector('label[for="'+(window.CSS&&CSS.escape?CSS.escape(el.id):el.id)+'"]');if(l)return txt(l);}var p=el.closest('label');if(p)return txt(p);return el.getAttribute('aria-label')||el.getAttribute('placeholder')||el.getAttribute('title')||'';}
Array.prototype.forEach.call(document.querySelectorAll('form'),function(f){
 if(f.hasAttribute('toolname')&&f.hasAttribute('tooldescription'))return;
 if(f.querySelector('input[type=password]'))return;
 var search=f.getAttribute('role')==='search'||f.closest('[role=search]')||f.querySelector('input[type=search]')||/(^|\/)search\/?(\?|$)/.test(f.getAttribute('action')||'');
 var name,desc;
 if(search){var w=where(f);name=uniq('search_site'+(w&&used.search_site?'_'+w:''));desc='Search '+C.site+' for articles and pages. Opens the results page.';if(!f.hasAttribute('toolautosubmit'))f.setAttribute('toolautosubmit','');}
 else{var h=f.getAttribute('aria-label')||txt(f.querySelector('legend,h2,h3,h4'))||txt(f.querySelector('button[type=submit],input[type=submit],button:not([type])'))||f.id||'form';name=uniq(slug(f.getAttribute('name')||f.id||h)||'form');desc=(h.charAt(0).toUpperCase()+h.slice(1))+' — a form on '+C.site+'. The visitor reviews it and submits it themselves.';}
 if(!f.hasAttribute('toolname'))f.setAttribute('toolname',f.getAttribute('toolname')||name);
 if(!f.hasAttribute('tooldescription'))f.setAttribute('tooldescription',desc);
 Array.prototype.forEach.call(f.elements,function(el){if(!el.name||el.type==='hidden'||el.type==='submit'||el.type==='button'||el.type==='reset'||el.hasAttribute('toolparamdescription'))return;var d=label(f,el);if(!d&&search&&(el.type==='search'||el.type==='text'))d='Words to search for';if(!d)d=el.name.replace(/[_-]+/g,' ');el.setAttribute('toolparamdescription',d);});
});
})();
JS;
    }
}
