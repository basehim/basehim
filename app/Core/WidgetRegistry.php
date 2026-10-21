<?php

declare(strict_types=1);

namespace App\Core;

/**
 * WidgetRegistry — a container singleton (like HookRegistry) that collects
 * widget definitions contributed by core, apps and themes.
 *
 * A widget is a small, self-contained unit that can appear on up to three
 * surfaces:
 *   - 'editor'    : offered as an insertable block in the post block editor
 *   - 'frontend'  : rendered on the public site (via the `widget` block, or a
 *                   theme calling render() directly)
 *   - 'dashboard' : shown as a card on the admin dashboard (docs/DASHBOARD-WIDGETS.md)
 *
 * Registration is cheap and happens during boot. Since 1.2.68:
 *
 *   - Every registration has an owner ('core', 'app:{slug}', 'theme:{slug}').
 *     A key belongs to whoever registered it first; the same owner may
 *     re-register (replace) it, anyone else is refused. Keys under 'core.' are
 *     core's alone. So an app can never replace a core widget or another
 *     app's, whatever order things boot in.
 *   - A malformed definition is refused with a reason (rejected()), and the
 *     reason is logged — it no longer disappears silently.
 *   - Dashboard widgets may declare a data provider and a native view instead
 *     of (or as well as) HTML, plus capability, sizes, settings, actions,
 *     caching and refresh. See normaliseDashboard().
 */
final class WidgetRegistry
{
    public const SURFACES = ['editor', 'frontend', 'dashboard'];

    /** Dashboard card sizes, in columns of the 12-column grid. */
    public const SIZES = ['small' => 3, 'third' => 4, 'half' => 6, 'two-thirds' => 8, 'full' => 12];

