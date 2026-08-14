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
        $this->flash('success', 'Profile updated.');
        return $this->redirect('/admin/profile');
    }
}
