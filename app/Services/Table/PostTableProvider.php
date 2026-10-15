<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Services\PostService;

/**
 * Data provider for the Posts table (and, via subclasses, Pages and
 * Templates, which are the same `posts` table filtered by type).
 *
 * It owns no SQL: it maps the generic, already-validated TableQuery onto the
 * EXISTING PostService::paginate(), which runs PostRepository::paginate() with
 * its whitelisted $sortMap and bound parameters. Posts keep their schema and
 * their query; this is only a shape adapter.
 */
class PostTableProvider implements TableProvider
{
    public function __construct(protected PostService $posts)
    {
    }

    /** The post type this provider lists. Overridden by Pages/Templates. */
    protected function type(): string
    {
        return 'post';
    }

    public function sortKeys(): array
    {
        // Mirrors PostRepository::$sortMap — the only orders that repository
        // will accept. Anything else is rejected before a query runs.
        return ['newest', 'oldest', 'title_az', 'title_za', 'views_desc', 'views_asc'];
    }

    public function filterKeys(): array
    {
        return ['status', 'author_id', 'trashed', 'category', 'tag'];
    }

    public function query(TableQuery $q): array
    {
        $filters = ['type' => $this->type()];
        if ($q->search !== null && $q->search !== '') {
            $filters['search'] = $q->search;
        }
        if ($q->sort !== null) {
            $filters['sort'] = $q->sort;
        }
        $status = $q->filter('status');
        if (is_string($status) && $status !== '') {
            $filters['status'] = $status;
        }
        $author = $q->filter('author_id');
        if ($author !== null && (int) $author > 0) {
            $filters['author_id'] = (int) $author;
        }
        if ($q->filter('trashed') === '1' || $q->filter('trashed') === 'trash') {
            $filters['trashed'] = true;
        }
        // Category and tag accept one id or a list of ids (multi-select). The
        // repository normalises and binds them; pass the value through as-is.
        $cat = $q->filter('category');
        if ($cat !== null && $cat !== '' && $cat !== []) {
            $filters['category'] = $cat;
        }
        $tag = $q->filter('tag');
        if ($tag !== null && $tag !== '' && $tag !== []) {
            $filters['tag'] = $tag;
        }

        return $this->posts->paginate($filters, $q->page, $q->perPage);
    }
}
