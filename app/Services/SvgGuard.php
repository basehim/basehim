<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Decides whether an uploaded SVG is safe to serve from this site.
 *
 * An SVG is a document, not just a picture: opened directly (a link to it, a
 * new tab) its <script>, event handlers and javascript: links run with the
 * site's origin — and so with the session of whoever opened it. Uploads are
 * open to authors, so until 1.2.51 an author could upload an SVG that took
 * over an administrator's session the moment they opened the file.
 *
 * This does not rewrite the file. It parses it the way a browser would
 * (internal entities expanded) and refuses it when it finds anything that can
 * run code or load a document: script-capable elements, on* attributes,
 * javascript:/vbscript: values, data: links other than images, links set by
 * animation, external entities and stylesheet processing instructions.
 * Ordinary drawings, including Illustrator and Inkscape exports, pass.
 */
final class SvgGuard
{
    /** Elements that run script, embed documents, or change how the file is loaded. */
    private const BANNED_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'frame', 'frameset', 'embed', 'object', 'applet',
        'handler', 'listener', 'link', 'meta', 'base', 'portal',
    ];

    private const ANIMATION = ['animate', 'set', 'animatemotion', 'animatetransform', 'animatecolor'];

    /** Attributes whose value is fetched or navigated to. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction'];

    /** Why this SVG is refused, in a phrase for the uploader, or null when it is safe. */
    public static function problem(string $svg): ?string
    {
        if (!class_exists(\DOMDocument::class)) {
            return 'SVG files cannot be checked on this server (the PHP DOM extension is missing)';
        }
        // External and parameter entities are never needed by a drawing, and
        // the expansion below must not read anything from outside the file.
        if (preg_match('/<!ENTITY\s+%/i', $svg) || preg_match('/<!ENTITY[^>]*\b(SYSTEM|PUBLIC)\b/i', $svg)) {
            return 'it declares external entities';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // NOENT: expand internal entities, so what is checked is what a
        // browser would see (an entity can hide a <script>). NONET: no network.
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;
        if (!$loaded || $root === null || strtolower((string) $root->localName) !== 'svg') {
            return 'it is not a well-formed SVG file';
        }

        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                return 'it contains a processing instruction (' . $node->nodeName . ')';
            }
        }

        foreach ($dom->getElementsByTagName('*') as $el) {
            /** @var \DOMElement $el */
            $name = strtolower((string) $el->localName);
            if (in_array($name, self::BANNED_ELEMENTS, true)) {
                return 'it contains a <' . $name . '> element';
            }
            if (in_array($name, self::ANIMATION, true)) {
                $target = strtolower(trim($el->getAttribute('attributeName')));
                if ($target === 'href' || str_ends_with($target, ':href')) {
                    return 'it changes a link by animation';
                }
            }
            if ($name === 'style' && self::dangerousCss((string) $el->textContent)) {
                return 'its stylesheet loads or runs code';
            }
            foreach ($el->attributes ?? [] as $attr) {
                /** @var \DOMAttr $attr */
                $local = strtolower((string) $attr->localName);
                if (str_starts_with($local, 'on')) {
                    return 'it has an event handler (' . $attr->nodeName . ')';
                }
                $value = (string) $attr->value;
                $squashed = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
                if (str_contains($squashed, 'javascript:') || str_contains($squashed, 'vbscript:')) {
                    return 'it contains a script link (' . $attr->nodeName . ')';
                }
                if (in_array($local, self::URL_ATTRIBUTES, true) && str_starts_with($squashed, 'data:')
                    && !preg_match('#^data:image/(png|jpe?g|gif|webp|avif);#', $squashed)) {
                    return 'it links to embedded content (' . $attr->nodeName . ')';
                }
                if ($local === 'style' && self::dangerousCss($value)) {
                    return 'an inline style loads or runs code';
                }
            }
        }
        return null;
    }

    private static function dangerousCss(string $css): bool
    {
        $c = strtolower((string) preg_replace('/[\x00-\x20]+|\/\*.*?\*\//s', '', $css));
        return str_contains($c, 'javascript:') || str_contains($c, 'vbscript:')
            || str_contains($c, 'expression(') || str_contains($c, '@import') || str_contains($c, '-moz-binding');
    }
}
