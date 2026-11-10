<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Ajax\AjaxException;
use App\Core\Helpers;
use App\Core\Table\QuickEditor;
use App\Repositories\TaxonomyRepository;
use App\Services\TaxonomyService;

/**
 * Quick Edit for a term in the terms table (1.2.123): name, slug, parent
 * (hierarchical taxonomies) and description, saved through TaxonomyService
 * so the term.updated hook, slug uniqueness and the cycle guard behave as
 * for every other save.
 *
 * The table's capability (manage_taxonomies) and area are checked before
 * this class runs; it checks the term belongs to the table's taxonomy.
 * The form carries a version so a save made against an older copy is
 * refused rather than overwriting someone else's change.
 */
final class TermQuickEdit implements QuickEditor
{
    /** The most terms offered as a parent; past that the current parent is kept. */
    private const PARENT_LIMIT = 1000;
    private const NAME_MAX = 200;
    private const DESCRIPTION_MAX = 5000;

    private string $taxonomy = '';

    public function __construct(
        private TaxonomyRepository $repo,
        private TaxonomyService $terms,
    ) {}

    public function forTable(string $key): void
    {
        $this->taxonomy = TermTableProvider::taxonomyFromKey($key);
    }

    public function form(int $id, array $user): array
    {
        [$term, $tax] = $this->record($id);
        $singular = strtolower((string) ($tax['singular'] ?? 'term'));
        $fields = [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'value' => (string) $term['name'],
             'required' => true, 'max' => self::NAME_MAX, 'span' => 6],
            ['name' => 'slug', 'type' => 'slug', 'label' => 'Slug', 'value' => (string) $term['slug'],
             'max' => 200, 'span' => 6, 'help' => $this->addressHelp((string) $term['slug'])],
        ];
        if (!empty($tax['hierarchical'])) {
            $fields[] = ['name' => 'parent', 'type' => 'select', 'label' => 'Parent ' . $singular,
                         'value' => (string) ($term['parent_id'] ?? ''), 'options' => $this->parentOptions($tax, $term), 'span' => 6];
        }
        $fields[] = ['name' => 'description', 'type' => 'textarea', 'label' => 'Description',
                     'value' => (string) ($term['description'] ?? ''), 'max' => self::DESCRIPTION_MAX, 'wide' => true, 'span' => 12,
                     'help' => 'Shown on the ' . $singular . '\'s page by themes that support it.'];
        return [
            'id'      => (int) $term['id'],
            'title'   => (string) $term['name'],
            'version' => $this->version($term),
            'fields'  => $fields,
        ];
    }

    public function save(int $id, array $values, array $user, array $options = []): array
    {
        [$term, $tax] = $this->record($id);
        $version = (string) ($options['version'] ?? '');
        if ($version === '' || !hash_equals($this->version($term), $version)) {
            throw new AjaxException('EDIT_CONFLICT',
                'This ' . strtolower((string) $tax['singular']) . ' was changed somewhere else after you opened Quick Edit. Cancel and open it again to start from the latest version.', 409);
        }
        $allowed = ['name', 'slug', 'description'];
        if (!empty($tax['hierarchical'])) $allowed[] = 'parent';
        $errors = [];
        foreach (array_keys($values) as $k) {
            if (!in_array($k, $allowed, true)) $errors[$k] = ['This field can\'t be changed here.'];
        }

        $data = [];
        if (array_key_exists('name', $values)) {
            $name = trim((string) $values['name']);
            if ($name === '') $errors['name'] = ['Give it a name.'];
            elseif (mb_strlen($name) > self::NAME_MAX) $errors['name'] = ['Keep the name to ' . self::NAME_MAX . ' characters.'];
            else $data['name'] = $name;
        }
        if (array_key_exists('slug', $values)) {
            $raw = trim((string) $values['slug']);
            $slug = Helpers::slug($raw !== '' ? $raw : (string) ($data['name'] ?? $term['name']), '-', true);
            if ($slug === '') $errors['slug'] = ['Use letters or numbers in the slug.'];
            else $data['slug'] = $slug;
        }
        if (array_key_exists('description', $values)) {
            $desc = trim((string) $values['description']);
            if (mb_strlen($desc) > self::DESCRIPTION_MAX) $errors['description'] = ['Keep the description to ' . self::DESCRIPTION_MAX . ' characters.'];
            else $data['description'] = $desc === '' ? null : $desc;
        }
        if (array_key_exists('parent', $values) && in_array('parent', $allowed, true)) {
            $parent = (int) $values['parent'];
            if ($parent === 0) {
                $data['parent_id'] = null;
            } elseif ($parent === (int) $term['id'] || in_array($parent, $this->descendants((int) $term['id'], (int) $tax['id']), true)) {
                $errors['parent'] = ['A ' . strtolower((string) $tax['singular']) . ' can\'t sit under itself or one of its own children.'];
            } else {
                $p = $this->repo->findTerm($parent);
                if (!$p || (int) $p['taxonomy_id'] !== (int) $tax['id']) $errors['parent'] = ['Choose a parent from the list.'];
                else $data['parent_id'] = $parent;
            }
        }
        if ($errors) throw AjaxException::invalid('Some fields need attention.', $errors);
        if ($data === []) throw AjaxException::invalid('Nothing was changed.');

        // TaxonomyService re-derives the slug from a new name when no slug is
        // given; a rename alone must not move the term's address.
        if (isset($data['name']) && !isset($data['slug'])) $data['slug'] = (string) $term['slug'];
        $this->terms->updateTerm((int) $term['id'], $data);

        $saved = $this->repo->findTermRow((int) $term['id']) ?? $term;
        $notices = [];
        if (isset($data['slug']) && (string) $saved['slug'] !== $data['slug']) {
            $notices[] = 'That slug was taken, so the address is now ' . $this->address((string) $saved['slug']) . '.';
        }
        $saved['taxonomy'] = $this->taxonomy;
        return ['row' => $saved, 'version' => $this->version($saved), 'notices' => $notices];
    }

    /** @return array{0:array,1:array} the term and its taxonomy */
    private function record(int $id): array
    {
        $tax = $this->taxonomy !== '' ? $this->repo->findTaxonomyBySlug($this->taxonomy) : null;
        $term = $this->repo->findTermRow($id);
        if ($tax === null || $term === null || (int) $term['taxonomy_id'] !== (int) $tax['id']) {
            throw AjaxException::notFound('This item no longer exists. It may have been deleted.');
        }
        return [$term, $tax];
    }

    private function version(array $term): string
    {
        return sha1(implode("\x1f", [
            (string) $term['name'], (string) $term['slug'], (string) ($term['parent_id'] ?? ''), (string) ($term['description'] ?? ''),
        ]));
    }

    /** Every other term of the taxonomy except this one's own subtree, A–Z. */
    private function parentOptions(array $tax, array $term): array
    {
        $exclude = array_flip(array_merge([(int) $term['id']], $this->descendants((int) $term['id'], (int) $tax['id'])));
        $opts = [['value' => '', 'label' => '— None (top level) —']];
        $rows = $this->repo->paginateTerms((int) $tax['id'], ['sort' => 'name_az'], 1, self::PARENT_LIMIT)['data'];
        $seenCurrent = false;
        foreach ($rows as $r) {
            if (isset($exclude[(int) $r['id']])) continue;
            if ((int) $r['id'] === (int) ($term['parent_id'] ?? 0)) $seenCurrent = true;
            $opts[] = ['value' => (string) $r['id'], 'label' => (string) $r['name']];
        }
        // Past the limit, keep the current parent selectable.
        if (!$seenCurrent && !empty($term['parent_id']) && ($p = $this->repo->findTerm((int) $term['parent_id']))) {
            $opts[] = ['value' => (string) $p['id'], 'label' => (string) $p['name']];
        }
        return $opts;
    }

    /** Ids of every term below this one. @return int[] */
    private function descendants(int $id, int $taxonomyId): array
    {
        $children = [];
        foreach ($this->repo->termsByTaxonomyId($taxonomyId) as $t) {
            if ($t['parent_id'] !== null) $children[(int) $t['parent_id']][] = (int) $t['id'];
        }
        $out = [];
        $stack = $children[$id] ?? [];
        while ($stack) {
            $n = array_pop($stack);
            if (in_array($n, $out, true)) continue;
            $out[] = $n;
            foreach ($children[$n] ?? [] as $c) $stack[] = $c;
        }
        return $out;
    }

    private function address(string $slug): string
    {
        try {
            $url = \App\Core\Application::getInstance()->make(\App\Services\TaxonomyRegistry::class)->termUrl($this->taxonomy, $slug);
        } catch (\Throwable) { $url = ''; }
        return $url !== '' ? $url : $slug;
    }

    private function addressHelp(string $slug): string
    {
        $a = $this->address($slug);
        return $a !== $slug ? 'Its address: ' . $a : 'Lowercase letters, numbers and dashes.';
    }
}
