<?php

declare(strict_types=1);

namespace App\Core\Ajax;

use App\Core\Application;
use App\Core\Config;
use App\Core\Logger;
use App\Core\OriginPolicy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Middleware\CheckCapability;
use App\Services\AppLogger;
use App\Services\AppService;
use App\Services\AuthService;
use App\Services\RateLimiter;

/**
 * POST /ajax — the one entry point for AJAX actions. See docs/APP-AJAX.md.
 *
 *     POST /ajax
 *     Content-Type: application/json
 *     X-CSRF-Token: …
 *
 *     {"action": "media.update", "data": {"id": 42, "alt_text": "A heron"}}
 *
 * Every request goes through the same checks, in this order, and the first
 * one that fails answers:
 *
 *   1. method is GET or POST                                       405
 *   2. no bearer token (this endpoint is for browser sessions)     401
 *   3. global rate limit for the client address                    429
 *   4. not cross-site (Sec-Fetch-Site, Origin)                     403
 *   5. POST: application/json, size within limit, valid JSON       415 413 400
 *      (or multipart/form-data with "action" and "data" fields, for
 *      actions that declare `files` — 1.2.87)
 *   6. the action is registered, its app is active                 404
 *   7. the action accepts this method, and this body size          405 413
 *   8. signed in, when the action requires it                      401
 *   9. CSRF token, for POST unless the action is public and opted out   403
 *  10. the action's capabilities                                   403
 *  11. the action's rate limit                                     429
 *  12. input matches the action's schema                           422
 *      and every file matches its declared rule (1.2.87)           422
 *  13. idempotency key, when the action asks for one               400 409
 *  14. the handler runs; its AjaxException becomes the answer, anything
 *      else it throws is logged and answered with a generic        500
 *
 * Route middleware cannot do this job: which checks apply depends on the
 * action, and the action is named inside the body. The checks reuse core's
 * own services — AuthService::sessionUser() for the session,
 * Session::verifyCsrf() for the token, CheckCapability::userCan() for roles,
 * OriginPolicy for origins — rather than a second copy of any of them.
 *
 * What this does NOT do is decide whether a user may touch a particular
 * record. A handler that takes an ID must check ownership itself; the
 * gateway only knows the action's general capability.
 */
final class AjaxGateway
{
    private const MESSAGES = [
        'METHOD_NOT_ALLOWED'     => 'This action does not accept that request method.',
        'UNSUPPORTED_AUTH'       => 'This endpoint uses the browser session. API clients should use /api/v1 with a token.',
        'ORIGIN_REJECTED'        => 'Requests from other sites are not accepted.',
        'UNSUPPORTED_MEDIA_TYPE' => 'Send the request as application/json.',
        'UPLOAD_TOO_LARGE'       => 'The upload is larger than this server accepts.',
        'PAYLOAD_TOO_LARGE'      => 'The request is too large.',
        'MALFORMED_JSON'         => 'The request body is not valid JSON.',
        'BAD_REQUEST'            => 'The request is not in the expected format.',
        'ACTION_NOT_FOUND'       => 'Unknown action.',
        'AUTH_REQUIRED'          => 'You are not signed in, or your session has expired. Sign in and try again.',
        'CSRF_INVALID'           => 'Your security token is missing or out of date. Reload the page and try again.',
        'FORBIDDEN'              => 'You do not have permission to perform this action.',
        'RATE_LIMITED'           => 'Too many requests. Wait a moment and try again.',
        'IDEMPOTENCY_KEY_REQUIRED' => 'This action needs an Idempotency-Key header.',
        'DUPLICATE_REQUEST'      => 'This request is already being processed, or its key was used for different data.',
        'SERVICE_UNAVAILABLE'    => 'The service is temporarily unavailable. Try again shortly.',
        'INTERNAL_ERROR'         => 'Something went wrong. If it keeps happening, give the request ID to the site administrator.',
    ];

    private Application $app;
    private Config $config;
    private string $requestId = '';
    private string $clientIp = '0.0.0.0';

    /** @var \Closure(int):?string reads at most N+1 bytes of the body */
    private \Closure $bodyReader;

