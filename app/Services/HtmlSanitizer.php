<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Allow-list HTML filter for content written by people who may not publish
 * arbitrary HTML: everyone without the unfiltered_html capability (authors,
 * contributors, subscribers, API keys and MCP tokens of such accounts, and
 * code running without a signed-in user).
 *
 * The public site and the admin share an origin, so a post's HTML runs with
 * the session of whoever opens it. Until 1.2.52 an author could publish a
 * <script> or an onerror= attribute and act as any administrator who viewed
 * the post. Editors and administrators keep raw HTML (unfiltered_html).
 *
 * How it works: the fragment is parsed into a DOM, everything not on the
 * allow-list is removed (scripts and other raw-text elements with their
 * contents; unknown tags are unwrapped and keep their text), attributes are
 * reduced to the allowed ones, and URLs and inline styles are checked. The
 * result is always the *re-serialized* DOM, never the input: the parser here
 * (libxml) and a browser can disagree on malformed markup, and only output
 * produced from the checked tree is known to mean the same thing to both.
 *
 * isSimpleMarkup() is the strict test used for raw HTML inside Markdown,
 * where the source is kept as written: a fragment passes only if it uses an
 * unambiguous subset (known tags, double-quoted attribute values with no
 * entities or angle brackets) that every parser reads the same way.
 */
