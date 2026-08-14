<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Http\Middleware\CheckCapability;
use App\Core\Response;
use App\Services\TaxonomyService;

class TaxonomyController extends ApiController
{
    use AuthorizesContent;

    public function taxonomies(Request $request): Response
    {
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        return Response::json(['data' => $tax->allTaxonomies()]);
    }

    public function terms(Request $request, string $taxonomy): Response
    {
        /** @var TaxonomyService $tax */
        $taxSvc = $this->app->make(TaxonomyService::class);
        if (!$taxSvc->findTaxonomyBySlug($taxonomy)) {
            return Response::json(['error' => 'Taxonomy not found'], 404);
        }
        return Response::json(['data' => $taxSvc->termsByTaxonomySlug($taxonomy)]);
    }

    public function storeTerm(Request $request, string $taxonomy): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('taxonomies:write')) return $denied;
        if (!CheckCapability::userCan($user, 'manage_taxonomies')) return $this->forbidden('manage_taxonomies');

        /** @var TaxonomyService $tax */
        $taxSvc = $this->app->make(TaxonomyService::class);
        $id = $taxSvc->createTerm($taxonomy, [
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'description' => $request->input('description'),
            'parent_id' => $request->input('parent_id'),
        ]);
        if (!$id) return Response::json(['error' => 'Could not create term'], 422);
        return Response::json(['data' => $taxSvc->findTerm($id)], 201);
    }

    public function updateTerm(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('taxonomies:write')) return $denied;
        if (!CheckCapability::userCan($user, 'manage_taxonomies')) return $this->forbidden('manage_taxonomies');

        /** @var TaxonomyService $tax */
        $taxSvc = $this->app->make(TaxonomyService::class);
        $term = $taxSvc->findTerm((int)$id);
        if (!$term) return Response::json(['error' => 'Not found'], 404);

        $taxSvc->updateTerm((int)$id, [
            'name' => $request->input('name', $term['name']),
            'slug' => $request->input('slug', $term['slug']),
            'description' => $request->input('description', $term['description']),
            'parent_id' => $request->input('parent_id', $term['parent_id']),
        ]);
        return Response::json(['data' => $taxSvc->findTerm((int)$id)]);
    }

    public function destroyTerm(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('taxonomies:write')) return $denied;
        if (!CheckCapability::userCan($user, 'manage_taxonomies')) return $this->forbidden('manage_taxonomies');

        /** @var TaxonomyService $tax */
        $taxSvc = $this->app->make(TaxonomyService::class);
        $term = $taxSvc->findTerm((int)$id);
        if (!$term) return Response::json(['error' => 'Not found'], 404);
        $taxSvc->deleteTerm((int)$id);
        return Response::json(['message' => 'Deleted']);
    }
}
