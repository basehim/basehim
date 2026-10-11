<?php

declare(strict_types=1);

namespace App\Core\Ajax;

use App\Core\Application;

/**
 * One registered AJAX action: a validated, immutable definition.
 *
 * Built only by AjaxRegistry::register(), which checks every option. Nothing
 * here is ever derived from a request: the browser names an action, and the
 * gateway looks the name up in the registry. It never supplies a class, a
 * method, a file or a callback.
 */
final class AjaxAction
{
    /**
     * @param string[] $methods      'GET' and/or 'POST'
     * @param string[] $capabilities all required; empty only for public actions
     * @param array{max:int,per:int,by:string}|null $rateLimit null = no per-action limit
     */
    public function __construct(
        public readonly string $name,
        public readonly string $owner,
        private readonly mixed $handler,
        public readonly array $methods,
        public readonly string $auth,
        public readonly array $capabilities,
        public readonly ?string $area,
        public readonly bool $csrf,
        public readonly InputSchema $input,
        public readonly ?array $rateLimit,
        public readonly int $maxBody,
        public readonly bool $idempotent,
        public readonly string $description,
    ) {}

    public function isPublic(): bool
    {
        return $this->auth === AjaxRegistry::AUTH_PUBLIC;
    }

    public function isCore(): bool
    {
        return $this->owner === AjaxRegistry::OWNER_CORE;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * The handler as a callable, resolved at dispatch.
     *
     * [ClassName::class, 'method'] pairs are resolved here rather than at
     * registration so registering stays cheap: boot() runs on every request
     * and should not autoload every handler class of every action.
     */
    public function callable(Application $app): callable
    {
        $h = $this->handler;
        if ($h instanceof \Closure) return $h;
        if (is_array($h) && is_object($h[0])) return $h;

        [$class, $method] = $h;
        if (!class_exists($class)) {
            throw new \RuntimeException("AJAX action '{$this->name}': handler class {$class} does not exist.");
        }
        $ref = new \ReflectionMethod($class, $method);
        if (!$ref->isPublic() || $ref->isAbstract()) {
            throw new \RuntimeException("AJAX action '{$this->name}': handler {$class}::{$method} must be public.");
        }
        return $ref->isStatic() ? [$class, $method] : [$app->make($class), $method];
    }
}
