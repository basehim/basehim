<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Database;

/**
 * ContentFixer
 *
 * Rewrites one post body so it no longer depends on the old WordPress site:
 *
 *   - every WordPress upload URL, wherever it appears — src, each srcset
 *     candidate, data-full-url, data-link, href, plain text — becomes the
 *     Basehim media URL (see UrlMapper). A URL that cannot be mapped is left
 *     exactly as it was.
 *   - links to the old site's pages (href only) become the new address when
 *     the redirects step knows it, otherwise a site-relative path that the
 *     redirect table catches at runtime.
 *   - [caption] becomes <figure> + <figcaption>.
 *   - [gallery] becomes a grid of the imported images.
 *
 * Used by the import's last step and by the "Repair image links" tool, so an
 * older migration can be brought up to the same result.
 */
final class ContentFixer
{
    /** @var array<string,string>|null from_path => to_path */
    private ?array $redirects = null;

    /** Counters for the log. */
    public array $stats = ['urls' => 0, 'unmapped' => 0, 'galleries' => 0, 'captions' => 0, 'links' => 0];

    /** @var list<string> a few unmapped URLs, for the log */
    public array $unmappedSamples = [];

    public function __construct(
        private Database $db,
        private IdMap $idMap,
        private UrlMapper $urls,
        private bool $rewriteLinks = true,
    ) {}

    /** @return string the fixed content (identical when nothing needed changing) */
    public function fix(string $content, ?int $oldPostId = null): string
    {
        if ($content === '') return $content;

        $content = $this->captions($content);
        $content = $this->galleries($content, $oldPostId);
        $content = $this->uploadUrls($content);
        $content = $this->attachmentLinks($content);
        $content = $this->dedupeSrcset($content);
        if ($this->rewriteLinks) $content = $this->pageLinks($content);
        return $content;
    }

    // ── upload URLs ──────────────────────────────────────────────────────────

    private function uploadUrls(string $content): string
    {
        $hosts = $this->urls->hosts();
        $patterns = [];
        if ($hosts) {
            $alt = implode('|', array_map(static fn($h) => preg_quote($h, '#'), $hosts));
            // Absolute and protocol-relative URLs on the old site's hosts.
            $patterns[] = '#(?:https?:)?//(?:' . $alt . ')(?::\d+)?/[^\s"\'<>()\\\\]*wp-content/uploads/[^\s"\'<>()\\\\]+#i';
        }
        // Site-relative ones — which is also what 1.2.0 left behind.
        $patterns[] = '#(?<=["\'\s,(=])/wp-content/uploads/[^\s"\'<>()\\\\]+#i';

        foreach ($patterns as $re) {
            $content = preg_replace_callback($re, function ($m) {
                $url = $m[0];
                // A URL at the end of a srcset entry or a sentence keeps its punctuation outside.
                $tail = '';
                if (preg_match('/[.,;:!?]+$/', $url, $t) && !preg_match('/\.[a-z0-9]{2,5}$/i', $url)) {
                    $tail = $t[0];
                    $url = substr($url, 0, -strlen($tail));
                }
                $new = $this->urls->map($url);
                if ($new === null) {
                    $this->stats['unmapped']++;
                    if (count($this->unmappedSamples) < 5) $this->unmappedSamples[] = $url;
                    return $m[0];
                }
                $this->stats['urls']++;
                return $new . $tail;
            }, $content) ?? $content;
        }
        return $content;
    }

