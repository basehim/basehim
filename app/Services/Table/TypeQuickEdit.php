<?php

declare(strict_types=1);

namespace App\Services\Table;

/**
 * Quick Edit for an app-registered content type's list (1.2.94). The type
 * comes from the table key (content.type.{slug}), set by TableActions through
 * forTable(); the fields follow what the type uses.
 */
final class TypeQuickEdit extends ContentQuickEdit
{
    private string $current = '';

    public function forTable(string $tableKey): void
    {
        $this->current = TypeTableProvider::typeFromKey($tableKey);
    }

    protected function type(): string
    {
        return $this->current;
    }

    protected function fieldNames(): array
    {
        $names = ['title', 'slug', 'author', 'status', 'publish'];
        try {
            $app = \App\Core\Application::getInstance();
            $tax = $app->make(\App\Services\TaxonomyRegistry::class);
            if ($tax->appliesTo('category', $this->current)) $names[] = 'categories';
            if ($tax->appliesTo('tag', $this->current)) $names[] = 'tags';
            if ($app->make(\App\Services\PostTypeRegistry::class)->supports($this->current, 'comments')) $names[] = 'comments';
        } catch (\Throwable) {}
        return $names;
    }
}
