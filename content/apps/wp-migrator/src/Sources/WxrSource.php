<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Sources;

/**
 * WxrSource
 *
 * Reads a WordPress WXR export (XML) file. The file is parsed once and
 * cached in memory; each fetch*() call returns a slice from the cache.
 *
 * For very large WXR files (hundreds of MB) a streaming XMLReader would
 * be more memory-friendly, but real-world WXR exports are usually small
 * because they don't include media binaries — just URLs.
 */
class WxrSource implements Source
{
    private array $users = [];
    private array $terms = [];      // categories + tags
    private array $posts = [];      // posts + pages (post_type != attachment)
    private array $attachments = []; // post_type = attachment
    private array $comments = [];
    private string $siteUrl = '';

    public function __construct(string $filePath)
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("WXR file not found: {$filePath}");
        }
        $this->parse($filePath);
    }

    public function siteUrl(): string { return $this->siteUrl; }

    public function countUsers(): int       { return count($this->users); }
    public function countTerms(): int       { return count($this->terms); }
    public function countPosts(): int       { return count($this->posts); }
    public function countAttachments(): int { return count($this->attachments); }
    public function countComments(): int    { return count($this->comments); }

    public function fetchUsers(int $offset, int $limit): array       { return array_slice($this->users,       $offset, $limit); }
    public function fetchTerms(int $offset, int $limit): array       { return array_slice($this->terms,       $offset, $limit); }
    public function fetchPosts(int $offset, int $limit): array       { return array_slice($this->posts,       $offset, $limit); }
    public function fetchAttachments(int $offset, int $limit): array { return array_slice($this->attachments, $offset, $limit); }
    public function fetchComments(int $offset, int $limit): array    { return array_slice($this->comments,    $offset, $limit); }

    // ------------------------------------------------------------------
    // Parser
    // ------------------------------------------------------------------

    /**
     * Strip characters that are illegal in XML 1.0 so that real-world
     * WordPress exports (which sometimes contain null bytes or other stray
     * control characters in post content) don't cause simplexml to abort.
     *
     * Legal XML 1.0 characters:
     *   #x9 | #xA | #xD | [#x20–#xD7FF] | [#xE000–#xFFFD] | [#x10000–#x10FFFF]
     *
     * Anything outside those ranges — most notably \x00 (null) and the
     * range \x01–\x08, \x0B, \x0C, \x0E–\x1F — is silently removed.
     *
     * A UTF-8 BOM at the very start of the file is also stripped; libxml
     * tolerates it, but some WordPress exporters place it before the
     * <?xml declaration, which confuses parsers.
     *
     * For a 15–20 MB file this approach loads the whole content into memory
     * once (~3× the file size in RAM) and writes a sanitized copy to a temp
     * file that simplexml reads. That stays well within a typical 128 M PHP
     * memory_limit.
     */
    private function sanitize(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read WXR file: {$path}");
        }

        // Strip UTF-8 BOM (EF BB BF) if present at the very beginning.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Remove all characters that are illegal in XML 1.0.
        // The /u flag enables UTF-8 mode so multi-byte sequences are handled
        // correctly and not accidentally mangled.
        $clean = preg_replace(
            '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $content
        );

        if ($clean === null) {
            // preg_replace returns null on PCRE error (e.g. invalid UTF-8 sequence).
            // Fall back: strip only null bytes so the parser at least has a chance.
            $clean = str_replace("\x00", '', $content);
        }

        // Write to a temp file so simplexml_load_file can stream it.
        $tmp = tempnam(sys_get_temp_dir(), 'wxr_');
        if ($tmp === false || file_put_contents($tmp, $clean) === false) {
            throw new \RuntimeException('Could not write sanitized WXR temp file. Check disk space and permissions on ' . sys_get_temp_dir());
        }

        return $tmp;
    }

    private function parse(string $path): void
    {
        // Sanitize the file first: strip illegal XML characters (null bytes,
        // stray control chars) and any leading UTF-8 BOM.
        $tmp = $this->sanitize($path);

        try {
            // Suppress libxml errors and retrieve them ourselves so we can
            // surface the real message instead of a generic "unknown error".
            $prev = libxml_use_internal_errors(true);
            $xml  = simplexml_load_file($tmp, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_PARSEHUGE);

            if (!$xml) {
                $errs = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
                $msg = $errs ? trim($errs[0]->message) : 'unknown error';
                throw new \RuntimeException("Could not parse WXR XML: {$msg}");
            }

            libxml_use_internal_errors($prev);
        } finally {
            // Always clean up the temp file, even if parsing threw.
            @unlink($tmp);
        }

        // Register WP namespaces so xpath can reach <wp:...> elements.
        $ns = [
            'wp'      => 'http://wordpress.org/export/1.2/',
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
            'dc'      => 'http://purl.org/dc/elements/1.1/',
        ];
        foreach ($ns as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }

        $channel = $xml->channel;
        if (!$channel) {
            throw new \RuntimeException('WXR file has no <channel> element.');
        }

        $this->siteUrl = trim((string)($channel->link ?? '')) ?: trim((string)($channel->children($ns['wp'])->base_site_url ?? ''));

        // --- Authors -----------------------------------------------------
        foreach ($channel->children($ns['wp'])->author ?? [] as $a) {
            $this->users[] = [
                'ID'            => (int)$a->author_id,
                'user_login'    => (string)$a->author_login,
                'user_email'    => (string)$a->author_email,
                'display_name'  => (string)$a->author_display_name,
                'first_name'    => (string)$a->author_first_name,
                'last_name'     => (string)$a->author_last_name,
            ];
        }

        // --- Terms (categories + tags) -----------------------------------
        foreach ($channel->children($ns['wp'])->category ?? [] as $c) {
            $this->terms[] = [
                'old_id'      => (int)$c->term_id,
                'taxonomy'    => 'category',
                'slug'        => (string)$c->category_nicename,
                'name'        => (string)$c->cat_name,
                'parent_slug' => trim((string)$c->category_parent),
                'description' => (string)$c->category_description,
            ];
        }
        foreach ($channel->children($ns['wp'])->tag ?? [] as $t) {
            $this->terms[] = [
                'old_id'      => (int)$t->term_id,
                'taxonomy'    => 'tag',
                'slug'        => (string)$t->tag_slug,
                'name'        => (string)$t->tag_name,
                'parent_slug' => '',
                'description' => (string)$t->tag_description,
            ];
        }

        // --- Items: posts, pages, attachments, comments ------------------
        foreach ($channel->item ?? [] as $item) {
            $wp = $item->children($ns['wp']);
            $contentNs = $item->children($ns['content']);
            $excerptNs = $item->children($ns['excerpt']);
            $dc = $item->children($ns['dc']);

            $type = (string)$wp->post_type;
            $row = [
                'ID'           => (int)$wp->post_id,
                'post_title'   => (string)$item->title,
                'post_name'    => (string)$wp->post_name,
                'post_content' => (string)$contentNs->encoded,
                'post_excerpt' => (string)$excerptNs->encoded,
                'post_status'  => (string)$wp->status,
                'post_type'    => $type,
                'post_date'    => (string)$wp->post_date,
                'post_parent'  => (int)$wp->post_parent,
                'menu_order'   => (int)$wp->menu_order,
                'comment_status' => (string)$wp->comment_status,
                'post_author'  => (string)$dc->creator,   // username, not ID
                'guid'         => (string)$item->guid,
                'link'         => (string)$item->link,
                'attachment_url' => (string)$wp->attachment_url,
                'categories'   => [],
                'tags'         => [],
                'postmeta'     => [],
            ];

            // <category domain="..." nicename="..."> entries
            foreach ($item->category ?? [] as $cat) {
                $domain = (string)$cat['domain'];
                $slug = (string)$cat['nicename'];
                if ($domain === 'category') $row['categories'][] = $slug;
                if ($domain === 'post_tag') $row['tags'][] = $slug;
            }

            // <wp:postmeta>
            foreach ($wp->postmeta ?? [] as $pm) {
                $row['postmeta'][] = [
                    'meta_key'   => (string)$pm->meta_key,
                    'meta_value' => (string)$pm->meta_value,
                ];
            }

            // <wp:comment>
            foreach ($wp->comment ?? [] as $cm) {
                $this->comments[] = [
                    'comment_ID'           => (int)$cm->comment_id,
                    'comment_post_ID'      => (int)$wp->post_id,
                    'comment_parent'       => (int)$cm->comment_parent,
                    'comment_author'       => (string)$cm->comment_author,
                    'comment_author_email' => (string)$cm->comment_author_email,
                    'comment_author_url'   => (string)$cm->comment_author_url,
                    'comment_author_IP'    => (string)$cm->comment_author_IP,
                    'comment_date'         => (string)$cm->comment_date,
                    'comment_content'      => (string)$cm->comment_content,
                    'comment_approved'     => (string)$cm->comment_approved,
                    'comment_type'         => (string)$cm->comment_type,
                    'user_id'              => (int)$cm->comment_user_id,
                ];
            }

            if ($type === 'attachment') {
                $this->attachments[] = $row;
            } elseif (in_array($type, ['post', 'page'], true)) {
                $this->posts[] = $row;
            }
            // CPTs and other types are ignored — could be made configurable.
        }
    }
}
