<?php

declare(strict_types=1);

namespace App\Core\Ajax;

use App\Core\Application;
use App\Core\Logger;
use App\Core\Request;
use App\Http\Middleware\CheckCapability;
use App\Services\AppLogger;

/**
 * What a handler is given: the validated input and who is asking.
 *
 *     function (AjaxContext $ctx): array {
 *         $id = $ctx->input('id');            // already checked against the schema
 *         if (!$ctx->can('edit_others_posts')) throw AjaxException::forbidden();
 *         ...
 *         return ['saved' => true];           // becomes "data" in the response
 *     }
 *
 * The gateway has already enforced the action's declared capability, CSRF
 * check, rate limit and schema. What it cannot know is whether this user may
 * touch this particular record; that check (ownership, status, …) is the
 * handler's, using user() and can().
 */
final class AjaxContext
{
    public function __construct(
        private readonly AjaxAction $action,
        private readonly array $input,
        private readonly ?array $user,
        private readonly Request $request,
        private readonly string $requestId,
        private readonly string $clientIp,
        /** @var array<string,UploadedFile> checked files, for actions that declare them (1.2.87) */
        private readonly array $files = [],
    ) {}

    /**
     * A file the action declared, already checked by the gateway (size,
     * extension, content), or null when an optional one was not sent.
     */
    public function file(string $field): ?UploadedFile
    {
        return $this->files[$field] ?? null;
    }

    /** @return array<string,UploadedFile> every file that was sent, by field */
    public function files(): array
    {
        return $this->files;
    }

    /** One validated input field, or $default when it was not sent. */
    public function input(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->input) ? $this->input[$key] : $default;
    }

    /** All validated input: only declared fields, only those that were sent. */
    public function all(): array
    {
        return $this->input;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    /**
     * The signed-in user, or null for a visitor. Always null for a public
     * action with CSRF switched off, which runs without the session.
     * Password hashes are not included.
     */
    public function user(): ?array
    {
        if ($this->user === null) return null;
        $u = $this->user;
        unset($u['password_hash'], $u['remember_token']);
        // users.meta.security holds the session epoch and two-factor state
        // (TwoFactorService); none of it is a handler's business, and a
        // handler that returns the user would otherwise hand it to the browser.
        if (isset($u['meta'])) {
            $meta = is_string($u['meta']) ? json_decode($u['meta'], true) : $u['meta'];
            if (is_array($meta)) {
                unset($meta['security']);
                $u['meta'] = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $u['meta'] = null;
            }
        }
        return $u;
    }

    public function userId(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    /** Whether the current user holds a capability (false for visitors). */
    public function can(string $capability): bool
    {
        return $this->user !== null && CheckCapability::userCan($this->user, $capability);
    }

    /** Throw FORBIDDEN (403) unless the current user holds the capability. */
    public function authorize(string $capability): void
    {
        if (!$this->can($capability)) {
            throw AjaxException::forbidden();
        }
    }

    public function action(): AjaxAction
    {
        return $this->action;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** The client address, honouring TRUSTED_PROXIES only. */
    public function ip(): string
    {
        return $this->clientIp;
    }

    /**
     * Write to the log of whoever owns the action — the app's own log
     * (Admin → Apps → Logs) or the core log — tagged with the request ID.
     * Do not log input wholesale: it may hold personal data.
     */
    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        $context['request_id'] = $this->requestId;
        $context['action'] = $this->action->name;
        $app = Application::getInstance();
        try {
            if ($this->action->isCore()) {
                $app->make(Logger::class)->log($level, $message, $context);
            } else {
                $app->make(AppLogger::class)->log($this->action->owner, $level, $message, $context);
            }
        } catch (\Throwable) {}
    }
}
