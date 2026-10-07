<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Sources;

/**
 * WxrSource
 *
 * Reads a WordPress WXR export (XML) file. Both the sanitize pass and the
 * XML parse are streaming, so memory use stays bounded (roughly a few MB)
 * regardless of whether the file is 2 MB or 500 MB — the old approach
 * loaded the whole file into memory 2-3x over and built a full SimpleXML
 * DOM tree, which made anything past ~100-200 MB a real risk of hitting
 * memory_limit.
 *
 * Sanitizing is done with a plain streaming byte scan (fread/fwrite in
 * fixed-size chunks) rather than loading the file whole. Parsing uses
 * XMLReader to walk the document node-by-node with O(1) memory, expanding
 * only the current <wp:author>/<wp:category>/<wp:tag>/<item> into a small
 * standalone SimpleXMLElement so the existing field-extraction logic can
 * stay the same (this is a well-established idiom for parsing large,
 * namespaced XML with SimpleXML's namespace-aware accessors).
 *
 * The resulting users/terms/posts/attachments/comments arrays are still
 * held fully in memory for pagination via array_slice(), same as before —
 * that's fine because the parsed PHP arrays are typically much smaller
 * than the raw XML (no markup overhead), and it keeps the rest of the
 * import pipeline (which expects synchronous fetch*() slices) unchanged.
 */
class WxrSource implements Source
{
    private const NS_WP      = 'http://wordpress.org/export/1.2/';
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';
    private const NS_DC      = 'http://purl.org/dc/elements/1.1/';

    /**
     * The file is parsed once, into an index beside it ({file}.index/): one
     * JSON line per record and a file of byte offsets per kind. Every batch
     * after that reads only its own slice.
     *
     * Up to 1.2.0 every batch request parsed the whole file again — writing a
     * sanitised copy of it first — and held all posts in memory, for 25 records
     * at a time. On a 500 MB export that is hundreds of full parses.
     */
    private const INDEX_VERSION = 2;
    private const KINDS = ['users', 'terms', 'posts', 'attachments', 'comments', 'refs'];

    private string $indexDir;
    private array $meta = [];
    private string $siteUrl = '';

    /** @var array<string,array{0:resource,1:resource,2:int}> kind => [data, offsets, count] while building */
    private array $writers = [];

