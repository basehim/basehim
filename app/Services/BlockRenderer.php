<?php

namespace App\Services;

use App\Core\HookRegistry;

/**
 * BlockRenderer — server-side rendering for block-format post content.
 *
 * Content saved by the block editor is JSON: {"version":1,"blocks":[...]}
 * where each block is {"id":"b_x","type":"paragraph","data":{...}} and
 * container blocks (columns, column, group, cover, media-text, buttons,
 * details) also carry "innerBlocks": [...].
 *
 * Rendering pipeline (all app-extensible via HookRegistry):
 *   1. `blocks.pre_render`        filter — mutate the decoded block tree.
 *   2. Per block (depth first):
 *        a. `blocks.render.{type}` filter — an app can return HTML for its
 *           own custom types (return non-null to take over rendering). The
 *           block array it receives includes innerBlocks.
 *        b. Otherwise a core renderer handles the built-in types.
 *   3. `blocks.rendered`          filter — final HTML post-processing.
 *
 * Unknown block types render their `data.html` (if provided by the app's
 * client-side save) or an HTML comment placeholder, so content never breaks
 * when an app is deactivated.
 *
 * Every core block accepts the shared settings the editor offers: an HTML
 * anchor (id), extra CSS classes, a style variation (is-style-*), wide/full
 * alignment, text/background colours and a preset font size. The markup uses
 * bh-block-* classes styled by admin/assets/css/blocks.css, which is linked
 * once per page (filter `blocks.stylesheet` to change or disable it).
 */
class BlockRenderer
{
    /** Preset font sizes offered by the editor's Typography panel. */
    private const FONT_SIZES = ['small' => '0.875rem', 'medium' => '1.125rem', 'large' => '1.5rem', 'x-large' => '2.25rem'];

    /** The stylesheet link is emitted once per request. */
    private static bool $styled = false;

