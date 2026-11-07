<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AuthService;
use App\Services\AvatarService;
use App\Services\UserService;

/**
 * Data provider for the Users table (1.2.102). Wraps the existing
 * UserService::paginate() (search, role, status, the sorts in
 * UserRepository::SORTS) and adds what the rows need to draw: the profile
 * photo's thumbnail and `row_flags` — `manage` when the signed-in user may
 * change or delete that person (AccessControl::canManage: never themselves,
 * never someone at their level or above).
 */
final class UserTableProvider implements TableProvider
{
    public function __construct(
        private UserService $users,
        private AvatarService $avatars,
        private AccessControl $access,
        private AuthService $auth,
    ) {
    }

    public function sortKeys(): array
    {
        return array_keys(UserRepository::SORTS);
    }

    public function filterKeys(): array
    {
        return ['role', 'status'];
    }

    public function query(TableQuery $q): array
    {
        $filters = ['sort' => $q->sort ?: 'newest'];
        if ($q->search !== null && $q->search !== '') $filters['search'] = $q->search;
        foreach (['role', 'status'] as $f) {
            $v = $q->filter($f);
            if (is_string($v) && $v !== '') $filters[$f] = $v;
        }
        $result = $this->users->paginate($filters, $q->page, $q->perPage);

        $rows = (array) ($result['data'] ?? []);
        $thumbs = $rows ? $this->avatars->thumbnails(array_map('intval', array_column($rows, 'id'))) : [];
        $me = $this->auth->sessionUser();
        foreach ($rows as &$row) {
            $row['avatar_thumb'] = $thumbs[(int) $row['id']] ?? '';
            $row['row_flags'] = $this->access->canManage($me, $row) ? ['manage'] : [];
        }
        unset($row);
        $result['data'] = $rows;
        return $result;
    }
}
