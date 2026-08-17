<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The Customizer's option model.
 *
 * ── Where options come from ────────────────────────────────────────────────
 *
 * Two sources, merged into one list of sections:
 *
 *   Core     identity and branding — site name, tagline, logo, favicon,
 *            footer text, custom CSS. The same on every theme.
 *   Theme    whatever the active theme declares in its theme.json under
 *            "customizer". Different per theme, and stored per theme.
 *
 * A theme declares data, not behaviour:
 *
 *     "customizer": {
 *       "colors": {
 *         "label": "Colours",
 *         "options": {
 *           "accent": { "type": "color", "label": "Accent", "default": "#e11d48" }
 *         }
 *       }
 *     }
 *
 * Core renders the field, validates by declared type, and stores the value.
 * The theme never writes admin code, and a malformed declaration is dropped
 * with a note in the log rather than breaking the screen.
 *
 * ── Where values are stored ────────────────────────────────────────────────
 *
 * Core values live in the `appearance` group, exactly where they always have,
 * so an existing site keeps its logo and footer text without a migration.
 * Theme values live in `theme:{slug}`, so switching themes and switching back
 * does not lose the work — which is the single most annoying thing about
 * customisers that store everything in one bucket.
 */
final class CustomizerService
{
    /** Field types the renderer and validator both understand. */
    public const TYPES = [
        'text', 'textarea', 'color', 'select', 'toggle',
        'number', 'range', 'image', 'url', 'font',
    ];

    public function __construct(
        private SettingService $settings,
        private ThemeService $themes,
        private ?\App\Core\Logger $logger = null
    ) {}

    // ==================================================================
    // Schema
    // ==================================================================

    /**
     * Every section shown in the Customizer, core first then the theme's.
     *
     * @return array<string, array{label:string, description?:string, options:array}>
     */
    public function sections(): array
    {
        $sections = $this->coreSections();

        foreach ($this->themeSections() as $key => $section) {
            // A theme cannot overwrite a core section — it would be able to
            // hide the logo field and leave the site owner stuck.
            $key = isset($sections[$key]) ? 'theme_' . $key : $key;
            $sections[$key] = $section;
        }

        return $sections;
    }

    /** The sections core always provides. */
    private function coreSections(): array
    {
        return [
            'identity' => [
                'label' => 'Site identity',
                'description' => 'The name, description and marks that identify this site.',
                'options' => [
                    'site_title' => [
                        'type' => 'text', 'label' => 'Site name',
                        'group' => 'general', 'default' => 'Basehim',
                        'preview' => 'reload',
                    ],
                    'tagline' => [
                        'type' => 'text', 'label' => 'Site description',
                        'group' => 'general', 'default' => '',
                        'help' => 'A short line describing the site. Themes may show it beside the name.',
                        'preview' => 'reload',
                    ],
                    'logo_url' => [
                        'type' => 'image', 'label' => 'Logo',
                        'group' => 'appearance', 'default' => '',
                        'preview' => 'reload',
                    ],
                    'favicon_url' => [
                        'type' => 'image', 'label' => 'Site icon',
                        'group' => 'appearance', 'default' => '',
                        'help' => 'Shown in browser tabs and bookmarks. A square PNG of 512×512 works best.',
                        'preview' => 'reload',
                    ],
                ],
            ],
            'footer' => [
                'label' => 'Footer',
                'options' => [
                    'footer_text' => [
                        'type' => 'text', 'label' => 'Footer text',
                        'group' => 'appearance', 'default' => '',
                        'help' => 'Usually a copyright line.',
                        'preview' => 'reload',
                    ],
                ],
            ],
            'advanced' => [
                'label' => 'Custom CSS',
                'description' => 'Applied to the front end, after the theme\'s own styles.',
                'options' => [
                    'custom_css' => [
                        'type' => 'textarea', 'label' => 'Custom CSS',
                        'group' => 'appearance', 'default' => '',
                        'rows' => 12, 'mono' => true,
                        'preview' => 'css',
                    ],
                ],
            ],
        ];
    }

