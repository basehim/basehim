<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Services\MediaService;

/**
 * Media actions for the AJAX gateway (registered in routes/ajax.php).
 *
 * The gateway has already checked the session, the CSRF token, upload_media
 * (and the /admin/media area rule) and the input schema. What it cannot know
 * is whose file this is, so the ownership rules are applied here, through the
 * same MediaService methods the admin controller and the REST API use.
 *
 * Uploads stay on /admin/media/upload: the gateway takes JSON only.
 */
final class MediaActions
{
    public function __construct(private MediaService $media) {}

    /** media.update — title, alt text, caption and description of one item. */
    public function update(AjaxContext $ctx): array
    {
        $id = (int) $ctx->input('id');
        $item = $this->media->find($id);
        if ($item === null) {
            throw AjaxException::notFound('That file no longer exists.');
        }
        // Resource-level check: an ID in the request is a claim, not a right.
        if (!MediaService::userCanEdit($ctx->user(), $item)) {
            throw AjaxException::forbidden('You can only edit your own files.');
        }

        $fields = array_intersect_key($ctx->all(), MediaService::META_FIELDS);
        if ($fields === []) {
            throw AjaxException::invalid('Send at least one of: title, alt_text, caption, description.');
        }

        $written = $this->media->updateMeta($id, $fields);
        $ctx->log('Media metadata updated', ['media_id' => $id, 'fields' => array_keys($written), 'user_id' => $ctx->userId()]);

        return ['item' => $this->media->find($id)];
    }

    /**
     * media.list — one page of the library, for the media library screen, the
     * picker and the avatar editor (replaces GET /admin/media/json).
     */
    public function list(AjaxContext $ctx): array
    {
        $filters = ['sort' => (string) ($ctx->input('sort') ?? 'newest')];
        $search = trim((string) ($ctx->input('q') ?? ''));
        $type = (string) ($ctx->input('type') ?? '');
        if ($search !== '') $filters['search'] = $search;
        if ($type !== '' && $type !== 'all') $filters['type'] = $type;
        $result = $this->media->paginate($filters, (int) ($ctx->input('page') ?? 1), (int) ($ctx->input('per_page') ?? 60));
        $result['counts'] = $this->media->typeCounts($search !== '' ? $search : null);
        return $result;
    }

    /**
     * media.delete — delete one or more files. Someone else's file needs
     * delete_media (MediaService::userCanModify); those are skipped and
     * counted as denied, the rest go ahead.
     */
    public function delete(AjaxContext $ctx): array
    {
        $user = $ctx->user();
        $deleted = 0; $failed = 0; $denied = 0; $missing = 0;
        foreach (array_unique(array_map('intval', (array) $ctx->input('ids'))) as $id) {
            $row = $this->media->find($id);
            if ($row === null) { $missing++; continue; }
            if (!MediaService::userCanModify($user, $row)) { $denied++; continue; }
            try {
                if ($this->media->delete($id)) $deleted++; else $failed++;
            } catch (\Throwable $e) {
                $failed++;
                $ctx->log('Media delete failed: ' . $e->getMessage(), ['media_id' => $id], 'error');
            }
        }
        if ($deleted === 0 && $denied > 0 && $failed === 0) {
            throw AjaxException::forbidden($denied === 1 ? 'You can only delete your own files.'
                : "None of these files were deleted: you can only delete your own files.");
        }
        if ($deleted > 0) {
            $ctx->log('Media deleted', ['count' => $deleted, 'user_id' => $ctx->userId()]);
        }
        return ['deleted' => $deleted, 'failed' => $failed, 'denied' => $denied, 'missing' => $missing];
    }

    /** media.regenerate_batch — one batch of Settings → Media → Regenerate thumbnails. */
    public function regenerateBatch(AjaxContext $ctx): array
    {
        if (!\extension_loaded('gd')) {
            throw new AjaxException('GD_MISSING', 'The GD image extension is not available on this server.', 422);
        }
        @set_time_limit(60);
        return $this->media->regenerateBatch((int) ($ctx->input('after') ?? 0), ($ctx->input('scope') ?? 'missing') !== 'all');
    }
}
