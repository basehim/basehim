<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Services\CommentService;

/**
 * Data provider for the Comments table — a second entity with its own schema,
 * proving the adapter does not force every table through one query. It wraps
 * the EXISTING CommentService::paginate() (which JOINs the comment's post).
 */
final class CommentTableProvider implements TableProvider
{
    public function __construct(private CommentService $comments)
    {
    }

    public function sortKeys(): array
    {
        // CommentService::paginate orders by created_at DESC; it exposes no
        // sort map, so the table offers no column sorting until the service
        // does. Declaring none means no sort token is ever accepted.
        return [];
    }

    public function filterKeys(): array
    {
        return ['status'];
    }

    public function query(TableQuery $q): array
    {
        $filters = [];
        if ($q->search !== null && $q->search !== '') {
            $filters['search'] = $q->search;
        }
        $status = $q->filter('status');
        if (is_string($status) && $status !== '') {
            $filters['status'] = $status;
        }
        return $this->comments->paginate($filters, $q->page, $q->perPage);
    }
}
