<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Helpers;

/**
 * PostImporter
 *
 * Imports WordPress `post` and `page` items into Basehim posts, attaches
 * categories/tags, copies postmeta, and writes SEO meta from Yoast or
 * Rank Math if present.
 *
 * Posts are de-duplicated by slug+type: if a Basehim post already exists
 * with the same slug and type, we update it rather than creating a duplicate.
 *
 * IDs are mapped in app_wpmig_idmap under entity_type 'post' so later
 * importers (comments, featured images, redirects) can find them.
 */
class PostImporter extends Importer
{
    public function entityType(): string { return 'posts'; }

    public function total(): int { return $this->source->countPosts(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchPosts($offset, $limit);
        if (!$rows) return 0;

        foreach ($rows as $row) {
            try {
                $this->importOne($row);
            } catch (\Throwable $e) {
                $this->log("post '{$row['post_title']}' failed: " . $e->getMessage());
            }
        }

        // After the last batch, repair the denormalized term count column.
        // The admin Categories page reads `terms.count` directly, so any
        // attachment that happened without the count being bumped (e.g.
        // from a partial earlier import, a manual DB edit, or a future bug)
        // will still show the wrong number. Rebuilding from post_term once
        // makes the displayed count always reflect reality.
        if ($offset + count($rows) >= $this->total()) {
            $this->recountTerms();
        }
        return count($rows);
    }

    /**
     * Recompute `terms.count` from the post_term table for category and tag
     * taxonomies. Safe to run repeatedly; it's a single SQL statement.
     */
    private function recountTerms(): void
    {
        try {
            $this->db->execute(
                "UPDATE terms
                 SET count = (
                     SELECT COUNT(*) FROM post_term WHERE term_id = terms.id
                 )
                 WHERE taxonomy_id IN (
                     SELECT id FROM taxonomies WHERE slug IN ('category','tag')
                 )"
            );
            $this->log("recounted term post counts from post_term");
        } catch (\Throwable $e) {
            $this->log("term recount failed: " . $e->getMessage());
        }
    }

    private function importOne(array $row): void
    {
        $oldId = (int)$row['ID'];
        $type  = $row['post_type'] === 'page' ? 'page' : 'post';
        $slug  = trim((string)($row['post_name'] ?? '')) ?: Helpers::slug((string)$row['post_title']);

        // Map author: try ID first (MySQL source has numeric), then login (WXR).
        $authorOld = $row['post_author'] ?? '';
        $authorNewId = null;
        if (is_numeric($authorOld)) {
            $authorNewId = $this->idMap->get('user', (int)$authorOld);
        }
        if (!$authorNewId && is_string($authorOld) && $authorOld !== '') {
            $authorNewId = $this->idMap->get('user_login', $authorOld);
        }
        // Fall back to user 1 (first admin) if author missing.
        if (!$authorNewId) $authorNewId = 1;

        $status = $this->mapStatus((string)$row['post_status']);
        $publishedAt = $this->mapDate((string)($row['post_date'] ?? ''));

        // Check for existing post (idempotent re-runs).
        //
        // First by IdMap (a previous successful import of THIS WP post). If we
        // don't find it there, fall back to a slug+type match — posts that
        // were imported before the IdMap was wiped (or created manually with
        // the same slug) should be overwritten in place rather than getting a
        // slug-2 / slug-3 duplicate from resolveSlug().
        $existingId = $this->idMap->get('post', $oldId);
        if (!$existingId) {
            $existingRow = $this->db->selectOne(
                'SELECT id FROM posts WHERE type = :t AND slug = :s LIMIT 1',
                ['t' => $type, 's' => $slug]
            );
            if ($existingRow) {
                $existingId = (int)$existingRow['id'];
                // Record the mapping so subsequent steps (comments, featured
                // media, redirects) and future re-imports can find this post.
                $this->idMap->put('post', $oldId, $existingId);
                $this->log("post '{$slug}' ({$type}) already existed as #{$existingId}; overwriting and attaching terms");
            }
        }
        $payload = [
            'uuid'          => Helpers::uuid(),
            'author_id'     => $authorNewId,
            'type'          => $type,
            'status'        => $status,
            'slug'          => $this->resolveSlug($slug, $type, $existingId),
            'title'         => (string)$row['post_title'] ?: 'Untitled',
            'content'       => (string)($row['post_content'] ?? ''),
            'content_format' => 'html',
            'excerpt'       => (string)($row['post_excerpt'] ?? ''),
            'comment_status' => $this->mapCommentStatus((string)($row['comment_status'] ?? '')),
            'menu_order'    => (int)($row['menu_order'] ?? 0),
            'published_at'  => $publishedAt,
        ];

        if ($existingId) {
            // Update path: drop fields we don't overwrite (uuid).
            unset($payload['uuid']);
            $this->db->update('posts', $payload, ['id' => $existingId]);
            $newId = $existingId;
        } else {
            $newId = (int) $this->db->insert('posts', $payload);
            $this->idMap->put('post', $oldId, $newId);
        }

        $this->state->bumpCount($this->jobId, $type === 'page' ? 'pages' : 'posts');

        // Attach terms.
        $this->attachTerms($newId, $row);

        // Copy postmeta to post_meta + SEO meta.
        $this->writeMeta($newId, $row['postmeta'] ?? []);

        // Remember old URL -> new URL mapping (for the redirects step).
        $this->idMap->put('post_link', $oldId, $newId);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function mapStatus(string $wp): string
    {
        return match ($wp) {
            'publish' => 'published',
            'draft', 'pending', 'auto-draft' => 'draft',
            'private' => 'private',
            'future'  => 'scheduled',
            'trash'   => 'trash',
            default   => 'draft',
        };
    }

    private function mapCommentStatus(string $wp): string
    {
        return $wp === 'closed' ? 'closed' : 'open';
    }

    private function mapDate(?string $wp): ?string
    {
        if (!$wp || $wp === '0000-00-00 00:00:00') return null;
        $ts = strtotime($wp);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function resolveSlug(string $slug, string $type, ?int $excludeId): string
    {
        $base = $slug ?: 'untitled';
        $candidate = $base;
        $i = 2;
        while (true) {
            $row = $this->db->selectOne(
                'SELECT id FROM posts WHERE type = :t AND slug = :s LIMIT 1',
                ['t' => $type, 's' => $candidate]
            );
            if (!$row || (int)$row['id'] === $excludeId) return $candidate;
            $candidate = $base . '-' . $i;
            $i++;
            if ($i > 999) return $candidate;
        }
    }

    /**
     * In-memory index of all terms in the 'category' and 'tag' taxonomies,
     * built once per PostImporter instance. Maps a normalized lookup key to
     * the term id. We try several keys when matching to be robust against
     * slug normalization differences between WP and Basehim.
     *
     * Structure: $termIndex['category']['news'] = 5
     */
    private ?array $termIndex = null;

    /** Lazily builds and returns the term index. */
    private function termIndex(): array
    {
        if ($this->termIndex !== null) return $this->termIndex;
        $this->termIndex = ['category' => [], 'tag' => []];

        $rows = $this->db->select(
            "SELECT t.id, t.slug, t.name, tx.slug AS tax_slug
             FROM terms t
             JOIN taxonomies tx ON tx.id = t.taxonomy_id
             WHERE tx.slug IN ('category','tag')"
        );

        foreach ($rows as $r) {
            $tax = (string)$r['tax_slug'];
            if (!isset($this->termIndex[$tax])) continue;
            $id = (int)$r['id'];
            // Index by every form we might receive at lookup time.
            $keys = array_unique(array_filter([
                (string)$r['slug'],                          // exact stored slug
                strtolower((string)$r['slug']),              // case-folded slug
                Helpers::slug((string)$r['slug']),           // re-normalized slug
                Helpers::slug((string)$r['name']),           // slugified display name
                strtolower(trim((string)$r['name'])),        // lowercased display name
            ], fn($k) => $k !== ''));
            foreach ($keys as $k) {
                // First writer wins so the canonical slug takes precedence
                // over fuzzy name-derived keys on collisions.
                if (!isset($this->termIndex[$tax][$k])) {
                    $this->termIndex[$tax][$k] = $id;
                }
            }
        }
        return $this->termIndex;
    }

    /**
     * Resolve a single (taxonomy, slug-from-source) pair to a term id, trying
     * every reasonable matching strategy. Returns null on miss.
     */
    private function resolveTermId(string $taxSlug, string $raw): ?int
    {
        $index = $this->termIndex();
        if (!isset($index[$taxSlug])) return null;
        $bucket = $index[$taxSlug];

        // Candidate keys, in order of preference. The first hit wins.
        $candidates = [$raw];
        $lower = strtolower($raw);
        if ($lower !== $raw)            $candidates[] = $lower;
        $norm = Helpers::slug($raw);
        if ($norm !== '' && $norm !== $raw) $candidates[] = $norm;
        if (str_contains($raw, '%')) {
            $decoded = rawurldecode($raw);
            $candidates[] = $decoded;
            $candidates[] = strtolower($decoded);
            $candidates[] = Helpers::slug($decoded);
        }

        foreach ($candidates as $k) {
            if ($k !== '' && isset($bucket[$k])) return $bucket[$k];
        }
        return null;
    }

    private function attachTerms(int $postId, array $row): void
    {
        $termIds = [];
        $unmatched = [];

        foreach (['categories' => 'category', 'tags' => 'tag'] as $key => $taxSlug) {
            foreach ((array)($row[$key] ?? []) as $termSlug) {
                $raw = trim((string)$termSlug);
                if ($raw === '') continue;

                $id = $this->resolveTermId($taxSlug, $raw);
                if ($id !== null) {
                    $termIds[] = $id;
                } else {
                    $unmatched[] = "{$taxSlug}:{$raw}";
                }
            }
        }

        if ($unmatched) {
            $this->log("post {$postId}: could not match terms " . implode(', ', $unmatched));
        }

        if (!$termIds) return;

        // Idempotent: diff against existing links so we don't try to insert
        // duplicates. Cast both sides to int — PDO can return BIGINT columns
        // as strings on some configurations, which would make in_array(...,
        // true) miss every time and cause spurious duplicate-key inserts.
        $existing = $this->db->select(
            'SELECT term_id FROM post_term WHERE post_id = :p',
            ['p' => $postId]
        );
        $existingIds = array_map('intval', array_column($existing, 'term_id'));
        $wantIds = array_values(array_unique(array_map('intval', $termIds)));

        // Remove links that are no longer present. The recountTerms() pass at
        // the end of the posts step will rebuild terms.count from scratch,
        // so we don't need to keep the counter in sync on every operation.
        $toRemove = array_values(array_diff($existingIds, $wantIds));
        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $this->db->execute(
                "DELETE FROM post_term WHERE post_id = ? AND term_id IN ({$placeholders})",
                array_merge([$postId], $toRemove)
            );
        }

        // Insert new links.
        foreach ($wantIds as $tid) {
            if (in_array($tid, $existingIds, true)) continue;
            try {
                $this->db->insert('post_term', ['post_id' => $postId, 'term_id' => $tid]);
            } catch (\Throwable) { /* duplicate race — already linked */ }
        }
    }

    private function writeMeta(int $postId, array $postmeta): void
    {
        if (!$postmeta) return;

        // First pass: filter out WP-internal / Yoast / Rank Math meta keys we
        // either handle specially or want to skip.
        $seo = ['meta_title' => null, 'meta_description' => null, 'focus_keyword' => null,
                'og_title' => null, 'og_description' => null, 'canonical_url' => null,
                'robots' => null];

        // Clear existing post_meta for this post (idempotent re-run).
        $this->db->execute('DELETE FROM post_meta WHERE post_id = :p', ['p' => $postId]);

        foreach ($postmeta as $m) {
            $k = (string)$m['meta_key'];
            $v = (string)$m['meta_value'];

            // Yoast SEO mappings.
            if ($k === '_yoast_wpseo_title')         $seo['meta_title']       = $v;
            elseif ($k === '_yoast_wpseo_metadesc')  $seo['meta_description'] = $v;
            elseif ($k === '_yoast_wpseo_focuskw')   $seo['focus_keyword']    = $v;
            elseif ($k === '_yoast_wpseo_canonical') $seo['canonical_url']    = $v;
            elseif ($k === '_yoast_wpseo_opengraph-title') $seo['og_title']    = $v;
            elseif ($k === '_yoast_wpseo_opengraph-description') $seo['og_description'] = $v;
            elseif ($k === '_yoast_wpseo_meta-robots-noindex' && $v === '1')
                                                     $seo['robots']           = 'noindex,follow';

            // Rank Math mappings.
            elseif ($k === 'rank_math_title')        $seo['meta_title']       = $seo['meta_title'] ?? $v;
            elseif ($k === 'rank_math_description')  $seo['meta_description'] = $seo['meta_description'] ?? $v;
            elseif ($k === 'rank_math_focus_keyword')$seo['focus_keyword']    = $seo['focus_keyword'] ?? $v;
            elseif ($k === 'rank_math_canonical_url')$seo['canonical_url']    = $seo['canonical_url'] ?? $v;
            elseif ($k === 'rank_math_facebook_title')      $seo['og_title']       = $seo['og_title'] ?? $v;
            elseif ($k === 'rank_math_facebook_description')$seo['og_description'] = $seo['og_description'] ?? $v;
            elseif ($k === 'rank_math_robots' && str_contains($v, 'noindex'))
                                                     $seo['robots']           = 'noindex,follow';

            // Skip WP-internal underscore keys (except known custom-field exceptions).
            elseif (str_starts_with($k, '_')) continue;

            // Everything else: store as custom field on post_meta.
            else {
                try {
                    $this->db->insert('post_meta', [
                        'post_id'    => $postId,
                        'meta_key'   => $k,
                        'meta_value' => $v,
                        'is_json'    => $this->looksLikeJson($v) ? 1 : 0,
                    ]);
                } catch (\Throwable) { /* ignore */ }
            }
        }

        // Write SEO meta if any field came in.
        if (array_filter($seo, fn($v) => $v !== null && $v !== '')) {
            $this->upsertSeo($postId, $seo);
        }
    }

    private function upsertSeo(int $postId, array $seo): void
    {
        $existing = $this->db->selectOne('SELECT id FROM seo_meta WHERE post_id = :p', ['p' => $postId]);
        $payload = [
            'meta_title'       => $this->trim($seo['meta_title']      ?? null, 160),
            'meta_description' => $this->trim($seo['meta_description']?? null, 320),
            'og_title'         => $this->trim($seo['og_title']        ?? null, 200),
            'og_description'   => $this->trim($seo['og_description']  ?? null, 400),
            'canonical_url'    => $this->trim($seo['canonical_url']   ?? null, 500),
            'robots'           => $this->trim($seo['robots']          ?? 'index,follow', 100),
            'focus_keyword'    => $this->trim($seo['focus_keyword']   ?? null, 200),
        ];
        if ($existing) {
            $this->db->update('seo_meta', $payload, ['id' => $existing['id']]);
        } else {
            $payload['post_id'] = $postId;
            try { $this->db->insert('seo_meta', $payload); } catch (\Throwable) {}
        }
    }

    private function trim(?string $v, int $max): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function looksLikeJson(string $v): bool
    {
        if ($v === '' || ($v[0] !== '{' && $v[0] !== '[')) return false;
        json_decode($v);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