    /**
     * @param (callable(int):?string)|null $bodyReader for tests; defaults to php://input
     * @param bool $acceptTestFiles for tests only: accept files that are not
     *        PHP uploads of this request (is_uploaded_file() is false for them)
     */
    public function __construct(?callable $bodyReader = null, private bool $acceptTestFiles = false)
    {
        $this->app = Application::getInstance();
        $this->config = $this->app->make(Config::class);
        $this->bodyReader = $bodyReader !== null ? \Closure::fromCallable($bodyReader) : self::readInput(...);
    }

    public function handle(Request $request): Response
    {
        $this->requestId = bin2hex(random_bytes(8));
        $this->clientIp = $request->clientIp((string) $this->config->get('ajax.trusted_proxies', ''));

        try {
            try {
                return $this->run($request);
            } catch (AjaxException $e) {
                return $this->fail($e->status, $e->errorCode, [], $e->fields, $e->getMessage());
            } catch (\Throwable $e) {
                $this->logThrowable($e, null);
                return $this->fail(500, 'INTERNAL_ERROR');
            }
        } catch (\Throwable $e) {
            // Last resort: nothing may escape to the global error page, which
            // would show the exception's message (and its file path) to the client.
            try { error_log('AJAX gateway failure [' . $this->requestId . ']: ' . $e->getMessage()); } catch (\Throwable) {}
            return new Response(
                '{"success":false,"error":{"code":"INTERNAL_ERROR","message":"' . self::MESSAGES['INTERNAL_ERROR'] . '"},"request_id":"' . $this->requestId . '"}',
                500,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']
            );
        }
    }

