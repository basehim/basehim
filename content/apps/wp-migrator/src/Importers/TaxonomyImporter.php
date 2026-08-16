<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Helpers;
use App\Services\TaxonomyService;

/**
 * TaxonomyImporter
 *
 * Imports WordPress categories and tags into Basehim terms. The default
 * Basehim schema ships with 'category' and 'tag' taxonomies, so this just
 * maps WP categories -> 'category' terms and WP tags -> 'tag' terms.
 *
 * Parent relationships are reconstructed in a second pass after all terms
 * exist (so children can reference parents regardless of insertion order).
 */
class TaxonomyImporter extends Importer
{
    public function entityType(): string { return 'taxonomies'; }
    public function total(): int { return $this->source->countTerms(); }

    public function runBatch(int $offset, int $limit): int
    {
        $rows = $this->source->fetchTerms($offset, $limit);
        if (!$rows) return 0;

        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);

        foreach ($rows as $row) {
            $taxSlug = $row['taxonomy'] === 'tag' ? 'tag' : 'category';
            $rawSlug = trim((string)($row['slug'] ?? '')) ?: $this->slugify((string)$row['name']);

            // Critical: TaxonomyService::createTerm() runs the slug through
            // Helpers::slug() before insert. If we look up by $rawSlug but
            // create writes the normalized form, the existence check will
            // miss on re-runs (DB has the normalized slug, lookup uses the
            // raw one), createTerm() then fails with a duplicate-key error,
            // and the IdMap entry for this term never gets written. Looking
            // up by BOTH forms keeps the lookup and the create consistent.
            $normalizedSlug = Helpers::slug($rawSlug) ?: Helpers::slug((string)$row['name']);

            $existing = $tax->findTermBySlug($taxSlug, $normalizedSlug)
                ?: $tax->findTermBySlug($taxSlug, $rawSlug);

            if ($existing) {
                $this->idMap->put('term', (int)$row['old_id'], (int)$existing['id']);
                continue;
            }

            try {
                $newId = $tax->createTerm($taxSlug, [
                    'name'        => $row['name'],
                    'slug'        => $normalizedSlug,
                    'description' => $row['description'] ?? null,
                ]);
                if ($newId) {
                    $this->idMap->put('term', (int)$row['old_id'], $newId);
                    $this->state->bumpCount($this->jobId, 'taxonomies');
                }
            } catch (\Throwable $e) {
                // If the insert raced or hit a constraint, try one more
                // lookup before giving up — the term may have just been
                // created by a parallel run or by a race we lost.
                $existing = $tax->findTermBySlug($taxSlug, $normalizedSlug);
                if ($existing) {
                    $this->idMap->put('term', (int)$row['old_id'], (int)$existing['id']);
                } else {
                    $this->log("term '{$normalizedSlug}' failed: " . $e->getMessage());
                }
            }
        }

        // After last batch, fix parent relationships.
        if ($offset + count($rows) >= $this->total()) {
            $this->rebuildParents();
        }

        return count($rows);
    }

    private function rebuildParents(): void
    {
        // For WXR, we have parent_slug; for MySQL, parent_id. Resolve both.
        $rows = $this->source->fetchTerms(0, PHP_INT_MAX);
        foreach ($rows as $row) {
            $newId = $this->idMap->get('term', (int)$row['old_id']);
            if (!$newId) continue;

            $parentNewId = null;
            if (!empty($row['parent_id'])) {
                $parentNewId = $this->idMap->get('term', (int)$row['parent_id']);
            } elseif (!empty($row['parent_slug'])) {
                // WXR uses parent_slug. Find the term by slug in the same taxonomy.
                $taxSlug = $row['taxonomy'] === 'tag' ? 'tag' : 'category';
                /** @var TaxonomyService $tax */
                $tax = $this->app->make(TaxonomyService::class);
                $parent = $tax->findTermBySlug($taxSlug, $row['parent_slug']);
                if ($parent) $parentNewId = (int)$parent['id'];
            }

            if ($parentNewId && $parentNewId !== $newId) {
                try {
                    $this->db->update('terms', ['parent_id' => $parentNewId], ['id' => $newId]);
                } catch (\Throwable $e) {
                    $this->log("parent fix for term {$newId} failed: " . $e->getMessage());
                }
            }
        }
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9\-_ ]+/', '', $s) ?? '';
        $s = preg_replace('/\s+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'term-' . substr(md5($s . microtime(true)), 0, 8);
    }
}
