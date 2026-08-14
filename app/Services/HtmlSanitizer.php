<?php
declare(strict_types=1);

namespace App\Services;

/**
 * HtmlSanitizer
 *
 * Allowlist sanitizer for post/page content authored by users who do not hold
 * the `unfiltered_html` capability.
 *
 * Content used to be echoed raw by the theme regardless of who wrote it, so a
 * contributor — the lowest content role — could store a <script> tag that ran
 * in an administrator's browser. Unfiltered HTML for admins is a deliberate CMS
 * feature; unfiltered HTML for everyone is a privilege-escalation path.
 *
 * Approach: parse with DOMDocument and walk the tree, dropping any element not
 * on the allowlist and any attribute not allowed for that element. Parsing
 * rather than regex matters here — regex sanitizers are defeated by malformed
 * markup precisely because the browser and the regex disagree about what the
 * markup means. DOMDocument normalises first, so what we inspect is what the
 * browser will build.
 *
 * Disallowed ELEMENTS are unwrapped rather than deleted (children are kept), so
 * stripping a stray <div> does not silently delete the paragraph inside it.
 * <script> and <style> are the exception: their text content is markup, not
 * prose, so they are removed outright.
 */
final class HtmlSanitizer
{
    /** element => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h1' => ['id'], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [], 'ins' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'mark' => [],
        'blockquote' => ['cite'], 'q' => ['cite'],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [],
        'dl' => [], 'dt' => [], 'dd' => [],
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'figure' => [], 'figcaption' => [],
        'pre' => [], 'code' => [], 'kbd' => [], 'samp' => [], 'var' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'caption' => [], 'colgroup' => ['span'], 'col' => ['span'],
        'span' => [], 'div' => [], 'section' => [], 'article' => [],
        'abbr' => ['title'], 'time' => ['datetime'],
    ];

    /** Elements removed with their contents — their text is code, not prose. */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'noscript'];

    /** URL schemes permitted in href/src. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** `class` is allowed on these, because themes style content with it. */
    private const CLASS_ALLOWED_ON = ['p', 'div', 'span', 'figure', 'figcaption', 'img', 'table', 'pre', 'code', 'blockquote'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') return '';

        // ext-dom is bundled with PHP but can be absent on a stripped-down
        // shared host. Falling back to a tag allowlist is weaker than parsing,
        // so it also drops every attribute — losing formatting is the right
        // trade against shipping unfiltered markup when the parser is missing.
        if (!class_exists(\DOMDocument::class)) {
            return self::cleanWithoutDom($html);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');

        // LIBXML_HTML_NOIMPLIED/NODEFDTD stop DOMDocument wrapping the fragment
        // in <html><body>; the UTF-8 meta hint stops it mangling non-ASCII.
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            // Unparseable: fall back to escaping everything rather than
            // returning markup we could not inspect.
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) {
                $node->parentNode?->removeChild($node);
            }
        }

        self::walk($doc, $doc);

        $out = '';
        foreach ($doc->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node, \DOMDocument $doc): void
    {
        // Snapshot the child list: the walk mutates it as it goes, and a live
        // NodeList would skip siblings after every removal.
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue; // text nodes are escaped on output by saveHTML
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIP_WITH_CONTENT, true)) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (!array_key_exists($tag, self::ALLOWED)) {
                self::walk($child, $doc);   // clean the subtree first
                self::unwrap($child);       // then keep the children, drop the tag
                continue;
            }

            self::cleanAttributes($child, $tag);
            self::walk($child, $doc);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];
        if (in_array($tag, self::CLASS_ALLOWED_ON, true)) {
            $allowed[] = 'class';
        }

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);

            // Every on* handler goes, allowlist or not — this is the belt to
            // the allowlist's braces.
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src', 'cite'], true)
                && !self::safeUrl((string) $attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // A link that opens a new tab must not hand the opener over with it.
        if ($tag === 'a' && $el->getAttribute('target') !== '') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Is this URL safe to put in an attribute?
     *
     * Relative and protocol-relative URLs are fine. Anything with a scheme must
     * name one we trust — which rules out javascript:, data:, and vbscript:.
     */
    private static function safeUrl(string $url): bool
    {
        // Strip characters a browser ignores but a naive check does not:
        // "java\tscript:alert(1)" is a live URL in some parsers.
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        if ($probe === '') return false;

        if (str_starts_with($probe, '//')) return true;   // protocol-relative
        if (!str_contains($probe, ':')) return true;      // relative or fragment

        // A colon appearing after the first '/' or '?' is part of a path or
        // query, not a scheme.
        $colon = strpos($probe, ':');
        $slash = strpos($probe, '/');
        $query = strpos($probe, '?');
        if (($slash !== false && $slash < $colon) || ($query !== false && $query < $colon)) {
            return true;
        }

        $scheme = substr($probe, 0, $colon);
        return in_array($scheme, self::SAFE_SCHEMES, true);
    }

    /**
     * Degraded path used only when ext-dom is unavailable.
     *
     * Removes script/style/etc. with their contents, then keeps a small set of
     * formatting tags and strips all attributes — no attributes means no href,
     * no src, and therefore no URL-scheme or event-handler surface at all.
     */
    private static function cleanWithoutDom(string $html): string
    {
        foreach (self::STRIP_WITH_CONTENT as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', '', $html) ?? $html;
            $html = preg_replace('#<' . $tag . '\b[^>]*/?>#i', '', $html) ?? $html;
        }

        $keep = '<p><br><hr><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><s><del><ins>'
              . '<sub><sup><small><mark><blockquote><ul><ol><li><dl><dt><dd><pre><code>'
              . '<table><thead><tbody><tfoot><tr><th><td><caption><figure><figcaption>';
        $html = strip_tags($html, $keep);

        // Strip every attribute from what survives.
        return preg_replace('#<([a-z0-9]+)\b[^>]*>#i', '<$1>', $html) ?? $html;
    }

    /** Replace an element with its own children. */
    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (!$parent) return;
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