    private function run(Request $request): Response
    {
        $method = $request->method;

        // 1–2 ------------------------------------------------------------
        if ($method !== 'GET' && $method !== 'POST') {
            return $this->fail(405, 'METHOD_NOT_ALLOWED', ['Allow' => 'GET, POST']);
        }
        if (self::isTokenAuth((string) $request->header('authorization', ''))) {
            return $this->fail(401, 'UNSUPPORTED_AUTH');
        }

        /** @var RateLimiter $limiter */
        $limiter = $this->app->make(RateLimiter::class);

        // 3. Global limit, per client address, before anything is read. The
        // address is the only key a client cannot mint more of for free.
        $globalMax = max(1, (int) $this->config->get('ajax.global_limit', 300));
        $g = $limiter->hit('ajax:*', 'ip:' . $this->ipKey(), $globalMax, 60);
        if ($g['error'] && $this->failMode(null) === 'closed') {
            return $this->fail(503, 'SERVICE_UNAVAILABLE');
        }
        if (!$g['allowed']) {
            if ($g['count'] === $globalMax + 1) {   // once per window, not per rejected request
                $this->log('warning', 'AJAX global rate limit reached', ['limit' => $globalMax]);
            }
            return $this->fail(429, 'RATE_LIMITED', ['Retry-After' => (string) $g['retry_after']]);
        }

        // 4. Defence in depth, ahead of the CSRF token: browsers say where a
        // request came from, and nothing on another site has a reason to call
        // this endpoint.
        if ($this->isCrossSite($request)) {
            return $this->fail(403, 'ORIGIN_REJECTED');
        }

        // 5. Read and parse ----------------------------------------------
        $bodyLength = 0;
        $multipart = false;
        if ($method === 'POST' && $this->isMultipart($request)) {
            // Uploads (1.2.87). PHP has already parsed the body into $_POST
            // and $_FILES; the action and its data are two ordinary fields,
            // "action" and "data" (the same JSON object a JSON request sends).
            $multipart = true;
            $post = $request->body;
            if ($post === [] && $request->files === []) {
                // A body over post_max_size is dropped whole by PHP: say so,
                // rather than "unknown action".
                $declared = $request->server['CONTENT_LENGTH'] ?? '';
                if (ctype_digit((string) $declared) && (int) $declared > 0) {
                    return $this->fail(413, 'UPLOAD_TOO_LARGE', [], [], 'The upload is larger than this server accepts (PHP post_max_size is ' . (string) ini_get('post_max_size') . ').');
                }
                return $this->fail(400, 'BAD_REQUEST', [], [], 'The request body is empty.');
            }
            foreach (array_keys($post) as $key) {
                if ($key !== 'action' && $key !== 'data') {
                    return $this->fail(400, 'BAD_REQUEST', [], [], 'Only "action" and "data" fields, and files, are accepted.');
                }
            }
            $name = $post['action'] ?? null;
            $raw = $post['data'] ?? '{}';
            if (!is_string($raw)) {
                return $this->fail(400, 'BAD_REQUEST', [], [], 'The "data" field must hold a JSON object.');
            }
            $readLimit = $this->readLimit();
            if (strlen($raw) > $readLimit) {
                return $this->fail(413, 'PAYLOAD_TOO_LARGE');
            }
            $bodyLength = strlen($raw);
            $data = $this->decodeObject($raw);
            if ($data instanceof Response) return $data;
        } elseif ($method === 'POST') {
            if (!$this->isJson($request)) {
                return $this->fail(415, 'UNSUPPORTED_MEDIA_TYPE');
            }
            $readLimit = $this->readLimit();
            $declared = $request->server['CONTENT_LENGTH'] ?? null;
            if ($declared !== null && $declared !== '' && (!ctype_digit((string) $declared) || (int) $declared > $readLimit)) {
                return $this->fail(413, 'PAYLOAD_TOO_LARGE');
            }
            $raw = ($this->bodyReader)($readLimit);
            if ($raw === null || strlen($raw) > $readLimit) {
                return $this->fail(413, 'PAYLOAD_TOO_LARGE');
            }
            $bodyLength = strlen($raw);
            if (trim($raw) === '') {
                return $this->fail(400, 'BAD_REQUEST', [], [], 'The request body is empty.');
            }
            $payload = $this->decodeObject($raw);
            if ($payload instanceof Response) return $payload;
            foreach (array_keys($payload) as $key) {
                if ($key !== 'action' && $key !== 'data') {
                    return $this->fail(400, 'BAD_REQUEST', [], [], 'Only "action" and "data" are accepted at the top level.');
                }
            }
            $name = $payload['action'] ?? null;
            $data = $payload['data'] ?? [];
        } else {
            $query = $request->query;
            $name = $query['action'] ?? null;
            unset($query['action']);
            $data = $query;
        }

        if (!is_string($name) || $name === '') {
            return $this->fail(400, 'BAD_REQUEST', [], [], 'Name an action.');
        }

        // 6. Look the name up. It is only ever a key into the registry, never
        // a class, method or path, so a crafted name can at worst miss.
        /** @var AjaxRegistry $registry */
        $registry = $this->app->make(AjaxRegistry::class);
        $action = strlen($name) <= 200 ? $registry->get($name) : null;
        if ($action === null) {
            return $this->fail(404, 'ACTION_NOT_FOUND');
        }
        if (!$action->isCore() && !$this->appIsActive($action->owner)) {
            $this->log('warning', 'AJAX action belongs to an app that is not active; refused', [], $action);
            return $this->fail(404, 'ACTION_NOT_FOUND');
        }

        // 7 ----------------------------------------------------------------
        if (!$action->allowsMethod($method)) {
            return $this->fail(405, 'METHOD_NOT_ALLOWED', ['Allow' => implode(', ', $action->methods)]);
        }
        if ($bodyLength > $action->maxBody) {
            return $this->fail(413, 'PAYLOAD_TOO_LARGE');
        }
        if ($multipart) {
            if (!$action->acceptsFiles()) {
                return $this->fail(415, 'UNSUPPORTED_MEDIA_TYPE');
            }
            // The whole request may not be larger than the files it may carry.
            $declared = $request->server['CONTENT_LENGTH'] ?? '';
            if (ctype_digit((string) $declared) && (int) $declared > $this->uploadCeiling($action)) {
                return $this->fail(413, 'UPLOAD_TOO_LARGE');
            }
        }

        // 8. Who is asking. A public action that opted out of CSRF runs as a
        // visitor: the session is not even opened, so a request forged from
        // another site cannot borrow anyone's sign-in through it.
        $user = null;
        $session = null;
        if (!($action->isPublic() && !$action->csrf)) {
            $session = $this->app->make(Session::class);
            $user = $this->app->make(AuthService::class)->sessionUser();
            if (!$action->isPublic() && $user === null) {
                return $this->fail(401, 'AUTH_REQUIRED');
            }
            // The signed-in user, where core's services look for it, as the
            // admin routes' Authenticate middleware does (1.2.87). Without
            // it, code a handler calls (MediaApi::fromUpload(), say) could not
            // tell who is uploading.
            if ($user !== null) $this->app->instance('auth.user', $user);
        }

        // 9. CSRF on every state-changing request. GET actions are reads and
        // must stay so; the registry documents it, and the token never goes
        // in a URL.
        if ($action->csrf && $method === 'POST') {
            $token = (string) $request->header('x-csrf-token', '');
            if ($session === null || !$session->verifyCsrf($token)) {
                if ($user !== null) {
                    // Logged only for signed-in users: anonymous failures are
                    // free to send and would let anyone fill the disk.
                    $this->log('notice', 'AJAX CSRF check failed', ['user_id' => (int) $user['id']], $action);
                }
                return $this->fail(403, 'CSRF_INVALID');
            }
        }

        // 10. Role capabilities, the same check as the admin area.
        foreach ($action->capabilities as $cap) {
            if (!CheckCapability::userCan($user, $cap)) {
                $this->log('notice', 'AJAX action denied: missing capability', [
                    'user_id' => (int) ($user['id'] ?? 0), 'capability' => $cap,
                ], $action);
                return $this->fail(403, 'FORBIDDEN');
            }
        }

        // 10b. The admin area the action stands in for, with the site's own
        // admin.area_policy rules: never more open than the screen itself.
        if ($action->area !== null) {
            $areaCap = \App\Http\Middleware\AdminAreaPolicy::requiredFor($action->area);
            if ($areaCap !== null && !CheckCapability::userCan($user, $areaCap)) {
                $this->log('notice', 'AJAX action denied: missing area capability', [
                    'user_id' => (int) ($user['id'] ?? 0), 'capability' => $areaCap, 'area' => $action->area,
                ], $action);
                return $this->fail(403, 'FORBIDDEN');
            }
        }

        // 11 ---------------------------------------------------------------
        if ($action->rateLimit !== null) {
            $rl = $action->rateLimit;
            $who = match (true) {
                $rl['by'] === 'user' && $user !== null           => 'user:' . (int) $user['id'],
                $rl['by'] === 'session' && session_id() !== ''   => 'session:' . hash('sha256', session_id()),
                default                                          => 'ip:' . $this->ipKey(),
            };
            $r = $limiter->hit('ajax:' . $action->name, $who, $rl['max'], $rl['per']);
            if ($r['error'] && $this->failMode($rl['fail']) === 'closed') {
                return $this->fail(503, 'SERVICE_UNAVAILABLE');
            }
            if (!$r['allowed']) {
                if ($r['count'] === $rl['max'] + 1) {
                    $this->log('warning', 'AJAX action rate limit reached', ['client' => $rl['by'], 'limit' => $rl['max'] . '/' . $rl['per'] . 's'], $action);
                }
                return $this->fail(429, 'RATE_LIMITED', ['Retry-After' => (string) $r['retry_after']]);
            }
        }

        // 12. Only declared fields reach the handler. Throws 422 itself.
        if (!is_array($data)) {
            throw AjaxException::invalid('The request data must be an object.', ['data' => ['Must be an object.']]);
        }
        $input = $action->input->validate($data, $method === 'GET');
        $files = $action->acceptsFiles() ? $this->checkFiles($action, $multipart ? $request->files : [], $input) : [];

        // 13 ---------------------------------------------------------------
        $claim = null;
        if ($action->idempotent && $method === 'POST') {
            $key = (string) $request->header('idempotency-key', '');
            if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) {
                return $this->fail(400, 'IDEMPOTENCY_KEY_REQUIRED');
            }
            $owner = match (true) {
                $user !== null       => 'user:' . (int) $user['id'],
                session_id() !== ''  => 'session:' . hash('sha256', session_id()),
                default              => 'ip:' . $this->ipKey(),
            };
            $scope = 'ajax:' . $action->name . ':' . $owner;
            // Since 1.2.75 (CR-27) a repeat of a request that completed gets
            // its first answer again, so a client that lost the response (a
            // timeout after the save) learns what was created instead of a
            // bare "duplicate". A repeat while the first is still running, or
            // the same key with different data, is 409 DUPLICATE_REQUEST.
            try {
                $store = $this->app->make(\App\Services\IdempotencyStore::class);
                // Files count as part of the request: the same key with a
                // different file is a different request.
                $body = (json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '')
                    . implode('|', array_map(static fn(UploadedFile $f) => $f->fingerprint(), $files));
                $begin = $store->begin($scope, $key, \App\Services\IdempotencyStore::requestHash('POST', $action->name, $body));
            } catch (\Throwable $e) {
                // Without the store there is no way to promise "once".
                $this->logThrowable($e, $action);
                return $this->fail(503, 'SERVICE_UNAVAILABLE');
            }
            if ($begin['state'] === 'replay') {
                return $begin['response'];
            }
            if ($begin['state'] !== 'new') {
                return $this->fail(409, 'DUPLICATE_REQUEST');
            }
            $claim = $begin['k'];
        }

