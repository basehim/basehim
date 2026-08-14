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
        if (!$taxData) return $this->abort(404, 'Taxonomy not found');

        $terms = $tax->termsByTaxonomySlug($taxonomy);
        $session = $this->app->make(Session::class);

        return $this->view('taxonomies.index', [
            'title' => $taxData['label'],
            'currentUser' => $this->user(),
            'taxonomy' => $taxData,
            'terms' => $terms,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function storeTerm(Request $request, string $taxonomy): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        $id = $tax->createTerm($taxonomy, [
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'description' => $request->input('description'),
            'parent_id' => $request->input('parent_id'),
        ]);
        $this->flash($id ? 'success' : 'error', $id ? 'Term created.' : 'Could not create term.');
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
