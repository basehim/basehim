<?php

declare(strict_types=1);

namespace App\Core;

/**
 * WidgetRegistry — a container singleton (like HookRegistry) that collects
 * widget definitions contributed by apps and themes.
 *
 * A widget is a small, self-contained unit of rendered HTML that can appear on
 * up to three surfaces:
 *   - 'editor'    : offered as an insertable block in the post block editor
 *   - 'frontend'  : rendered on the public site (via the `widget` block, or a
 *                   theme calling render() directly)
 *   - 'dashboard' : shown as a card on the admin dashboard
 *
 * Each widget supplies a render callback: fn(array $settings, string $surface): string
 * returning HTML. Registration is cheap and happens during app/theme boot.
 */
final class WidgetRegistry
{
    /** @var array<string, array> */
    private array $widgets = [];

    /**
     * Register (or replace) a widget.
     *
     * @param string   $key      Unique slug, e.g. 'weather.current' or 'my-app.stats'.
     * @param array    $def      {
     *   title:       string   Human label (required),
     *   render:      callable  fn(array $settings, string $surface): string (required),
     *   description: string,
     *   icon:        string    Font Awesome class, e.g. 'fa-cloud',
     *   surfaces:    string[]  subset of ['editor','frontend','dashboard'] (default all three),
     *   fields:      array     optional settings schema for the editor inspector:
     *                          [ ['key'=>..,'label'=>..,'type'=>'text|number|select|checkbox','options'=>[]], ... ],
     *   source:      string    provenance label ('app:slug' / 'theme:slug'), set automatically,
     *   dashboard:   array     optional { width: 'full'|'half'|'third', priority: int },
     * }
     */
    public function register(string $key, array $def): void
    {
        $key = $this->normaliseKey($key);
        if ($key === '' || empty($def['title']) || !isset($def['render']) || !is_callable($def['render'])) {
            return; // silently ignore malformed definitions
        }
        $surfaces = $def['surfaces'] ?? ['editor', 'frontend', 'dashboard'];
        $surfaces = array_values(array_intersect(
            array_map('strval', (array) $surfaces),
            ['editor', 'frontend', 'dashboard']
        ));
        if (!$surfaces) $surfaces = ['editor', 'frontend', 'dashboard'];

        $this->widgets[$key] = [
            'key'         => $key,
            'title'       => (string) $def['title'],
            'description' => (string) ($def['description'] ?? ''),
            'icon'        => (string) ($def['icon'] ?? 'fa-puzzle-piece'),
            'surfaces'    => $surfaces,
            'fields'      => is_array($def['fields'] ?? null) ? array_values($def['fields']) : [],
            'render'      => $def['render'],
            'source'      => (string) ($def['source'] ?? ''),
            'dashboard'   => is_array($def['dashboard'] ?? null) ? $def['dashboard'] : [],
        ];
    }

    /** Remove a widget (e.g. on app deactivation, if desired). */
    public function unregister(string $key): void
    {
        unset($this->widgets[$this->normaliseKey($key)]);
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$this->normaliseKey($key)]);
    }

    /** Full definition for one widget (includes the render callable). */
    public function get(string $key): ?array
    {
        return $this->widgets[$this->normaliseKey($key)] ?? null;
    }

    /**
     * All widgets, optionally filtered to a surface. Render callables are
     * stripped so the result is safe to expose over the API / to templates.
     *
     * @return array<int, array>
     */
    public function all(?string $surface = null): array
    {
        $out = [];
        foreach ($this->widgets as $w) {
            if ($surface !== null && !in_array($surface, $w['surfaces'], true)) continue;
            $public = $w;
            unset($public['render']);
            $out[] = $public;
        }
        // Dashboard ordering respects an optional priority (lower = earlier).
        if ($surface === 'dashboard') {
            usort($out, fn($a, $b) => (($a['dashboard']['priority'] ?? 100) <=> ($b['dashboard']['priority'] ?? 100)));
        }
        return $out;
    }

    /**
     * Render a widget to HTML for a given surface. Never throws — a broken
     * widget returns an inline error comment instead of taking down the page.
     */
    public function render(string $key, array $settings = [], string $surface = 'frontend'): string
    {
        $w = $this->get($key);
        if (!$w) {
            return '<!-- bh-widget: unknown "' . htmlspecialchars($key, ENT_QUOTES) . '" -->';
        }
        if (!in_array($surface, $w['surfaces'], true)) {
            return '<!-- bh-widget: "' . htmlspecialchars($key, ENT_QUOTES) . '" not available on ' . htmlspecialchars($surface, ENT_QUOTES) . ' -->';
        }
        try {
            $html = ($w['render'])($settings, $surface);
            return is_string($html) ? $html : '';
        } catch (\Throwable $e) {
            return '<!-- bh-widget "' . htmlspecialchars($key, ENT_QUOTES) . '" failed: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES) . ' -->';
        }
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        return (string) preg_replace('/[^a-z0-9._-]/', '', $key);
    }
}
