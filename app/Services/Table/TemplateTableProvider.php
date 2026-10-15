<?php

declare(strict_types=1);

namespace App\Services\Table;

/** Templates are the posts table with type = 'template'. */
final class TemplateTableProvider extends PostTableProvider
{
    protected function type(): string
    {
        return 'template';
    }
}