    /**
     * Does this content look like block-editor JSON, regardless of what the
     * post's content_format column claims?
     *
     * The two can drift apart easily — the format dropdown is switched, a post
     * is written through the REST API or MCP, imported, or set by an app —
     * and when they do the old code dumped raw JSON onto the public site.
     * Sniffing the content makes rendering self-correcting.
     */
    public static function looksLikeBlocks(string $content): bool
    {
        $t = ltrim($content);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) return false;
        $doc = json_decode($t, true);
        if (!is_array($doc)) return false;
        // Canonical shape: {"version":1,"blocks":[...]}
        if (isset($doc['blocks']) && is_array($doc['blocks'])) return true;
        // Bare list of blocks: [{"type":"...","data":{...}}, ...]
        if (isset($doc[0]) && is_array($doc[0]) && isset($doc[0]['type'])) return true;
        return false;
    }

    public static function render(string $json, ?HookRegistry $hooks = null): string
    {
        $doc = json_decode($json, true);
        if (!is_array($doc)) return $json;                       // not blocks JSON — pass through
        $blocks = $doc['blocks'] ?? (isset($doc[0]) ? $doc : null);
        if (!is_array($blocks)) return $json;

        if ($hooks) $blocks = $hooks->applyFilters('blocks.pre_render', $blocks);
        if (!is_array($blocks)) return '';

        $final = self::renderList($blocks, $hooks, null);
        if ($hooks) $final = $hooks->applyFilters('blocks.rendered', $final, $blocks);

        return self::stylesheet($hooks) . $final;
    }

    /** One <link> to the block stylesheet, the first time content is rendered. */
    private static function stylesheet(?HookRegistry $hooks): string
    {
        if (self::$styled) return '';
        self::$styled = true;
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $ver  = defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1';
        $url  = $base . '/admin/assets/css/blocks.css?v=' . rawurlencode($ver);
        if ($hooks) $url = (string) $hooks->applyFilters('blocks.stylesheet', $url);
        if ($url === '') return '';
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-basehim-blocks>' . "\n";
    }

    /** Render a list of blocks; `$parent` is the containing block's type (or null at top level). */
    private static function renderList(array $blocks, ?HookRegistry $hooks, ?string $parent): string
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $html = self::renderBlock($block, $hooks, $parent);
            if ($html !== null && $html !== '') $out[] = $html;
        }
        return implode("\n", $out);
    }

    private static function renderBlock(array $block, ?HookRegistry $hooks, ?string $parent): ?string
    {
        $type  = (string) ($block['type'] ?? '');
        $data  = is_array($block['data'] ?? null) ? $block['data'] : [];
        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

        $html = null;
        if ($hooks) {
            // Apps take precedence: return a string to own this type.
            $html = $hooks->applyFilters('blocks.render.' . $type, null, $data, $block);
        }
        if ($html === null) {
            $innerHtml = fn() => self::renderList($inner, $hooks, $type);
            $html = self::renderCore($type, $data, $innerHtml, $parent);
        }
        return $html === null ? null : (string) $html;
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    private static function esc(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function color(mixed $v): string
    {
        return is_string($v) && preg_match('/^#[0-9a-f]{3,8}$/i', $v) ? $v : '';
    }

    /** A URL safe to put in href/src: no script or data schemes. */
    private static function safeUrl(mixed $u): string
    {
        $u = trim((string) $u);
        if ($u === '') return '';
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $u) ?? '');
        if (preg_match('/^(javascript|vbscript|data):/', $probe)) return '';
        return $u;
    }

    private static function classList(array $classes): string
    {
        $classes = array_values(array_filter(array_map('trim', $classes), fn($c) => $c !== ''));
        return implode(' ', array_unique($classes));
    }

    /**
     * id/class/style attributes for a block's outer element.
     *
     * @param array  $d       block data
     * @param array  $classes the block's own classes
     * @param array  $styles  the block's own inline styles (prop => value)
     * @param array  $opt     which shared settings apply: color, fontSize, textAlign, align
     */
    private static function attrs(array $d, array $classes, array $styles = [], array $opt = []): string
    {
        $opt += ['color' => true, 'fontSize' => true, 'textAlign' => false, 'align' => true];

        if (!empty($d['styleName']) && preg_match('/^[a-z0-9-]+$/', (string) $d['styleName'])) {
            $classes[] = 'is-style-' . $d['styleName'];
        }
        if ($opt['align'] && !empty($d['align']) && in_array($d['align'], ['left', 'center', 'right', 'wide', 'full'], true)) {
            $classes[] = 'align' . $d['align'];
        }
        if ($opt['textAlign'] && !empty($d['align']) && in_array($d['align'], ['left', 'center', 'right'], true)) {
            $classes[] = 'has-text-align-' . $d['align'];
            $styles['text-align'] = $d['align'];
        }
        if ($opt['color']) {
            if ($c = self::color($d['textColor'] ?? '')) { $classes[] = 'has-text-color'; $styles['color'] = $c; }
            if ($c = self::color($d['backgroundColor'] ?? '')) { $classes[] = 'has-background'; $styles['background-color'] = $c; }
        }
        if ($opt['fontSize'] && !empty($d['fontSize']) && isset(self::FONT_SIZES[$d['fontSize']])) {
            $classes[] = 'has-' . $d['fontSize'] . '-font-size';
            $styles['font-size'] = self::FONT_SIZES[$d['fontSize']];
        }
        if (!empty($d['className'])) {
            foreach (preg_split('/\s+/', (string) $d['className']) as $c) {
                if (preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $c)) $classes[] = $c;
            }
        }

        $out = '';
        if (!empty($d['anchor'])) {
            $id = preg_replace('/[^A-Za-z0-9_:.-]/', '-', (string) $d['anchor']);
            if ($id !== '') $out .= ' id="' . self::esc($id) . '"';
        }
        $cls = self::classList($classes);
        if ($cls !== '') $out .= ' class="' . self::esc($cls) . '"';
        $css = [];
        foreach ($styles as $k => $v) {
            if ($v === '' || $v === null) continue;
            $css[] = $k . ':' . $v;
        }
        if ($css) $out .= ' style="' . self::esc(implode(';', $css)) . '"';
        return $out;
    }

    private static function caption(mixed $html, string $class = 'bh-block-caption'): string
    {
        $c = self::inline((string) $html);
        return trim(strip_tags($c)) === '' ? '' : '<figcaption class="' . $class . '">' . $c . '</figcaption>';
    }

    private static function linkAttrs(array $d, string $hrefKey = 'href'): string
    {
        $href = self::safeUrl($d[$hrefKey] ?? '');
        if ($href === '') return '';
        $a = ' href="' . self::esc($href) . '"';
        $newTab = ($d['linkTarget'] ?? '') === '_blank' || !empty($d['newTab']);
        $rel = trim((string) preg_replace('/[^\w\s-]/', '', (string) ($d['rel'] ?? '')));
        if ($newTab) { $a .= ' target="_blank"'; $rel = trim($rel . ' noopener noreferrer'); }
        if ($rel !== '') $a .= ' rel="' . self::esc($rel) . '"';
        return $a;
    }

    /**
     * Where an embed URL points: iframe source and shape. Mirrors embedInfo()
     * in block-editor.js so the editor preview matches the published page.
     *
     * @return array{provider:string,src:string,aspect:?string,height:?int,card:bool}
     */
    public static function embedInfo(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) return null;
        $r = fn(string $p, string $src, ?string $aspect = '16-9', ?int $height = null, bool $card = false) =>
            ['provider' => $p, 'src' => $src, 'aspect' => $aspect, 'height' => $height, 'card' => $card];

        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([\w-]{6,})#i', $url, $m)) {
            return $r('youtube', 'https://www.youtube.com/embed/' . $m[1], stripos($url, '/shorts/') !== false ? '9-16' : '16-9');
        }
        if (preg_match('#vimeo\.com/(?:video/|channels/[\w-]+/|groups/[\w-]+/videos/)?(\d+)#i', $url, $m)) return $r('vimeo', 'https://player.vimeo.com/video/' . $m[1]);
        if (preg_match('#(?:dailymotion\.com/(?:embed/)?video/|dai\.ly/)([a-z0-9]+)#i', $url, $m)) return $r('dailymotion', 'https://www.dailymotion.com/embed/video/' . $m[1]);
        if (preg_match('#open\.spotify\.com/(?:embed/)?(track|album|playlist|episode|show|artist)/(\w+)#i', $url, $m)) {
            return $r('spotify', 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2], null, in_array($m[1], ['track', 'episode'], true) ? 152 : 352);
        }
        if (preg_match('#loom\.com/(?:share|embed)/(\w+)#i', $url, $m)) return $r('loom', 'https://www.loom.com/embed/' . $m[1]);
        if (preg_match('#codepen\.io/([\w-]+)/(?:pen|embed)/(\w+)#i', $url, $m)) return $r('codepen', 'https://codepen.io/' . $m[1] . '/embed/' . $m[2] . '?default-tab=result', null, 400);
        if (preg_match('#w\.soundcloud\.com/player#i', $url)) return $r('soundcloud', $url, null, 166);
        if (preg_match('#soundcloud\.com/#i', $url)) return $r('soundcloud', 'https://w.soundcloud.com/player/?url=' . rawurlencode($url), null, 166);
        if (preg_match('#(?:twitter|x)\.com/\w+/status/\d+#i', $url)) return $r('twitter', '', null, null, true);
        if (preg_match('#google\.[a-z.]+/maps/embed#i', $url)) return $r('google-maps', $url, '4-3');
        if (preg_match('#ted\.com/talks/([\w-]+)#i', $url, $m)) return $r('ted', 'https://embed.ted.com/talks/' . $m[1]);
        return $r('generic', $url);
    }

    private static function relativeLuminance(string $hex): float
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (strlen($h) < 6) return 0.0;
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($h, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    // ------------------------------------------------------------------
    // Core blocks
    // ------------------------------------------------------------------

    /** Render a built-in block type. Returns null for unknown types without fallback html. */
    private static function renderCore(string $type, array $d, \Closure $inner, ?string $parent): ?string
    {
        switch ($type) {
            case 'paragraph':
                $text = self::inline($d['text'] ?? '');
                $cls = !empty($d['dropCap']) ? ['has-drop-cap'] : [];
                return '<p' . self::attrs($d, $cls, [], ['textAlign' => true, 'align' => false]) . '>' . $text . '</p>';

            case 'heading':
                $level = max(1, min(6, (int) ($d['level'] ?? 2)));
                return "<h{$level}" . self::attrs($d, ['bh-block-heading'], [], ['textAlign' => true, 'align' => false]) . '>' . self::inline($d['text'] ?? '') . "</h{$level}>";

            case 'list':
                $tag = (($d['style'] ?? 'ul') === 'ol') ? 'ol' : 'ul';
                $items = is_array($d['items'] ?? null) ? $d['items'] : [];
                $lis = implode('', array_map(fn($i) => '<li>' . self::inline(is_string($i) ? $i : '') . '</li>', $items));
                $styles = [];
                // Centred or right-aligned lists need the markers inside, or they
                // sit far from the text they belong to (1.x data).
                if (!empty($d['align']) && in_array($d['align'], ['center', 'right'], true)) {
                    $styles = ['list-style-position' => 'inside', 'padding-left' => '0'];
                }
                $extra = '';
                if ($tag === 'ol' && (int) ($d['start'] ?? 1) > 1) $extra .= ' start="' . (int) $d['start'] . '"';
                if ($tag === 'ol' && !empty($d['reversed'])) $extra .= ' reversed';
                return "<{$tag}" . self::attrs($d, ['bh-block-list'], $styles, ['textAlign' => true, 'align' => false]) . $extra . ">{$lis}</{$tag}>";

            case 'quote':
                $cite = trim(strip_tags(self::inline($d['cite'] ?? ''))) !== '' ? '<cite>' . self::inline($d['cite']) . '</cite>' : '';
                $paras = self::paragraphs($d['text'] ?? '');
                return '<blockquote' . self::attrs($d, ['bh-block-quote'], [], ['textAlign' => true, 'align' => false]) . '>' . $paras . $cite . '</blockquote>';

            case 'pullquote':
                $cite = trim(strip_tags(self::inline($d['cite'] ?? ''))) !== '' ? '<cite>' . self::inline($d['cite']) . '</cite>' : '';
                return '<figure' . self::attrs($d, ['bh-block-pullquote']) . '><blockquote>' . self::paragraphs($d['text'] ?? '') . $cite . '</blockquote></figure>';

            case 'code':
                $lang = !empty($d['language']) && preg_match('/^[\w+#-]+$/', (string) $d['language']) ? ' class="language-' . self::esc($d['language']) . '"' : '';
                return '<pre' . self::attrs($d, ['bh-block-code'], [], ['color' => false, 'fontSize' => false, 'align' => false]) . '><code' . $lang . '>' . self::esc($d['code'] ?? '') . '</code></pre>';

            case 'preformatted':
                return '<pre' . self::attrs($d, ['bh-block-preformatted'], [], ['align' => false]) . '>' . self::inline($d['text'] ?? '') . '</pre>';

            case 'html':
                // Trusted admin-authored raw HTML (same trust level as the old editor).
                return (string) ($d['html'] ?? '');

            case 'details':
                $summary = self::inline($d['summary'] ?? '');
                return '<details' . self::attrs($d, ['bh-block-details'], [], ['align' => false]) . (!empty($d['open']) ? ' open' : '') . '><summary>' . ($summary !== '' ? $summary : 'Details') . '</summary>' . $inner() . '</details>';

            case 'image':
                return self::image($d);

            case 'gallery':
                $imgs = is_array($d['images'] ?? null) ? $d['images'] : [];
                $items = '';
                foreach ($imgs as $im) {
                    if (!is_array($im)) continue;
                    $src = self::safeUrl($im['url'] ?? '');
                    if ($src === '') continue;
                    $items .= '<figure class="bh-gallery-item"><img src="' . self::esc($src) . '" alt="' . self::esc($im['alt'] ?? '') . '" loading="lazy">'
                        . self::caption($im['caption'] ?? '', 'bh-gallery-item__caption') . '</figure>';
                }
                if ($items === '') return '';
                $cols = max(1, min(8, (int) ($d['columns'] ?? min(3, count($imgs)))));
                $cls = ['bh-block-gallery', 'columns-' . $cols];
                if (($d['crop'] ?? true) !== false) $cls[] = 'is-cropped';
                return '<figure' . self::attrs($d, $cls, ['--bh-gallery-columns' => (string) $cols], ['color' => false, 'fontSize' => false]) . '><div class="bh-gallery-grid">' . $items . '</div>' . self::caption($d['caption'] ?? '') . '</figure>';

            case 'video':
                $src = self::safeUrl($d['src'] ?? '');
                if ($src === '') return '';
                $a = ' src="' . self::esc($src) . '"';
                if (($d['controls'] ?? true) !== false) $a .= ' controls';
                foreach (['autoplay' => 'autoplay', 'loop' => 'loop', 'muted' => 'muted', 'playsInline' => 'playsinline'] as $k => $attr) {
                    if (!empty($d[$k])) $a .= ' ' . $attr;
                }
                if (!empty($d['poster']) && ($p = self::safeUrl($d['poster'])) !== '') $a .= ' poster="' . self::esc($p) . '"';
                $a .= ' preload="' . (in_array($d['preload'] ?? '', ['auto', 'none'], true) ? $d['preload'] : 'metadata') . '"';
                return '<figure' . self::attrs($d, ['bh-block-video'], [], ['color' => false, 'fontSize' => false]) . '><video' . $a . '></video>' . self::caption($d['caption'] ?? '') . '</figure>';

            case 'audio':
                $src = self::safeUrl($d['src'] ?? '');
                if ($src === '') return '';
                $a = ' src="' . self::esc($src) . '" controls preload="none"';
                if (!empty($d['autoplay'])) $a .= ' autoplay';
                if (!empty($d['loop'])) $a .= ' loop';
                return '<figure' . self::attrs($d, ['bh-block-audio'], [], ['color' => false, 'fontSize' => false]) . '><audio' . $a . '></audio>' . self::caption($d['caption'] ?? '') . '</figure>';

            case 'file':
                $href = self::safeUrl($d['href'] ?? '');
                if ($href === '') return '';
                $name = self::inline($d['fileName'] ?? '');
                if (trim(strip_tags($name)) === '') $name = self::esc(basename(parse_url($href, PHP_URL_PATH) ?: 'Download'));
                $target = ($d['linkTarget'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
                $out = '<div' . self::attrs($d, ['bh-block-file'], [], ['color' => false, 'fontSize' => false]) . '>';
                if (!empty($d['showPreview']) && preg_match('/\.pdf(\?|$)/i', $href)) {
                    $out .= '<object class="bh-file-embed" data="' . self::esc($href) . '" type="application/pdf" aria-label="' . self::esc(strip_tags($name)) . '"></object>';
                }
                $out .= '<a href="' . self::esc($href) . '"' . $target . '>' . $name . '</a>';
                if (($d['showDownloadButton'] ?? true) !== false) {
                    $label = trim(strip_tags((string) ($d['downloadText'] ?? ''))) !== '' ? self::esc(strip_tags((string) $d['downloadText'])) : 'Download';
                    $out .= ' <a href="' . self::esc($href) . '" class="bh-file-button" download>' . $label . '</a>';
                }
                return $out . '</div>';

            case 'embed':
                return self::embed($d);

            case 'cover':
                return self::cover($d, $inner);

            case 'media-text':
                return self::mediaText($d, $inner);

            case 'buttons':
                $cls = ['bh-block-buttons', 'is-content-justification-' . (in_array($d['justify'] ?? '', ['center', 'right', 'space-between'], true) ? $d['justify'] : 'left')];
                if (($d['orientation'] ?? '') === 'vertical') $cls[] = 'is-vertical';
                return '<div' . self::attrs($d, $cls, [], ['color' => false]) . '>' . $inner() . '</div>';

            case 'button':
                return self::button($d, $parent);

            case 'columns':
                $cls = ['bh-block-columns'];
                if (($d['isStackedOnMobile'] ?? true) === false) $cls[] = 'is-not-stacked-on-mobile';
                if (!empty($d['verticalAlign']) && in_array($d['verticalAlign'], ['top', 'center', 'bottom'], true)) $cls[] = 'are-vertically-aligned-' . $d['verticalAlign'];
                return '<div' . self::attrs($d, $cls) . '>' . $inner() . '</div>';

            case 'column':
                $styles = [];
                $w = (float) ($d['width'] ?? 0);
                if ($w > 0 && $w <= 100) $styles['flex-basis'] = rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.') . '%';
                $cls = ['bh-block-column'];
                if (!empty($d['verticalAlign']) && in_array($d['verticalAlign'], ['top', 'center', 'bottom'], true)) $cls[] = 'is-vertically-aligned-' . $d['verticalAlign'];
                return '<div' . self::attrs($d, $cls, $styles, ['align' => false]) . '>' . $inner() . '</div>';

            case 'group':
                $tag = in_array($d['tagName'] ?? '', ['section', 'main', 'article', 'aside', 'header', 'footer'], true) ? $d['tagName'] : 'div';
                $cls = ['bh-block-group'];
                if (in_array($d['layout'] ?? '', ['row', 'stack'], true)) $cls[] = 'is-layout-' . $d['layout'];
                if (in_array($d['justify'] ?? '', ['center', 'right', 'space-between'], true)) $cls[] = 'is-content-justification-' . $d['justify'];
                if (($d['layout'] ?? '') === 'row' && ($d['wrap'] ?? true) === false) $cls[] = 'is-nowrap';
                if (in_array($d['padding'] ?? '', ['small', 'medium', 'large'], true)) $cls[] = 'has-padding-' . $d['padding'];
                return "<{$tag}" . self::attrs($d, $cls) . '>' . $inner() . "</{$tag}>";

            case 'divider':
                $styles = [];
                if ($c = self::color($d['backgroundColor'] ?? '')) { $styles['color'] = $c; $styles['background-color'] = $c; }
                return '<hr' . self::attrs($d, ['bh-block-separator', $styles ? 'has-background' : ''], $styles, ['color' => false, 'fontSize' => false, 'align' => false]) . '>';

            case 'spacer':
                // 1.x spacers had no height saved when left at the old 40px default.
                $h = max(1, min(2000, (int) ($d['height'] ?? 40)));
                return '<div' . self::attrs($d, ['bh-block-spacer'], ['height' => $h . 'px'], ['color' => false, 'fontSize' => false, 'align' => false]) . ' aria-hidden="true"></div>';

            case 'table':
                return self::table($d);

            default:
                // Unknown type (app block with no server renderer active):
                // fall back to client-provided html, else leave a marker.
                if (!empty($d['html'])) return (string) $d['html'];
                return '<!-- bh-block:' . self::esc($type) . ' (no renderer) -->';
        }
    }

    /** Quote text: blank lines (two <br>) become separate paragraphs. */
    private static function paragraphs(mixed $html): string
    {
        $parts = preg_split('#(?:<br\s*/?>\s*){2,}#i', self::inline((string) $html)) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if (trim(strip_tags($p)) === '' && stripos($p, '<br') === false) continue;
            $out .= '<p>' . $p . '</p>';
        }
        return $out;
    }

    private static function image(array $d): string
    {
        $src = self::safeUrl($d['url'] ?? '');
        if ($src === '') return '';
        $w = (int) ($d['width'] ?? 0);
        $img = '<img src="' . self::esc($src) . '" alt="' . self::esc($d['alt'] ?? '') . '"'
            . ($w > 0 ? ' width="' . $w . '"' : '') . ' loading="lazy" decoding="async">';
        $link = self::linkAttrs($d);
        if ($link !== '') $img = '<a' . $link . '>' . $img . '</a>';
        $cls = ['bh-block-image'];
        $styles = [];
        // 1.x markup kept the align-* class; inline width keeps captions in line
        // with a resized image.
        if (!empty($d['align']) && in_array($d['align'], ['left', 'center', 'right'], true)) $cls[] = 'align-' . $d['align'];
        if ($w > 0) { $cls[] = 'is-resized'; $styles['width'] = $w . 'px'; $styles['max-width'] = '100%'; }
        return '<figure' . self::attrs($d, $cls, $styles, ['color' => false, 'fontSize' => false]) . '>' . $img . self::caption($d['caption'] ?? '') . '</figure>';
    }

    private static function embed(array $d): string
    {
        $url = trim((string) ($d['url'] ?? ''));
        if ($url === '') return '';
        $info = self::embedInfo($url);
        if ($info === null) return '';
        $cls = ['bh-block-embed', 'bh-embed', 'is-provider-' . $info['provider']];
        $cap = self::caption($d['caption'] ?? '');
        if ($info['card']) {
            $body = '<blockquote class="twitter-tweet" data-dnt="true"><a href="' . self::esc($url) . '">' . self::esc($url) . '</a></blockquote>'
                . '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
            return '<figure' . self::attrs($d, $cls, [], ['color' => false, 'fontSize' => false]) . '>' . $body . $cap . '</figure>';
        }
        $src = self::safeUrl($info['src']);
        if ($src === '') return '';
        $wrapCls = 'bh-embed-wrap';
        $wrapStyle = '';
        if ($info['aspect'] && ($d['responsive'] ?? true) !== false) $wrapCls .= ' has-aspect is-aspect-' . $info['aspect'];
        if ($info['height']) $wrapStyle = ' style="height:' . (int) $info['height'] . 'px"';
        $iframe = '<iframe src="' . self::esc($src) . '" title="' . self::esc(ucfirst($info['provider']) . ' embed') . '" loading="lazy" allowfullscreen'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
            . ' referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe>';
        return '<figure' . self::attrs($d, $cls, [], ['color' => false, 'fontSize' => false]) . '><div class="' . $wrapCls . '"' . $wrapStyle . '>' . $iframe . '</div>' . $cap . '</figure>';
    }

    private static function cover(array $d, \Closure $inner): string
    {
        $url = self::safeUrl($d['url'] ?? '');
        $overlay = self::color($d['overlayColor'] ?? '') ?: '#000000';
        $dim = max(0, min(100, (int) ($d['dimRatio'] ?? 50)));
        $unit = ($d['minHeightUnit'] ?? '') === 'vh' ? 'vh' : 'px';
        $minH = max(1, min($unit === 'vh' ? 100 : 3000, (int) ($d['minHeight'] ?? ($unit === 'vh' ? 100 : 430))));
        $pos = (string) ($d['contentPosition'] ?? 'center center');
        if (!preg_match('/^(top|center|bottom) (left|center|right)$/', $pos)) $pos = 'center center';

        $cls = ['bh-block-cover', 'is-position-' . str_replace(' ', '-', $pos)];
        if ($url === '' && $dim >= 50 && self::relativeLuminance($overlay) > 0.5) $cls[] = 'is-light';
        $styles = ['min-height' => $minH . $unit];
        $media = '';
        if ($url !== '') {
            if (($d['backgroundType'] ?? '') === 'video') {
                $media = '<video class="bh-cover__video" src="' . self::esc($url) . '" autoplay muted loop playsinline aria-hidden="true"></video>';
            } elseif (!empty($d['hasParallax'])) {
                $cls[] = 'has-parallax';
                $styles['background-image'] = 'url(' . str_replace([')', '(', '"', "'"], ['%29', '%28', '%22', '%27'], $url) . ')';
                if (!empty($d['focalPoint']) && preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $d['focalPoint'])) $styles['background-position'] = $d['focalPoint'];
            } else {
                $fp = !empty($d['focalPoint']) && preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $d['focalPoint']) ? ' style="object-position:' . $d['focalPoint'] . '"' : '';
                $media = '<img class="bh-cover__image" src="' . self::esc($url) . '" alt="' . self::esc($d['alt'] ?? '') . '"' . $fp . ' loading="lazy" decoding="async">';
            }
        }
        $span = '<span class="bh-cover__overlay" aria-hidden="true" style="background-color:' . $overlay . ';opacity:' . ($dim / 100) . '"></span>';
        return '<div' . self::attrs($d, $cls, $styles, ['color' => false]) . '>' . $media . $span . '<div class="bh-cover__inner">' . $inner() . '</div></div>';
    }

    private static function mediaText(array $d, \Closure $inner): string
    {
        $url = self::safeUrl($d['url'] ?? '');
        $w = max(15, min(85, (int) ($d['mediaWidth'] ?? 50)));
        $right = ($d['mediaPosition'] ?? 'left') === 'right';
        $cls = ['bh-block-media-text'];
        if ($right) $cls[] = 'has-media-on-the-right';
        if (($d['isStackedOnMobile'] ?? true) !== false) $cls[] = 'is-stacked-on-mobile';
        if (!empty($d['imageFill'])) $cls[] = 'is-image-fill';
        if (in_array($d['verticalAlign'] ?? '', ['top', 'center', 'bottom'], true)) $cls[] = 'is-vertically-aligned-' . $d['verticalAlign'];
        $styles = ['grid-template-columns' => $right ? (100 - $w) . '% ' . $w . '%' : $w . '% ' . (100 - $w) . '%'];
        $media = '';
        if ($url !== '') {
            if (($d['mediaType'] ?? '') === 'video') {
                $media = '<video src="' . self::esc($url) . '" controls preload="metadata"></video>';
            } else {
                $fp = !empty($d['focalPoint']) && preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $d['focalPoint']) ? ' style="object-position:' . $d['focalPoint'] . '"' : '';
                $media = '<img src="' . self::esc($url) . '" alt="' . self::esc($d['alt'] ?? '') . '"' . $fp . ' loading="lazy" decoding="async">';
                $link = self::linkAttrs($d);
                if ($link !== '') $media = '<a' . $link . '>' . $media . '</a>';
            }
        }
        $fig = '<figure class="bh-media-text__media">' . $media . '</figure>';
        $content = '<div class="bh-media-text__content">' . $inner() . '</div>';
        return '<div' . self::attrs($d, $cls, $styles) . '>' . $fig . $content . '</div>';
    }

    private static function button(array $d, ?string $parent): string
    {
        $text = self::inline($d['text'] ?? '');
        $href = self::linkAttrs($d, 'url');
        // A 1.x button with neither text nor link renders nothing, as before.
        if (trim(strip_tags($text)) === '' && $href === '') return '';
        if (trim(strip_tags($text)) === '') $text = 'Click here';

        $styles = [];
        $classes = ['bh-block-button__link', 'bh-btn-block'];
        $bg = self::color($d['backgroundColor'] ?? '');
        $fg = self::color($d['textColor'] ?? '');
        $outline = ($d['styleName'] ?? '') === 'outline';
        if ($bg !== '') { $classes[] = 'has-background'; $styles[$outline ? 'border-color' : 'background-color'] = $bg; if ($outline && $fg === '') $styles['color'] = $bg; }
        if ($fg !== '') { $classes[] = 'has-text-color'; $styles['color'] = $fg; }
        if (!empty($d['fontSize']) && isset(self::FONT_SIZES[$d['fontSize']])) $styles['font-size'] = self::FONT_SIZES[$d['fontSize']];
        $css = '';
        foreach ($styles as $k => $v) $css .= $k . ':' . $v . ';';
        $a = '<a class="' . self::esc(implode(' ', $classes)) . '"' . $href . ($css !== '' ? ' style="' . self::esc(rtrim($css, ';')) . '"' : '') . '>' . $text . '</a>';

        $wrapCls = ['bh-block-button'];
        $width = (int) ($d['width'] ?? 0);
        if (in_array($width, [25, 50, 75, 100], true)) { $wrapCls[] = 'has-custom-width'; $wrapCls[] = 'bh-block-button__width-' . $width; }
        // The wrapper takes style, anchor and classes; colours live on the link.
        $btn = '<div' . self::attrs(array_diff_key($d, array_flip(['textColor', 'backgroundColor', 'fontSize', 'align'])), $wrapCls) . '>' . $a . '</div>';

        if ($parent === 'buttons') return $btn;
        // A 1.x top-level button: wrap it so its alignment still applies.
        $j = in_array($d['align'] ?? '', ['center', 'right'], true) ? $d['align'] : 'left';
        return '<div class="bh-block-buttons is-content-justification-' . $j . '">' . $btn . '</div>';
    }

    private static function table(array $d): string
    {
        $align = is_array($d['columnAlign'] ?? null) ? $d['columnAlign'] : [];
        $section = function (string $key, string $tag, string $cell) use ($d, $align): string {
            $rows = is_array($d[$key] ?? null) ? $d[$key] : [];
            if (!$rows) return '';
            $html = '';
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $html .= '<tr>';
                foreach (array_values($row) as $i => $c) {
                    $a = in_array($align[$i] ?? '', ['left', 'center', 'right'], true) ? ' class="has-text-align-' . $align[$i] . '" data-align="' . $align[$i] . '"' : '';
                    $html .= "<{$cell}{$a}>" . self::inline(is_string($c) ? $c : (string) (is_array($c) ? ($c['content'] ?? '') : '')) . "</{$cell}>";
                }
                $html .= '</tr>';
            }
            return "<{$tag}>{$html}</{$tag}>";
        };
        $body = $section('head', 'thead', 'th') . $section('body', 'tbody', 'td') . $section('foot', 'tfoot', 'td');
        if ($body === '') return '';
        $tableCls = ($d['hasFixedLayout'] ?? true) !== false ? ' class="has-fixed-layout"' : '';
        return '<figure' . self::attrs($d, ['bh-block-table']) . '><table' . $tableCls . '>' . $body . '</table>' . self::caption($d['caption'] ?? '') . '</figure>';
    }

    /**
     * Sanitize inline rich text coming from contenteditable: allow a small set
     * of formatting tags and strip everything else, including every attribute
     * except a link's href (and target="_blank", which gets rel added).
     */
    private static function inline(string $html): string
    {
        $allowed = '<b><strong><i><em><u><s><code><a><br><mark><sub><sup>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
            $out = '<a';
            if (preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[0], $h)) {
                $href = html_entity_decode($h[2] !== '' ? $h[2] : (($h[3] ?? '') !== '' ? $h[3] : ($h[4] ?? '')), ENT_QUOTES, 'UTF-8');
                $href = self::safeUrl($href);
                $out .= ' href="' . htmlspecialchars($href !== '' ? $href : '#', ENT_QUOTES, 'UTF-8') . '"';
            }
            if (preg_match('/target\s*=\s*["\']?_blank/i', $m[0])) $out .= ' target="_blank" rel="noopener noreferrer"';
            else $out .= ' rel="noopener"';
            return $out . '>';
        }, $html) ?? '';
        // Remove attributes from all other allowed tags.
        $html = preg_replace('/<(b|strong|i|em|u|s|code|br|mark|sub|sup)\b[^>]*>/i', '<$1>', $html) ?? '';
        return $html;
    }
}
