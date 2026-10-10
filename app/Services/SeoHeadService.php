<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\HookRegistry;
use App\Core\Time;
use App\Repositories\PostRepository;

/**
 * Everything a search engine or a social network reads from a page's <head>,
 * produced by core for every theme (1.2.45):
 *
 *   title        <title>
 *   description  <meta name="description">
 *   canonical    <link rel="canonical">
 *   robots       <meta name="robots">
 *   opengraph    og:* and article:* (Facebook, LinkedIn, WhatsApp, Slack…)
 *   twitter      twitter:* (X)
 *   jsonld       one <script type="application/ld+json"> @graph: WebSite,
 *                Organization, WebPage, Article, BreadcrumbList
 *   generator    <meta name="generator" content="Basehim CMS">
 *
 * Themes don't print any of these. When a theme still does (one written
 * before 1.2.45), its copies are removed from the finished page so a crawler
 * sees one consistent set.
 *
 * Each group is in one of three states:
 *   core      core prints it (and removes the theme's copies)
 *   off       nobody prints it (the theme's copies are removed too)
 *   external  core leaves it alone: the theme or an app prints it
 *
 * Settings → SEO turns the whole service or each group on or off. A theme can
 * hand a group to itself in theme.json ("seo": {"jsonld": false}, or
 * "seo": false for all of it). Apps and themes can change anything through
 * filters (see THEME-DEVELOPMENT.md, "SEO and social tags"):
 *
 *   seo.enabled  (bool $on, array $ctx)
 *   seo.groups   (array $states, array $ctx)       group => core|off|external
 *   seo.title / seo.description / seo.canonical / seo.robots (string, $ctx)
 *   seo.image    (?array $image, $ctx)             ['url','width','height','alt','type']
 *   seo.jsonld   (array $nodes, $ctx)              the @graph
 *   seo.head     (array $head, $ctx)               the whole result, last word
 *
 * and, while a page renders, through bh_seo(): ->set('title', …),
 * ->addJsonLd([...]), ->disable('twitter'), ->handOff('jsonld').
 */
final class SeoHeadService
{
    public const GROUPS = ['title', 'description', 'canonical', 'robots', 'opengraph', 'twitter', 'jsonld', 'generator'];
    public const JSONLD_TYPES = ['website', 'organization', 'webpage', 'article', 'breadcrumb'];

    /** Values set while the page renders (bh_seo()->set()). */
    private array $overrides = [];
    /** Extra @graph nodes added while the page renders. */
    private array $extraJsonLd = [];
    /** Group states set while the page renders. */
    private array $runtimeGroups = [];
    /** The last head built for this request. */
    private ?array $last = null;
    /** Per-request lookups. */
    private array $memo = [];

    public function __construct(private SettingService $settings, private HookRegistry $hooks) {}

    // ── Settings ────────────────────────────────────────────────────────────

    /** Settings → SEO, with defaults. */
    public function settings(): array
    {
        $g = $this->settings->getGroup('seo');
        $b = static function ($v, bool $d): bool {
            if ($v === null || $v === '') return $d;
            return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
        };
        // The WebSite schema used to be an AI-access setting; its old value is
        // the default here until SEO settings are saved.
        $aiWebsite = true;
        try { $aiWebsite = $b($this->settings->get('ai', 'jsonld_website', null), true); } catch (\Throwable) {}

        $out = [
            'enabled'      => $b($g['head_enabled'] ?? null, true),
            'strip_theme'  => $b($g['head_strip_theme'] ?? null, true),
            'noindex_search' => $b($g['noindex_search'] ?? null, true),
            'max_image_preview' => $b($g['max_image_preview'] ?? null, true),
            'home_title'   => trim((string) ($g['home_title'] ?? '')),
            'home_description' => trim((string) ($g['home_description'] ?? '')),
            'title_format' => trim((string) ($g['default_meta_title'] ?? '')) ?: '%title%',
            'separator'    => trim((string) ($g['title_separator'] ?? '')) ?: '–',
            'default_description' => trim((string) ($g['default_meta_description'] ?? '')),
            'default_image' => trim((string) ($g['default_og_image'] ?? '')),
            'twitter_handle' => trim((string) ($g['twitter_handle'] ?? '')),
            'groups' => [],
            'jsonld' => [],
        ];
        foreach (self::GROUPS as $grp) $out['groups'][$grp] = $b($g['head_' . $grp] ?? null, true);
        foreach (self::JSONLD_TYPES as $t) {
            $out['jsonld'][$t] = $b($g['jsonld_' . $t] ?? null, $t === 'website' ? $aiWebsite : true);
        }
        return $out;
    }

