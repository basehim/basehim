<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\MediaService;
use App\Core\Config;
use App\Core\Session;

class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $session = $this->app->make(Session::class);
        $config = $this->app->make(Config::class);

        // The grid loads itself through media.list and the List view is a
        // Data Table, so the page needs only the total for its heading.
        return $this->view('media.index', [
            'title' => 'Media Library',
            'currentUser' => $this->user(),
            'total' => $media->totalCount(),
            'csrf' => $session->csrfToken(),
            // What this site accepts, as Settings → Media says (the same lists
            // upload() checks), not the config defaults.
            'maxSize' => $media->maxUploadBytes((int) $config->get('cms.media.max_upload_size', 64 * 1024 * 1024)),
            'allowedTypes' => $media->allowedTypes((array) $config->get('cms.media.allowed_types', [])),
        ]);
    }

    /**
     * JSON endpoint used by the media picker modal & API consumers.
     * Named listJson() to avoid collision with base Controller::json() helper.
     */
    public function listJson(Request $request): Response
    {
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 60)));
        $search = (string)$request->query('q', '');
        $type = (string)$request->query('type', '');
        $sort = (string)$request->query('sort', 'newest');

        $filters = ['sort' => $sort];
        if ($search !== '') $filters['search'] = $search;
        if ($type !== '' && $type !== 'all') $filters['type'] = $type;

        $result = $media->paginate($filters, $page, $perPage);
        // Type counts respect the current search but ignore the active type pill,
        // so each pill shows how many items it would reveal.
        $result['counts'] = $media->typeCounts($search !== '' ? $search : null);
        return Response::json($result);
    }

    /** Bulk delete media items by id list (WordPress-style bulk action). */
    public function bulkDestroy(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['error' => 'Security check failed.'], 419);
        }
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $ids = $request->input('ids', []);
        if (is_string($ids)) $ids = array_filter(explode(',', $ids));
        if (!is_array($ids) || empty($ids)) {
            return Response::json(['error' => 'No items selected.'], 422);
        }
        $deleted = 0; $failed = 0; $denied = 0;
        $user = $this->user();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            try {
                $row = $media->find($id);
                // Someone else's file needs delete_media (MediaService::userCanModify).
                if ($row && !MediaService::userCanModify($user, $row)) { $denied++; $failed++; continue; }
                if ($media->delete($id)) $deleted++; else $failed++;
            } catch (\Throwable) { $failed++; }
        }
        return Response::json(['success' => true, 'deleted' => $deleted, 'failed' => $failed, 'denied' => $denied]);
    }

    /**
     * POST /admin/media/{id}/update — kept for older callers.
     *
     * Superseded by the "media.update" AJAX action, which the admin's own
     * scripts now use. Hardened in place: it applies the same ownership rule
     * (MediaService::userCanEdit), takes string values only, and no longer
     * returns a raw exception message.
     */
    public function updateMeta(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['error' => 'Security check failed.'], 419);
        }
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $row = $media->find((int)$id);
        if (!$row) return Response::json(['error' => 'Not found.'], 404);
        if (!MediaService::userCanEdit($this->user(), $row)) {
            return Response::json(['error' => 'You can only edit your own files.'], 403);
        }

        $fields = [];
        foreach (array_keys(MediaService::META_FIELDS) as $f) {
            $v = $request->input($f, null);
            if ($v === null) continue;
            if (!is_string($v)) return Response::json(['error' => "The {$f} field must be text."], 422);
            $fields[$f] = $v;
        }
        if (empty($fields)) return Response::json(['error' => 'Nothing to update.'], 422);

        try {
            $media->updateMeta((int)$id, $fields);
            return Response::json(['success' => true, 'data' => $media->find((int)$id)]);
        } catch (\Throwable $e) {
            try { $this->app->make(\App\Core\Logger::class)->error('Media metadata update failed: ' . $e->getMessage(), ['id' => (int) $id]); } catch (\Throwable) {}
            return Response::json(['error' => 'Could not save the changes.'], 500);
        }
    }

    public function upload(Request $request): Response
    {
        $isAjax = $this->isAjax($request);

        if (!$this->verifyCsrf($request)) {
            if ($isAjax) return Response::json(['error' => 'Security check failed.'], 419);
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/media');
        }

        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $config = $this->app->make(Config::class);

        $allowed = $media->allowedTypes((array) $config->get('cms.media.allowed_types', []));
        $maxBytes = $media->maxUploadBytes((int) $config->get('cms.media.max_upload_size', 64 * 1024 * 1024));

        if (empty($_FILES['file'])) {
            if ($isAjax) return Response::json(['error' => 'No file selected.'], 422);
            $this->flash('error', 'No file selected.');
            return $this->redirect('/admin/media');
        }

        try {
            $created = $media->upload($_FILES['file'], $this->userId() ?? 1, $allowed, $maxBytes, [
                'title' => $request->input('title'),
                'alt_text' => $request->input('alt_text'),
                'caption' => $request->input('caption'),
                // The post editor sends the post title; used only when the
                // file's own name is meaningless (see MediaService::upload).
                'name_hint' => is_scalar($request->input('name_hint')) ? mb_substr((string) $request->input('name_hint'), 0, 200) : '',
                // 1.2.87: a hint for the media.upload.* filters; colon contexts are the server's own.
                'context' => is_string($request->input('context')) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/', (string) $request->input('context'))
                    ? (string) $request->input('context') : 'library',
            ]);
            if ($isAjax) {
                // upload() returns the inserted row; decorate URL with base prefix
                $row = is_array($created) ? $media->find((int)$created['id']) : $media->find((int)$created);
                return Response::json([
                    'success' => true,
                    'data' => $row,
                ], 201);
            }
            $this->flash('success', 'File uploaded successfully.');
        } catch (\App\Services\MediaRejected $e) {
            // MediaService's own messages: written for the person uploading.
            if ($isAjax) return Response::json(['error' => $e->getMessage()], 422);
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            // Anything else (a database error, say) may name tables or paths:
            // it goes to the log, and the browser gets a plain sentence.
            if (function_exists('error_log')) error_log('[Basehim] Media upload failed: ' . get_class($e) . ': ' . $e->getMessage());
            $msg = 'The file could not be saved. Please try again.';
            if ($isAjax) return Response::json(['error' => $msg], 500);
            $this->flash('error', $msg);
        }

        return $this->redirect('/admin/media');
    }

    public function destroy(Request $request, string $id): Response
    {
        $isAjax = $this->isAjax($request);
        if (!$this->verifyCsrf($request)) {
            if ($isAjax) return Response::json(['error' => 'Security check failed.'], 419);
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/media');
        }
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $row = $media->find((int)$id);
        if ($row && !MediaService::userCanModify($this->user(), $row)) {
            if ($isAjax) return Response::json(['error' => 'You can only delete your own files.'], 403);
            $this->flash('error', 'You can only delete your own files.');
            return $this->redirect('/admin/media');
        }
        $media->delete((int)$id);
        if ($isAjax) return Response::json(['success' => true]);
        $this->flash('success', 'File deleted.');
        return $this->redirect('/admin/media');
    }

    private function isAjax(Request $request): bool
    {
        if ($request->query('ajax') === '1') return true;
        $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($header, 'XMLHttpRequest') === 0) return true;
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }
}
