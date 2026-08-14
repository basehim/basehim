<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ApiKeyService;
use App\Services\McpOAuthService;
use App\Repositories\UserRepository;

/**
 * OAuthController — the OAuth 2.1 surface that lets MCP clients (Claude's web
 * connector, Claude Desktop, Inspector, …) connect to this site's /mcp endpoint.
 *
 * Endpoints
 *   GET  /.well-known/oauth-protected-resource   RFC 9728 — discovery from /mcp
 *   GET  /.well-known/oauth-authorization-server RFC 8414 — endpoint discovery
 *   POST /oauth/register                          RFC 7591 — dynamic registration
 *   GET  /oauth/authorize                         consent screen (admin login required)
 *   POST /oauth/authorize                         approve/deny -> redirect with code
 *   POST /oauth/token                             code -> tokens, and refresh
 *
 * Because dynamic registration is supported, connecting is just "paste the URL":
 * the client registers itself, so there is no client id/secret to configure.
 */
final class OAuthController
{
    // ==================================================================
    // Discovery
    // ==================================================================

    public function protectedResource(Request $request): Response
    {
        return $this->json($this->svc()->protectedResourceMetadata());
    }

    public function authorizationServer(Request $request): Response
    {
        return $this->json($this->svc()->authorizationServerMetadata());
    }

    // ==================================================================
    // Dynamic client registration (RFC 7591)
    // ==================================================================