    public function __construct(string $filePath)
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("WXR file not found: {$filePath}");
        }
        $this->indexDir = $filePath . '.index';
        if (!$this->loadIndex($filePath)) {
            $this->buildIndex($filePath);
            if (!$this->loadIndex($filePath)) {
                throw new \RuntimeException('Could not index the WXR file. Check free disk space and permissions on storage/cache/.');
            }
        }
    }

    public function siteUrl(): string { return $this->siteUrl; }

    public function countUsers(): int       { return (int) ($this->meta['counts']['users'] ?? 0); }
    public function countTerms(): int       { return (int) ($this->meta['counts']['terms'] ?? 0); }
    public function countPosts(): int       { return (int) ($this->meta['counts']['posts'] ?? 0); }
    public function countAttachments(): int { return (int) ($this->meta['counts']['attachments'] ?? 0); }
    public function countComments(): int    { return (int) ($this->meta['counts']['comments'] ?? 0); }

    public function fetchUsers(int $offset, int $limit): array       { return $this->slice('users', $offset, $limit); }
    public function fetchTerms(int $offset, int $limit): array       { return $this->slice('terms', $offset, $limit); }
    public function fetchPosts(int $offset, int $limit): array       { return $this->slice('posts', $offset, $limit); }
    public function fetchAttachments(int $offset, int $limit): array { return $this->slice('attachments', $offset, $limit); }
    public function fetchComments(int $offset, int $limit): array    { return $this->slice('comments', $offset, $limit); }

    /** Every post and page, without its content: ID, post_type, link, post_name, thumbnail_id. */
    public function postRefs(): array { return $this->slice('refs', 0, PHP_INT_MAX); }

    // ------------------------------------------------------------------
    // The index
    // ------------------------------------------------------------------

    private function loadIndex(string $source): bool
    {
        $metaFile = $this->indexDir . '/meta.json';
        if (!is_file($metaFile)) return false;
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        clearstatcache(true, $source);
        if (!is_array($meta) || ($meta['version'] ?? 0) !== self::INDEX_VERSION
            || (int) ($meta['source_size'] ?? -1) !== (int) filesize($source)) {
            return false;
        }
        $this->meta = $meta;
        $this->siteUrl = (string) ($meta['site_url'] ?? '');
        return true;
    }

    private function buildIndex(string $source): void
    {
        if (!is_dir($this->indexDir) && !@mkdir($this->indexDir, 0775, true) && !is_dir($this->indexDir)) {
            throw new \RuntimeException('Could not create the index directory ' . $this->indexDir);
        }
        // One build at a time: a second request waits, then finds it done.
        $lock = @fopen($this->indexDir . '/.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        try {
            if ($this->loadIndex($source)) return;
            @unlink($this->indexDir . '/meta.json');
            foreach (self::KINDS as $k) {
                $d = @fopen($this->indexDir . "/{$k}.jsonl", 'wb');
                $o = @fopen($this->indexDir . "/{$k}.idx", 'wb');
                if (!$d || !$o) throw new \RuntimeException('Could not write the WXR index in ' . $this->indexDir);
                $this->writers[$k] = [$d, $o, 0];
            }
            $this->parse($source);
            $counts = [];
            foreach ($this->writers as $k => [$d, $o, $n]) { fclose($d); fclose($o); $counts[$k] = $n; }
            $this->writers = [];
            // Written last: its presence means the index is complete.
            file_put_contents($this->indexDir . '/meta.json', json_encode([
                'version'     => self::INDEX_VERSION,
                'source_size' => (int) filesize($source),
                'site_url'    => $this->siteUrl,
                'counts'      => $counts,
                'built_at'    => date('c'),
            ]));
        } finally {
            foreach ($this->writers as [$d, $o]) { @fclose($d); @fclose($o); }
            $this->writers = [];
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Append one record while building. */
    private function emit(string $kind, array $row): void
    {
        [$d, $o, $n] = $this->writers[$kind];
        fwrite($o, pack('P', ftell($d)));
        fwrite($d, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        $this->writers[$kind][2] = $n + 1;
    }

    /** Records $offset .. $offset+$limit of one kind, read from the index. */
    private function slice(string $kind, int $offset, int $limit): array
    {
        $total = (int) ($this->meta['counts'][$kind] ?? 0);
        if ($offset < 0 || $offset >= $total || $limit <= 0) return [];
        $limit = min($limit, $total - $offset);

        $idx = @fopen($this->indexDir . "/{$kind}.idx", 'rb');
        $dat = @fopen($this->indexDir . "/{$kind}.jsonl", 'rb');
        if (!$idx || !$dat) return [];
        fseek($idx, $offset * 8);
        $first = unpack('P', (string) fread($idx, 8))[1] ?? 0;
        fclose($idx);

        fseek($dat, (int) $first);
        $out = [];
        while (count($out) < $limit && ($line = fgets($dat)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row)) $out[] = $row;
        }
        fclose($dat);
        return $out;
    }

    // ------------------------------------------------------------------
    // Limits
    // ------------------------------------------------------------------

    /**
     * Best-effort: parsing a large (hundreds-of-MB) WXR file needs more
     * headroom than typical shared-hosting defaults. Unlike
     * upload_max_filesize/post_max_size (which are fixed before the
     * request even starts), memory_limit and max_execution_time are
     * PHP_INI_ALL and can be raised at runtime. On hosts that lock them
     * down this silently no-ops and the existing limit still applies.
     */
    private function bumpLimits(): void
    {
        $currentMemory = $this->iniBytes((string)ini_get('memory_limit'));
        if ($currentMemory !== -1 && $currentMemory < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        $currentTime = (int)ini_get('max_execution_time');
        if ($currentTime !== 0 && $currentTime < 1800) {
            // Indexing a 500 MB export can take several minutes; keep going
            // even if the browser gives up waiting, so the work is not lost.
            @set_time_limit(1800);
            @ignore_user_abort(true);
        }
    }

    private function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;
        if ($val === '-1') return -1;
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    // ------------------------------------------------------------------
    // Streaming sanitizer
    // ------------------------------------------------------------------

    /**
     * Strip characters that are illegal in XML 1.0 so that real-world
     * WordPress exports (which sometimes contain null bytes or other stray
     * control characters in post content) don't cause the parser to abort.
     *
     * Legal XML 1.0 characters:
     *   #x9 | #xA | #xD | [#x20–#xD7FF] | [#xE000–#xFFFD] | [#x10000–#x10FFFF]
     *
     * A UTF-8 BOM at the very start of the file is also stripped.
     *
     * This reads and writes in fixed-size chunks instead of loading the
     * whole file into memory, so peak memory is roughly the chunk size
     * (a few MB) no matter how large the file is. Each chunk boundary is
     * adjusted so it never falls in the middle of a multi-byte UTF-8
     * sequence (utf8SafeSplit), since splitting mid-sequence would corrupt
     * the regex's UTF-8 mode and the character right at the cut.
     */
    private function sanitizeStreaming(string $path): string
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Could not read WXR file: {$path}");
        }

        // Beside the upload rather than in the system temp directory, which is
        // often a small partition that a 500 MB copy fills.
        $tmp = tempnam(is_dir($this->indexDir) ? $this->indexDir : sys_get_temp_dir(), 'wxr_');
        $out = $tmp !== false ? @fopen($tmp, 'wb') : false;
        if ($tmp === false || $out === false) {
            fclose($in);
            throw new \RuntimeException('Could not write sanitized WXR temp file. Check disk space and permissions on ' . dirname($tmp ?: $this->indexDir));
        }

        // Matches the same illegal-XML-1.0-character ranges as before.
        $illegal = '/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

        $chunkSize = 4 * 1024 * 1024; // 4 MB — bounds peak memory regardless of file size.
        $carry = '';
        $first = true;

        try {
            while (!feof($in)) {
                $chunk = fread($in, $chunkSize);
                if ($chunk === false) {
                    throw new \RuntimeException("Could not read WXR file: {$path}");
                }
                if ($chunk === '') continue;

                $buf = $carry . $chunk;

                if ($first) {
                    if (str_starts_with($buf, "\xEF\xBB\xBF")) {
                        $buf = substr($buf, 3);
                    }
                    $first = false;
                }

                [$safe, $carry] = $this->utf8SafeSplit($buf);

                $clean = preg_replace($illegal, '', $safe);
                if ($clean === null) {
                    // PCRE error (e.g. leftover invalid byte sequence) — fall
                    // back to stripping only null bytes so parsing at least
                    // has a chance.
                    $clean = str_replace("\x00", '', $safe);
                }

                if (fwrite($out, $clean) === false) {
                    throw new \RuntimeException('Could not write sanitized WXR temp file. Check disk space on ' . dirname($tmp));
                }
            }

            // Flush whatever's left at true EOF.
            if ($carry !== '') {
                $clean = preg_replace($illegal, '', $carry);
                fwrite($out, $clean ?? str_replace("\x00", '', $carry));
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $tmp;
    }

    /**
     * Splits $buf into [safe, carry] where $safe ends on a complete UTF-8
     * character boundary and $carry holds any trailing bytes that look
     * like the start of a multi-byte sequence that might continue in the
     * next chunk. Looking back up to 3 bytes is enough to detect a
     * truncated 2-, 3-, or 4-byte UTF-8 sequence.
     */
    private function utf8SafeSplit(string $buf): array
    {
        $len = strlen($buf);
        if ($len === 0) return [$buf, ''];

        $lookback = min(3, $len);
        for ($i = 1; $i <= $lookback; $i++) {
            $byte = ord($buf[$len - $i]);
            if ($byte < 0x80) {
                break; // ASCII byte — nothing multi-byte ends here
            }
            if ($byte >= 0xC0) {
                $seqLen = $this->utf8SeqLen($byte);
                if ($seqLen > $i) {
                    return [substr($buf, 0, $len - $i), substr($buf, $len - $i)];
                }
                break; // sequence starting at this lead byte is complete
            }
            // else: 0x80-0xBF continuation byte, keep looking further back
        }

        return [$buf, ''];
    }

    private function utf8SeqLen(int $leadByte): int
    {
        if ($leadByte >= 0xF0) return 4;
        if ($leadByte >= 0xE0) return 3;
        if ($leadByte >= 0xC0) return 2;
        return 1;
    }

    // ------------------------------------------------------------------
    // Streaming parser
    // ------------------------------------------------------------------

    private function parse(string $path): void
    {
        $this->bumpLimits();
        $tmp = $this->sanitizeStreaming($path);

        $prev = libxml_use_internal_errors(true);
        $reader = new \XMLReader();

        try {
            if (!@$reader->open($tmp, null, LIBXML_PARSEHUGE)) {
                $errs = libxml_get_errors();
                $msg = $errs ? trim($errs[0]->message) : 'unknown error';
                throw new \RuntimeException("Could not parse WXR XML: {$msg}");
            }

            $foundChannel = false;
            while (@$reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'channel') {
                    $foundChannel = true;
                    break;
                }
            }
            if (!$foundChannel) {
                throw new \RuntimeException('WXR file has no <channel> element.');
            }

            // Walk the direct children of <channel>: authors, terms, items.
            // next() advances to the next sibling without expanding
            // subtrees, so memory stays flat as we scan through them.
            if (@$reader->read()) {
                do {
                    if ($reader->nodeType !== \XMLReader::ELEMENT) continue;

                    $ns = $reader->namespaceURI;
                    $local = $reader->localName;

                    if ($ns === '' && $local === 'link') {
                        if ($this->siteUrl === '') {
                            $this->siteUrl = trim((string)$this->expand($reader));
                        }
                    } elseif ($ns === self::NS_WP && $local === 'base_site_url') {
                        $val = trim((string)$this->expand($reader));
                        if ($val !== '') $this->siteUrl = $val;
                    } elseif ($ns === self::NS_WP && $local === 'author') {
                        $this->parseAuthor($this->expand($reader));
                    } elseif ($ns === self::NS_WP && $local === 'category') {
                        $this->parseCategory($this->expand($reader));
                    } elseif ($ns === self::NS_WP && $local === 'tag') {
                        $this->parseTag($this->expand($reader));
                    } elseif ($ns === '' && $local === 'item') {
                        $this->parseItem($this->expand($reader));
                    }
                } while (@$reader->next());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $reader->close();
            @unlink($tmp);
        }
    }

    /**
     * Expands the reader's current node into a standalone SimpleXMLElement.
     * readOuterXml() serializes the node as self-contained, well-formed
     * XML (libxml pulls in any ancestor namespace declarations the node's
     * prefixes need), so the fragment can be parsed independently with
     * SimpleXML's usual namespace-aware ->children($ns) accessors.
     */
    private function expand(\XMLReader $reader): \SimpleXMLElement
    {
        $xml = $reader->readOuterXml();
        $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        if ($el === false) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            $msg = $errs ? trim($errs[0]->message) : 'unknown error';
            throw new \RuntimeException("Could not parse WXR <{$reader->localName}> node: {$msg}");
        }
        return $el;
    }

    private function parseAuthor(\SimpleXMLElement $root): void
    {
        $wp = $root->children(self::NS_WP);
        $this->emit('users', [
            'ID'            => (int)$wp->author_id,
            'user_login'    => (string)$wp->author_login,
            'user_email'    => (string)$wp->author_email,
            'display_name'  => (string)$wp->author_display_name,
            'first_name'    => (string)$wp->author_first_name,
            'last_name'     => (string)$wp->author_last_name,
        ]);
    }

    private function parseCategory(\SimpleXMLElement $root): void
    {
        $wp = $root->children(self::NS_WP);
        $this->emit('terms', [
            'old_id'      => (int)$wp->term_id,
            'taxonomy'    => 'category',
            'slug'        => (string)$wp->category_nicename,
            'name'        => (string)$wp->cat_name,
            'parent_slug' => trim((string)$wp->category_parent),
            'description' => (string)$wp->category_description,
        ]);
    }

    private function parseTag(\SimpleXMLElement $root): void
    {
        $wp = $root->children(self::NS_WP);
        $this->emit('terms', [
            'old_id'      => (int)$wp->term_id,
            'taxonomy'    => 'tag',
            'slug'        => (string)$wp->tag_slug,
            'name'        => (string)$wp->tag_name,
            'parent_slug' => '',
            'description' => (string)$wp->tag_description,
        ]);
    }

    private function parseItem(\SimpleXMLElement $item): void
    {
        $wp = $item->children(self::NS_WP);
        $contentNs = $item->children(self::NS_CONTENT);
        $excerptNs = $item->children(self::NS_EXCERPT);
        $dc = $item->children(self::NS_DC);

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
            $this->emit('comments', [
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
            ]);
        }

        if ($type === 'attachment') {
            $this->emit('attachments', $row);
        } elseif (in_array($type, ['post', 'page'], true)) {
            $this->emit('posts', $row);
            $thumb = '';
            foreach ($row['postmeta'] as $m) if ($m['meta_key'] === '_thumbnail_id') { $thumb = $m['meta_value']; break; }
            $this->emit('refs', ['ID' => $row['ID'], 'post_type' => $type, 'post_name' => $row['post_name'],
                                 'link' => $row['link'], 'thumbnail_id' => (int) $thumb]);
        }
        // CPTs and other types are ignored — could be made configurable.
    }
}
