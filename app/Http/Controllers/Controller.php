<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Application;
use App\Core\View;
use App\Core\Response;
use App\Core\Session;
use App\Core\Request;

abstract class Controller
{
    protected Application $app;

    public function __construct()
    {
        $this->app = Application::getInstance();
    }

    protected function view(string $template, array $data = []): Response
    {
        $view = $this->app->make(View::class);
        // Always provide flash if available
        $session = $this->app->make(Session::class);
        $flash = $session->getFlash('_flash');
        if ($flash && !isset($data['flash'])) {
            $data['flash'] = $flash;
        }
        return new Response($view->render($template, $data));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    protected function back(): Response
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '/admin';
        return Response::redirect($ref);
    }

    protected function flash(string $type, string $message): void
    {
        $session = $this->app->make(Session::class);
        $session->flash('_flash', ['type' => $type, 'message' => $message]);
    }

    protected function user(): ?array
    {
        return $this->app->has('auth.user') ? $this->app->make('auth.user') : null;
    }

    protected function userId(): ?int
    {
        $u = $this->user();
        return $u ? (int)$u['id'] : null;
    }

    protected function verifyCsrf(Request $request): bool
    {
        $token = $request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? '';
        $session = $this->app->make(Session::class);
        return $session->verifyCsrf((string)$token);
    }

    protected function abort(int $status, string $message = ''): Response
    {
        return new Response($message ?: "HTTP {$status}", $status);
    }
}
