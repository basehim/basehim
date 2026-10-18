<?php

declare(strict_types=1);

namespace App\Services\Table;

/**
 * Quick Edit for the Pages table (1.2.61). Pages have no categories or tags;
 * they have a parent and an order. Comments appear only if the page type
 * declares comment support (config/cms.php content_types.page.supports).
 */
final class PageQuickEdit extends ContentQuickEdit
{
    protected function type(): string
    {
        return 'page';
    }

    protected function fieldNames(): array
    {
        return ['title', 'slug', 'author', 'status', 'publish_date', 'publish_time', 'parent', 'menu_order', 'comments'];
    }
}
