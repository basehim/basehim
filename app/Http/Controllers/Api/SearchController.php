<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\PostService;

class SearchController extends ApiController
{
    public function index(Request $request): Response
    {
        $query = trim((string)$request->query('q', ''));
        if ($query === '') return Response::json(['data' => [], 'meta' => ['total' => 0]]);

        $page = $this->pageNumber($request);
        $per = $this->perPage($request, 10);

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        return Response::json($posts->search($query, $page, $per));
    }
}