    /**
     * Sections the active theme declares.
     *
     * Every declaration is checked. A theme that ships a broken customizer
     * block loses that block, not the screen — the site owner can still reach
     * their logo and their CSS.
     */
    public function themeSections(): array
    {
        $manifest = $this->themes->activeManifest();
        $raw = $manifest['customizer'] ?? null;
        if (!is_array($raw)) return [];

        $slug = $this->themes->activeSlug();
        $out = [];

        foreach ($raw as $key => $section) {
            if (!is_string($key) || !is_array($section)) {
                $this->warn("theme {$slug}: customizer section \"{$key}\" is not an object; skipped");
                continue;
            }
            $options = [];
            foreach (($section['options'] ?? []) as $optKey => $opt) {
                $clean = $this->sanitiseDeclaration((string) $optKey, $opt, $slug);
                if ($clean !== null) $options[$optKey] = $clean;
            }
            if (!$options) continue;

            $out[$this->slugKey($key)] = [
                'label'       => (string) ($section['label'] ?? ucfirst((string) $key)),
                'description' => isset($section['description']) ? (string) $section['description'] : null,
                'options'     => $options,
                'theme'       => $slug,
            ];
        }

        return $out;
    }

    /** Check one option declaration, or reject it with a reason. */
    private function sanitiseDeclaration(string $key, mixed $opt, string $slug): ?array
    {
        if (!is_array($opt)) {
            $this->warn("theme {$slug}: option \"{$key}\" is not an object; skipped");
            return null;
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
            // The key becomes a CSS custom property and a settings key, so it
            // has to be predictable.
            $this->warn("theme {$slug}: option key \"{$key}\" must be lowercase letters, digits and underscores");
            return null;
        }

        $type = (string) ($opt['type'] ?? 'text');
        if (!in_array($type, self::TYPES, true)) {
            $this->warn("theme {$slug}: option \"{$key}\" has unknown type \"{$type}\"; skipped");
            return null;
        }

        $clean = [
            'type'    => $type,
            'label'   => (string) ($opt['label'] ?? ucfirst(str_replace('_', ' ', $key))),
            'default' => $opt['default'] ?? $this->emptyFor($type),
            'group'   => 'theme:' . $slug,
        ];

        foreach (['help', 'css_var', 'unit', 'placeholder'] as $k) {
            if (isset($opt[$k]) && is_scalar($opt[$k])) $clean[$k] = (string) $opt[$k];
        }
        foreach (['min', 'max', 'step', 'rows'] as $k) {
            if (isset($opt[$k]) && is_numeric($opt[$k])) $clean[$k] = $opt[$k] + 0;
        }
        if ($type === 'select') {
            $choices = $opt['choices'] ?? [];
            if (!is_array($choices) || !$choices) {
                $this->warn("theme {$slug}: select \"{$key}\" has no choices; skipped");
                return null;
            }
            $clean['choices'] = array_map('strval', $choices);
        }

        /*
         * How the preview should react. 'css' updates a custom property with no
         * reload, which is what makes colour and spacing feel instant. Anything
         * structural has to reload, because the markup itself changes.
         */
        $clean['preview'] = in_array(($opt['preview'] ?? null), ['css', 'reload'], true)
            ? $opt['preview']
            : (in_array($type, ['color', 'range', 'number', 'font'], true) ? 'css' : 'reload');

        return $clean;
    }

    private function emptyFor(string $type): mixed
    {
        return match ($type) {
            'toggle' => false,
            'number', 'range' => 0,
            default => '',
        };
    }

    // ==================================================================
    // Values
    // ==================================================================

    /** Current value of every option, keyed as section.option. */
    public function values(): array
    {
        $out = [];
        foreach ($this->sections() as $sKey => $section) {
            foreach ($section['options'] as $oKey => $opt) {
                $out[$sKey . '.' . $oKey] = $this->settings->get(
                    $opt['group'], $oKey, $opt['default']
                );
            }
        }
        return $out;
    }

