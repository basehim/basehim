<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HookRegistry
 *
 * Implements actions (event-style, fire and forget) and filters
 * (transform a value through registered callbacks). This is the
 * extensibility seam the spec calls out: apps hook in here
 * instead of touching service internals.
 */
final class HookRegistry
{
    /** @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int}>> */
    private array $actions = [];

    /** @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int}>> */
    private array $filters = [];

    public function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->actions[$tag][] = [
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $acceptedArgs,
        ];
        usort($this->actions[$tag], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function doAction(string $tag, mixed ...$args): void
    {
        foreach ($this->actions[$tag] ?? [] as $hook) {
            $slice = array_slice($args, 0, $hook['accepted_args']);
            try {
                ($hook['callback'])(...$slice);
            } catch (\Throwable $e) {
                $this->logHookFailure('action', $tag, $hook, $e);
                // Actions are fire-and-forget — keep going so one bad
                // listener can't take down the whole request.
            }
        }
    }

    public function addFilter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->filters[$tag][] = [
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $acceptedArgs,
        ];
        usort($this->filters[$tag], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->filters[$tag] ?? [] as $hook) {
            $callArgs = array_merge([$value], $args);
            $callArgs = array_slice($callArgs, 0, $hook['accepted_args']);
            try {
                $value = ($hook['callback'])(...$callArgs);
            } catch (\Throwable $e) {
                $this->logHookFailure('filter', $tag, $hook, $e);
                // On failure, keep the previous value and continue the chain
                // so other apps still get a chance to filter it.
            }
        }
        return $value;
    }

    /**
     * Log a hook callback failure to the application logger if available.
     * Best-effort: never re-throws (we're already in an error path).
     */
    private function logHookFailure(string $kind, string $tag, array $hook, \Throwable $e): void
    {
        try {
            $logger = \App\Core\Application::getInstance()->make(\App\Core\Logger::class);
            $cb = $hook['callback'];
            if (is_array($cb)) {
                $target = is_object($cb[0]) ? $cb[0]::class : (string)$cb[0];
                $name = $target . '::' . (string)$cb[1];
            } elseif ($cb instanceof \Closure) {
                $name = 'Closure';
            } else {
                $name = is_string($cb) ? $cb : 'callable';
            }
            $logger->error("Hook {$kind} '{$tag}' callback threw", [
                'tag'      => $tag,
                'callback' => $name,
                'error'    => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // Swallow; if we can't even log, there's nothing more to do.
        }
    }

    public function removeAction(string $tag, callable $callback): void
    {
        if (!isset($this->actions[$tag])) {
            return;
        }
        $this->actions[$tag] = array_filter(
            $this->actions[$tag],
            fn($h) => $h['callback'] !== $callback
        );
    }

    public function removeFilter(string $tag, callable $callback): void
    {
        if (!isset($this->filters[$tag])) {
            return;
        }
        $this->filters[$tag] = array_filter(
            $this->filters[$tag],
            fn($h) => $h['callback'] !== $callback
        );
    }

    public function hasAction(string $tag): bool
    {
        return !empty($this->actions[$tag]);
    }

    public function hasFilter(string $tag): bool
    {
        return !empty($this->filters[$tag]);
    }
}
