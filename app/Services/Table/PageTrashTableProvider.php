<?php

declare(strict_types=1);

namespace App\Services\Table;

/** The pages' trash (1.2.122): the posts table with type = 'page', trashed rows. */
final class PageTrashTableProvider extends PostTrashTableProvider
{
    protected function type(): string
    {
        return 'page';
    }
}
