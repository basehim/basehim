<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The RSS 2.0 document behind /feed (1.2.88).
 *
 * Before 1.2.88 the feed printed each post's stored content as it was: block
 * JSON for posts written in the block editor, Markdown for posts written
 * through the API, and in every case image paths relative to the site
 * (/uploads/…). Readers showed raw JSON, and services that pin or share from
 * a feed (Pinterest, Flipboard, IFTTT, Zapier) found no image to use.
 *
 * Every item now carries:
 *
 *  - content:encoded  the post rendered to HTML (the controller runs it through
 *                     the same post.content filter as the post page), with every
 *                     src, href, srcset and poster made absolute;
 *  - description      a lead <img> and a plain-text summary;
 *  - media:content    and an enclosure for the item's image: the featured image,
 *                     or else the first raster image in the post;
 *  - dc:creator       for the author (RSS's <author> is meant for an email
 *                     address, so a bare name there is invalid);
 *  - category         for each category and tag.
 *
 * This class is pure (no database, no request): the controller passes the
 * rows, the rendered HTML and the site's origin, so all of it is unit-tested.
 */
final class FeedBuilder
{
    /** Image types feed consumers accept. SVG is left out: Pinterest and most readers cannot use it. */
    private const IMAGE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
    ];

    /** @var \Closure(string):int|null */
    private ?\Closure $sizeOf;

    /**
     * @param string        $origin  scheme and host, e.g. https://example.com (no path, no trailing slash)
     * @param callable|null $sizeOf  bytes of an image given its absolute URL, 0 when unknown (for <enclosure length>)
     */
    public function __construct(private string $origin, ?callable $sizeOf = null)
    {
        $this->origin = rtrim($origin, '/');
        $this->sizeOf = $sizeOf !== null ? \Closure::fromCallable($sizeOf) : null;
    }

    /**
     * This site's origin: the request's scheme and host (HTTPS behind a proxy
     * included), else APP_URL. The same rule the SEO head uses for canonicals.
     */
    public static function requestOrigin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            $app = (string) (\App\Core\Env::get('APP_URL', '') ?? '');
            $origin = rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $app), '/');
            return $origin !== '' ? $origin : 'http://localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    // ------------------------------------------------------------------
    // URLs
    // ------------------------------------------------------------------

    /** A URL as a reader outside the site needs it. Absolute, data:, mailto:, tel: and #anchors pass through. */
    public function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#' || preg_match('#^(?:[a-z][a-z0-9+.\-]*:)#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return (str_starts_with($this->origin, 'https') ? 'https:' : 'http:') . $url;
        }
        if ($url[0] === '/') {
            return $this->origin . $url;
        }
        // Relative to the site root ("uploads/x.jpg"): readers resolve against the item
        // link, which is not where the file is, so anchor it at the root.
        return $this->origin . '/' . $url;
    }

    /** Every src, href, poster, data-src and srcset in $html, made absolute. */
    public function absolutizeHtml(string $html): string
    {
        $html = (string) preg_replace_callback(
            '/(\s(?:src|href|poster|data-src)\s*=\s*)(["\'])(.*?)\2/i',
            fn(array $m): string => $m[1] . $m[2] . htmlspecialchars($this->absolute(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . $m[2],
            $html
        );
        return (string) preg_replace_callback(
            '/(\s(?:srcset|data-srcset)\s*=\s*)(["\'])(.*?)\2/i',
            function (array $m): string {
                $parts = array_map(function (string $candidate): string {
                    $candidate = trim($candidate);
                    if ($candidate === '') return '';
                    $bits = preg_split('/\s+/', $candidate, 2) ?: [$candidate];
                    return $this->absolute($bits[0]) . (isset($bits[1]) ? ' ' . $bits[1] : '');
                }, explode(',', html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                return $m[1] . $m[2] . htmlspecialchars(implode(', ', array_filter($parts, fn($p) => $p !== '')), ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . $m[2];
            },
            $html
        );
    }

    // ------------------------------------------------------------------
    // The item's image
    // ------------------------------------------------------------------

    /**
     * The image that represents a post: its featured image, else the first
     * raster <img> in the rendered content. SVG and data: images are skipped.
     *
     * @return array{url:string,type:string,width:int,height:int,alt:string}|null
     */
    public function leadImage(array $post, string $html): ?array
    {
        $featured = (string) ($post['featured_url'] ?? '');
        if ($featured !== '' && ($type = self::imageType($featured)) !== null) {
            return [
                'url'    => $this->absolute($featured),
                'type'   => $type,
                'width'  => (int) ($post['featured_width'] ?? 0),
                'height' => (int) ($post['featured_height'] ?? 0),
                'alt'    => trim((string) ($post['featured_alt'] ?? '')),
            ];
        }
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = self::attr($tag, 'src');
                if ($src === '' || str_starts_with(strtolower($src), 'data:')) continue;
                $type = self::imageType($src);
                if ($type === null) continue;
                return [
                    'url'    => $this->absolute($src),
                    'type'   => $type,
                    'width'  => (int) self::attr($tag, 'width'),
                    'height' => (int) self::attr($tag, 'height'),
                    'alt'    => self::attr($tag, 'alt'),
                ];
            }
        }
        return null;
    }

    /** The MIME type of an image URL from its extension, or null when it is not a raster image feeds can use. */
    public static function imageType(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::IMAGE_TYPES[$ext] ?? null;
    }

    // ------------------------------------------------------------------
    // The document
    // ------------------------------------------------------------------

    /**
     * One <item>.
     *
     * @param array  $post       a published post row (title, published_at, excerpt, author_name, featured_* …)
     * @param string $html       its content rendered to HTML (the post.content filter)
     * @param string $link       its absolute URL
     * @param string[] $categories names of its categories and tags
     */
    public function item(array $post, string $html, string $link, array $categories = []): string
    {
        $html = $this->absolutizeHtml(self::stripComments($html));
        $image = $this->leadImage($post, $html);
        $summary = self::summary((string) ($post['excerpt'] ?? ''), $html);
        $when = (string) ($post['published_at'] ?? '') !== '' ? (string) $post['published_at'] : (string) ($post['created_at'] ?? 'now');
        $ts = strtotime($when . ' UTC');

        $x  = "    <item>\n";
        $x .= '      <title>' . self::text((string) ($post['title'] ?? '')) . "</title>\n";
        $x .= '      <link>' . self::text($link) . "</link>\n";
        $x .= '      <guid isPermaLink="true">' . self::text($link) . "</guid>\n";
        $x .= '      <pubDate>' . gmdate(DATE_RSS, $ts !== false ? $ts : time()) . "</pubDate>\n";
        if (trim((string) ($post['author_name'] ?? '')) !== '') {
            $x .= '      <dc:creator>' . self::cdata(trim((string) $post['author_name'])) . "</dc:creator>\n";
        }
        foreach (array_unique(array_filter(array_map(fn($c) => trim((string) $c), $categories))) as $c) {
            $x .= '      <category>' . self::cdata($c) . "</category>\n";
        }

        // The description leads with the image: some consumers (Pinterest among
        // them) read the first image in it when no media element is present.
        $desc = '';
        if ($image !== null) {
            $desc .= '<p><img src="' . htmlspecialchars($image['url'], ENT_QUOTES | ENT_HTML5) . '" alt="'
                   . htmlspecialchars($image['alt'] !== '' ? $image['alt'] : (string) ($post['title'] ?? ''), ENT_QUOTES | ENT_HTML5) . '"'
                   . ($image['width'] > 0 ? ' width="' . $image['width'] . '"' : '')
                   . ($image['height'] > 0 ? ' height="' . $image['height'] . '"' : '') . '></p>';
        }
        $desc .= $summary !== '' ? '<p>' . htmlspecialchars($summary, ENT_QUOTES | ENT_HTML5) . '</p>' : '';
        $x .= '      <description>' . self::cdata($desc) . "</description>\n";
        $x .= '      <content:encoded>' . self::cdata($html) . "</content:encoded>\n";

        if ($image !== null) {
            $u = self::text($image['url']);
            $length = $this->sizeOf !== null ? max(0, (int) ($this->sizeOf)($image['url'])) : 0;
            $x .= '      <enclosure url="' . $u . '" length="' . $length . '" type="' . $image['type'] . "\" />\n";
            $x .= '      <media:content url="' . $u . '" medium="image" type="' . $image['type'] . '"'
                . ($image['width'] > 0 ? ' width="' . $image['width'] . '"' : '')
                . ($image['height'] > 0 ? ' height="' . $image['height'] . '"' : '') . ">\n";
            $x .= '        <media:title type="plain">' . self::cdata((string) ($post['title'] ?? '')) . "</media:title>\n";
            if ($image['alt'] !== '') {
                $x .= '        <media:description type="plain">' . self::cdata($image['alt']) . "</media:description>\n";
            }
            $x .= "      </media:content>\n";
            $x .= '      <media:thumbnail url="' . $u . "\" />\n";
        }
        $x .= "    </item>\n";
        return $x;
    }

    /**
     * The whole document.
     *
     * @param array{title:string,link:string,description:string,language:string,self:string,updated?:?string} $channel
     * @param string[] $items <item> elements from item()
     */
    public function document(array $channel, array $items): string
    {
        $updated = isset($channel['updated']) && $channel['updated'] ? strtotime($channel['updated'] . ' UTC') : false;
        $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
        $x .= "  <channel>\n";
        $x .= '    <title>' . self::text($channel['title']) . "</title>\n";
        $x .= '    <link>' . self::text($channel['link']) . "</link>\n";
        $x .= '    <description>' . self::text($channel['description']) . "</description>\n";
        $x .= '    <language>' . self::text(self::language($channel['language'])) . "</language>\n";
        $x .= '    <atom:link href="' . self::text($channel['self']) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";
        if ($updated !== false) {
            $x .= '    <lastBuildDate>' . gmdate(DATE_RSS, $updated) . "</lastBuildDate>\n";
        }
        $x .= "    <generator>Basehim</generator>\n";
        $x .= implode('', $items);
        $x .= "  </channel>\n</rss>\n";
        return $x;
    }

    // ------------------------------------------------------------------
    // Text helpers
    // ------------------------------------------------------------------

    /** Escaped character data, with the control characters XML 1.0 forbids removed. */
    public static function text(string $s): string
    {
        return htmlspecialchars(self::clean($s), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** A CDATA section that stays one even when the text contains "]]>". */
    public static function cdata(string $s): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::clean($s)) . ']]>';
    }

    /** RSS wants "en-us", Basehim stores "en_US". */
    public static function language(string $l): string
    {
        $l = strtolower(str_replace('_', '-', trim($l)));
        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $l) ? $l : 'en-us';
    }

    /** The excerpt, or the start of the text: plain, at most 300 characters. */
    public static function summary(string $excerpt, string $html): string
    {
        $text = trim($excerpt) !== '' ? $excerpt : strip_tags((string) preg_replace('#<(script|style|svg)\b.*?</\1>#is', ' ', $html));
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) > 300) {
            $cut = mb_substr($text, 0, 300);
            $space = mb_strrpos($cut, ' ');
            $text = rtrim($space !== false && $space > 200 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
        }
        return $text;
    }

    /** HTML comments (block-editor markers such as <!-- wp:paragraph -->) mean nothing to a reader. */
    public static function stripComments(string $html): string
    {
        return (string) preg_replace('/<!--(?!\[if).*?-->/s', '', $html);
    }

    private static function clean(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    }

    private static function attr(string $tag, string $name): string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/i', $tag, $m)) {
            return html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*([^\s>]+)/i', $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
}