    /** Native dashboard views the admin can draw without app markup. */
    public const VIEWS = ['stat', 'list', 'table', 'chart', 'feed', 'status', 'html'];

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]*(\.[a-z0-9][a-z0-9_-]*){0,3}$/';
    /** Field types the dashboard's settings form draws; any other is shown as text. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'url', 'color'];
    /** Colours a card and its tiles can take (1.2.77 adds indigo). */
    public const TONES = ['slate', 'blue', 'green', 'amber', 'red', 'violet', 'indigo'];

    /** @var array<string, array> */
    private array $widgets = [];

    /** @var array<string, string> alias => current key */
    private array $aliases = [];

    /** @var array<int, array{key:string,owner:string,reason:string}> */
    private array $rejected = [];

    /**
     * Remove every widget (and alias) an owner registered — 'app:{slug}' — so
     * an app whose boot() failed part-way leaves no cards behind (1.2.71,
     * AppGuard). Core's widgets cannot be removed this way.
     */
    public function forgetOwner(string $owner): int
    {
        if ($owner === '' || $owner === 'core') return 0;
        $n = 0;
        foreach ($this->widgets as $key => $w) {
            if (($w['source'] ?? '') === $owner) {
                unset($this->widgets[$key]);
                $n++;
            }
        }
        foreach ($this->aliases as $alias => $key) {
            if (!isset($this->widgets[$key])) unset($this->aliases[$alias]);
        }
        return $n;
    }

    /**
     * Register (or, for the same owner, replace) a widget.
     *
     * @param string $key  Unique slug, e.g. 'weather.current' or 'my-app.stats'.
     * @param array  $def  {
     *   title:       string    Human label (required),
     *   render:      callable  fn(array $settings, string $surface): string — HTML.
     *                          Required unless the widget is dashboard-only with a
     *                          dashboard.data provider or dashboard.endpoint.
     *   description: string,
     *   icon:        string    Heroicon name ('chart-bar') or legacy 'fa-*' class,
     *   surfaces:    string[]  subset of ['editor','frontend','dashboard'] (default all three),
     *   fields:      array     settings schema, shared by every surface:
     *                          [ ['key'=>..,'label'=>..,'type'=>'text|textarea|number|select|checkbox|url|color',
     *                             'options'=>[], 'default'=>.., 'min'=>.., 'max'=>.., 'help'=>..], ... ],
     *   capability:  string|string[]  needed to see the widget on the dashboard
     *                          and to preview it in the editor (default 'read'),
     *   source:      string    owner ('core' / 'app:slug' / 'theme:slug'); set by the
     *                          App base class and by ThemeService, never trust a
     *                          caller-supplied value over theirs,
     *   dashboard:   array     dashboard behaviour — see normaliseDashboard().
     * }
     * @return bool false when the definition was refused (see rejected()).
     */
    public function register(string $key, array $def): bool
    {
        $raw = $key;
        $key = $this->normaliseKey($key);
        $owner = $this->normaliseOwner((string) ($def['source'] ?? ''));

        $fail = function (string $reason) use ($raw, $owner): bool {
            $this->rejected[] = ['key' => $raw, 'owner' => $owner, 'reason' => $reason];
            $this->log("Widget '{$raw}' not registered: {$reason}", $owner);
            return false;
        };

        if ($key === '' || !preg_match(self::KEY_PATTERN, $key)) {
            return $fail('the key must be lower-case letters, digits, "-", "_" and up to three dots.');
        }
        if (!is_string($def['title'] ?? null) || trim($def['title']) === '') {
            return $fail("'title' is required.");
        }

        // Ownership: core.* is core's; a key someone else already holds is theirs.
        if (str_starts_with($key, 'core.') && $owner !== 'core') {
            return $fail("keys under 'core.' are reserved for core.");
        }
        if (isset($this->widgets[$key]) && $this->widgets[$key]['source'] !== $owner) {
            return $fail("the key is already registered by {$this->widgets[$key]['source']}.");
        }
        if (isset($this->aliases[$key])) {
            return $fail("the key is an alias of '{$this->aliases[$key]}'.");
        }

        $surfaces = $def['surfaces'] ?? self::SURFACES;
        $surfaces = array_values(array_intersect(self::SURFACES, array_map('strval', (array) $surfaces)));
        if (!$surfaces) $surfaces = self::SURFACES;

        $render = $def['render'] ?? null;
        if ($render !== null && !is_callable($render)) {
            return $fail("'render' must be callable.");
        }

        try {
            $fields = $this->normaliseFields($def['fields'] ?? [], $key, $owner);
            // An app's widget that declares no capability is for the people
            // who can use that app (access_app:{slug}: administrators, and
            // anyone granted the app), not every signed-in account (1.2.73,
            // BH-001/BH-013). Until 1.2.73 it fell back to 'read', so any
            // subscriber saw it on the dashboard and could preview it. An app
            // that means its widget for everyone declares 'capability' => 'read'.
            $defaultCap = str_starts_with($owner, 'app:')
                ? \App\Services\AccessControl::appCap(substr($owner, 4))
                : 'read';
            $caps = $this->normaliseCaps($def['capability'] ?? $defaultCap);
            $dash = in_array('dashboard', $surfaces, true)
                ? $this->normaliseDashboard(is_array($def['dashboard'] ?? null) ? $def['dashboard'] : [], $owner, $render !== null)
                : (is_array($def['dashboard'] ?? null) ? $def['dashboard'] : []);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }

        // A render callable is needed everywhere except a dashboard-only widget
        // that brings its own data provider or endpoint.
        $dashOnlyData = $surfaces === ['dashboard'] && (isset($dash['data']) || isset($dash['endpoint']));
        if ($render === null && !$dashOnlyData) {
            return $fail("'render' is required (or, for a dashboard-only widget, 'dashboard.data' or 'dashboard.endpoint').");
        }

        // Aliases (renamed keys) — refuse ones that would shadow a live key.
        $aliases = [];
        foreach ((array) ($dash['aliases'] ?? []) as $alias) {
            $a = $this->normaliseKey((string) $alias);
            if ($a === '' || $a === $key) continue;
            if (isset($this->widgets[$a]) || (isset($this->aliases[$a]) && $this->aliases[$a] !== $key)) {
                $this->log("Widget '{$key}': alias '{$a}' ignored, it is taken.", $owner);
                continue;
            }
            if (str_starts_with($a, 'core.') && $owner !== 'core') continue;
            $aliases[] = $a;
        }
        $dash['aliases'] = $aliases;
        foreach ($aliases as $a) {
            $this->aliases[$a] = $key;
        }

        if (isset($this->widgets[$key])) {
            // Same owner, so allowed — but say so: a second definition under a
            // key silently replacing the first is how one surface's widget
            // disappears behind another's.
            $this->log("Widget '{$key}' was registered again by {$owner}; the later definition replaces the earlier one.", $owner, 'notice');
        }
        $this->widgets[$key] = [
            'key'         => $key,
            'title'       => trim((string) $def['title']),
            'description' => (string) ($def['description'] ?? ''),
            'icon'        => (string) ($def['icon'] ?? 'puzzle-piece'),
            'surfaces'    => $surfaces,
            'fields'      => $fields,
            'render'      => $render,
            'source'      => $owner,
            'capability'  => $caps,
            'dashboard'   => $dash,
        ];
        return true;
    }

    /** Remove a widget (e.g. on app deactivation, if desired). */
    public function unregister(string $key): void
    {
        $key = $this->resolveKey($key);
        foreach (($this->widgets[$key]['dashboard']['aliases'] ?? []) as $a) {
            unset($this->aliases[$a]);
        }
        unset($this->widgets[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$this->resolveKey($key)]);
    }

    /** Full definition for one widget (includes callables). Aliases resolve. */
    public function get(string $key): ?array
    {
        return $this->widgets[$this->resolveKey($key)] ?? null;
    }

    /** The current key for $key, following a rename alias. */
    public function resolveKey(string $key): string
    {
        $key = $this->normaliseKey($key);
        return $this->aliases[$key] ?? $key;
    }

    /**
     * All widgets, optionally filtered to a surface. Callables are stripped
     * so the result is safe to expose over the API / to templates.
     *
     * @return array<int, array>
     */
    public function all(?string $surface = null): array
    {
        $out = [];
        foreach ($this->widgets as $w) {
            if ($surface !== null && !in_array($surface, $w['surfaces'], true)) continue;
            $out[] = self::publicDefinition($w);
        }
        // Dashboard ordering respects an optional priority (lower = earlier).
        if ($surface === 'dashboard') {
            usort($out, fn($a, $b) => (($a['dashboard']['priority'] ?? 100) <=> ($b['dashboard']['priority'] ?? 100))
                ?: strcmp($a['key'], $b['key']));
        }
        return $out;
    }

    /** Full (callable-bearing) definitions for one surface, in dashboard order. @return array<string,array> */
    public function definitions(string $surface): array
    {
        $out = array_filter($this->widgets, fn($w) => in_array($surface, $w['surfaces'], true));
        if ($surface === 'dashboard') {
            uasort($out, fn($a, $b) => ($a['dashboard']['priority'] <=> $b['dashboard']['priority']) ?: strcmp($a['key'], $b['key']));
        }
        return $out;
    }

    /** Registrations that were refused this request, with the reason. */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * Render a widget to HTML for a given surface. Never throws — a broken
     * widget returns an inline comment instead of taking down the page. The
     * exception's message is logged, not printed (it can hold paths or SQL).
     */
    public function render(string $key, array $settings = [], string $surface = 'frontend'): string
    {
        $w = $this->get($key);
        $safeKey = htmlspecialchars($this->normaliseKey($key), ENT_QUOTES);
        if (!$w) {
            return '<!-- bh-widget: unknown "' . $safeKey . '" -->';
        }
        if (!in_array($surface, $w['surfaces'], true)) {
            return '<!-- bh-widget: "' . $safeKey . '" not available on ' . htmlspecialchars($surface, ENT_QUOTES) . ' -->';
        }
        if (!is_callable($w['render'])) {
            return '<!-- bh-widget: "' . $safeKey . '" has no HTML renderer -->';
        }
        try {
            $html = ($w['render'])($settings, $surface);
            return is_string($html) ? $html : '';
        } catch (\Throwable $e) {
            $this->log("Widget '{$w['key']}' failed to render on {$surface}: " . $e->getMessage(), $w['source'], 'error');
            return '<!-- bh-widget "' . $safeKey . '" failed to render -->';
        }
    }

    /** Whether a user may see this widget (capability list, all required). */
    public static function userCan(array $widget, ?array $user): bool
    {
        if ($user === null) return false;
        foreach ((array) ($widget['capability'] ?? ['read']) as $cap) {
            if (!\App\Http\Middleware\CheckCapability::userCan($user, (string) $cap)) return false;
        }
        return true;
    }

    /** A definition without callables — what templates and JSON may see. */
    public static function publicDefinition(array $w): array
    {
        unset($w['render']);
        if (isset($w['dashboard']) && is_array($w['dashboard'])) {
            $d = $w['dashboard'];
            $d['has_data'] = isset($d['data']) || isset($d['endpoint']);
            unset($d['data'], $d['configure'], $d['visible']);
            foreach ($d['actions'] ?? [] as $i => $a) {
                $d['actions'][$i]['handler'] = isset($a['handler']);
            }
            $w['dashboard'] = $d;
        }
        return $w;
    }

    // ------------------------------------------------------------------
    // Normalisation
    // ------------------------------------------------------------------

    /**
     * The dashboard part of a definition, validated. Every key is optional.
     *
     *   view        'stat'|'list'|'table'|'chart'|'feed'|'status'|'html' — how the
     *               admin draws what data returns (default: the 'view' key in the
     *               returned array, else 'html' for render-only widgets)
     *   data        fn(\App\Services\Dashboard\WidgetContext $ctx): array — the
     *               provider. Runs on the server, per request, behind the
     *               widget's capability. Return the view's payload.
     *   endpoint    'my-app.widget-data' — instead of data: an AJAX action the
     *               browser calls through the gateway (its own capability, CSRF and
     *               rate limit apply). Must be in the owner's namespace.
     *   configure   fn(array $settings, WidgetContext $ctx): array — extra checks
     *               after the fields schema; throw \InvalidArgumentException('message').
     *   visible     fn(array $user): bool — a business rule on top of capability.
     *   sizes       allowed sizes, subset of SIZES (default: all)
     *   size        default size (alias: width, the pre-1.2.68 name)
     *   priority    default order, lower first (default 100)
     *   default     on a new user's dashboard (default true)
     *   multiple    may be added more than once (default false)
     *   refresh     auto-refresh interval in seconds, 0 = off (min 60)
     *   cache       seconds, or ['ttl'=>s, 'scope'=>'user'|'role'|'global'] (default user)
     *   collapsible / removable / refreshable   toolbar features (default true;
     *               refreshable needs data or render)
     *   actions     [['id','label','url'?|'handler'?,'icon'?,'capability'?,'confirm'?], ...]
     *   empty       ['title','text','action'=>['label','url']]
     *   tone        'blue' (default) | 'green' | 'amber' | 'red' | 'violet' | 'indigo' | 'slate' — the
     *               colour of the card's icon square (1.2.77)
     *   aliases     old keys this widget answers to (for renames)
     */
    private function normaliseDashboard(array $d, string $owner, bool $hasRender): array
    {
        $bad = static fn(string $m): never => throw new \InvalidArgumentException($m);

        $view = $d['view'] ?? null;
        if ($view !== null && !in_array($view, self::VIEWS, true)) {
            $bad("dashboard.view must be one of: " . implode(', ', self::VIEWS) . '.');
        }
        if (isset($d['data']) && !is_callable($d['data'])) $bad('dashboard.data must be callable.');
        if (isset($d['configure']) && !is_callable($d['configure'])) $bad('dashboard.configure must be callable.');
        if (isset($d['visible']) && !is_callable($d['visible'])) $bad('dashboard.visible must be callable.');
        $endpoint = null;
        if (isset($d['endpoint'])) {
            if (isset($d['data'])) $bad('use dashboard.data or dashboard.endpoint, not both.');
            $endpoint = (string) $d['endpoint'];
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}(\.[a-z][a-z0-9_-]{0,63}){1,3}$/', $endpoint)) {
                $bad("dashboard.endpoint must be an AJAX action name like 'my-app.widget-data'.");
            }
            $ns = explode('.', $endpoint, 2)[0];
            $ownerNs = $owner === 'core' ? null : (explode(':', $owner, 2)[1] ?? '');
            if ($owner !== 'core' && $ns !== $ownerNs) {
                $bad("dashboard.endpoint must be one of this owner's own AJAX actions ('{$ownerNs}.…').");
            }
        }

        $allSizes = array_keys(self::SIZES);
        $sizes = isset($d['sizes']) ? array_values(array_intersect($allSizes, array_map('strval', (array) $d['sizes']))) : $allSizes;
        if (!$sizes) $bad('dashboard.sizes lists no known size (' . implode(', ', $allSizes) . ').');
        $size = (string) ($d['size'] ?? $d['width'] ?? '');
        if (!in_array($size, $sizes, true)) {
            $size = in_array('third', $sizes, true) ? 'third' : $sizes[0];
        }

        $refresh = (int) ($d['refresh'] ?? 0);
        if ($refresh > 0) $refresh = max(60, min(86400, $refresh));

        $cache = $d['cache'] ?? 0;
        $ttl = is_array($cache) ? (int) ($cache['ttl'] ?? 0) : (int) $cache;
        $scope = is_array($cache) ? (string) ($cache['scope'] ?? 'user') : 'user';
        if (!in_array($scope, ['user', 'role', 'global'], true)) $bad("dashboard.cache scope must be user, role or global.");

        $actions = [];
        foreach ((array) ($d['actions'] ?? []) as $a) {
            if (!is_array($a)) $bad('each dashboard action must be an array.');
            $id = (string) ($a['id'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id)) $bad("dashboard action ids are lower-case slugs; got '{$id}'.");
            if (isset($actions[$id])) $bad("dashboard action '{$id}' is declared twice.");
            $label = trim((string) ($a['label'] ?? ''));
            if ($label === '') $bad("dashboard action '{$id}' needs a label.");
            $url = isset($a['url']) ? (string) $a['url'] : null;
            $handler = $a['handler'] ?? null;
            if (($url === null) === ($handler === null)) $bad("dashboard action '{$id}' needs exactly one of url or handler.");
            if ($url !== null && !preg_match('#^(/(?!/)|https?://)#i', $url)) $bad("dashboard action '{$id}': url must be a site path or an http(s) URL.");
            if ($handler !== null && !is_callable($handler)) $bad("dashboard action '{$id}': handler must be callable.");
            $actions[$id] = [
                'id'         => $id,
                'label'      => $label,
                'url'        => $url,
                'handler'    => $handler,
                'icon'       => (string) ($a['icon'] ?? ''),
                'capability' => isset($a['capability']) ? $this->normaliseCaps($a['capability']) : [],
                'confirm'    => isset($a['confirm']) ? (string) $a['confirm'] : null,
                'danger'     => !empty($a['danger']),
            ];
        }

        $empty = is_array($d['empty'] ?? null) ? $d['empty'] : [];
        $emptyAction = null;
        if (is_array($empty['action'] ?? null) && isset($empty['action']['label'], $empty['action']['url'])
            && preg_match('#^(/(?!/)|https?://)#i', (string) $empty['action']['url'])) {
            $emptyAction = ['label' => (string) $empty['action']['label'], 'url' => (string) $empty['action']['url']];
        }

        $hasProvider = isset($d['data']) || $endpoint !== null;
        $out = [
            'view'        => $view ?? ($hasProvider ? null : 'html'),
            'sizes'       => $sizes,
            'size'        => $size,
            'priority'    => (int) ($d['priority'] ?? 100),
            'default'     => (bool) ($d['default'] ?? true),
            'multiple'    => (bool) ($d['multiple'] ?? false),
            'refresh'     => $refresh,
            'cache'       => ['ttl' => max(0, min(86400, $ttl)), 'scope' => $scope],
            'collapsible' => (bool) ($d['collapsible'] ?? true),
            'removable'   => (bool) ($d['removable'] ?? true),
            'refreshable' => (bool) ($d['refreshable'] ?? true) && ($hasProvider || $hasRender),
            'actions'     => array_values($actions),
            'empty'       => [
                'title'  => (string) ($empty['title'] ?? 'Nothing here yet'),
                'text'   => (string) ($empty['text'] ?? ''),
                'action' => $emptyAction,
            ],
            // The colour of the card's icon square (1.2.77).
            'tone'        => in_array($d['tone'] ?? null, self::TONES, true) ? $d['tone'] : 'blue',
            'aliases'     => (array) ($d['aliases'] ?? []),
            // Kept for widgets written against the pre-1.2.68 docs.
            'width'       => $size,
        ];
        if (isset($d['data'])) $out['data'] = $d['data'];
        if ($endpoint !== null) $out['endpoint'] = $endpoint;
        if (isset($d['configure'])) $out['configure'] = $d['configure'];
        if (isset($d['visible'])) $out['visible'] = $d['visible'];
        return $out;
    }

    /**
     * The settings schema, checked without changing what existing widgets
     * rely on: a field's type and options are kept as written (options may be
     * a callable — see fieldOptions()). A field without a usable key, or a
     * second field with the same key, is dropped and logged rather than
     * failing the whole widget, which worked before 1.2.68.
     *
     * @return array<int,array>
     */
    private function normaliseFields(mixed $fields, string $widgetKey = '', string $owner = ''): array
    {
        if (!is_array($fields)) return [];
        $out = [];
        $seen = [];
        foreach (array_values($fields) as $f) {
            if (!is_array($f)) continue;
            $key = (string) ($f['key'] ?? '');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', $key) || isset($seen[$key])) {
                $this->log("Widget '{$widgetKey}': field '" . substr($key, 0, 40) . "' ignored (missing, invalid or repeated key).", $owner);
                continue;
            }
            $seen[$key] = true;
            $f['key'] = $key;
            $f['type'] = is_string($f['type'] ?? null) && $f['type'] !== '' ? $f['type'] : 'text';
            $f['label'] = (string) ($f['label'] ?? $key);
            $out[] = $f;
        }
        return $out;
    }

    /**
     * A select field's options as value => label, whatever form they were
     * written in: ['a' => 'A'], ['a', 'b'], [['value' => 'a', 'label' => 'A']],
     * or a callable returning one of those. A callable that throws gives [].
     *
     * @return array<string,string>
     */
    public static function fieldOptions(array $field): array
    {
        $raw = $field['options'] ?? [];
        if (is_callable($raw) && !is_string($raw)) {
            try { $raw = $raw(); } catch (\Throwable) { $raw = []; }
        }
        $opts = [];
        $raw = (array) $raw;
        // Only a true list (['a', 'b']) uses each value as its own label. Before
        // 1.2.77 any integer key did, so ['6' => 'Last 6 months'] (PHP makes
        // '6' an integer) offered the label as the value.
        $list = array_is_list($raw);
        foreach ($raw as $k => $v) {
            if (is_array($v)) $opts[(string) ($v['value'] ?? '')] = (string) ($v['label'] ?? $v['value'] ?? '');
            elseif ($list) $opts[(string) $v] = (string) $v;
            elseif (is_scalar($v)) $opts[(string) $k] = (string) $v;
        }
        return $opts;
    }

    /** @return string[] */
    private function normaliseCaps(mixed $caps): array
    {
        $caps = is_array($caps) ? $caps : [$caps];
        $out = [];
        foreach ($caps as $c) {
            if (!is_string($c) || !preg_match('/^[a-z][a-z0-9_:.-]{1,99}$/', $c)) {
                throw new \InvalidArgumentException("capability names are lower-case strings like 'edit_posts'.");
            }
            $out[] = $c;
        }
        if (!$out) throw new \InvalidArgumentException("'capability' cannot be empty.");
        return array_values(array_unique($out));
    }

    private function normaliseOwner(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '' || $source === 'core') return $source === '' ? 'unknown' : 'core';
        if (preg_match('/^(app|theme):[a-z0-9][a-z0-9_-]{0,79}$/', $source)) return $source;
        return 'unknown';
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        return (string) preg_replace('/[^a-z0-9._-]/', '', $key);
    }

    private function log(string $message, string $owner, string $level = 'warning'): void
    {
        try {
            $app = Application::getInstance();
            if (str_starts_with($owner, 'app:')) {
                $app->make(\App\Services\AppLogger::class)->log(substr($owner, 4), $level, $message, []);
            }
            $app->make(Logger::class)->log($level, '[widgets] ' . $message, ['owner' => $owner]);
        } catch (\Throwable) {
            // No container (unit tests) or no log directory: nothing to do.
        }
    }
}
