<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

/**
 * RedirectImporter
 *
 * Builds 301 redirects from old WordPress URLs to new Basehim URLs so
 * external links and search-engine results keep working after the
 * migration. For each imported post we know:
 *
 *   - the old WordPress URL (from item.link / post.guid / siteurl)
 *   - the new Basehim URL (determined by permalink structure setting)
 *
 * The app's onRequest hook then issues a 301 when the old path is hit.
 */
class RedirectImporter extends Importer
{
    public function entityType(): string { return 'redirects'; }
    public function total(): int
    {
        return $this->idMap->count('post');
    }

    public function runBatch(int $offset, int $limit): int
    {
        // We iterate the idmap itself — every imported post should get a
        // redirect record (if we can resolve its old URL).
        $rows = $this->db->select(
            'SELECT old_id, new_id FROM {app_wpmig_idmap}
             WHERE entity_type = :t ORDER BY id LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
            ['t' => 'post']
        );
        if (!$rows) return 0;

        // Old addresses from the post references — not every post body, which
        // up to 1.2.0 was loaded again for every batch of 25.
        $byOldId = [];
        foreach ($this->source->postRefs() as $p) { $byOldId[(int)$p['ID']] = $p; }

        $siteUrl = rtrim($this->source->siteUrl(), '/');

        foreach ($rows as $r) {
            $oldId = (int)$r['old_id'];
            $newId = (int)$r['new_id'];
            $wp = $byOldId[$oldId] ?? null;
            if (!$wp) continue;

            $newPost = $this->db->selectOne('SELECT * FROM {posts} WHERE id = :id', ['id' => $newId]);
            if (!$newPost) continue;

            $fromPath = $this->extractPath((string)($wp['link'] ?? ''), $siteUrl);
            if (!$fromPath || $fromPath === '/') continue;

            // The new address as core builds it, whatever the site's permalink
            // setting (/{slug}, /posts/{slug}, /{category}/{slug}); 1.2.0
            // assumed /posts/{slug}.
            try {
                $toPath = $newPost['type'] === 'page'
                    ? \App\Core\Helpers::pageUrl($newPost)
                    : \App\Core\Helpers::postUrl($newPost);
            } catch (\Throwable) {
                $toPath = '/' . $newPost['slug'];
            }

            if ($fromPath === $toPath) continue;

            try {
                $existing = $this->db->selectOne(
                    'SELECT id FROM {app_wpmig_redirects} WHERE from_path = :p',
                    ['p' => $fromPath]
                );
                if ($existing) {
                    $this->db->update('app_wpmig_redirects',
                        ['to_path' => $toPath, 'status_code' => 301],
                        ['id' => $existing['id']]
                    );
                } else {
                    $this->db->insert('app_wpmig_redirects', [
                        'from_path'   => $fromPath,
                        'to_path'     => $toPath,
                        'status_code' => 301,
                    ]);
                }
                $this->state->bumpCount($this->jobId, 'redirects');
            } catch (\Throwable $e) {
                $this->log("redirect {$fromPath} failed: " . $e->getMessage());
            }
        }
        return count($rows);
    }

    private function extractPath(string $url, string $siteUrl): ?string
    {
        if ($url === '') return null;
        if ($siteUrl && str_starts_with($url, $siteUrl)) {
            $url = substr($url, strlen($siteUrl));
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return null;
        return '/' . ltrim($path, '/');
    }
}
