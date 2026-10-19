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

    /** Type groups a request may name (MediaRepository::TYPE_GROUPS). */
    public const TYPES = ['image', 'svg', 'video', 'audio', 'document'];

    /**
     * The list filters from a request, every value checked: type and types
     * against TYPES, month as YYYY-MM turned into UTC bounds, sort by the
     * schema's `in` list. Shared by media.list and the media.library table.
     */
    public static function filtersFrom(array $in): array
    {
        $filters = ['sort' => (string) ($in['sort'] ?? 'newest')];
        $search = trim((string) ($in['q'] ?? ''));
        if ($search !== '') $filters['search'] = $search;

        $type = (string) ($in['type'] ?? '');
        if ($type !== '' && $type !== 'all') {
            if (!in_array($type, self::TYPES, true)) {
                throw AjaxException::invalid('Unknown media type.', ['type' => 'Use one of: all, ' . implode(', ', self::TYPES) . '.']);
            }
            $filters['type'] = $type;
        }
        $types = trim((string) ($in['types'] ?? ''));
        if ($types !== '') {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $types)))));
            foreach ($list as $t) {
                if (!in_array($t, self::TYPES, true)) {
                    throw AjaxException::invalid('Unknown media type.', ['types' => 'Use a comma list of: ' . implode(', ', self::TYPES) . '.']);
                }
            }
            $filters['allowed_types'] = $list;
            // `type` (the pill) narrows inside the allowed set; one outside it shows nothing.
            if (isset($filters['type'])) {
                if (!in_array($filters['type'], $list, true)) $list = [];
                else $list = [$filters['type']];
                unset($filters['type']);
            }
            $filters['types'] = $list;
        }
        $month = (string) ($in['month'] ?? '');
        if ($month !== '' && $month !== 'all') {
            $b = MediaService::monthBounds($month);
            if ($b === null) {
                throw AjaxException::invalid('Unknown month.', ['month' => 'Use YYYY-MM.']);
            }
            $filters['from'] = $b['from'];
            $filters['to'] = $b['to'];
        }
        return $filters;
    }

    /**
     * media.list — one page of the library, for the media library screen, the
     * picker and the avatar editor (replaces GET /admin/media/json).
     *
     * meta.has_more says whether another page exists (the picker's Load
     * More). The type counts, and with months=1 the month list, come with
     * page 1 only: a Load More page does not pay for them again.
     */
    public function list(AjaxContext $ctx): array
    {
        $in = $ctx->all();
        $filters = self::filtersFrom($in);
        $page = (int) ($ctx->input('page') ?? 1);
        $result = $this->media->paginate($filters, $page, (int) ($ctx->input('per_page') ?? 60));
        if ($page <= 1) {
            // Each pill counts the same search and month, whatever its type.
            $counts = $this->media->typeCounts($filters['search'] ?? null, array_intersect_key($filters, ['from' => 1, 'to' => 1]));
            if (isset($filters['allowed_types'])) {
                // In a picker limited to some types, "All" means all of those.
                $counts['all'] = array_sum(array_intersect_key($counts, array_flip($filters['allowed_types'])));
            }
            $result['counts'] = $counts;
            if (!empty($in['months'])) $result['months'] = $this->media->months();
        }
        return $result;
    }

    /** media.get — one item, for a details view or a picker's initial selection. */
    public function get(AjaxContext $ctx): array
    {
        $item = $this->media->find((int) $ctx->input('id'));
        if ($item === null) {
            throw AjaxException::notFound('That file no longer exists.');
        }
        $user = $ctx->user();
        return ['item' => $item, 'can' => [
            'edit'   => MediaService::userCanEdit($user, $item),
            'delete' => MediaService::userCanModify($user, $item),
        ]];
    }

    /**
     * media.delete — delete one or more files, with a result for each.
     *
     * Someone else's file needs delete_media (MediaService::userCanModify);
     * those are skipped as 'denied', the rest go ahead. An ID that is already
     * gone is 'missing', so repeating a request is harmless. A file whose row
     * went but which could not be removed from disk is 'partial' and counted
     * under failed: it is never reported as deleted.
     */
    public function delete(AjaxContext $ctx): array
    {
        $user = $ctx->user();
        $deleted = 0; $failed = 0; $denied = 0; $missing = 0;
        $results = [];
        foreach (array_unique(array_map('intval', (array) $ctx->input('ids'))) as $id) {
            $row = $this->media->find($id);
            if ($row === null) { $missing++; $results[] = ['id' => $id, 'status' => 'missing']; continue; }
            if (!MediaService::userCanModify($user, $row)) { $denied++; $results[] = ['id' => $id, 'status' => 'denied']; continue; }
            try {
                $r = $this->media->deleteWithReport($id);
            } catch (\Throwable $e) {
                $r = ['status' => 'failed'];
                $ctx->log('Media delete failed: ' . get_class($e), ['media_id' => $id], 'error');
            }
            switch ($r['status']) {
                case 'deleted': $deleted++; break;
                case 'missing': $missing++; break;
                case 'partial':
                    $failed++;
                    $ctx->log('Media deleted but files were left on disk', ['media_id' => $id, 'files' => count($r['leftover'] ?? [])], 'warning');
                    break;
                default: $failed++;
            }
            $results[] = ['id' => $id, 'status' => $r['status']];
        }
        if ($deleted === 0 && $denied > 0 && $failed === 0) {
            throw AjaxException::forbidden($denied === 1 ? 'You can only delete your own files.'
                : "None of these files were deleted: you can only delete your own files.");
        }
        if ($deleted > 0) {
            $ctx->log('Media deleted', ['count' => $deleted, 'user_id' => $ctx->userId()]);
        }
        return ['deleted' => $deleted, 'failed' => $failed, 'denied' => $denied, 'missing' => $missing, 'results' => $results];
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