    // ── Runtime API (bh_seo()) ──────────────────────────────────────────────

    /**
     * Set a value for this page while it renders. Keys: title (full, used as
     * is), description, canonical, robots, image (URL or ['url','width',
     * 'height','alt']), og_type, og_title, og_description, twitter_card.
     */
    public function set(string $key, mixed $value): self
    {
        $this->overrides[$key] = $value;
        return $this;
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->overrides)) return $this->overrides[$key];
        return $this->last['values'][$key] ?? null;
    }

    /** Add a schema.org node to the page's @graph (needs "@type"). */
    public function addJsonLd(array $node): self
    {
        if (!empty($node['@type'])) $this->extraJsonLd[] = $node;
        return $this;
    }

    /** No tags of this group on this page, from anyone. */
    public function disable(string $group): self
    {
        if (in_array($group, self::GROUPS, true)) $this->runtimeGroups[$group] = 'off';
        return $this;
    }

    /** Core leaves this group alone on this page: the theme or an app prints it. */
    public function handOff(string $group): self
    {
        if (in_array($group, self::GROUPS, true)) $this->runtimeGroups[$group] = 'external';
        return $this;
    }

    /** The last head built for this request (values, tags, jsonld), or null. */
    public function current(): ?array
    {
        return $this->last;
    }

    // ── Building ────────────────────────────────────────────────────────────

    /** Is core producing SEO tags for this page at all? */
    public function enabled(array $ctx = []): bool
    {
        $s = $this->settings();
        $on = $s['enabled'];
        $theme = $this->themeSeoManifest();
        if ($theme === false) $on = false;
        try { $on = (bool) $this->hooks->applyFilters('seo.enabled', $on, $ctx); } catch (\Throwable) {}
        return $on;
    }

    /** group => core|off|external, after settings, theme.json, runtime and filters. */
    public function groupStates(array $ctx = []): array
    {
        $s = $this->settings();
        $states = [];
        foreach (self::GROUPS as $g) $states[$g] = $s['groups'][$g] ? 'core' : 'off';

        $theme = $this->themeSeoManifest();
        if (is_array($theme)) {
            foreach ($theme as $g => $v) {
                if (!isset($states[$g])) continue;
                if ($v === false || $v === 'theme' || $v === 'external') $states[$g] = 'external';
                elseif ($v === 'off') $states[$g] = 'off';
            }
        }
        foreach ($this->runtimeGroups as $g => $v) $states[$g] = $v;
        try {
            $f = $this->hooks->applyFilters('seo.groups', $states, $ctx);
            if (is_array($f)) {
                foreach ($f as $g => $v) {
                    if (isset($states[$g]) && in_array($v, ['core', 'off', 'external'], true)) $states[$g] = $v;
                }
            }
        } catch (\Throwable) {}
        // A page needs a title: "off" means "leave the theme's".
        if ($states['title'] === 'off') $states['title'] = 'external';
        return $states;
    }

    /**
     * Work out every value and tag for a page.
     *
     * $ctx: type (home|post|page|archive|author|search|404|other), post,
     * terms, term, author, seo (what the controller worked out), page (number),
     * is_preview, url.
     *
     * @return array{values: array, tags: array, jsonld: array, states: array, context: array}
     */
    public function build(array $ctx): array
    {
        $s = $this->settings();
        $states = $this->groupStates($ctx);
        $site = $this->siteName();
        $tagline = $this->tagline();
        $type = (string) ($ctx['type'] ?? 'other');
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : null;
        $ctrl = is_array($ctx['seo'] ?? null) ? $ctx['seo'] : [];
        $meta = $post ? $this->postSeoMeta((int) ($post['id'] ?? 0)) : [];
        $pageNo = max(1, (int) ($ctx['page'] ?? 1));

        // Title ---------------------------------------------------------------
        $explicit = trim((string) ($meta['meta_title'] ?? ''));
        if ($type === 'home' && $s['home_title'] !== '') {
            $title = $this->fillTitle($s['home_title'], $site, $site, $tagline, $s['separator'], $pageNo);
        } elseif ($type === 'home' && !$post) {
            $title = $tagline !== '' && $s['title_format'] !== '%title%'
                ? $this->fillTitle($s['title_format'], $site, $site, $tagline, $s['separator'], $pageNo)
                : $site;
        } elseif ($explicit !== '') {
            $title = $explicit;
        } else {
            $base = trim((string) ($ctrl['title'] ?? '')) ?: ($post['title'] ?? $site);
            if ($type === '404') $base = 'Page not found';
            $title = $this->fillTitle($s['title_format'], (string) $base, $site, $tagline, $s['separator'], $pageNo);
        }
        if ($pageNo > 1 && !str_contains($s['title_format'], '%page%') && in_array($type, ['home', 'archive', 'author', 'search'], true)) {
            $title .= ' ' . $s['separator'] . ' Page ' . $pageNo;
        }

        // Description ---------------------------------------------------------
        if ($type === 'home' && $s['home_description'] !== '') {
            $description = $s['home_description'];
        } else {
            $description = trim((string) ($ctrl['description'] ?? ''));
            if ($description === '' && $post) $description = $this->excerptOf($post);
            if ($description === '' && $type === 'home') $description = $tagline;
            if ($description === '') $description = $s['default_description'];
        }
        $description = $this->oneLine($description, 300);

        // Canonical -----------------------------------------------------------
        $canonical = trim((string) ($ctrl['canonical'] ?? ''));
        if ($canonical === '' && !in_array($type, ['404', 'search'], true)) $canonical = $this->currentUrl($pageNo);
        $canonical = $canonical !== '' ? $this->absolute($canonical) : '';

        // Robots --------------------------------------------------------------
        $robots = trim((string) ($ctrl['robots'] ?? ''));
        if ($robots === '' && $post) $robots = trim((string) ($meta['robots'] ?? ''));
        if (!empty($ctx['is_preview'])) $robots = 'noindex, nofollow';
        elseif ($type === '404') $robots = 'noindex';
        elseif ($type === 'search' && $s['noindex_search']) $robots = 'noindex, follow';
        if ($robots === '') $robots = 'index, follow';
        $robots = $this->normaliseRobots($robots);
        if ($s['max_image_preview'] && !str_contains($robots, 'noindex') && !str_contains($robots, 'max-image-preview')) {
            $robots .= ', max-image-preview:large';
        }

        // Share image -------------------------------------------------------
        $image = $this->shareImage($post, $meta, $s);

        $values = [
            'title' => $title, 'description' => $description, 'canonical' => $canonical,
            'robots' => $robots, 'image' => $image,
            'og_type' => $type === 'post' ? 'article' : ($type === 'author' ? 'profile' : 'website'),
            'og_title' => trim((string) ($meta['og_title'] ?? '')) ?: ($post ? (string) ($post['title'] ?? $title) : $title),
            'og_description' => $this->oneLine(trim((string) ($meta['og_description'] ?? '')) ?: $description, 300),
            'twitter_card' => $image ? 'summary_large_image' : 'summary',
            'site_name' => $site,
            'locale' => $this->locale(),
        ];
        if ($type === 'home' && !$post) $values['og_title'] = $title;

        // Runtime overrides, then the per-value filters.
        foreach ($this->overrides as $k => $v) {
            if ($k === 'image') $v = $this->normaliseImage($v);
            $values[$k] = $v;
        }
        foreach (['title', 'description', 'canonical', 'robots'] as $k) {
            try {
                $f = $this->hooks->applyFilters('seo.' . $k, $values[$k], $ctx);
                if (is_string($f)) $values[$k] = $f;
            } catch (\Throwable) {}
        }
        try {
            $f = $this->hooks->applyFilters('seo.image', $values['image'], $ctx);
            $values['image'] = $f === null ? null : $this->normaliseImage($f);
        } catch (\Throwable) {}
        if (!array_key_exists('twitter_card', $this->overrides)) {
            $values['twitter_card'] = $values['image'] ? 'summary_large_image' : 'summary';
        }

        // Tags ----------------------------------------------------------------
        $tags = [];
        $add = static function (string $group, string $tag, array $attrs) use (&$tags) {
            $tags[] = ['group' => $group, 'tag' => $tag, 'attrs' => $attrs];
        };
        if ($values['description'] !== '') $add('description', 'meta', ['name' => 'description', 'content' => $values['description']]);
        if ($values['robots'] !== '')      $add('robots', 'meta', ['name' => 'robots', 'content' => $values['robots']]);
        if ($values['canonical'] !== '')   $add('canonical', 'link', ['rel' => 'canonical', 'href' => $values['canonical']]);

        $add('opengraph', 'meta', ['property' => 'og:locale', 'content' => $values['locale']]);
        $add('opengraph', 'meta', ['property' => 'og:type', 'content' => $values['og_type']]);
        $add('opengraph', 'meta', ['property' => 'og:title', 'content' => $values['og_title']]);
        if ($values['og_description'] !== '') $add('opengraph', 'meta', ['property' => 'og:description', 'content' => $values['og_description']]);
        if ($values['canonical'] !== '') $add('opengraph', 'meta', ['property' => 'og:url', 'content' => $values['canonical']]);
        $add('opengraph', 'meta', ['property' => 'og:site_name', 'content' => $site]);
        if ($img = $values['image']) {
            $add('opengraph', 'meta', ['property' => 'og:image', 'content' => $img['url']]);
            if (str_starts_with($img['url'], 'https://')) $add('opengraph', 'meta', ['property' => 'og:image:secure_url', 'content' => $img['url']]);
            if (!empty($img['type']))   $add('opengraph', 'meta', ['property' => 'og:image:type', 'content' => $img['type']]);
            if (!empty($img['width']))  $add('opengraph', 'meta', ['property' => 'og:image:width', 'content' => (string) (int) $img['width']]);
            if (!empty($img['height'])) $add('opengraph', 'meta', ['property' => 'og:image:height', 'content' => (string) (int) $img['height']]);
            if (!empty($img['alt']))    $add('opengraph', 'meta', ['property' => 'og:image:alt', 'content' => $img['alt']]);
        }
        if ($type === 'post' && $post) {
            if ($p = Time::iso($post['published_at'] ?? null)) $add('opengraph', 'meta', ['property' => 'article:published_time', 'content' => $p]);
            if ($m = Time::iso($post['updated_at'] ?? null))   $add('opengraph', 'meta', ['property' => 'article:modified_time', 'content' => $m]);
            [$cat, $tagsList] = $this->postTerms($ctx);
            if ($cat) $add('opengraph', 'meta', ['property' => 'article:section', 'content' => $cat['name']]);
            foreach (array_slice($tagsList, 0, 10) as $t) $add('opengraph', 'meta', ['property' => 'article:tag', 'content' => $t['name']]);
        }

        $add('twitter', 'meta', ['name' => 'twitter:card', 'content' => $values['twitter_card']]);
        $add('twitter', 'meta', ['name' => 'twitter:title', 'content' => $values['og_title']]);
        if ($values['og_description'] !== '') $add('twitter', 'meta', ['name' => 'twitter:description', 'content' => $values['og_description']]);
        if ($img = $values['image']) {
            $add('twitter', 'meta', ['name' => 'twitter:image', 'content' => $img['url']]);
            if (!empty($img['alt'])) $add('twitter', 'meta', ['name' => 'twitter:image:alt', 'content' => $img['alt']]);
        }
        if ($h = $this->twitterHandle($s['twitter_handle'])) $add('twitter', 'meta', ['name' => 'twitter:site', 'content' => $h]);

        $add('generator', 'meta', ['name' => 'generator', 'content' => 'Basehim CMS']);

        // JSON-LD -------------------------------------------------------------
        $jsonld = $this->jsonLd($ctx, $values, $s);
        foreach ($this->extraJsonLd as $node) $jsonld[] = $node;
        try {
            $f = $this->hooks->applyFilters('seo.jsonld', $jsonld, $ctx);
            if (is_array($f)) $jsonld = array_values(array_filter($f, 'is_array'));
        } catch (\Throwable) {}

        // Only groups core prints.
        $tags = array_values(array_filter($tags, fn($t) => ($states[$t['group']] ?? 'off') === 'core'));
        if (($states['jsonld'] ?? 'off') !== 'core') $jsonld = [];

        $head = ['values' => $values, 'tags' => $tags, 'jsonld' => $jsonld, 'states' => $states, 'context' => $ctx];
        try {
            $f = $this->hooks->applyFilters('seo.head', $head, $ctx);
            if (is_array($f) && isset($f['values'], $f['tags'])) $head = $f + ['jsonld' => [], 'states' => $states, 'context' => $ctx];
        } catch (\Throwable) {}

        return $this->last = $head;
    }

    /** The head as HTML (without <title>, which apply() places itself). */
    public function render(array $head): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lines = [];
        foreach ($head['tags'] as $t) {
            $a = '';
            foreach ($t['attrs'] as $k => $v) {
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', (string) $k)) continue;
                $a .= ' ' . $k . '="' . $e($v) . '"';
            }
            $lines[] = '<' . ($t['tag'] === 'link' ? 'link' : 'meta') . $a . '>';
        }
        if (!empty($head['jsonld'])) {
            $doc = ['@context' => 'https://schema.org', '@graph' => array_values($head['jsonld'])];
            $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            if (is_string($json)) $lines[] = '<script type="application/ld+json">' . $json . '</script>';
        }
        return $lines ? "    " . implode("\n    ", $lines) . "\n" : '';
    }

    /**
     * Put the head into a finished page.
     *
     * The theme's own copies of every group core prints or turns off are
     * removed (unless "Remove SEO tags printed by themes" is off), except
     * inside bh_head()'s output, which belongs to apps. The <title> is
     * replaced in place. The rest goes right after <meta charset>.
     */
    public function apply(string $html, array $ctx): string
    {
        if (!$this->enabled($ctx)) return $html;
        $end = stripos($html, '</head>');
        if ($end === false) return $html;

        $head = $this->build($ctx);
        $s = $this->settings();
        $states = $head['states'];

        $top = substr($html, 0, $end);
        $rest = substr($html, $end);

        // bh_head()'s output (apps' tags) is set aside so it is never stripped.
        $keep = [];
        foreach (bh_head_outputs() as $i => $chunk) {
            if ($chunk === '' || !str_contains($top, $chunk)) continue;
            $ph = "\x00BHKEEP{$i}\x00";
            $top = str_replace($chunk, $ph, $top);
            $keep[$ph] = $chunk;
        }

        if ($s['strip_theme']) {
            foreach ($states as $group => $state) {
                if ($state === 'external' || $group === 'title') continue;
                $top = $this->stripGroup($top, $group);
            }
        }

        // Title.
        if ($states['title'] === 'core') {
            $t = '<title>' . htmlspecialchars($head['values']['title'], ENT_QUOTES, 'UTF-8') . '</title>';
            $count = 0;
            $top = preg_replace_callback('#<title\b[^>]*>.*?</title>#is', function () use (&$count, $t) {
                return $count++ === 0 ? $t : '';
            }, $top) ?? $top;
            if ($count === 0) $head['_prepend_title'] = $t;
        }

        $block = "    <!-- SEO (Basehim) -->\n" . (isset($head['_prepend_title']) ? '    ' . $head['_prepend_title'] . "\n" : '') . $this->render($head);
        if (trim($block) !== '<!-- SEO (Basehim) -->') {
            if (preg_match('#<meta\s+charset[^>]*>#i', $top, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $top = substr($top, 0, $pos) . "\n" . $block . substr($top, $pos);
            } elseif (preg_match('#<head\b[^>]*>#i', $top, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $top = substr($top, 0, $pos) . "\n" . $block . substr($top, $pos);
            } else {
                $top .= $block;
            }
        }

        if ($keep) $top = strtr($top, $keep);
        // Collapse blank lines left where tags were removed.
        $top = preg_replace("/\n[ \t]*(?:\n[ \t]*)+\n/", "\n", $top) ?? $top;
        return $top . $rest;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function stripGroup(string $html, string $group): string
    {
        $meta = static fn(string $attr, string $re): string =>
            '#[ \t]*<meta\b[^>]*\b' . $attr . '\s*=\s*["\']?' . $re . '["\']?[^>]*>[ \t]*\r?\n?#i';
        $patterns = match ($group) {
            'description' => [$meta('name', 'description')],
            'robots'      => [$meta('name', '(?:robots|googlebot)')],
            'canonical'   => ['#[ \t]*<link\b[^>]*\brel\s*=\s*["\']?canonical["\']?[^>]*>[ \t]*\r?\n?#i'],
            'opengraph'   => [$meta('property', '(?:og|article|fb):[a-z_:]+')],
            'twitter'     => [$meta('name', 'twitter:[a-z_:]+'), $meta('property', 'twitter:[a-z_:]+')],
            'jsonld'      => ['#[ \t]*<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>.*?</script>[ \t]*\r?\n?#is'],
            'generator'   => [$meta('name', 'generator')],
            default       => [],
        };
        foreach ($patterns as $p) $html = preg_replace($p, '', $html) ?? $html;
        return $html;
    }

    /** theme.json "seo": false | {group: false|"off"|true}. Null when not set. */
    private function themeSeoManifest(): array|bool|null
    {
        if (array_key_exists('theme', $this->memo)) return $this->memo['theme'];
        $v = null;
        try {
            $m = Application::getInstance()->make(ThemeService::class)->activeManifest();
            if (is_array($m) && array_key_exists('seo', $m)) $v = $m['seo'];
        } catch (\Throwable) {}
        if (!is_array($v) && $v !== false) $v = null;
        return $this->memo['theme'] = $v;
    }

    private function fillTitle(string $format, string $title, string $site, string $tagline, string $sep, int $page): string
    {
        $out = strtr($format, [
            '%title%' => $title, '%site%' => $site, '%tagline%' => $tagline,
            '%sep%' => $sep, '%page%' => $page > 1 ? 'Page ' . $page : '',
        ]);
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $sepRe = preg_quote($sep, '/');
        $out = preg_replace('/^(\s*' . $sepRe . '\s*)+|(\s*' . $sepRe . '\s*)+$/u', '', trim($out)) ?? $out;
        return trim($out) !== '' ? trim($out) : $title;
    }

    private function shareImage(?array $post, array $meta, array $s): ?array
    {
        $img = null;
        $ogId = (int) ($meta['og_image_id'] ?? 0);
        if ($ogId > 0) {
            try {
                $m = Application::getInstance()->make(MediaService::class)->find($ogId);
                if ($m && !empty($m['url']) && str_starts_with((string) ($m['mime_type'] ?? ''), 'image/')) {
                    $img = ['url' => PostRepository::mediaUrl((string) $m['url']), 'width' => $m['width'] ?? null,
                            'height' => $m['height'] ?? null, 'alt' => $m['alt_text'] ?? null, 'type' => $m['mime_type'] ?? null];
                }
            } catch (\Throwable) {}
        }
        if ($img === null && $post && !empty($post['featured_url'])) {
            $img = ['url' => (string) $post['featured_url'], 'width' => $post['featured_width'] ?? null,
                    'height' => $post['featured_height'] ?? null, 'alt' => $post['featured_alt'] ?? null, 'type' => null];
        }
        if ($img === null && $s['default_image'] !== '') {
            $img = ['url' => $s['default_image'], 'width' => null, 'height' => null, 'alt' => null, 'type' => null];
        }
        if ($img === null) return null;
        if (trim((string) ($img['alt'] ?? '')) === '' && $post) $img['alt'] = (string) ($post['title'] ?? '');
        return $this->normaliseImage($img);
    }

    private function normaliseImage(mixed $v): ?array
    {
        if (is_string($v) && $v !== '') $v = ['url' => $v];
        if (!is_array($v) || empty($v['url'])) return null;
        return [
            'url' => $this->absolute((string) $v['url']),
            'width' => isset($v['width']) && (int) $v['width'] > 0 ? (int) $v['width'] : null,
            'height' => isset($v['height']) && (int) $v['height'] > 0 ? (int) $v['height'] : null,
            'alt' => trim((string) ($v['alt'] ?? '')) ?: null,
            'type' => !empty($v['type']) ? (string) $v['type'] : null,
        ];
    }

    private function jsonLd(array $ctx, array $v, array $s): array
    {
        $on = $s['jsonld'];
        $type = (string) ($ctx['type'] ?? 'other');
        if ($type === '404') return [];
        $origin = $this->origin();
        $home = $origin . $this->base() . '/';
        $nodes = [];
        $orgId = $home . '#organization';
        $siteId = $home . '#website';
        $pageUrl = $v['canonical'] !== '' ? $v['canonical'] : $this->currentUrl(1);
        $pageId = $pageUrl . '#webpage';
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : null;
        $crumbs = $on['breadcrumb'] ? $this->breadcrumbs($ctx, $v) : [];

        if ($on['organization']) {
            $org = ['@type' => 'Organization', '@id' => $orgId, 'name' => $v['site_name'], 'url' => $home];
            $logo = $this->logoUrl();
            if ($logo !== '') $org['logo'] = ['@type' => 'ImageObject', 'url' => $this->absolute($logo)];
            $nodes[] = $org;
        }
        if ($on['website']) {
            $site = ['@type' => 'WebSite', '@id' => $siteId, 'url' => $home, 'name' => $v['site_name'],
                     'potentialAction' => ['@type' => 'SearchAction',
                         'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $origin . $this->base() . '/search?q={search_term_string}'],
                         'query-input' => 'required name=search_term_string']];
            if ($this->tagline() !== '') $site['description'] = $this->tagline();
            if ($on['organization']) $site['publisher'] = ['@id' => $orgId];
            $nodes[] = $site;
        }
        if ($on['webpage']) {
            $pType = match ($type) { 'archive' => 'CollectionPage', 'author' => 'ProfilePage', 'search' => 'SearchResultsPage', default => 'WebPage' };
            $page = ['@type' => $pType, '@id' => $pageId, 'url' => $pageUrl, 'name' => $v['title'], 'inLanguage' => str_replace('_', '-', $v['locale'])];
            if ($v['description'] !== '') $page['description'] = $v['description'];
            if ($on['website']) $page['isPartOf'] = ['@id' => $siteId];
            if ($v['image']) $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $v['image']['url']];
            if ($crumbs) $page['breadcrumb'] = ['@id' => $pageUrl . '#breadcrumb'];
            if ($post) {
                if ($d = Time::iso($post['published_at'] ?? null)) $page['datePublished'] = $d;
                if ($d = Time::iso($post['updated_at'] ?? null)) $page['dateModified'] = $d;
            }
            $nodes[] = $page;
        }
        if ($on['article'] && $type === 'post' && $post) {
            $art = ['@type' => 'BlogPosting', '@id' => $pageUrl . '#article', 'headline' => mb_substr((string) ($post['title'] ?? ''), 0, 110),
                    'mainEntityOfPage' => $on['webpage'] ? ['@id' => $pageId] : $pageUrl];
            if ($v['description'] !== '') $art['description'] = $v['description'];
            if ($v['image']) $art['image'] = array_filter(['@type' => 'ImageObject', 'url' => $v['image']['url'],
                'width' => $v['image']['width'], 'height' => $v['image']['height']]);
            if ($d = Time::iso($post['published_at'] ?? null)) $art['datePublished'] = $d;
            if ($d = Time::iso($post['updated_at'] ?? null)) $art['dateModified'] = $d;
            $author = $this->authorOf($post);
            if ($author) $art['author'] = $author;
            if ($on['organization']) $art['publisher'] = ['@id' => $orgId];
            [$cat, $tags] = $this->postTerms($ctx);
            if ($cat) $art['articleSection'] = $cat['name'];
            if ($tags) $art['keywords'] = implode(', ', array_column($tags, 'name'));
            $art['inLanguage'] = str_replace('_', '-', $v['locale']);
            $nodes[] = $art;
        }
        if ($crumbs) {
            $items = [];
            foreach ($crumbs as $i => $c) {
                $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name']];
                if (!empty($c['url'])) $item['item'] = $c['url'];
                $items[] = $item;
            }
            $nodes[] = ['@type' => 'BreadcrumbList', '@id' => $pageUrl . '#breadcrumb', 'itemListElement' => $items];
        }
        return $nodes;
    }

    /** Home › (category) › this page. Empty on the home page itself. */
    private function breadcrumbs(array $ctx, array $v): array
    {
        $type = (string) ($ctx['type'] ?? 'other');
        if ($type === 'home' || $type === '404') return [];
        $origin = $this->origin() . $this->base();
        $list = [['name' => 'Home', 'url' => $origin . '/']];
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : null;
        if ($type === 'post' && $post) {
            [$cat] = $this->postTerms($ctx);
            if ($cat) $list[] = ['name' => $cat['name'], 'url' => $origin . '/category/' . rawurlencode((string) $cat['slug'])];
            $list[] = ['name' => (string) ($post['title'] ?? ''), 'url' => $v['canonical'] ?: null];
        } elseif ($type === 'page' && $post) {
            $list[] = ['name' => (string) ($post['title'] ?? ''), 'url' => $v['canonical'] ?: null];
        } elseif ($type === 'archive' && is_array($ctx['term'] ?? null)) {
            $list[] = ['name' => (string) ($ctx['term']['name'] ?? ''), 'url' => $v['canonical'] ?: null];
        } elseif ($type === 'author' && is_array($ctx['author'] ?? null)) {
            $list[] = ['name' => (string) ($ctx['author']['display_name'] ?? 'Author'), 'url' => $v['canonical'] ?: null];
        } elseif ($type === 'search') {
            $list[] = ['name' => 'Search', 'url' => null];
        } else {
            return [];
        }
        return $list;
    }

    /** [primary category ['name','slug'] | null, tags [['name','slug'], …]] */
    private function postTerms(array $ctx): array
    {
        $key = 'terms' . (int) ($ctx['post']['id'] ?? 0);
        if (isset($this->memo[$key])) return $this->memo[$key];
        $terms = $ctx['terms'] ?? null;
        if (!is_array($terms) && !empty($ctx['post']['id'])) {
            try { $terms = Application::getInstance()->make(PostService::class)->terms((int) $ctx['post']['id']); } catch (\Throwable) { $terms = []; }
        }
        $cat = null; $tags = [];
        foreach ((array) $terms as $t) {
            if (!is_array($t) || empty($t['name'])) continue;
            $tax = (string) ($t['taxonomy_slug'] ?? $t['taxonomy'] ?? '');
            if ($tax === 'category' && $cat === null) $cat = ['name' => (string) $t['name'], 'slug' => (string) ($t['slug'] ?? '')];
            if ($tax === 'tag' || $tax === 'post_tag') $tags[] = ['name' => (string) $t['name'], 'slug' => (string) ($t['slug'] ?? '')];
        }
        return $this->memo[$key] = [$cat, $tags];
    }

    private function authorOf(array $post): ?array
    {
        $name = trim((string) ($post['author_name'] ?? ''));
        if ($name === '') return null;
        $a = ['@type' => 'Person', 'name' => $name];
        try {
            $svc = Application::getInstance()->make(AuthorService::class);
            $u = $svc->find((int) ($post['author_id'] ?? 0));
            if ($u) {
                $url = $svc->url($u);
                if ($url !== '') $a['url'] = $this->origin() . $this->base() . $url;
            }
        } catch (\Throwable) {}
        return $a;
    }

    private function postSeoMeta(int $postId): array
    {
        if ($postId <= 0) return [];
        $k = 'meta' . $postId;
        if (!isset($this->memo[$k])) {
            try { $this->memo[$k] = Application::getInstance()->make(SeoService::class)->forPost($postId) ?? []; }
            catch (\Throwable) { $this->memo[$k] = []; }
        }
        return $this->memo[$k];
    }

    private function excerptOf(array $post): string
    {
        $ex = trim((string) ($post['excerpt'] ?? ''));
        if ($ex !== '') return $ex;
        // Tags become spaces first, so "<h1>About</h1><p>This" doesn't read "AboutThis".
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', (string) ($post['content'] ?? '')) ?? '';
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' $0 ', $html) ?? $html), ENT_QUOTES, 'UTF-8'));
        return $text !== '' ? mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 160) : '';
    }

    private function oneLine(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<[^>]+>/', ' $0 ', $s) ?? $s)) ?? $s);
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    private function normaliseRobots(string $r): string
    {
        $parts = array_filter(array_map('trim', explode(',', strtolower($r))));
        return implode(', ', array_unique($parts));
    }

    private function twitterHandle(string $h): string
    {
        $h = trim($h);
        if ($h === '') return '';
        if (preg_match('#(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})#i', $h, $m)) $h = $m[1];
        $h = ltrim($h, '@');
        return preg_match('/^[A-Za-z0-9_]{1,15}$/', $h) ? '@' . $h : '';
    }

    private function siteName(): string
    {
        try {
            return (string) Application::getInstance()->make(CustomizerService::class)->coreValue('identity', 'site_title', 'Basehim');
        } catch (\Throwable) {
            return (string) $this->settings->get('general', 'site_title', 'Basehim');
        }
    }

    private function tagline(): string
    {
        try {
            return trim((string) Application::getInstance()->make(CustomizerService::class)->coreValue('identity', 'tagline', ''));
        } catch (\Throwable) {
            return trim((string) $this->settings->get('general', 'tagline', ''));
        }
    }

    private function logoUrl(): string
    {
        try {
            return trim((string) Application::getInstance()->make(CustomizerService::class)->coreValue('identity', 'logo_url', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function locale(): string
    {
        $l = (string) $this->settings->get('general', 'language', 'en_US');
        return preg_match('/^[a-z]{2}_[A-Z]{2}$/', $l) ? $l : 'en_US';
    }

    private function base(): string
    {
        return defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
    }

    private function origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            $app = (string) (\App\Core\Env::get('APP_URL', '') ?? '');
            return rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $app), '/');
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    private function absolute(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) return $url;
        if (str_starts_with($url, '//')) return (str_starts_with($this->origin(), 'https') ? 'https:' : 'http:') . $url;
        return $this->origin() . '/' . ltrim($url, '/');
    }

    /** This request's URL without its query string, keeping ?page=N past page 1. */
    private function currentUrl(int $page): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path === '') $path = '/';
        return $this->origin() . $path . ($page > 1 ? '?page=' . $page : '');
    }
}