        // 14 ---------------------------------------------------------------
        $ctx = new AjaxContext($action, $input, $user, $request, $this->requestId, $this->clientIp, $files);
        $level = ob_get_level();
        ob_start();
        try {
            $result = ($action->callable($this->app))($ctx);
        } catch (\Throwable $e) {
            // Nothing happened (or not completely): let the client retry.
            if ($claim !== null) $store->releaseHash($claim);
            if ($e instanceof AjaxException) throw $e;
            // An app that hasn't been allowed what this needs (1.2.85): say
            // so, instead of a bare server error, so the page can ask for it.
            if (($pm = \App\Core\AppPermissionMissing::in($e)) !== null) {
                $this->logThrowable($e, $action);
                throw new AjaxException('APP_PERMISSION_MISSING', \App\Http\Controllers\Admin\AppPermissionPage::sentence($pm), 403);
            }
            $this->logThrowable($e, $action);
            return $this->fail(500, 'INTERNAL_ERROR');
        } finally {
            $stray = '';
            while (ob_get_level() > $level) {
                $stray .= (string) ob_get_clean();
            }
            if ($stray !== '') {
                // An echo, a var_dump or a PHP notice would corrupt the JSON
                // (and could leak a path), so it is dropped and reported.
                $this->log('warning', 'AJAX handler printed output; it was discarded', ['bytes' => strlen($stray)], $action);
            }
        }

