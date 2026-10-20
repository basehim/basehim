<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Application;
use App\Http\Middleware\CheckCapability;

/**
 * What a dashboard widget's data provider, configure handler and actions are
 * given (1.2.68):
 *
 *     'data' => function (WidgetContext $ctx): array {
 *         $limit = (int) $ctx->setting('count', 5);       // already validated
 *         if (!$ctx->can('edit_others_posts')) { … only theirs … }
 *         return ['items' => …];
 *     }
 *
 * The engine has already checked the widget's capability and visibility rule
 * for this user, and validated the settings against the widget's fields.
 * Anything finer — which records this user may see — is the provider's
 * business, using user() and can().
 */
final class WidgetContext
{
    public function __construct(
        private readonly array $widget,
        private readonly array $user,
        private readonly array $settings,
        private readonly string $instanceId,
        private readonly bool $forced = false,
    ) {}

    /** The signed-in user (no password hash, no security meta). */
    public function user(): array
    {
        $u = $this->user;
        unset($u['password_hash'], $u['remember_token'], $u['meta']);
        return $u;
    }

    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function can(string $capability): bool
    {
        return CheckCapability::userCan($this->user, $capability);
    }

    /** All of this instance's settings, validated, with defaults filled in. */
    public function settings(): array
    {
        return $this->settings;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    /** The card's instance id on this user's dashboard. */
    public function instanceId(): string
    {
        return $this->instanceId;
    }

    /** The registered widget key, e.g. 'core.dashboard.recent-posts'. */
    public function widgetKey(): string
    {
        return (string) $this->widget['key'];
    }

    /** True when the user pressed Refresh (the cache was skipped). */
    public function forced(): bool
    {
        return $this->forced;
    }

    /** A core service from the container. */
    public function make(string $abstract): mixed
    {
        return Application::getInstance()->make($abstract);
    }

    /** An admin URL with the install base, e.g. url('/admin/posts'). */
    public function url(string $path): string
    {
        return (defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '') . '/' . ltrim($path, '/');
    }
}
