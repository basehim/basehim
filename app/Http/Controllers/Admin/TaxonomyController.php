<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\TaxonomyService;

class TaxonomyController extends Controller
{
    public function index(Request $request, string $taxonomy): Response
    {
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        $taxData = $tax->findTaxonomyBySlug($taxonomy);
        // An app's taxonomy whose row went missing is stored again (1.2.94).
        if (!$taxData && $this->app->make(\App\Services\TaxonomyRegistry::class)->ensureStored($taxonomy)) {
            $taxData = $tax->findTaxonomyBySlug($taxonomy);
        }
        if (!$taxData) return $this->abort(404, 'Taxonomy not found');

        // ?edit={id} (the front-end admin bar's "Edit category", older links):
        // search for the term so it is on the first page; the page then opens
        // its Quick Edit.
        $editId = (int) $request->query('edit', 0);
        if ($editId > 0 && (string) $request->query('q', '') === '') {
            $t = $tax->findTerm($editId);
            if ($t && (int) $t['taxonomy_id'] === (int) $taxData['id']) {
                return $this->redirect('/admin/taxonomies/' . rawurlencode($taxonomy) . '?' . http_build_query(['q' => (string) $t['name'], 'edit' => $editId]));
            }
        }

        $session = $this->app->make(Session::class);
        // 1.2.123: the list is the terms data table, paged in SQL. Only a
        // hierarchical taxonomy's parent choices are loaded here (capped), for
        // the New dialog; the old screen loaded and printed every term twice.
        $parents = [];
        if (!empty($taxData['hierarchical'])) {
            $parents = $this->app->make(\App\Repositories\TaxonomyRepository::class)
                ->paginateTerms((int) $taxData['id'], ['sort' => 'name_az'], 1, 1000)['data'];
        }

        return $this->view('taxonomies.index', [
            'title' => $taxData['label'],
            'currentUser' => $this->user(),
            'taxonomy' => $taxData,
            'parents' => $parents,
            'tableKey' => \App\Services\Table\TermTableProvider::keyFor($taxonomy),
            'termCount' => $tax->countTermsBySlug($taxonomy),
            'csrf' => $session->csrfToken(),
        ]);
    }

    /**
     * POST /admin/taxonomies/{taxonomy}/bulk — the terms table's Delete
     * (1.2.123): bulk_action=delete, ids[]. Only terms of this taxonomy.
     */
    public function bulk(Request $request, string $taxonomy): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        $taxData = $tax->findTaxonomyBySlug($taxonomy);
        if (!$taxData) return $this->abort(404, 'Taxonomy not found');
        $action = (string) $request->input('bulk_action', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        if ($action !== 'delete' || $ids === []) {
            $this->flash('error', 'Pick an action and at least one item.');
            return $this->redirect("/admin/taxonomies/{$taxonomy}");
        }
        $done = 0;
        foreach ($ids as $id) {
            $term = $tax->findTerm($id);
            if (!$term || (int) $term['taxonomy_id'] !== (int) $taxData['id']) continue;
            if ($tax->deleteTerm($id)) $done++;
        }
        \App\Services\ActivityLogService::record($this->userId(), 'term.bulk_delete', 'term', null,
            sprintf('Deleted %d %s', $done, strtolower((string) $taxData['label'])));
        $noun = strtolower((string) ($done === 1 ? $taxData['singular'] : $taxData['label']));
        $this->flash($done > 0 ? 'success' : 'error', $done > 0 ? sprintf('%d %s deleted.', $done, $noun) : 'Nothing was deleted.');
        return $this->redirect("/admin/taxonomies/{$taxonomy}");
    }

    public function storeTerm(Request $request, string $taxonomy): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        if (!$tax->findTaxonomyBySlug($taxonomy)) $this->app->make(\App\Services\TaxonomyRegistry::class)->ensureStored($taxonomy);
        $id = $tax->createTerm($taxonomy, [
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'description' => $request->input('description'),
            'parent_id' => $request->input('parent_id'),
        ]);
        $taxRow = $tax->findTaxonomyBySlug($taxonomy);
        $one = (string) ($taxRow['singular'] ?? 'Term');
        $this->flash($id ? 'success' : 'error', $id ? $one . ' added.' : $one . ' could not be added. Give it a name.');
        return $this->redirect("/admin/taxonomies/{$taxonomy}");
    }

    public function updateTerm(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var TaxonomyService $tax */
        $taxService = $this->app->make(TaxonomyService::class);
        $term = $taxService->findTerm((int)$id);
        if (!$term) return $this->abort(404);

        $taxService->updateTerm((int)$id, [
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'description' => $request->input('description'),
            'parent_id' => $request->input('parent_id'),
        ]);

        // find taxonomy slug to redirect back
        $tax = $this->app->make(\App\Core\Database::class)->selectOne(
            'SELECT slug FROM {taxonomies} WHERE id = :id', ['id' => $term['taxonomy_id']]
        );
        $this->flash('success', 'Term updated.');
        return $this->redirect("/admin/taxonomies/" . ($tax['slug'] ?? 'category'));
    }

    public function destroyTerm(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var TaxonomyService $tax */
        $taxService = $this->app->make(TaxonomyService::class);
        $term = $taxService->findTerm((int)$id);
        if (!$term) return $this->abort(404);
        $taxonomySlug = $this->app->make(\App\Core\Database::class)->selectOne(
            'SELECT slug FROM {taxonomies} WHERE id = :id', ['id' => $term['taxonomy_id']]
        );
        $taxService->deleteTerm((int)$id);
        $this->flash('success', 'Term deleted.');
        return $this->redirect("/admin/taxonomies/" . ($taxonomySlug['slug'] ?? 'category'));
    }
}