    public function register(Request $request): Response
    {
        $body = $this->jsonBody();
        try {
            $client = $this->svc()->registerClient($body);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'invalid_client_metadata', 'error_description' => $e->getMessage()], 400);
        }
        return $this->json($client, 201);
    }

    // ==================================================================
    // Authorization
    // ==================================================================

    public function showAuthorize(Request $request): Response
    {
        $svc = $this->svc();
        $q = [
            'client_id'             => (string) $request->query('client_id', ''),
            'redirect_uri'          => (string) $request->query('redirect_uri', ''),
            'response_type'         => (string) $request->query('response_type', ''),
            'code_challenge'        => (string) $request->query('code_challenge', ''),
            'code_challenge_method' => (string) $request->query('code_challenge_method', ''),
            'scope'                 => (string) $request->query('scope', ''),
            'state'                 => (string) $request->query('state', ''),
            'resource'              => (string) $request->query('resource', ''),
        ];

        $client = $svc->findClient($q['client_id']);
        // Errors that must NOT redirect (we can't trust the redirect_uri yet).
        if (!$client) {
            return $this->errorPage('Unknown client', 'This application is not registered with this site.');
        }
        if ($q['redirect_uri'] === '' || !$svc->redirectAllowed($client, $q['redirect_uri'])) {
            return $this->errorPage('Invalid redirect URI', 'The redirect URI does not match the one registered for this application.');
        }
        // From here on, protocol errors go back to the client per OAuth 2.1.
        if ($q['response_type'] !== 'code') {
            return $this->redirectError($q, 'unsupported_response_type', 'Only the authorization_code flow is supported.');
        }
        if ($q['code_challenge'] === '' || strtoupper($q['code_challenge_method'] ?: 'PLAIN') !== 'S256') {
            return $this->redirectError($q, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        // Require a signed-in Basehim user; bounce through the normal login.
        $user = $this->currentUser();
        if (!$user) {
            $session = $this->app()->make(Session::class);
            $session->set('intended_url', $this->relativeSelf());
            return Response::redirect('/admin/login');
        }

        $scope = $svc->sanitizeScopes($q['scope']);
        $session = $this->app()->make(Session::class);

        return Response::view('auth.oauth-consent', [
            'title'       => 'Authorize ' . ($client['client_name'] ?? 'application'),
            'client'      => $client,
            'scopes'      => array_values(array_filter(explode(' ', $scope))),
            'scopeLabels' => ApiKeyService::SCOPES,
            'user'        => $user,
            'csrf'        => $session->csrfToken(),
            'params'      => $q + ['scope' => $scope],
        ]);
    }

    public function authorize(Request $request): Response
    {
        $session = $this->app()->make(Session::class);
        if (!hash_equals((string) $session->csrfToken(), (string) $request->input('_csrf', ''))) {
            return $this->errorPage('Security check failed', 'Please try connecting again.');
        }
        $user = $this->currentUser();
        if (!$user) return Response::redirect('/admin/login');

        $svc = $this->svc();
        $q = [
            'client_id'      => (string) $request->input('client_id', ''),
            'redirect_uri'   => (string) $request->input('redirect_uri', ''),
            'code_challenge' => (string) $request->input('code_challenge', ''),
            'code_challenge_method' => (string) $request->input('code_challenge_method', 'S256'),
            'scope'          => (string) $request->input('scope', ''),
            'state'          => (string) $request->input('state', ''),
            'resource'       => (string) $request->input('resource', ''),
        ];
        $client = $svc->findClient($q['client_id']);
        if (!$client || !$svc->redirectAllowed($client, $q['redirect_uri'])) {
            return $this->errorPage('Invalid request', 'This authorization request is no longer valid.');
        }

        if ((string) $request->input('decision', '') !== 'allow') {
            return $this->redirectError($q, 'access_denied', 'The user declined the request.');
        }

        // Only ever grant scopes the signing-in user actually holds.
        $granted = $this->filterByCapability($svc->sanitizeScopes($q['scope']), $user);
        if ($granted === '') {
            return $this->redirectError($q, 'invalid_scope', 'Your account cannot grant any of the requested permissions.');
        }

        $code = $svc->issueCode(
            $q['client_id'], (int) $user['id'], $q['redirect_uri'],
            $q['code_challenge'], 'S256', $granted,
            $q['resource'] !== '' ? $q['resource'] : $svc->resourceUrl()
        );

        try {
            \App\Services\ActivityLogService::record((int) $user['id'], 'mcp.authorized', 'user', (int) $user['id'],
                'Authorized "' . ($client['client_name'] ?? 'MCP client') . '" (' . $granted . ')');
        } catch (\Throwable) {}

        $sep = str_contains($q['redirect_uri'], '?') ? '&' : '?';
        $url = $q['redirect_uri'] . $sep . http_build_query(array_filter([
            'code'  => $code,
            'state' => $q['state'] !== '' ? $q['state'] : null,
        ]));
        return Response::redirect($url);
    }

    // ==================================================================
    // Token
    // ==================================================================

    public function token(Request $request): Response
    {
        $svc = $this->svc();
        $grant = (string) $request->input('grant_type', '');

        [$clientId, $clientSecret] = $this->clientCredentials($request);
        $client = $clientId !== '' ? $svc->findClient($clientId) : null;
        if (!$client) {
            return $this->tokenError('invalid_client', 'Unknown client.', 401);
        }
        // Confidential clients must prove themselves.
        if ((int) $client['is_public'] !== 1) {
            $ok = $clientSecret !== '' && !empty($client['secret_hash'])
               && hash_equals((string) $client['secret_hash'], hash('sha256', $clientSecret));
            if (!$ok) return $this->tokenError('invalid_client', 'Client authentication failed.', 401);
        }

        try {
            if ($grant === 'authorization_code') {
                $row = $svc->redeemCode(
                    (string) $request->input('code', ''),
                    $clientId,
                    (string) $request->input('redirect_uri', ''),
                    (string) $request->input('code_verifier', '')
                );
                $tokens = $svc->issueTokens($clientId, (int) $row['user_id'], (string) $row['scope'], $row['resource'] ?? null);
                return $this->json($tokens);
            }
            if ($grant === 'refresh_token') {
                $tokens = $svc->refresh((string) $request->input('refresh_token', ''), $clientId);
                return $this->json($tokens);
            }
        } catch (\Throwable $e) {
            return $this->tokenError('invalid_grant', 'The grant is invalid, expired, or already used.', 400);
        }
        return $this->tokenError('unsupported_grant_type', 'Use authorization_code or refresh_token.', 400);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function app(): Application { return Application::getInstance(); }
    private function svc(): McpOAuthService { return $this->app()->make(McpOAuthService::class); }

    private function currentUser(): ?array
    {
        try {
            $session = $this->app()->make(Session::class);
            $uid = $session->get('user_id');
            if (!$uid) return null;
            $u = $this->app()->make(UserRepository::class)->find((int) $uid);
            return ($u && ($u['status'] ?? '') === 'active') ? $u : null;
        } catch (\Throwable) { return null; }
    }

    /** Keep only scopes the user's role actually permits. */
    private function filterByCapability(string $scope, array $user): string
    {
        $map = [
            'posts:read'       => 'edit_posts',
            'posts:write'      => 'edit_posts',
            'taxonomies:read'  => 'edit_posts',
            'taxonomies:write' => 'manage_taxonomies',
            'media:read'       => 'upload_media',
            'media:write'      => 'upload_media',
            'comments:read'    => 'moderate_comments',
            'comments:write'   => 'moderate_comments',
            'users:read'       => 'manage_users',
            'settings:read'    => 'manage_settings',
        ];
        $out = [];
        foreach (array_filter(explode(' ', $scope)) as $s) {
            $cap = $map[$s] ?? null;
            if ($cap === null) { $out[] = $s; continue; }
            try {
                if (\App\Http\Middleware\CheckCapability::userCan($user, $cap)) $out[] = $s;
            } catch (\Throwable) {}
        }
        return implode(' ', $out);
    }

    /** client_id/secret from POST body or HTTP Basic. */
    private function clientCredentials(Request $request): array
    {
        $id = (string) $request->input('client_id', '');
        $secret = (string) $request->input('client_secret', '');
        if ($id === '') {
            $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if ($hdr === '' && function_exists('apache_request_headers')) {
                $h = apache_request_headers();
                $hdr = $h['Authorization'] ?? $h['authorization'] ?? '';
            }
            if (stripos($hdr, 'basic ') === 0) {
                $dec = base64_decode(substr($hdr, 6), true);
                if ($dec !== false && str_contains($dec, ':')) {
                    [$id, $secret] = explode(':', $dec, 2);
                    $id = urldecode($id); $secret = urldecode($secret);
                }
            }
        }
        return [$id, $secret];
    }

    private function relativeSelf(): string
    {
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/oauth/authorize');
        if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
        return $uri === '' ? '/oauth/authorize' : $uri;
    }

    private function redirectError(array $q, string $error, string $desc): Response
    {
        $uri = (string) ($q['redirect_uri'] ?? '');
        if ($uri === '') return $this->errorPage('Authorization error', $desc);
        $sep = str_contains($uri, '?') ? '&' : '?';
        return Response::redirect($uri . $sep . http_build_query(array_filter([
            'error' => $error, 'error_description' => $desc,
            'state' => ($q['state'] ?? '') !== '' ? $q['state'] : null,
        ])));
    }

    private function errorPage(string $title, string $msg): Response
    {
        $t = htmlspecialchars($title); $m = htmlspecialchars($msg);
        return Response::make(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $t . '</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}'
            . '.c{max-width:26rem;padding:2rem;text-align:center}h1{font-size:1.15rem;margin:0 0 .5rem}p{color:#64748b;font-size:.9rem;line-height:1.6}</style>'
            . '</head><body><div class="c"><h1>' . $t . '</h1><p>' . $m . '</p></div></body></html>',
            400, ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function tokenError(string $error, string $desc, int $status): Response
    {
        return $this->json(['error' => $error, 'error_description' => $desc], $status);
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $d = json_decode($raw, true);
        return is_array($d) ? $d : ($_POST ?: []);
    }

    private function json(array $data, int $status = 200): Response
    {
        return Response::make(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES),
            $status,
            [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-store',
                'Pragma'        => 'no-cache',
                // Discovery documents are fetched cross-origin by MCP clients.
                'Access-Control-Allow-Origin' => '*',
            ]
        );
    }
}
