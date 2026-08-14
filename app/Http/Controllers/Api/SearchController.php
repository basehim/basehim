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

        $page = max(1, (int)$request->query('page', 1));
        $per = min(100, max(1, (int)$request->query('per_page', 10)));

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        return Response::json($posts->search($query, $page, $per));
    }
}
