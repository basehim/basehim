<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;

/**
 * ApiController
 *
 * Handles the /admin/api/* section:
 *   - Overview  : status, base URL, quick-start info
 *   - Keys      : list / create / revoke / delete API keys
 *   - Reference : browsable list of all available REST endpoints
 */
class ApiController extends Controller
{
    // -------------------------------------------------------------------------
    // Sub-page: Overview
    // -------------------------------------------------------------------------

    public function overview(Request $request): Response
    {
        return $this->render('overview', [
            'title'   => 'API — Overview',
            'subtab'  => 'overview',
        ]);
    }

    /** Setup guide for the MCP server (how to connect Claude and friends). */
    public function mcp(Request $request): Response
    {
        return $this->render('mcp', [
            'title'   => 'API — MCP Server',
            'subtab'  => 'mcp',
        ]);
    }

    // -------------------------------------------------------------------------
    // Sub-page: Keys list
    // -------------------------------------------------------------------------

    public function keys(Request $request): Response
    {
        /** @var ApiKeyService $svc */
        $svc  = $this->app->make(ApiKeyService::class);
        $keys = $svc->all();

        $session = $this->app->make(Session::class);
        // One-time plain-key flash (shown once after create)
        $newKey = $session->get('api_new_key');
        if ($newKey) {
            $session->forget('api_new_key');
        }

        return $this->render('keys', [
            'title'   => 'API — Keys',
            'subtab'  => 'keys',
            'keys'    => $keys,
            'newKey'  => $newKey,
            'scopes'  => ApiKeyService::availableScopes(),   // includes app-contributed scopes
            'csrf'    => $session->csrfToken(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Create key (POST)
    // -------------------------------------------------------------------------

    public function createKey(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->redirect('/admin/api/keys');
        }

        $name      = trim((string)$request->input('name', ''));
        $scopes    = (array)($request->input('scopes') ?? []);
        $rateLimit = max(1, min(100000, (int)$request->input('rate_limit', 1000)));
        $expiresIn = $request->input('expires_in');   // e.g. "30d", "1y", "" = never

        if ($name === '') {
            $this->flash('error', 'Key name is required.');
            return $this->redirect('/admin/api/keys');
        }

        $expiresAt = null;
        if ($expiresIn && $expiresIn !== 'never') {
            $expiresAt = new \DateTimeImmutable('+' . $expiresIn);
        }

        /** @var ApiKeyService $svc */
        $svc    = $this->app->make(ApiKeyService::class);
        $result = $svc->create($this->userId(), $name, $scopes, $rateLimit, $expiresAt);

        // Store plain key in session — shown ONCE, then discarded
        $session = $this->app->make(Session::class);
        $session->set('api_new_key', $result['key']);

        $this->flash('success', "API key \"{$name}\" created successfully.");
        return $this->redirect('/admin/api/keys');
    }

    // -------------------------------------------------------------------------
    // Revoke key (POST /admin/api/keys/{id}/revoke)
    // -------------------------------------------------------------------------

    public function revokeKey(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->json(['error' => 'CSRF mismatch'], 403);
        }

        /** @var ApiKeyService $svc */
        $svc = $this->app->make(ApiKeyService::class);
        $key = $svc->find((int)$id);
        if (!$key) {
            $this->flash('error', 'Key not found.');
            return $this->redirect('/admin/api/keys');
        }

        $svc->revoke((int)$id);
        $this->flash('success', "Key \"{$key['name']}\" revoked.");
        return $this->redirect('/admin/api/keys');
    }

    // -------------------------------------------------------------------------
    // Delete key (POST /admin/api/keys/{id}/delete)
    // -------------------------------------------------------------------------

    public function deleteKey(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->json(['error' => 'CSRF mismatch'], 403);
        }

        /** @var ApiKeyService $svc */
        $svc = $this->app->make(ApiKeyService::class);
        $svc->delete((int)$id);

        $this->flash('success', 'API key deleted.');
        return $this->redirect('/admin/api/keys');
    }

    // -------------------------------------------------------------------------
    // Sub-page: Reference (API docs)
    // -------------------------------------------------------------------------

    public function reference(Request $request): Response
    {
        return $this->render('reference', [
            'title'  => 'API — Reference',
            'subtab' => 'reference',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function render(string $view, array $data): Response
    {
        return $this->view("api.{$view}", array_merge($data, [
            'currentUser' => $this->user(),
        ]));
    }
}
