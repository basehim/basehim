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
        $page = max(1, (int)$request->query('page', 1));
        $search = (string)$request->query('q', '');

        $filters = [];
        if ($search !== '') $filters['search'] = $search;

        $result = $media->paginate($filters, $page, 24);

        $session = $this->app->make(Session::class);
        $config = $this->app->make(Config::class);

        return $this->view('media.index', [
            'title' => 'Media Library',
            'currentUser' => $this->user(),
            'items' => $result['data'],
            'meta' => $result['meta'],
            'search' => $search,
            'csrf' => $session->csrfToken(),
            'maxSize' => $config->get('cms.media.max_upload_size', 64 * 1024 * 1024),
            'allowedTypes' => $config->get('cms.media.allowed_types', []),
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
        $deleted = 0; $failed = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            try {
                if ($media->delete($id)) $deleted++; else $failed++;
            } catch (\Throwable) { $failed++; }
        }
        return Response::json(['success' => true, 'deleted' => $deleted, 'failed' => $failed]);
    }

    /** PATCH-like: update a media item's editable metadata from the detail pane. */
    public function updateMeta(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['error' => 'Security check failed.'], 419);
        }
        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $row = $media->find((int)$id);
        if (!$row) return Response::json(['error' => 'Not found.'], 404);

        $fields = [];
        foreach (['title', 'alt_text', 'caption', 'description'] as $f) {
            $v = $request->input($f, null);
            if ($v !== null) $fields[$f] = is_string($v) ? substr($v, 0, ($f === 'title' || $f === 'alt_text') ? 255 : 65535) : $v;
        }
        if (empty($fields)) return Response::json(['error' => 'Nothing to update.'], 422);

        try {
            $media->update((int)$id, $fields);
            return Response::json(['success' => true, 'data' => $media->find((int)$id)]);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
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
        } catch (\Throwable $e) {
            if ($isAjax) return Response::json(['error' => $e->getMessage()], 422);
            $this->flash('error', $e->getMessage());
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
