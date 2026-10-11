<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Services\PostEditorService;

/**
 * Tag lookups for the post editor's tag box (registered in routes/ajax.php).
 * Read-only GET actions: tag names are public anyway (archives, the API), so
 * these only need someone who can write posts.
 */
final class TermActions
{
    public function __construct(private PostEditorService $editor) {}

    /** terms.tag_suggest — tags matching what is being typed. */
    public function tagSuggest(AjaxContext $ctx): array
    {
        $exclude = array_map('intval', array_filter(explode(',', (string) ($ctx->input('exclude') ?? ''))));
        return ['tags' => $this->editor->suggestTags((string) $ctx->input('q'), (int) ($ctx->input('limit') ?? 8), $exclude)];
    }

    /** terms.tag_overview — most used tags, and the ones this user used lately. */
    public function tagOverview(AjaxContext $ctx): array
    {
        return $this->editor->tagOverview($ctx->user(), (int) ($ctx->input('limit') ?? 15));
    }
}