    /**
     * The pending values held for the preview frame, if this is one.
     *
     * Only ever consulted inside a verified preview. On an ordinary request
     * this returns nothing, so a stale draft in someone's session can never
     * leak into what a visitor sees.
     */
    public function draft(): array
    {
        if (!$this->isPreview()) return [];
        try {
            $session = \App\Core\Application::getInstance()->make(\App\Core\Session::class);
            $d = $session->get('customizer_draft', []);
            return is_array($d) ? $d : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A core value, with a pending preview value taking precedence.
     *
     * This is what themes and the layout call, so a preview shows the site
     * title being typed rather than the one on record.
     */
    public function coreValue(string $section, string $key, mixed $default = null): mixed
    {
        $draft = $this->draft();
        $path = $section . '.' . $key;
        if (array_key_exists($path, $draft)) {
            $opt = $this->coreSections()[$section]['options'][$key] ?? null;
            if ($opt !== null) {
                $clean = $this->coerce($draft[$path], $opt);
                if ($clean !== null) return $clean;
            }
        }
        $group = $this->coreSections()[$section]['options'][$key]['group'] ?? 'appearance';
        return $this->settings->get($group, $key, $default);
    }

    /**
     * Save a set of values.
     *
     * Anything not declared is ignored rather than stored: the payload comes
     * from a browser, and an option that no longer exists should not linger in
     * the database forever.
     *
     * @return array{saved:int, skipped:string[]}
     */
    public function save(array $input): array
    {
        $sections = $this->sections();
        $saved = 0; $skipped = [];

        foreach ($input as $path => $value) {
            [$sKey, $oKey] = array_pad(explode('.', (string) $path, 2), 2, null);
            $opt = $sections[$sKey]['options'][$oKey] ?? null;
            if ($opt === null) { $skipped[] = (string) $path; continue; }

            $clean = $this->coerce($value, $opt);
            if ($clean === null) { $skipped[] = (string) $path; continue; }

            $this->settings->set($opt['group'], (string) $oKey, $clean);
            $saved++;
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /** Force a value into the shape its declared type promises. */
    private function coerce(mixed $value, array $opt): mixed
    {
        switch ($opt['type']) {
            case 'toggle':
                return (bool) $value;

            case 'number':
            case 'range':
                if (!is_numeric($value)) return null;
                $n = $value + 0;
                if (isset($opt['min']) && $n < $opt['min']) $n = $opt['min'];
                if (isset($opt['max']) && $n > $opt['max']) $n = $opt['max'];
                return $n;

            case 'color':
                $v = trim((string) $value);
                if ($v === '') return '';
                // Only a hex colour. The value is written into a stylesheet, so
                // anything else is a way to inject CSS.
                return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : null;

            case 'select':
                $v = (string) $value;
                return array_key_exists($v, $opt['choices'] ?? []) ? $v : null;

            case 'image':
            case 'url':
                $v = trim((string) $value);
                if ($v === '') return '';
                // Relative paths are normal for uploads on this site.
                if (str_starts_with($v, '/')) return $v;
                return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;

            case 'textarea':
                // Custom CSS is stored as typed. It is written inside a <style>
                // element, so the one thing that must not survive is a closing
                // tag that would let markup escape it.
                return str_ireplace('</style', '<\\/style', (string) $value);

            case 'font':
                $v = trim((string) $value);
                // A font stack goes straight into CSS. Semicolons and braces
                // would end the declaration and start another.
                return preg_match('/[;{}<>]/', $v) ? null : $v;

            default:
                return (string) $value;
        }
    }

    // ==================================================================
    // Output
    // ==================================================================

    /**
     * The theme's options as CSS custom properties.
     *
     * This is what makes options worth having. A theme's stylesheet writes
     * `var(--bh-accent)` and the value follows whatever the site owner chose —
     * no PHP in templates, and the live preview can update a colour by setting
     * one property rather than reloading the page.
     */
    public function cssVariables(): string
    {
        $decls = [];
        foreach ($this->themeSections() as $section) {
            foreach ($section['options'] as $key => $opt) {
                if (($opt['preview'] ?? '') !== 'css') continue;
                $value = $this->settings->get($opt['group'], $key, $opt['default']);
                if ($value === '' || $value === null) continue;
                $var = $opt['css_var'] ?? ('--bh-' . str_replace('_', '-', $key));
                $unit = $opt['unit'] ?? '';
                $decls[] = $var . ': ' . $this->cssSafe((string) $value . $unit);
            }
        }
        return $decls ? ':root{' . implode(';', $decls) . '}' : '';
    }

    /** Last line of defence for anything reaching a stylesheet. */
    private function cssSafe(string $v): string
    {
        return trim(str_replace(['<', '>', '{', '}', ';', '"', "'"], '', $v));
    }

    /** Everything the front end needs to inject, as one block. */
    public function headMarkup(): string
    {
        $out = '';
        $draft = $this->draft();

        $vars = $draft ? $this->cssVariablesFor($draft) : $this->cssVariables();
        // Always emitted, even when empty: the preview replaces the contents of
        // this element, and it has to exist for that to work.
        $out .= '<style id="bh-customizer-vars">' . $vars . '</style>';

        $css = array_key_exists('advanced.custom_css', $draft)
            ? (string) $draft['advanced.custom_css']
            : (string) $this->settings->get('appearance', 'custom_css', '');
        $out .= '<style id="bh-custom-css">' . str_ireplace('</style', '<\\/style', $css) . '</style>';

        if ($this->isPreview()) $out .= $this->previewBridge();

        return $out;
    }

    // ==================================================================
    // Preview
    // ==================================================================

    /**
     * Is this request the Customizer's preview frame?
     *
     * The token proves the request came from a signed-in administrator who
     * opened the Customizer. Without it, `?bh_preview=1` would let anyone put
     * a script into the page — the bridge below listens for styles from its
     * parent frame, which is exactly the sort of thing not to hand out.
     */
    public function isPreview(): bool
    {
        $given = (string) ($_GET['bh_preview'] ?? '');
        if ($given === '') return false;

        try {
            $session = \App\Core\Application::getInstance()->make(\App\Core\Session::class);
            $expected = substr(hash('sha256', 'bh-preview|' . $session->csrfToken()), 0, 32);
        } catch (\Throwable) {
            return false;
        }
        return hash_equals($expected, $given);
    }

    /**
     * The script that applies pending changes inside the preview frame.
     *
     * Two kinds of message. A `vars` message rewrites the custom properties,
     * which is instant and needs no request — that is what makes dragging a
     * colour picker feel live. A `reload` message reloads the frame, for
     * options that change the markup rather than its appearance.
     *
     * Nothing here writes to the database. A preview must never alter what a
     * visitor sees, and abandoning the screen must leave nothing behind.
     */
    private function previewBridge(): string
    {
        $origin = json_encode($this->siteOrigin(), JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script id="bh-preview-bridge">
(function () {
  var ORIGIN = {$origin};
  function el(id) {
    var n = document.getElementById(id);
    if (!n) { n = document.createElement('style'); n.id = id; document.head.appendChild(n); }
    return n;
  }
  window.addEventListener('message', function (e) {
    // Only from the window that opened this frame, and only from this site.
    // A preview page accepting styles from anywhere would be an injection
    // vector dressed up as a feature.
    if (e.source !== window.parent) return;
    if (ORIGIN && e.origin !== ORIGIN) return;

    var msg = e.data;
    if (!msg || msg.channel !== 'bh-customizer') return;

    if (msg.type === 'vars' && typeof msg.css === 'string') {
      el('bh-customizer-vars').textContent = msg.css;
    } else if (msg.type === 'css' && typeof msg.css === 'string') {
      el('bh-custom-css').textContent = msg.css;
    } else if (msg.type === 'reload') {
      window.location.reload();
    }
  });
  // Tell the parent the frame is ready, so it can send the current state
  // rather than waiting for the next change.
  try { window.parent.postMessage({ channel: 'bh-customizer', type: 'ready' }, ORIGIN || '*'); } catch (err) {}
})();
</script>
HTML;
    }

    /** This site's own origin, for postMessage targeting. */
    private function siteOrigin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]+$/', $host)) return '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    /**
     * Build CSS custom properties from values that are not yet saved.
     *
     * Used by the preview only. Same rules as cssVariables(), so what is
     * previewed is what will be stored.
     */
    public function cssVariablesFor(array $pending): string
    {
        $decls = [];
        foreach ($this->themeSections() as $sKey => $section) {
            foreach ($section['options'] as $key => $opt) {
                if (($opt['preview'] ?? '') !== 'css') continue;
                $path = $sKey . '.' . $key;
                $value = array_key_exists($path, $pending)
                    ? $this->coerce($pending[$path], $opt)
                    : $this->settings->get($opt['group'], $key, $opt['default']);
                if ($value === null || $value === '') continue;
                $var = $opt['css_var'] ?? ('--bh-' . str_replace('_', '-', $key));
                $decls[] = $var . ': ' . $this->cssSafe((string) $value . ($opt['unit'] ?? ''));
            }
        }
        return $decls ? ':root{' . implode(';', $decls) . '}' : '';
    }

    private function warn(string $msg): void
    {
        try { $this->logger?->warning('Customizer: ' . $msg); } catch (\Throwable) {}
    }

    private function slugKey(string $k): string
    {
        return preg_replace('/[^a-z0-9_]/', '_', strtolower($k)) ?: 'section';
    }
}