        if ($result instanceof Response) {
            $this->log('error', 'AJAX handler returned a Response; handlers return data or throw AjaxException', [], $action);
            if ($claim !== null) $store->releaseHash($claim);
            return $this->fail(500, 'INTERNAL_ERROR');
        }
        $response = $this->respond(200, ['success' => true, 'data' => $result]);
        if ($claim !== null) $store->complete($claim, $response);
        return $response;
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function isCrossSite(Request $request): bool
    {
        if (strtolower((string) $request->header('sec-fetch-site', '')) === 'cross-site') {
            return true;
        }
        $origin = (string) $request->header('origin', '');
        return $origin !== '' && !OriginPolicy::isTrusted($origin);
    }

    /**
     * True for an Authorization header that carries an API credential
     * (Bearer, a key, anything but Basic).
     *
     * Basic is the one scheme a browser adds by itself: on a site behind an
     * HTTP password (a staging copy protected in .htaccess or the hosting
     * panel) it is sent with every request, fetch() included. It identifies
     * the visitor to the web server, never to Basehim, which ignores it; the
     * session and the CSRF token still decide everything here. Refusing it
     * made every AJAX call fail on such a site (1.2.46).
     */
    private static function isTokenAuth(string $header): bool
    {
        $header = trim($header);
        if ($header === '') return false;
        return strtolower(strtok($header, " \t") ?: '') !== 'basic';
    }

    private function isJson(Request $request): bool
    {
        $ct = strtolower(trim((string) ($request->server['CONTENT_TYPE'] ?? $request->header('content-type', ''))));
        $parts = array_map('trim', explode(';', $ct));
        if (($parts[0] ?? '') !== 'application/json') return false;
        foreach (array_slice($parts, 1) as $param) {
            if (str_starts_with($param, 'charset=') && !in_array(trim(substr($param, 8), '"'), ['utf-8', 'utf8'], true)) {
                return false;
            }
        }
        return true;
    }

    private function isMultipart(Request $request): bool
    {
        $ct = strtolower(trim((string) ($request->server['CONTENT_TYPE'] ?? $request->header('content-type', ''))));
        return str_starts_with($ct, 'multipart/form-data');
    }

