<?php

declare(strict_types=1);

namespace App\Services\Table;

/** Pages are the posts table with type = 'page'. */
final class PageTableProvider extends PostTableProvider
{
    protected function type(): string
    {
        return 'page';
    }
}