final class HtmlSanitizer
{
    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'textarea', 'title',
        'template', 'svg', 'math', 'object', 'embed', 'applet', 'frame', 'frameset', 'select', 'option',
        'optgroup', 'datalist', 'button', 'link', 'meta', 'base', 'head', 'portal', 'param', 'canvas',
    ];

    /** Kept, with the attributes each may carry besides the global ones. */
    private const ALLOWED = [
        'a' => ['href', 'target', 'rel', 'name', 'hreflang', 'type', 'referrerpolicy'],
        'abbr' => [], 'address' => [], 'article' => [], 'aside' => [], 'b' => [], 'bdi' => [], 'bdo' => [],
        'blockquote' => ['cite'], 'br' => [], 'caption' => ['align'], 'center' => [], 'cite' => [], 'code' => [],
        'col' => ['span', 'width', 'align', 'valign'], 'colgroup' => ['span', 'width', 'align', 'valign'],
        'data' => ['value'], 'dd' => [], 'del' => ['cite', 'datetime'], 'details' => ['open'], 'dfn' => [],
        'div' => ['align'], 'dl' => [], 'dt' => [], 'em' => [], 'figcaption' => [], 'figure' => [], 'footer' => [],
        'h1' => ['align'], 'h2' => ['align'], 'h3' => ['align'], 'h4' => ['align'], 'h5' => ['align'], 'h6' => ['align'],
        'header' => [], 'hgroup' => [], 'hr' => [], 'i' => [],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy', 'sandbox'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'align', 'border'],
        'input' => ['type', 'checked', 'disabled'],
        'ins' => ['cite', 'datetime'], 'kbd' => [], 'li' => ['value'], 'main' => [], 'mark' => [], 'nav' => [],
        'ol' => ['start', 'reversed', 'type'], 'p' => ['align'], 'picture' => [], 'pre' => [], 'q' => ['cite'],
        'rp' => [], 'rt' => [], 'ruby' => [], 's' => [], 'samp' => [], 'section' => [], 'small' => [],
        'source' => ['src', 'srcset', 'sizes', 'type', 'media'], 'span' => [], 'strike' => [], 'strong' => [],
        'sub' => [], 'summary' => [], 'sup' => [],
        'table' => ['width', 'border', 'cellpadding', 'cellspacing', 'align', 'summary'],
        'tbody' => ['align', 'valign'], 'thead' => ['align', 'valign'], 'tfoot' => ['align', 'valign'],
        'td' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr', 'align', 'valign', 'width', 'height'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr', 'align', 'valign', 'width', 'height'],
        'time' => ['datetime'], 'tr' => ['align', 'valign'],
        'track' => ['src', 'kind', 'srclang', 'label', 'default'], 'tt' => [], 'u' => [], 'ul' => ['type'],
        'var' => [], 'wbr' => [],
        'video' => ['src', 'poster', 'controls', 'width', 'height', 'preload', 'loop', 'muted', 'autoplay', 'playsinline'],
        'audio' => ['src', 'controls', 'preload', 'loop', 'muted', 'autoplay'],
    ];

    /** Allowed on every kept element (plus aria-* and data-*, except data-bh-*). */
    private const GLOBAL_ATTRS = ['class', 'id', 'title', 'lang', 'dir', 'role', 'style', 'hidden', 'tabindex', 'translate'];

    /** Attributes holding one URL. srcset is checked entry by entry. */
    private const URL_ATTRS = ['href', 'src', 'poster', 'cite'];

    /**
     * Where an <iframe> may point: https only, host (and optional path
     * prefix). The embed providers the block editor knows. Sites can extend
     * this with the html.iframe_hosts filter (see PostService).
     */
    public const IFRAME_HOSTS = [
        'www.youtube.com/embed/', 'youtube.com/embed/', 'www.youtube-nocookie.com/embed/', 'youtube-nocookie.com/embed/',
        'player.vimeo.com/video/', 'www.dailymotion.com/embed/', 'open.spotify.com/embed/', 'www.loom.com/embed/',
        'codepen.io/', 'w.soundcloud.com/player/', 'www.google.com/maps/embed', 'maps.google.com/maps',
        'embed.ted.com/',
    ];

    /** Readable names for what was taken out, for the message shown to the writer. */
    private const REASON = [
        'script' => 'scripts', 'noscript' => 'scripts', 'style' => 'style blocks',
        'object' => 'plugins (object, embed)', 'embed' => 'plugins (object, embed)', 'applet' => 'plugins (object, embed)',
        'svg' => 'inline SVG or MathML', 'math' => 'inline SVG or MathML',
        'textarea' => 'forms and form fields', 'select' => 'forms and form fields', 'button' => 'forms and form fields',
        'option' => 'forms and form fields', 'input' => 'forms and form fields', 'form' => 'forms and form fields',
        'meta' => 'page-level tags (meta, link, base)', 'link' => 'page-level tags (meta, link, base)',
        'base' => 'page-level tags (meta, link, base)',
        'iframe' => 'frames from sites other than the supported video, audio and map providers',
        'frame' => 'frames from sites other than the supported video, audio and map providers',
    ];

    /**
     * Filter an HTML fragment.
     *
     * @param string[] $iframeHosts host[/path-prefix] entries an iframe may load (https only)
     * @return array{html:string, removed:string[]} removed: readable descriptions, empty when nothing
     *         of note was taken out (the HTML is still the re-serialized form)
     */
    public static function clean(string $html, array $iframeHosts = self::IFRAME_HOSTS): array
    {
        if (trim($html) === '') return ['html' => $html, 'removed' => []];
        $removed = [];
        // Twice: the second pass sees the markup exactly as the first pass
        // wrote it, so the result is stable (sanitizing it again changes nothing).
        $out = self::pass($html, $iframeHosts, $removed);
        $out = self::pass($out, $iframeHosts, $removed);
        return ['html' => $out, 'removed' => array_values(array_unique($removed))];
    }

    /**
     * Is this fragment written in an unambiguous subset that is safe as it
     * stands? Text, and tags from the allow-list whose attributes are allowed,
     * double-quoted and free of '&', '<' and '>', with safe URLs and styles.
     * Character references are allowed in text only. Used for raw HTML inside
     * Markdown, which is kept as the writer typed it when every part passes.
     */
    public static function isSimpleMarkup(string $fragment, array $iframeHosts = self::IFRAME_HOSTS): bool
    {
        $tag = '<(/?)([A-Za-z][A-Za-z0-9]*)((?:\s+[A-Za-z][A-Za-z0-9_:-]*(?:="[^"<>&]*")?)*)\s*/?>';
        $text = '[^<>&]++|&(?:[A-Za-z][A-Za-z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6});';
        if (preg_match('~^(?:' . $tag . '|' . $text . ')*+$~u', $fragment) !== 1) return false;

        preg_match_all('~' . $tag . '~', $fragment, $tags, PREG_SET_ORDER);
        foreach ($tags as $t) {
            $name = strtolower($t[2]);
            if (!isset(self::ALLOWED[$name])) return false;
            if ($t[1] === '/') continue;
            preg_match_all('~([A-Za-z][A-Za-z0-9_:-]*)(?:="([^"]*)")?~', $t[3], $attrs, PREG_SET_ORDER);
            $values = [];
            foreach ($attrs as $a) {
                $attr = strtolower($a[1]);
                $value = $a[2] ?? '';
                if (!self::attributeAllowed($name, $attr)) return false;
                if (!self::attributeValueSafe($name, $attr, $value, $iframeHosts)) return false;
                $values[$attr] = $value;
            }
            if ($name === 'input' && strtolower($values['type'] ?? '') !== 'checkbox') return false;
            if ($name === 'iframe' && !self::iframeAllowed($values['src'] ?? '', $iframeHosts)) return false;
            if ($name === 'a' && strtolower($values['target'] ?? '_self') === '_blank'
                && !preg_match('/\bnoopener\b/i', $values['rel'] ?? '')) return false;
        }
        return true;
    }

    // ------------------------------------------------------------------

    private static function pass(string $html, array $iframeHosts, array &$removed): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) return '';
        self::walk($body, $iframeHosts, $removed);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= (string) $doc->saveHTML($child);
        }
        return $out;
    }

    private static function walk(\DOMNode $parent, array $iframeHosts, array &$removed): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMCdataSection) {          // kept as plain text, never as markup
                $parent->replaceChild($parent->ownerDocument->createTextNode($node->data), $node);
                continue;
            }
            if ($node instanceof \DOMText) continue;
            if (!$node instanceof \DOMElement) {                // comments, processing instructions
                $parent->removeChild($node);
                continue;
            }
            $name = strtolower($node->localName ?? $node->nodeName);

            if (in_array($name, self::DROP, true)) {
                $removed[] = self::REASON[$name] ?? "<{$name}> elements";
                $parent->removeChild($node);
                continue;
            }
            if ($name === 'input' && strtolower($node->getAttribute('type')) !== 'checkbox') {
                $removed[] = self::REASON['input'];
                $parent->removeChild($node);
                continue;
            }
            if ($name === 'iframe' && !self::iframeAllowed($node->getAttribute('src'), $iframeHosts)) {
                $removed[] = self::REASON['iframe'];
                $parent->removeChild($node);
                continue;
            }
            if (!isset(self::ALLOWED[$name])) {
                // Unknown or unsupported tag (font, marquee, form, custom
                // elements): keep what is inside it, drop the tag.
                if ($name === 'form') $removed[] = self::REASON['form'];
                self::walk($node, $iframeHosts, $removed);
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node);
                continue;
            }

            self::cleanAttributes($node, $name, $iframeHosts, $removed);
            if ($name === 'iframe') {
                while ($node->firstChild) $node->removeChild($node->firstChild);
                continue;
            }
            self::walk($node, $iframeHosts, $removed);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $name, array $iframeHosts, array &$removed): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            /** @var \DOMAttr $attr */
            $key = strtolower($attr->nodeName);
            $value = (string) $attr->value;
            if (str_starts_with($key, 'on')) {
                $removed[] = 'event handlers (onclick and similar)';
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if (!self::attributeAllowed($name, $key)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($key === 'style') {
                $css = self::cleanStyle($value);
                if ($css !== $value) {
                    $removed[] = 'unsafe inline styles';
                    if ($css === '') { $el->removeAttribute($attr->nodeName); continue; }
                    $el->setAttribute('style', $css);
                }
                continue;
            }
            if (!self::attributeValueSafe($name, $key, $value, $iframeHosts)) {
                $removed[] = in_array($key, ['href', 'src', 'srcset', 'poster', 'cite'], true)
                    ? 'links to javascript:, data: or other unsafe addresses' : 'unsafe attributes';
                $el->removeAttribute($attr->nodeName);
            }
        }
        if ($name === 'a' && strtolower($el->getAttribute('target')) === '_blank') {
            $rel = trim($el->getAttribute('rel'));
            if (!preg_match('/\bnoopener\b/i', $rel)) $el->setAttribute('rel', trim($rel . ' noopener noreferrer'));
        }
    }

    private static function attributeAllowed(string $tag, string $attr): bool
    {
        if (str_starts_with($attr, 'on')) return false;
        if (in_array($attr, self::GLOBAL_ATTRS, true) || in_array($attr, self::ALLOWED[$tag] ?? [], true)) return true;
        if (preg_match('/^aria-[a-z-]+$/', $attr)) return true;
        return (bool) preg_match('/^data-[a-z0-9_.-]+$/', $attr) && !str_starts_with($attr, 'data-bh-');
    }

    private static function attributeValueSafe(string $tag, string $attr, string $value, array $iframeHosts): bool
    {
        if (in_array($attr, self::URL_ATTRS, true)) {
            if ($tag === 'iframe' && $attr === 'src') return self::iframeAllowed($value, $iframeHosts);
            return self::urlSafe($value, $tag === 'img' && $attr === 'src');
        }
        if ($attr === 'srcset') {
            foreach (explode(',', $value) as $entry) {
                $url = preg_split('/\s+/', trim($entry))[0] ?? '';
                if ($url !== '' && !self::urlSafe($url, false)) return false;
            }
            return true;
        }
        if ($attr === 'style') return self::cleanStyle($value) === $value;
        if ($attr === 'id' || $attr === 'name') return (bool) preg_match('/^[A-Za-z][\w:.-]*$/', $value);
        if ($attr === 'target') return in_array(strtolower($value), ['_blank', '_self'], true);
        return true;
    }

    /** What a browser would see in an attribute value: entities decoded, controls and spaces gone. */
    private static function urlProbe(string $value): string
    {
        // libxml leaves HTML5-only references (&Tab; &colon; &NewLine;) and
        // numeric ones missing their ';' as text; a browser decodes them.
        $v = (string) preg_replace('/&#(x[0-9a-f]+|[0-9]+)(?![0-9a-f;])/i', '&#$1;', $value);
        $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strtolower((string) preg_replace('/[\x00-\x20\x7f]+/u', '', $v));
    }

    private static function urlSafe(string $value, bool $imageData): bool
    {
        $v = self::urlProbe($value);
        if ($v === '') return true;
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $v, $m)) return true;           // relative
        if (in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) return true;
        return $imageData && (bool) preg_match('#^data:image/(png|jpe?g|gif|webp|avif);#', $v);
    }

    private static function iframeAllowed(string $src, array $hosts): bool
    {
        $v = self::urlProbe($src);
        if (!str_starts_with($v, 'https://')) return false;
        $rest = substr($v, 8);
        foreach ($hosts as $h) {
            $h = strtolower(trim((string) $h));
            if ($h === '') continue;
            // The host must end where the entry's host ends ("youtube.com.evil" fails).
            if (str_starts_with($rest, $h) && (str_contains($h, '/') || preg_match('#^[/?:]|^$#', substr($rest, strlen($h))))) {
                return true;
            }
        }
        return false;
    }

    /**
     * An inline style with the dangerous declarations taken out: anything
     * that runs code (expression(), javascript:, behaviors, bindings), loads
     * from a non-http address, or positions the element over the page (an
     * author could otherwise lay a fake sign-in box over the site). CSS
     * escapes and comments make a style unreadable to this check, so a style
     * using them is dropped whole.
     */
    private static function cleanStyle(string $css): string
    {
        $decoded = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#\\\\|/\*|<|>#', $decoded)) return '';
        $kept = [];
        foreach (explode(';', $decoded) as $decl) {
            if (trim($decl) === '') continue;
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) continue;
            $prop = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (!preg_match('/^-?[a-z][a-z0-9-]*$/', $prop)) continue;
            if (in_array($prop, ['position', 'behavior', '-moz-binding'], true)) continue;
            $lv = strtolower((string) preg_replace('/\s+/', '', $value));
            if (preg_match('/expression\(|javascript:|vbscript:|@import/', $lv)) continue;
            if (preg_match_all('/url\(([^)]*)\)/i', $value, $urls)) {
                foreach ($urls[1] as $u) {
                    $u = trim($u, " \t\n\r\"'");
                    if (!self::urlSafe($u, true)) continue 2;
                }
            }
            $kept[] = $prop . ': ' . $value;
        }
        $out = implode('; ', $kept);
        // Unchanged in substance → hand back the original spelling.
        $norm = fn(string $s) => strtolower((string) preg_replace('/\s+|;$/', '', $s));
        return $norm($out) === $norm(rtrim(trim($decoded), ';')) ? $css : $out;
    }
}