    /** A JSON object, or the error response for anything else. */
    private function decodeObject(string $raw): array|Response
    {
        try {
            $v = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $this->fail(400, 'MALFORMED_JSON');
        }
        // "{}" and "[]" both decode to an empty PHP array; only the first
        // is an object.
        if (!is_array($v) || ($v !== [] && array_is_list($v)) || ($v === [] && (ltrim($raw)[0] ?? '') !== '{')) {
            return $this->fail(400, 'BAD_REQUEST', [], [], 'The request body must be a JSON object.');
        }
        return $v;
    }

    /** Largest body an upload action can need: its files, its data and form overhead. */
    private function uploadCeiling(AjaxAction $action): int
    {
        $total = $action->maxBody + 65536;
        foreach ($action->files as $rule) {
            $total += $this->fileRules($rule, $action)['max'] + 16384;
        }
        return $total;
    }

    /**
     * A file rule with 'media' resolved to the site's own lists (Settings →
     * Media, and the media.upload.* filters apps may add).
     *
     * @return array{types:string[],max:int}
     */
    private function fileRules(array $rule, AjaxAction $action, ?string $context = null): array
    {
        $types = $rule['types'];
        $max = $rule['max_size'];
        if ($types === 'media' || $max === 'media') {
            $m = $this->app->make(\App\Services\MediaService::class)->uploadRules($context ?? ('ajax:' . $action->name));
            if ($types === 'media') $types = $m['types'];
            if ($max === 'media') $max = $m['max_bytes'];
        }
        return ['types' => array_values(array_diff((array) $types, \App\Services\MediaService::NEVER_ALLOWED)), 'max' => (int) $max];
    }

