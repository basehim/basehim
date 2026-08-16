<?php

namespace App\Services;

use App\Core\HookRegistry;

/**
 * BlockRenderer — server-side rendering for block-format post content.
 *
 * Content saved by the block editor is JSON: {"version":1,"blocks":[...]}
 * where each block is {"id":"b_x","type":"paragraph","data":{...}}.
 *
 * Rendering pipeline (all app-extensible via HookRegistry):
 *   1. `blocks.pre_render`        filter — mutate the decoded block list.
 *   2. Per block:
 *        a. `blocks.render.{type}` filter — a app can return HTML for its
 *           own custom types (return non-null to take over rendering).
 *        b. Otherwise a core renderer handles the built-in types.
 *   3. `blocks.rendered`          filter — final HTML post-processing.
 *
 * Unknown block types render their `data.html` (if provided by the app's
 * client-side save) or an HTML comment placeholder, so content never breaks
 * when a app is deactivated.
 */
class BlockRenderer
{
    /**
     * Does this content look like block-editor JSON, regardless of what the
     * post's content_format column claims?
     *
     * The two can drift apart easily — the format dropdown is switched, a post
     * is written through the REST API or MCP, imported, or set by a app —
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

        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $type = (string)($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $html = null;
            if ($hooks) {
                // Apps take precedence: return a string to own this type.
                $html = $hooks->applyFilters('blocks.render.' . $type, null, $data, $block);
            }
            if ($html === null) $html = self::renderCore($type, $data);
            if ($html !== null && $html !== '') $out[] = $html;
        }

        $final = implode("\n", $out);
        if ($hooks) $final = $hooks->applyFilters('blocks.rendered', $final, $blocks);
        return $final;
    }

    /** Render a built-in block type. Returns null for unknown types w/o fallback html. */
    private static function renderCore(string $type, array $d): ?string
    {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $alignClass = fn() => !empty($d['align']) ? ' style="text-align:' . $esc($d['align']) . '"' : '';

        switch ($type) {
            case 'paragraph':
                // `text` carries inline HTML (b/i/a/code) produced by contenteditable.
                return '<p' . $alignClass() . '>' . self::inline($d['text'] ?? '') . '</p>';

            case 'heading':
                $level = (int)($d['level'] ?? 2);
                $level = max(1, min(6, $level));
                return "<h{$level}" . $alignClass() . '>' . self::inline($d['text'] ?? '') . "</h{$level}>";

            case 'image':
                if (empty($d['url'])) return '';
                $img = '<img src="' . $esc($d['url']) . '" alt="' . $esc($d['alt'] ?? '') . '" loading="lazy">';
                $cap = !empty($d['caption']) ? '<figcaption>' . self::inline($d['caption']) . '</figcaption>' : '';
                // The align-* class is kept for themes that style it, but an inline style
                // is emitted too: no bundled theme defines those classes, so alignment
                // was silently doing nothing on the published page.
                $alignStyle = '';
                if (!empty($d['align'])) {
                    $alignStyle = match ($d['align']) {
                        'center' => 'margin-left:auto;margin-right:auto;display:block;width:fit-content;max-width:100%',
                        'right'  => 'margin-left:auto;margin-right:0;display:block;width:fit-content;max-width:100%',
                        'left'   => 'margin-right:auto;margin-left:0;display:block;width:fit-content;max-width:100%',
                        default  => '',
                    };
                }
                $cls = !empty($d['align'])
                    ? ' class="align-' . $esc($d['align']) . '"'
                        . ($alignStyle !== '' ? ' style="' . $alignStyle . '"' : '')
                    : '';
                return "<figure{$cls}>{$img}{$cap}</figure>";

            case 'list':
                $tag = (($d['style'] ?? 'ul') === 'ol') ? 'ol' : 'ul';
                $items = is_array($d['items'] ?? null) ? $d['items'] : [];
                $lis = implode('', array_map(fn($i) => '<li>' . self::inline($i) . '</li>', $items));
                // Alignment on a list needs the markers inside, or they sit far from
                // the text they belong to.
                $style = '';
                if (!empty($d['align'])) {
                    $style = ' style="text-align:' . $esc($d['align']) . '"';
                    if ($d['align'] === 'center' || $d['align'] === 'right') {
                        $style = ' style="text-align:' . $esc($d['align'])
                               . ';list-style-position:inside;padding-left:0"';
                    }
                }
                return "<{$tag}{$style}>{$lis}</{$tag}>";

            case 'quote':
                $cite = !empty($d['cite']) ? '<cite>' . self::inline($d['cite']) . '</cite>' : '';
                return '<blockquote' . $alignClass() . '><p>' . self::inline($d['text'] ?? '') . '</p>' . $cite . '</blockquote>';

            case 'code':
                $lang = !empty($d['language']) ? ' class="language-' . $esc($d['language']) . '"' : '';
                return '<pre><code' . $lang . '>' . $esc($d['code'] ?? '') . '</code></pre>';

            case 'html':
                // Trusted admin-authored raw HTML (same trust level as the old editor).
                return (string)($d['html'] ?? '');

            case 'divider':
                return '<hr>';

            case 'spacer':
                $h = max(4, min(400, (int)($d['height'] ?? 40)));
                return '<div style="height:' . $h . 'px" aria-hidden="true"></div>';

            case 'button':
                if (empty($d['url']) && empty($d['text'])) return '';
                return '<p' . $alignClass() . '><a class="bh-btn-block" href="' . $esc($d['url'] ?? '#') . '">'
                    . self::inline($d['text'] ?? 'Click here') . '</a></p>';

            case 'embed':
                if (empty($d['url'])) return '';
                $url = $esc($d['url']);
                // Centring an embed needs a margin on the wrapper, not text-align.
                $wrapStyle = '';
                if (!empty($d['align'])) {
                    $wrapStyle = match ($d['align']) {
                        'center' => ' style="margin-left:auto;margin-right:auto"',
                        'right'  => ' style="margin-left:auto;margin-right:0"',
                        'left'   => ' style="margin-right:auto;margin-left:0"',
                        default  => '',
                    };
                }
                // Simple, safe iframe embed; apps can override via blocks.render.embed.
                return '<div class="bh-embed"' . $wrapStyle . '><iframe src="' . $url . '" loading="lazy" allowfullscreen frameborder="0"></iframe></div>';

            default:
                // Unknown type (app block with no server renderer active):
                // fall back to client-provided html, else leave a marker.
                if (!empty($d['html'])) return (string)$d['html'];
                return '<!-- bh-block:' . $esc($type) . ' (no renderer) -->';
        }
    }

    /**
     * Sanitize inline rich text coming from contenteditable: allow a small set
     * of formatting tags, strip everything else (including event handlers, by
     * virtue of stripping attributes except href on links).
     */
    private static function inline(string $html): string
    {
        $allowed = '<b><strong><i><em><u><s><code><a><br><mark><sub><sup>';
        $html = strip_tags($html, $allowed);
        // Strip all attributes except href on <a>, and force rel on links.
        $html = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
            if (preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $h)) {
                $href = $h[2] !== '' ? $h[2] : ($h[3] ?? '');
                // Block javascript: URLs.
                if (preg_match('/^\s*javascript:/i', $href)) $href = '#';
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" rel="noopener">';
            }
            return '<a>';
        }, $html);
        // Remove attributes from all other allowed tags.
        $html = preg_replace('/<(b|strong|i|em|u|s|code|br|mark|sub|sup)\b[^>]*>/i', '<$1>', $html);
        return $html;
    }
}
