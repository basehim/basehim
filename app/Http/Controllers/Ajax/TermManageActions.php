<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Helpers;
use App\Repositories\TaxonomyRepository;
use App\Services\TaxonomyRegistry;
use App\Services\TaxonomyService;

/**
 * Creating categories, tags and apps' terms from their admin screen (1.2.123):
 * the New dialog's "One" and "Several" tabs. Registered with the
 * manage_taxonomies capability and the /admin/taxonomies area, like the screen.
 *
 * Several: names one per line or comma-separated. A name that matches an
 * existing term (by name, case-insensitively, or by slug) is skipped, never
 * duplicated as "web-design-2". `preview` reports what would happen without
 * writing, so the dialog can say "12 new · 3 already exist" first.
 */
final class TermManageActions
{
    /** The most names one "Several" request takes. */
    public const MANY_MAX = 500;

    public function __construct(
        private TaxonomyService $terms,
        private TaxonomyRepository $repo,
        private TaxonomyRegistry $registry,
    ) {}

    /** terms.create — one term. */
    public function create(AjaxContext $ctx): array
    {
        $tax = $this->taxonomy($ctx);
        $name = trim((string) $ctx->input('name', ''));
        $errors = [];
        if ($name === '') $errors['name'] = ['Give it a name.'];
        $parent = $this->parent($ctx, $tax, $errors);
        if ($name !== '' && ($dupe = $this->existing((int) $tax['id'], [$name])[mb_strtolower($name)] ?? null)) {
            $errors['name'] = ['There is already a ' . strtolower((string) $tax['singular']) . ' called "' . $dupe['name'] . '".'];
        }
        if ($errors) throw AjaxException::invalid('Some fields need attention.', $errors);

        $id = $this->terms->createTerm((string) $tax['slug'], [
            'name' => $name,
            'slug' => (string) $ctx->input('slug', ''),
            'description' => ($d = trim((string) $ctx->input('description', ''))) === '' ? null : $d,
            'parent_id' => $parent,
        ]);
        if (!$id) throw new AjaxException('CREATE_FAILED', 'It could not be saved. Try again.', 500);
        $term = $this->repo->findTerm($id) ?? [];
        $ctx->log('Term created', ['taxonomy' => $tax['slug'], 'id' => $id]);
        return [
            'id'   => $id,
            'name' => (string) ($term['name'] ?? $name),
            'slug' => (string) ($term['slug'] ?? ''),
            'url'  => $this->registry->termUrl((string) $tax['slug'], (string) ($term['slug'] ?? ''), defined('BASEHIM_BASE') ? BASEHIM_BASE : ''),
        ];
    }

    /** terms.create_many — several names at once (or a preview of them). */
    public function createMany(AjaxContext $ctx): array
    {
        $tax = $this->taxonomy($ctx);
        $errors = [];
        $parent = $this->parent($ctx, $tax, $errors);
        if ($errors) throw AjaxException::invalid('Some fields need attention.', $errors);

        $names = self::splitNames((string) $ctx->input('names', ''));
        if ($names === []) throw AjaxException::invalid('Add at least one name.', ['names' => ['Type or paste names, one per line or separated by commas.']]);
        if (count($names) > self::MANY_MAX) {
            throw AjaxException::invalid('That is a lot at once.', ['names' => ['Add up to ' . self::MANY_MAX . ' at a time.']]);
        }
        $tooLong = array_values(array_filter($names, static fn($n) => mb_strlen($n) > 200));
        $names = array_values(array_filter($names, static fn($n) => mb_strlen($n) <= 200));
        $found = $this->existing((int) $tax['id'], $names);
        $new = $existing = [];
        foreach ($names as $n) {
            if (isset($found[mb_strtolower($n)])) $existing[] = $found[mb_strtolower($n)]['name'];
            else $new[] = $n;
        }
        if ((bool) $ctx->input('preview', false)) {
            return ['preview' => true, 'new' => $new, 'existing' => $existing, 'too_long' => $tooLong, 'created' => []];
        }
        $created = [];
        foreach ($new as $n) {
            $id = $this->terms->createTerm((string) $tax['slug'], ['name' => $n, 'parent_id' => $parent]);
            if ($id) $created[] = $n;
        }
        $ctx->log('Terms created', ['taxonomy' => $tax['slug'], 'count' => count($created)]);
        return ['preview' => false, 'new' => $new, 'existing' => $existing, 'too_long' => $tooLong, 'created' => $created];
    }

    /**
     * Names from a pasted list: lines and commas separate them; blanks and
     * repeats (ignoring case) are dropped; inner spaces are collapsed.
     *
     * @return string[]
     */
    public static function splitNames(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/u', $raw) ?: [] as $part) {
            $n = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($n === '') continue;
            $k = mb_strtolower($n);
            if (!isset($out[$k])) $out[$k] = $n;
        }
        return array_values($out);
    }

    /** @return array the taxonomy's stored row (label, singular, hierarchical …) */
    private function taxonomy(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('taxonomy', '');
        if ($this->registry->get($slug) === null) throw AjaxException::notFound('No such taxonomy.');
        $tax = $this->repo->findTaxonomyBySlug($slug);
        if ($tax === null && $this->registry->ensureStored($slug)) $tax = $this->repo->findTaxonomyBySlug($slug);
        if ($tax === null) throw AjaxException::notFound('No such taxonomy.');
        return $tax;
    }

    private function parent(AjaxContext $ctx, array $tax, array &$errors): ?int
    {
        $id = (int) $ctx->input('parent_id', 0);
        if ($id <= 0) return null;
        $p = $this->repo->findTerm($id);
        if (empty($tax['hierarchical']) || !$p || (int) $p['taxonomy_id'] !== (int) $tax['id']) {
            $errors['parent_id'] = ['Choose a parent from the list.'];
            return null;
        }
        return $id;
    }

    /**
     * Existing terms matching these names, keyed by the lower-cased name
     * asked for: by name (any case) or by the slug the name would get.
     *
     * @param string[] $names
     * @return array<string,array{id:int,name:string}>
     */
    private function existing(int $taxonomyId, array $names): array
    {
        if ($names === []) return [];
        $bySlug = [];
        foreach ($names as $n) {
            $s = Helpers::slug($n, '-', true);
            if ($s !== '') $bySlug[$s] = mb_strtolower($n);
        }
        $rows = $this->repo->termsMatching($taxonomyId, array_map('mb_strtolower', $names), array_keys($bySlug));
        $out = [];
        foreach ($rows as $r) {
            $lname = mb_strtolower((string) $r['name']);
            foreach ($names as $n) {
                $k = mb_strtolower($n);
                if (isset($out[$k])) continue;
                if ($lname === $k || (isset($bySlug[(string) $r['slug']]) && $bySlug[(string) $r['slug']] === $k)) {
                    $out[$k] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
                }
            }
        }
        return $out;
    }
}