    /** `?attachment_id=55` (a gallery's link to an attachment page) => the imported file. */
    private function attachmentLinks(string $content): string
    {
        $hosts = $this->urls->hosts();
        if (!$hosts) return $content;
        $alt = implode('|', array_map(static fn($h) => preg_quote($h, '#'), $hosts));
        return preg_replace_callback(
            '#(?:https?:)?//(?:' . $alt . ')(?::\d+)?/(?:index\.php)?\?attachment_id=(\d+)#i',
            function ($m) {
                $id = $this->idMap->get('media', (int) $m[1]);
                $row = $id ? $this->db->selectOne('SELECT url FROM {media} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]) : null;
                if (!$row) return $m[0];
                $this->stats['urls']++;
                return (string) $row['url'];
            },
            $content
        ) ?? $content;
    }

    /**
     * A srcset whose candidates now all point at the same file (the copies
     * were mapped to their full image) keeps one entry per file, the widest.
     */
    private function dedupeSrcset(string $content): string
    {
        return preg_replace_callback('#\bsrcset=(["\'])([^"\']*)\1#i', function ($m) {
            $best = [];
            foreach (preg_split('/\s*,\s*(?=\S)/', trim($m[2])) ?: [] as $cand) {
                $parts = preg_split('/\s+/', trim($cand));
                $url = $parts[0] ?? '';
                if ($url === '') continue;
                $w = (int) ($parts[1] ?? 0);
                if (!isset($best[$url]) || $w > $best[$url][0]) $best[$url] = [$w, trim($cand)];
            }
            $kept = implode(', ', array_column($best, 1));
            return $kept === trim($m[2]) ? $m[0] : 'srcset=' . $m[1] . $kept . $m[1];
        }, $content) ?? $content;
    }

    // ── links to the old site's pages ────────────────────────────────────────

    private function pageLinks(string $content): string
    {
        $hosts = $this->urls->hosts();
        if (!$hosts) return $content;
        $alt = implode('|', array_map(static fn($h) => preg_quote($h, '#'), $hosts));

        return preg_replace_callback(
            '#\bhref=(["\'])(?:https?:)?//(?:' . $alt . ')(?::\d+)?(/[^"\']*)?\1#i',
            function ($m) {
                $path = ($m[2] ?? '') !== '' ? $m[2] : '/';
                if (stripos($path, '/wp-content/') !== false || stripos($path, '/wp-admin') === 0) {
                    return $m[0];   // uploads were handled above; wp-admin links stay as they were
                }
                $bare = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
                $key = rtrim($bare, '/') ?: '/';
                $map = $this->redirects();
                $to = $map[$key] ?? $map[$key . '/'] ?? null;
                $this->stats['links']++;
                return 'href=' . $m[1] . ($to ?? $path) . $m[1];
            },
            $content
        ) ?? $content;
    }

    /** @return array<string,string> */
    private function redirects(): array
    {
        if ($this->redirects !== null) return $this->redirects;
        $this->redirects = [];
        try {
            foreach ($this->db->select('SELECT from_path, to_path FROM {app_wpmig_redirects}') as $r) {
                $this->redirects[rtrim((string) $r['from_path'], '/') ?: '/'] = (string) $r['to_path'];
            }
        } catch (\Throwable) {}
        return $this->redirects;
    }

    // ── [caption] ────────────────────────────────────────────────────────────

    private function captions(string $content): string
    {
        // A shortcode alone in its paragraph replaces the paragraph: a <figure> inside <p> is invalid.
        return preg_replace_callback('#(?:<p>\s*)?\[caption([^\]]*)\](.*?)\[/caption\](?:\s*</p>)?#is', function ($m) {
            $attrs = self::attrs($m[1]);
            $inner = trim($m[2]);
            // The caption is whatever follows the image (and its link).
            $media = '';
            $text = $inner;
            if (preg_match('#^\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)(.*)$#is', $inner, $p)) {
                $media = $p[1];
                $text = trim($p[2]);
            }
            // The caption attribute goes into the page as WordPress printed it:
            // undecoded. Decoding it first turned text such as "&lt;script&gt;"
            // into live markup (1.3.1).
            $rawCaption = self::attrs($m[1], false)['caption'] ?? '';
            if ($text === '' && $rawCaption !== '') $text = $rawCaption;
            $align = preg_match('/align(left|right|center|none)/', (string) ($attrs['align'] ?? ''), $a) ? ' align' . $a[1] : '';
            $width = (int) ($attrs['width'] ?? 0);
            $style = $width > 0 ? ' style="max-width:' . $width . 'px"' : '';
            $this->stats['captions']++;
            return '<figure class="wp-caption' . $align . '"' . $style . '>' . $media
                 . ($text !== '' ? '<figcaption class="wp-caption-text">' . $text . '</figcaption>' : '')
                 . '</figure>';
        }, $content) ?? $content;
    }

    // ── [gallery] ────────────────────────────────────────────────────────────

    private function galleries(string $content, ?int $oldPostId): string
    {
        return preg_replace_callback('#(?:<p>\s*)?\[gallery([^\]]*)\](?:\s*</p>)?#i', function ($m) use ($oldPostId) {
            $attrs = self::attrs($m[1]);
            $oldIds = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string) ($attrs['ids'] ?? $attrs['include'] ?? '')) ?: [])));
            if (!$oldIds && $oldPostId) $oldIds = $this->attachedTo($oldPostId);

            $media = [];
            foreach ($oldIds as $old) {
                $id = $this->idMap->get('media', $old);
                if (!$id) continue;
                $row = $this->db->selectOne('SELECT id, url, sizes, alt_text, caption FROM {media} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
                if ($row) $media[] = $row;
            }
            if (!$media) return $m[0];   // nothing resolvable: leave the shortcode for a later repair

            $cols = max(1, min(9, (int) ($attrs['columns'] ?? 3)));
            $size = strtolower((string) ($attrs['size'] ?? 'thumbnail'));
            $link = strtolower((string) ($attrs['link'] ?? ''));   // file, none, or attachment page
            $crop = $size === 'thumbnail';

            $items = '';
            foreach ($media as $row) {
                $sizes = json_decode((string) ($row['sizes'] ?? ''), true) ?: [];
                // The size the gallery asked for, when core has made it; the original otherwise.
                $pick = $size !== 'full' ? ($sizes[$size]['url'] ?? null) : null;
                if (!$pick && $size === 'thumbnail') $pick = $sizes['medium']['url'] ?? null;
                $src = $pick ?: (string) $row['url'];
                $alt = htmlspecialchars((string) ($row['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8');
                $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy"'
                     . ' style="display:block;width:100%;height:' . ($crop ? '100%;aspect-ratio:1/1;object-fit:cover' : 'auto') . '">';
                if ($link !== 'none') {
                    $img = '<a href="' . htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8') . '">' . $img . '</a>';
                }
                $cap = trim((string) ($row['caption'] ?? ''));
                $items .= '<figure class="wp-block-image" style="margin:0">' . $img
                        . ($cap !== '' ? '<figcaption>' . htmlspecialchars($cap, ENT_QUOTES, 'UTF-8') . '</figcaption>' : '')
                        . '</figure>';
            }
            $this->stats['galleries']++;
            return '<figure class="wp-block-gallery has-nested-images columns-' . $cols . ($crop ? ' is-cropped' : '') . '"'
                 . ' style="display:grid;grid-template-columns:repeat(' . $cols . ',minmax(0,1fr));gap:8px;margin:1.5em 0">'
                 . $items . '</figure>';
        }, $content) ?? $content;
    }

    /** Old attachment ids whose parent was this post, in menu order. */
    private function attachedTo(int $oldPostId): array
    {
        $rows = $this->db->select(
            'SELECT old_id FROM {app_wpmig_idmap} WHERE entity_type = :t AND old_id LIKE :p ORDER BY old_id',
            ['t' => 'media_parent', 'p' => $oldPostId . ':%']
        );
        $ids = [];
        foreach ($rows as $r) {
            $parts = explode(':', (string) $r['old_id']);
            $ids[] = (int) end($parts);
        }
        return $ids;
    }

    /** Shortcode attributes: key="value", key='value' or key=value. */
    public static function attrs(string $s, bool $decode = true): array
    {
        $out = [];
        if (preg_match_all('/([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/i', $s, $mm, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            foreach ($mm as $m) {
                $v = (string) ($m[2] ?? $m[3] ?? $m[4] ?? '');
                $out[strtolower((string) $m[1])] = $decode ? html_entity_decode($v, ENT_QUOTES) : $v;
            }
        }
        return $out;
    }
}
