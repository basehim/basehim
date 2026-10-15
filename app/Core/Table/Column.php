<?php

declare(strict_types=1);

namespace App\Core\Table;

/**
 * One column of a table.
 *
 * A column is a declarative definition, validated once at registration the
 * same way an AJAX action's options are (see AjaxRegistry::build). Nothing
 * about it is discovered later as a check that never ran.
 *
 * How a cell's value is produced — exactly one of:
 *
 *  - `value`:   a row key. Core reads $row[value] and HTML-escapes it. The
 *               safe default for plain fields (title, author name, a date).
 *  - `render`:  callable(array $row, mixed $prepared): string returning HTML.
 *               Used for badges, links, formatted values. Its output is
 *               treated as untrusted when the column belongs to an app and is
 *               run through HtmlSanitizer; a render callback MUST still escape
 *               the data it interpolates.
 *
 * Avoiding N+1 — the two-phase contract:
 *
 *  - `prepare`: optional callable(array $rows): mixed, run ONCE for the whole
 *               page before any cell is rendered. It does a single bulk query
 *               (WHERE id IN (...)) and returns a lookup (e.g. keyed by row id).
 *               Core passes that lookup to every render() call as $prepared.
 *               `render` must do no I/O of its own — it reads $row and
 *               $prepared only. This keeps a page's cost at O(columns), never
 *               O(rows × columns).
 *
 * Capabilities: a column may name a `capability`. A user without it never
 * receives the column in the response — it is removed from the payload, not
 * hidden in CSS. A hidden column is not a security boundary.
 *
 * `sortable` lists the sort tokens this column activates (and which the
 * provider must also accept); an empty list means the column cannot be
 * sorted. `filter` declares a filter this column drives. Neither is assumed:
 * a capability must be declared to exist.
 */
final class Column
{
    public const OWNER_CORE = 'core';

    /**
     * @param string[]                 $sortable   Sort tokens this column enables (⊆ provider sortKeys)
     * @param array{field:string,in?:array}|null $filter  Declared filter, or null
     * @param ('always'|'desktop-only'|'tablet-up') $responsive  Responsive hint (NOT user visibility)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $value = null,
        /** @var null|callable */
        public readonly mixed $render = null,
        /** @var null|callable */
        public readonly mixed $prepare = null,
        public readonly array $sortable = [],
        public readonly ?array $filter = null,
        public readonly bool $defaultVisible = true,
        public readonly bool $canHide = true,
        public readonly ?string $capability = null,
        public readonly string $responsive = 'always',
        public readonly string $align = 'left',
        public readonly string $owner = self::OWNER_CORE,
    ) {
    }

    public function isSortable(): bool
    {
        return $this->sortable !== [];
    }

    public function isFilterable(): bool
    {
        return $this->filter !== null;
    }

    /** The public shape sent to the client (never includes PHP callables). */
    public function toClientArray(): array
    {
        return [
            'id'              => $this->id,
            'label'           => $this->label,
            'sortable'        => $this->sortable,
            'filter'          => $this->filter,
            'default_visible' => $this->defaultVisible,
            'can_hide'        => $this->canHide,
            'responsive'      => $this->responsive,
            'align'           => $this->align,
            'owner'           => $this->owner,
        ];
    }
}
