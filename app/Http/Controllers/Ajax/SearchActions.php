<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Search\SearchManager;

/** Settings → Search (1.2.92): index status, Build now, Rebuild. */
final class SearchActions
{
    public function __construct(private SearchManager $search) {}

    public function status(AjaxContext $ctx): array
    {
        return $this->payload();
    }

    /** Index for a few seconds; the screen calls this again until everything is ready. */
    public function build(AjaxContext $ctx): array
    {
        $this->search->build(4.0);
        return $this->payload();
    }

    public function rebuild(AjaxContext $ctx): array
    {
        $id = (string) $ctx->input('source');
        $s = $this->search->get($id);
        if ($s === null || !$s->indexed()) {
            throw AjaxException::invalid('Unknown search source.', ['source' => ['Choose a source from the list.']]);
        }
        $this->search->rebuild($id);
        return $this->payload();
    }

    private function payload(): array
    {
        $sources = array_values($this->search->status());
        $busy = false;
        foreach ($sources as $s) if ($s['kind'] === 'indexed' && !$s['ready']) $busy = true;
        return ['engine' => $this->search->engine()->name(), 'building' => $busy, 'sources' => $sources];
    }
}
