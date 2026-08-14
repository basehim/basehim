<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private array $items = [];

    public function __construct(private string $path)
    {
        $this->loadAll();
    }

    private function loadAll(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            /** @noinspection PhpIncludeInspection */
            $this->items[$name] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->items;
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }
        return $current;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public function all(): array
    {
        return $this->items;
    }
}
