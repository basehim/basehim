<?php

declare(strict_types=1);

namespace App\Services\Table;

/** Quick Edit for the Posts table (1.2.61). */
final class PostQuickEdit extends ContentQuickEdit
{
    protected function type(): string
    {
        return 'post';
    }

    protected function fieldNames(): array
    {
        return ['title', 'slug', 'author', 'status', 'publish_date', 'publish_time', 'comments', 'categories', 'tags'];
    }
}
