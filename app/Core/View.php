<?php

declare(strict_types=1);

namespace App\Core;

/**
 * View
 *
 * Plain-PHP template renderer. No Blade — we keep dependencies zero.
 * Templates can `$this->extend('layout')` and `$this->section('name')`.
 */
final class View
{
    private array $sections = [];
    private array $sectionStack = [];
    private ?string $layout = null;
    private array $sharedData = [];

    public function __construct(private string $viewPath) {}

    public function share(string $key, mixed $value): void
    {
        $this->sharedData[$key] = $value;
    }

    public function render(string $template, array $data = []): string
    {
        // Reset per-render state but keep shared data
        $this->sections = [];
        $this->sectionStack = [];
        $this->layout = null;

        $data = array_merge($this->sharedData, $data);

        $content = $this->captureTemplate($template, $data);

        // If template called extend(), render the parent layout
        while ($this->layout !== null) {
            $parentTemplate = $this->layout;
            $this->layout = null;
            // Default content section becomes the child output if not explicitly set
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->captureTemplate($parentTemplate, $data);
        }

        return $this->withUnprintedStyles($content);
    }

    private function captureTemplate(string $template, array $data): string
    {
        $file = $this->resolve($template);
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template} (looked in {$file})");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            /** @noinspection PhpIncludeInspection */
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean() ?: '';
    }

    private function resolve(string $template): string
    {
        $relative = str_replace('.', '/', $template) . '.php';
        return rtrim($this->viewPath, '/') . '/' . $relative;
    }

    // Template helper API ---------------------------------------------------

    public function extend(string $layout): void
    {
        $this->layout = $layout;
        // Core's layouts print <head> after the view: stylesheets the view
        // and its partials ask for can wait for it (1.3.0).
        \App\Core\Http\HeadStyles::expectHead();
    }

    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            return;
        }
        $this->sections[$name] = ob_get_clean() ?: '';
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Render a template (typically a layout) with one or more sections
     * pre-populated from strings. The layout can yield them as usual via
     * `$this->yieldSection('name')`. Use this when the content for a
     * section was built elsewhere (e.g. from a app-owned template).
     */
    public function renderWithSections(string $template, array $sections, array $data = []): string
    {
        // Reset per-render state but keep shared data, then inject sections.
        $this->sections = $sections;
        $this->sectionStack = [];
        $this->layout = null;

        $data = array_merge($this->sharedData, $data);
        $content = $this->captureTemplate($template, $data);

        // If the rendered template itself called extend(), keep climbing.
        while ($this->layout !== null) {
            $parentTemplate = $this->layout;
            $this->layout = null;
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->captureTemplate($parentTemplate, $data);
        }

        return $this->withUnprintedStyles($content);
    }

    /**
     * Ask for a stylesheet in the page's <head> (1.3.0); see HeadStyles,
     * which partials call directly (they may be included by an app, where
     * $this is not this class). Prints the link here when no <head> is to come.
     *
     * @param array<string, string|true> $attrs extra attributes (true = bare)
     */
    public function style(string $href, array $attrs = []): void
    {
        echo \App\Core\Http\HeadStyles::add($href, $attrs);
    }

    /** The waiting <link> tags, for a layout's <head>. */
    public function headStyles(): string
    {
        return \App\Core\Http\HeadStyles::printHead();
    }

    /** A page whose layout printed no <head> (an app's own layout) still gets its stylesheets, first. */
    private function withUnprintedStyles(string $content): string
    {
        $links = \App\Core\Http\HeadStyles::unprinted();
        return $links === '' ? $content : $links . $content;
    }

    public function include(string $partial, array $data = []): void
    {
        echo $this->captureTemplate($partial, array_merge($this->sharedData, $data));
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
