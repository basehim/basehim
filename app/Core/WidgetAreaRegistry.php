<?php

declare(strict_types=1);

namespace App\Core;

/**
 * WidgetAreaRegistry — collects the *widget areas* (a.k.a. sidebars) that a
 * theme or app declares. An area is a named region of the public site into
 * which an admin can drop and order frontend widgets (see WidgetRegistry).
 *
 * This is the counterpart to menu locations: a theme says "I have a place called
 * 'sidebar' where widgets can go", renders it with `widget_area('sidebar')`, and
 * the admin decides *what* goes there.
 *
 * Areas are usually declared declaratively in theme.json:
 *   "widget_areas": {
 *     "sidebar": { "name": "Sidebar", "description": "Main blog sidebar" },
 *     "footer-1": { "name": "Footer Column 1" }
 *   }
 * …or registered from a app's boot():
 *   $app->make(WidgetAreaRegistry::class)->register('shop-sidebar', [...]);
 *
 * Each area may carry wrapper markup so themes control how widgets are boxed.
 */
final class WidgetAreaRegistry
{
    /** @var array<string, array> */
    private array $areas = [];

    /**
     * Register (or replace) a widget area.
     *
     * @param string $key Unique slug, e.g. 'sidebar' or 'footer-1'.
     * @param array  $def {
     *   name:          string  Human label (defaults to a title-cased key),
     *   description:   string,
     *   before_widget: string  markup opened before each widget (default a <div>),
     *   after_widget:  string  markup closed after each widget,
     *   before_title:  string  markup before a widget's title,
     *   after_title:   string  markup after a widget's title,
     *   class:         string  class for the area wrapper element,
     *   source:        string  provenance ('theme:slug' / 'app:slug'), set automatically,
     * }
     */
    public function register(string $key, array $def = []): void
    {
        $key = $this->normaliseKey($key);
        if ($key === '') return;

        $name = trim((string) ($def['name'] ?? ''));
        if ($name === '') {
            $name = ucwords(str_replace(['-', '_', '.'], ' ', $key));
        }

        $this->areas[$key] = [
            'key'           => $key,
            'name'          => $name,
            'description'   => (string) ($def['description'] ?? ''),
            'before_widget' => (string) ($def['before_widget'] ?? '<div class="widget %2$s">'),
            'after_widget'  => (string) ($def['after_widget'] ?? '</div>'),
            'before_title'  => (string) ($def['before_title'] ?? '<h3 class="widget-title">'),
            'after_title'   => (string) ($def['after_title'] ?? '</h3>'),
            'class'         => (string) ($def['class'] ?? ''),
            'source'        => (string) ($def['source'] ?? ''),
        ];
    }

    public function unregister(string $key): void
    {
        unset($this->areas[$this->normaliseKey($key)]);
    }

    public function has(string $key): bool
    {
        return isset($this->areas[$this->normaliseKey($key)]);
    }

    public function get(string $key): ?array
    {
        return $this->areas[$this->normaliseKey($key)] ?? null;
    }

    /** @return array<int, array> all registered areas, ordered by name. */
    public function all(): array
    {
        $out = array_values($this->areas);
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        return (string) preg_replace('/[^a-z0-9._-]/', '', $key);
    }
}
