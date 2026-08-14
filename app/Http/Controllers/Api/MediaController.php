<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Http\Middleware\CheckCapability;
use App\Services\MediaService;

class MediaController extends ApiController
{
    use AuthorizesContent;

    public function index(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('media:read')) return $denied;
        if (!CheckCapability::userCan($user, 'upload_media')) return $this->forbidden('upload_media');

        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $page = max(1, (int)$request->query('page', 1));
        $per = min(100, max(1, (int)$request->query('per_page', 24)));
        $filters = [];
        if ($request->query('q')) $filters['search'] = $request->query('q');
        return Response::json($media->paginate($filters, $page, $per));
    }

    public function upload(Request $request): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('media:write')) return $denied;
        if (!CheckCapability::userCan($user, 'upload_media')) return $this->forbidden('upload_media');
        if (empty($_FILES['file'])) return Response::json(['error' => 'No file'], 422);

        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        try {
            $id = $media->upload(
                $_FILES['file'],
                (int)$user['id'],
                $config->get('cms.media.allowed_types', []),
                (int)$config->get('cms.media.max_upload_size', 64 * 1024 * 1024),
                [
                    'title' => $request->input('title'),
                    'alt_text' => $request->input('alt_text'),
                    'caption' => $request->input('caption'),
                ]
            );
            return Response::json(['data' => $media->find($id)], 201);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('media:write')) return $denied;

        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $item = $media->find((int)$id);
        if (!$item) return Response::json(['error' => 'Not found'], 404);

        // Uploaders may remove their own files; removing someone else's needs
        // the site-wide delete_media capability.
        $own = (int)($item['author_id'] ?? 0) === (int)$user['id'];
        $cap = $own ? 'upload_media' : 'delete_media';
        if (!CheckCapability::userCan($user, $cap)) return $this->forbidden($cap);

        $media->delete((int)$id);
        return Response::json(['message' => 'Deleted']);
    }

    /**
     * PATCH /media/{id} — update metadata.
     *
     * Only descriptive fields are writable. Path, mime type and dimensions
     * describe a file that is actually on disk; letting the API rewrite them
     * would desynchronise the row from reality.
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->authUser();
        if (!$user) return Response::json(['error' => 'Unauthenticated'], 401);
        if ($denied = $this->requireScope('media:write')) return $denied;

        /** @var MediaService $media */
        $media = $this->app->make(MediaService::class);
        $item = $media->find((int) $id);
        if (!$item) return Response::json(['error' => 'Not found'], 404);

        $own = (int)($item['author_id'] ?? 0) === (int)$user['id'];
        $cap = $own ? 'upload_media' : 'delete_media';
        if (!CheckCapability::userCan($user, $cap)) return $this->forbidden($cap);

        $data = [];
        foreach (['title', 'alt_text', 'caption', 'description'] as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = (string) $request->input($field);
            }
        }
        if (!$data) {
            return Response::json([
                'error' => 'Provide at least one of: title, alt_text, caption, description',
            ], 422);
        }

        $media->update((int) $id, $data);
        return Response::json(['data' => $media->find((int) $id)]);
    }
}