    /**
     * Check every file against the action's rules. Returns the files by
     * field; throws 422 with a message per field for anything wrong.
     *
     * @param array<string,mixed> $sent $_FILES
     * @return array<string,UploadedFile>
     */
    private function checkFiles(AjaxAction $action, array $sent, array $input = []): array
    {
        // An action whose input has a "context" field (media.upload's
        // library, editor, …) has its 'media' rules resolved for that
        // context, exactly as MediaService will when it stores the file.
        $context = is_string($input['context'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $input['context'])
            ? $input['context'] : null;
        $errors = [];
        foreach (array_keys($sent) as $field) {
            if (!isset($action->files[$field])) $errors[(string) $field] = ['This action does not accept a file here.'];
        }
        $out = [];
        foreach ($action->files as $field => $rule) {
            $entry = $sent[$field] ?? null;
            if (!is_array($entry) || (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                if ($rule['required']) $errors[$field] = ['No file was selected.'];
                continue;
            }
            [$file, $problem] = UploadedFile::fromEntry($field, $entry, !$this->acceptTestFiles);
            if ($file === null) { $errors[$field] = [(string) $problem]; continue; }

            $r = $this->fileRules($rule, $action, $context);
            $ext = $file->extension();
            if ($ext === '' || !in_array($ext, $r['types'], true)) {
                $errors[$field] = [$ext === '' ? 'The file has no extension.'
                    : 'Files of type ".' . $ext . '" are not accepted here (accepted: ' . implode(', ', $r['types']) . ').'];
                continue;
            }
            if ($file->size() > $r['max']) {
                $errors[$field] = ['The file is larger than ' . \App\Core\Helpers::bytesFormat($r['max']) . '.'];
                continue;
            }
            // The content must be what the name says: no script or page under
            // any name, a real image under an image name (MediaService's rule).
            $problem = \App\Services\MediaService::contentProblem($ext, $file->mime());
            if ($problem === null && ($ext === 'svg' || $file->mime() === 'image/svg+xml')) {
                $why = \App\Services\SvgGuard::problem((string) @file_get_contents($file->path(), false, null, 0, 8 * 1048576));
                if ($why !== null) $problem = 'This SVG was refused because ' . $why . '.';
            }
            if ($problem === null && $rule['mimes'] !== [] && !self::mimeMatches($file->mime(), $rule['mimes'])) {
                $problem = 'The file\'s content (' . $file->mime() . ') is not what this field accepts.';
            }
            if ($problem !== null) { $errors[$field] = [$problem]; continue; }
            $out[$field] = $file;
        }
        if ($errors !== []) {
            $first = reset($errors)[0];
            throw AjaxException::invalid(count($errors) === 1 ? $first : 'Some files were not accepted.', $errors);
        }
        return $out;
    }

    /** @param string[] $allowed 'application/zip' or 'image/*' */
    private static function mimeMatches(string $mime, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if ($a === $mime) return true;
            if (str_ends_with($a, '/*') && str_starts_with($mime, substr($a, 0, -1))) return true;
        }
        return false;
    }

    /** The most any registered action accepts, so the body is read once. */
    private function readLimit(): int
    {
        $limit = (int) $this->config->get('ajax.max_body', 65536);
        foreach ($this->app->make(AjaxRegistry::class)->all() as $a) {
            $limit = max($limit, $a->maxBody);
        }
        return min($limit, AjaxRegistry::MAX_BODY_LIMIT);
    }

    private static function readInput(int $limit): ?string
    {
        $h = @fopen('php://input', 'rb');
        if ($h === false) return '';
        $raw = stream_get_contents($h, $limit + 1);
        fclose($h);
        if ($raw === false) return '';
        return strlen($raw) > $limit ? null : $raw;
    }

    private function appIsActive(string $slug): bool
    {
        try {
            return $this->app->make(AppService::class)->isBooted($slug);
        } catch (\Throwable) {
            return false;
        }
    }

    private function failMode(?string $actionMode): string
    {
        $mode = $actionMode ?? (string) $this->config->get('ajax.rate_limit_fail', 'open');
        return $mode === 'closed' ? 'closed' : 'open';
    }

    /**
     * The rate-limit key for the client's address. IPv6 is counted per /64,
     * because a single subscriber usually holds a whole /64 and could
     * otherwise rotate through addresses to reset its limit.
     */
    private function ipKey(): string
    {
        if (str_contains($this->clientIp, ':')) {
            $bin = @inet_pton($this->clientIp);
            if ($bin !== false && strlen($bin) === 16) {
                return bin2hex(substr($bin, 0, 8)) . '::/64';
            }
        }
        return $this->clientIp;
    }

    // ------------------------------------------------------------------
    // Responses and logging
    // ------------------------------------------------------------------

    /** @param array<string,string[]> $fields */
    private function fail(int $status, string $code, array $headers = [], array $fields = [], ?string $message = null): Response
    {
        $error = ['code' => $code, 'message' => $message ?? (self::MESSAGES[$code] ?? 'The request could not be completed.')];
        if ($fields !== []) $error['fields'] = $fields;
        return $this->respond($status, ['success' => false, 'error' => $error], $headers);
    }

    private function respond(int $status, array $body, array $headers = []): Response
    {
        $body['request_id'] = $this->requestId;
        // INVALID_UTF8_SUBSTITUTE: text read from files or old rows (a log line,
        // a title saved by a broken import) must not turn a good answer into a 500.
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        try {
            $json = json_encode($body, $flags);
        } catch (\JsonException $e) {
            // A handler returned something JSON cannot carry (invalid UTF-8,
            // a resource, a recursive structure).
            $this->log('error', 'AJAX response could not be encoded: ' . $e->getMessage());
            $status = 500;
            $json = json_encode([
                'success' => false,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => self::MESSAGES['INTERNAL_ERROR']],
                'request_id' => $this->requestId,
            ], JSON_UNESCAPED_SLASHES);
        }
        return new Response((string) $json, $status, $headers + [
            'Content-Type'           => 'application/json; charset=utf-8',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-Id'           => $this->requestId,
        ]);
    }

    private function logThrowable(\Throwable $e, ?AjaxAction $action): void
    {
        $this->log('error', 'AJAX handler failed: ' . $e::class . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ], $action);
    }

    /**
     * Core log always; the owning app's log too, so an app developer sees
     * their action's failures under Admin → Apps → Logs. Never the request
     * body, the CSRF token, cookies or the session ID.
     */
    private function log(string $level, string $message, array $context = [], ?AjaxAction $action = null): void
    {
        $context = ['request_id' => $this->requestId, 'action' => $action?->name, 'ip' => $this->clientIp] + $context;
        try {
            $this->app->make(Logger::class)->log($level, $message, $context);
        } catch (\Throwable) {}
        if ($action !== null && !$action->isCore()) {
            try {
                unset($context['trace']);
                $this->app->make(AppLogger::class)->log($action->owner, $level, $message, $context);
            } catch (\Throwable) {}
        }
    }
}
