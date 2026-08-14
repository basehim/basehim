<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/**
 * TemplateController — reusable block templates (patterns).
 *
 * Templates are posts with type 'template': they reuse the whole post
 * pipeline (PostService, slugs, revisions of behaviour, the block editor,
 * list view with search/filter/sort/pagination and bulk actions). Templates
 * never render on the public site; their purpose is to be inserted into
 * other posts from the block editor's inserter ("Templates" section).
 */
class TemplateController extends PostController
{
    protected string $type = 'template';
}
