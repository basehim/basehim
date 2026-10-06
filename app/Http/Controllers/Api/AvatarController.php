<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AvatarService;

/**
 * Profile photos over the REST API.
 *
 *   GET    /api/v1/me/avatar            your photo
 *   POST   /api/v1/me/avatar            set it: multipart `avatar` (an image file),
 *                                        or `media_id` of an image in the library
 *   DELETE /api/v1/me/avatar            remove it
 *   GET    /api/v1/users/{id}/avatar    anyone's photo
 *   POST   /api/v1/users/{id}/avatar    set it — yourself, or an administrator
 *   DELETE /api/v1/users/{id}/avatar    remove it — yourself, or an administrator
 *
 * Answers: {"data": {"user_id": 7, "avatar": {media_id, url, thumbnail_url,
 * medium_url, width, height} | null}}. Errors: 401, 403, 404, 422.
 */
class AvatarController extends ApiController
{
    public function showMe(Request $request): Response    { return $this->show($request, '0', true); }
    public function updateMe(Request $request): Response  { return $this->update($request, '0', true); }
    public function destroyMe(Request $request): Response { return $this->destroy($request, '0', true); }

    public function show(Request $request, string $id, bool $me = false): Response
    {
        $auth = $this->authUser();
        if (!$auth) return Response::json(['error' => 'Unauthenticated'], 401);
        $uid = $me ? (int) $auth['id'] : (int) $id;
        $user = $this->target($uid);
        if (!$user) return Response::json(['error' => 'Not found'], 404);
        return Response::json(['data' => $this->payload($user)]);
    }

    public function update(Request $request, string $id, bool $me = false): Response
    {
        $auth = $this->authUser();
        if (!$auth) return Response::json(['error' => 'Unauthenticated'], 401);
        $uid = $me ? (int) $auth['id'] : (int) $id;
        if (!$this->target($uid)) return Response::json(['error' => 'Not found'], 404);
        if (!$this->mayChange($auth, $uid)) return Response::json(['error' => 'Forbidden'], 403);

        $svc = $this->app->make(AvatarService::class);
        try {
            $file = $_FILES['avatar'] ?? ($_FILES['file'] ?? null);
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $svc->upload($uid, $file, (int) $auth['id']);
            } elseif ((int) $request->input('media_id', 0) > 0) {
                $svc->setMedia($uid, (int) $request->input('media_id'));
            } else {
                return Response::json(['error' => 'Send an image as multipart field "avatar", or "media_id" of an image in the media library.',
                                       'rules' => $svc->rules()], 422);
            }
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage(), 'rules' => $svc->rules()], 422);
        }
        return Response::json(['data' => $this->payload($this->target($uid))]);
    }

    public function destroy(Request $request, string $id, bool $me = false): Response
    {
        $auth = $this->authUser();
        if (!$auth) return Response::json(['error' => 'Unauthenticated'], 401);
        $uid = $me ? (int) $auth['id'] : (int) $id;
        if (!$this->target($uid)) return Response::json(['error' => 'Not found'], 404);
        if (!$this->mayChange($auth, $uid)) return Response::json(['error' => 'Forbidden'], 403);

        $this->app->make(AvatarService::class)->remove($uid);
        return Response::json(['data' => $this->payload($this->target($uid))]);
    }

    // ------------------------------------------------------------------

    /** Yourself, or an administrator — the same rule as PUT /users/{id}. */
    private function mayChange(array $auth, int $uid): bool
    {
        return (int) $auth['id'] === $uid || in_array($auth['role'] ?? '', ['super_admin', 'admin'], true);
    }

    private function target(int $id): ?array
    {
        if ($id <= 0) return null;
        return $this->app->make(\App\Core\Database::class)->selectOne(
            'SELECT id, avatar_media_id FROM {users} WHERE id = :id AND deleted_at IS NULL', ['id' => $id]
        ) ?: null;
    }

    private function payload(array $user): array
    {
        return ['user_id' => (int) $user['id'], 'avatar' => $this->app->make(AvatarService::class)->forUser($user)];
    }
}
