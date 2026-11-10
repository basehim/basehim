<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableQuery;
use App\Http\Middleware\CheckCapability;
use App\Services\AuthService;
use App\Services\PostService;

/**
 * The trash of a post type (1.2.122): the same rows and query as the list,
 * always the trashed ones, newest-deleted first.
 *
 * Each row says what this user may do with it (`row_flags`), so the row menu
 * offers only what the bulk handler will carry out: `restore` when they may
 * edit it (their own, or edit_others_*), `purge` when they may delete it
 * (their own, or delete_others_*). The handler checks again on submit.
 */
class PostTrashTableProvider extends PostTableProvider
{
    public function __construct(PostService $posts, private AuthService $auth)
    {
        parent::__construct($posts);
    }

    public function sortKeys(): array
    {
        return ['deleted_desc', 'deleted_asc', 'title_az', 'title_za'];
    }

    public function filterKeys(): array
    {
        // `trashed` is not a choice here: this list is always the trash.
        return ['status', 'author_id'];
    }

    public function query(TableQuery $q): array
    {
        $q = new TableQuery(
            $q->table, $q->page, $q->perPage, $q->sort ?? 'deleted_desc', $q->search,
            ['trashed' => '1'] + array_intersect_key($q->filters, array_flip($this->filterKeys())),
            $q->columns,
        );
        $result = parent::query($q);

        $user = $this->auth->sessionUser();
        $me = (int) ($user['id'] ?? 0);
        $editOthers = $user !== null && CheckCapability::userCan($user, 'edit_others_' . $this->type() . 's');
        $deleteOthers = $user !== null && CheckCapability::userCan($user, 'delete_others_' . $this->type() . 's');
        foreach ($result['data'] as &$row) {
            $own = $me > 0 && (int) ($row['author_id'] ?? 0) === $me;
            $flags = [];
            if ($own || $editOthers) $flags[] = 'restore';
            if ($own || $deleteOthers) $flags[] = 'purge';
            $row['row_flags'] = $flags;
        }
        unset($row);
        return $result;
    }
}
