<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Applies HtmlSanitizer to a post's content in whatever format it is stored,
 * for writers without the unfiltered_html capability (PostService decides
 * who that is). The format is judged the way the public site renders it
 * (Application's post.content filter): block JSON, Markdown (labelled, or
 * HTML that looks like Markdown), or HTML.
 *
 *  - HTML: filtered and stored re-serialized.
 *  - Blocks: the raw-HTML field of every block (Custom HTML, and app blocks
 *    rendered from their saved html) is filtered; everything else a block
 *    holds is escaped by BlockRenderer already.
 *  - Markdown is kept exactly as written when it passes no raw HTML through,
 *    or only HTML in the unambiguous subset HtmlSanitizer::isSimpleMarkup()
 *    accepts (<br>, <details>, plain <img src="https://…">). Otherwise it is
 *    rendered and stored as filtered HTML, because Markdown source cannot be
 *    filtered without changing what it means.
 */
final class PostHtmlFilter
{
    /**
     * @param string[] $iframeHosts
     * @return array{content:string, format:?string, removed:string[], converted:bool}
     *         format: the content_format to store when it changes (Markdown
     *         saved as HTML), else null
     */
    public static function filter(string $content, string $format, array $iframeHosts = HtmlSanitizer::IFRAME_HOSTS): array
    {
        $same = ['content' => $content, 'format' => null, 'removed' => [], 'converted' => false];

        if ($format === 'blocks' || BlockRenderer::looksLikeBlocks($content)) {
            return self::blocks($content, $iframeHosts) ?? $same;
        }

        if ($format === 'markdown' || (($format === 'html' || $format === '') && Markdown::looksLikeMarkdown($content))) {
            $simple = true;
            foreach (Markdown::rawHtmlParts($content) as $part) {
                if (!HtmlSanitizer::isSimpleMarkup($part, $iframeHosts)) { $simple = false; break; }
            }
            if ($simple) return $same;
            $clean = HtmlSanitizer::clean(Markdown::toHtml($content), $iframeHosts);
            return ['content' => $clean['html'], 'format' => 'html', 'removed' => $clean['removed'], 'converted' => true];
        }

        if (!str_contains($content, '<') && !str_contains($content, '&')) return $same;   // plain text
        $clean = HtmlSanitizer::clean($content, $iframeHosts);
        return ['content' => $clean['html'], 'format' => null, 'removed' => $clean['removed'], 'converted' => false];
    }

    /** @return array{content:string, format:?string, removed:string[], converted:bool}|null null when unchanged */
    private static function blocks(string $json, array $iframeHosts): ?array
    {
        $doc = json_decode($json, true);
        if (!is_array($doc)) return null;
        $removed = [];
        $changed = false;
        $walk = function (array &$node) use (&$walk, &$removed, &$changed, $iframeHosts): void {
            if (isset($node['type'], $node['data']) && is_array($node['data'])
                && isset($node['data']['html']) && is_string($node['data']['html']) && $node['data']['html'] !== '') {
                $clean = HtmlSanitizer::clean($node['data']['html'], $iframeHosts);
                if ($clean['html'] !== $node['data']['html']) {
                    $node['data']['html'] = $clean['html'];
                    $changed = true;
                }
                array_push($removed, ...$clean['removed']);
            }
            foreach ($node as &$child) {
                if (is_array($child)) $walk($child);
            }
            unset($child);
        };
        $walk($doc);
        if (!$changed) return null;
        $out = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($out === false) {
            // Cannot happen for JSON that decoded, but never fall back to the unfiltered original.
            return ['content' => '{"version":1,"blocks":[]}', 'format' => null,
                    'removed' => ['content that could not be checked'], 'converted' => false];
        }
        return ['content' => $out, 'format' => null, 'removed' => array_values(array_unique($removed)), 'converted' => false];
    }
}
