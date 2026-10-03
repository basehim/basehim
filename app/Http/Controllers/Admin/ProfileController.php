<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\UserService;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user();
        if (!$user) return $this->redirect('/admin/login');
        $session = $this->app->make(Session::class);
        return $this->view('profile.index', [
            'title' => 'My Profile',
            'currentUser' => $user,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function update(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }

        $userId = $this->userId();
        if (!$userId) return $this->redirect('/admin/login');

        /** @var UserService $users */
        $users = $this->app->make(UserService::class);
        $current = $users->find($userId);
        if (!$current) return $this->redirect('/admin/login');

        $data = [
            'display_name' => $request->input('display_name', $current['display_name']),
            'email' => $request->input('email', $current['email']),
            'bio' => $request->input('bio'),
        ];

        $newPassword = (string)$request->input('new_password', '');
        if ($newPassword !== '') {
            $currentPassword = (string)$request->input('current_password', '');
            if (!password_verify($currentPassword, $current['password_hash'])) {
                $this->flash('error', 'Current password is incorrect.');
                return $this->back();
            }
            if (strlen($newPassword) < 8) {
                $this->flash('error', 'New password must be at least 8 characters.');
                return $this->back();
            }
            $data['password'] = $newPassword;
        }

        $users->update($userId, $data);

        // Public author address. Cleaned and made unique by AuthorService; an
        // unusable value keeps the current one.
        $slugIn = trim((string) $request->input('author_slug', ''));
        if ($slugIn !== '') {
            /** @var \App\Services\AuthorService $authorSvc */
            $authorSvc = $this->app->make(\App\Services\AuthorService::class);
            $currentSlug = ($u = $authorSvc->find($userId)) ? $authorSvc->slugFor($u) : '';
            if ($slugIn !== $currentSlug && $authorSvc->setSlug($userId, $slugIn) === null) {
                $this->flash('error', 'That author page address could not be used; the previous one was kept.');
            }
        }
        $this->flash('success', 'Profile updated.');
        return $this->redirect('/admin/profile');
    }

    // ── Profile photo: your own, always allowed ──────────────────────────

    public function avatar(Request $request): Response
    {
        $uid = $this->userId();
        if (!$uid) return Response::json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        return $this->avatarSave($request, (int) $uid);
    }

    public function avatarDelete(Request $request): Response
    {
        $uid = $this->userId();
        if (!$uid) return Response::json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        return $this->avatarRemove($request, (int) $uid);
    }

    /** POST {path} — upload a photo (multipart `avatar`) or use `media_id`. JSON. */
    private function avatarSave(Request $request, int $userId): Response
    {
        if (!$this->verifyCsrf($request)) return Response::json(['ok' => false, 'error' => 'Security check failed. Reload the page and try again.'], 419);
        /** @var \App\Services\AvatarService $svc */
        $svc = $this->app->make(\App\Services\AvatarService::class);
        try {
            $file = $_FILES['avatar'] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $av = $svc->upload($userId, $file, (int) ($this->userId() ?? $userId));
            } elseif ((int) $request->input('media_id', 0) > 0) {
                $av = $svc->setMedia($userId, (int) $request->input('media_id'));
            } else {
                return Response::json(['ok' => false, 'error' => 'Choose a photo to upload.'], 422);
            }
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        return Response::json(['ok' => true, 'avatar' => $av]);
    }

    private function avatarRemove(Request $request, int $userId): Response
    {
        if (!$this->verifyCsrf($request)) return Response::json(['ok' => false, 'error' => 'Security check failed. Reload the page and try again.'], 419);
        $this->app->make(\App\Services\AvatarService::class)->remove($userId);
        return Response::json(['ok' => true, 'avatar' => null]);
    }
}
