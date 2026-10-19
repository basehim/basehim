<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Repositories\MediaRepository;
use App\Services\MediaService;

/**
 * Data provider for the Media Library's List view (1.2.67), table key
 * `media.library`. It wraps the EXISTING MediaService::paginate() — the same
 * query the grid, the picker and the REST API use — so the two views can
 * never disagree about what is in the library.
 *
 * Filters:
 *   type   one of MediaRepository::TYPE_GROUPS (also checked by the column's
 *          declared `in` set before it gets here)
 *   month  YYYY-MM in the site's timezone; anything else is ignored
 */
final class MediaTableProvider implements TableProvider
{
    public function __construct(private MediaService $media)
    {
    }

    public function sortKeys(): array
    {
        return array_keys(MediaRepository::SORTS);
    }

    public function filterKeys(): array
    {
        return ['type', 'month'];
    }

    public function query(TableQuery $q): array
    {
        $filters = ['sort' => $q->sort ?? 'newest'];
        if ($q->search !== null && $q->search !== '') {
            $filters['search'] = $q->search;
        }
        $type = $q->filter('type');
        if (is_string($type) && isset(MediaRepository::TYPE_GROUPS[$type])) {
            $filters['type'] = $type;
        }
        $month = $q->filter('month');
        if (is_string($month) && ($b = MediaService::monthBounds($month)) !== null) {
            $filters['from'] = $b['from'];
            $filters['to'] = $b['to'];
        }
        return $this->media->paginate($filters, $q->page, $q->perPage);
    }
}
