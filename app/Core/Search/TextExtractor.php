<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * Plain text for the search index, from HTML, Markdown or block JSON.
 *
 * Deliberately does not render: rendering runs theme and app code (block
 * filters, shortcodes), which a background indexer must not depend on. Block
 * JSON is read for its text values instead.
 */
final class TextExtractor
{
    /** Longest text kept per document, in characters. */
    public const MAX_BODY = 60000;

    /** Block attribute names whose values are text a reader sees. */
    private const TEXT_KEYS = ['text', 'content', 'html', 'caption', 'title', 'alt', 'label', 'value',
        'heading', 'subheading', 'description', 'quote', 'citation', 'body', 'summary', 'question', 'answer',
        'items', 'rows', 'cells', 'code', 'name'];

    public static function fromContent(?string $content, ?string $format = null, int $max = self::MAX_BODY): string
    {
        $raw = (string) $content;
        if (trim($raw) === '') return '';
        $trim = ltrim($raw);
        if ($format === 'blocks' || ($trim !== '' && $trim[0] === '{' && str_contains($trim, '"blocks"'))) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $out = [];
                self::walk($data, $out, false);
                return self::finish(implode("\n", $out), $max);
            }
        }
        if ($format === 'markdown') {
            $raw = preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/m', '', $raw) ?? $raw;           // heading, quote, list markers
            $raw = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $raw) ?? $raw;                    // links and images: keep the label
            $raw = preg_replace('/(\*\*|__|\*|_|~~|`{1,3})/', '', $raw) ?? $raw;                      // emphasis, code
        }
        return self::html($raw, $max);
    }

    /** Readable text from HTML. */
    public static function html(string $html, int $max = self::MAX_BODY): string
    {
        $html = preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/blockquote|/figcaption)\b[^>]*>#i', "$0\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[[a-z_]+\d*\]/i', ' ', $text) ?? $text;   // leftover shortcodes
        return self::finish($text, $max);
    }

    /** Words joined with spaces, for keywords: "Snipaste_2026-10" → "snipaste 2026 10"; "DigitalOcean" → "digital ocean". */
    public static function words(string ...$texts): string
    {
        $out = [];
        foreach ($texts as $t) {
            if ($t === '') continue;
            $split = preg_replace('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', ' ', $t) ?? $t;
            foreach ([$t, $split] as $s) {
                $w = implode(' ', SearchQuery::split($s));
                if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
            }
        }
        return implode(' ', $out);
    }

    private static function walk(mixed $node, array &$out, bool $text): void
    {
        if (is_string($node)) {
            if ($text && trim($node) !== '' && !preg_match('#^(https?:|/|\#|data:)#i', $node)) {
                $out[] = str_contains($node, '<') ? self::html($node, 20000) : $node;
            }
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $k => $v) {
            self::walk($v, $out, $text || (is_string($k) && in_array(strtolower($k), self::TEXT_KEYS, true)) || (is_int($k) && $text));
        }
    }

    private static function finish(string $text, int $max): string
    {
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\s*\n\s*/", "\n", $text) ?? $text);
        if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) $text = mb_substr($text, 0, $max, 'UTF-8');
        return $text;
    }
}
