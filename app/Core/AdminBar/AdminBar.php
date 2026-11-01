<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

use App\Core\Application;
use App\Http\Middleware\CheckCapability;

/**
 * The frontend admin bar's registry (1.2.96).
 *
 * Everything the bar shows is a registered component: core's own sections
 * (CoreItems) and apps' (through $this->api()->adminBar(), see AdminBarApi)
 * go through the same validation, permission checks and failure isolation.
 *
 * Components:
 *   menu       a top-level entry in the bar, with a dropdown of items/panels
 *   item       an entry in a dropdown (nested up to three levels)
 *   panel      a box of live information at the top of a dropdown, loaded
 *              when the dropdown opens (AJAX), with loading, empty, error and
 *              refresh states
 *   indicator  a badge on a menu or item: a count, a status or a short text,
 *              loaded after the page (or computed with it: 'lazy' => false)
 *   notifications  a source of notices for the bell
 *   search     a source of quick-search results
 *
 * Visibility is decided on the server, for each request: 'capability'
 * (every one listed must be held), 'roles', 'context' (see
 * AdminBarContext::matches()) and a 'visible' callable. A component the
 * user can't see is never rendered and its callables never run; the AJAX
 * actions check the same rules again, so a request for a hidden panel is
 * answered as if it didn't exist.
 *
 * Registrations are per request, like routes and widgets: an app switched
 * off registers nothing, and AppService::forgetOwner() removes what a
 * failing app registered. A callable that throws hides its component for
 * that request and is written to the app's log; the rest of the bar is
 * unaffected.
 */
final class AdminBar
{
    /** Core's top-level menus, which apps may add items to. */
    public const CORE_MENUS = ['site', 'context', 'new', 'search', 'notifications', 'user', 'display'];

    public const REGIONS = ['main', 'end'];
    public const INDICATOR_TYPES = ['count', 'status', 'text'];
    public const TONES = ['neutral', 'info', 'success', 'warning', 'danger'];
    private const MAX_DEPTH = 3;
    private const ID = '/^[a-z0-9][a-z0-9_-]{0,60}(\.[a-z0-9][a-z0-9_-]{0,60}){0,3}$/';

    /** @var array<string,array> id => node (menus, items, panels) */
    private array $nodes = [];
    /** @var array<string,array> */
    private array $indicators = [];
    /** @var array<string,array> */
    private array $notifiers = [];
    /** @var array<string,array> */
    private array $searchers = [];
    /** @var list<array{id:string,owner:string,reason:string}> */
    private array $rejected = [];
    /** @var array<string,true> owner|id pairs already logged this request */
    private array $logged = [];
    private bool $coreLoaded = false;
    private int $seq = 0;

    /** @param bool $withCore false: no core sections (tests, and building a bar of apps' components only) */
    public function __construct(private ?Application $app = null, private bool $withCore = true) {}

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    public function menu(array $def, string $owner = 'core'): bool
    {
        return $this->add('menu', $def, $owner);
    }

    public function item(array $def, string $owner = 'core'): bool
    {
        return $this->add('item', $def, $owner);
    }

    public function panel(array $def, string $owner = 'core'): bool
    {
        return $this->add('panel', $def, $owner);
    }

    public function indicator(array $def, string $owner = 'core'): bool
    {
        $id = (string) ($def['id'] ?? '');
        $why = $this->checkId($id, $owner, $this->indicators);
        if ($why === null && (string) ($def['parent'] ?? '') === '') $why = "an indicator needs a 'parent': the menu or item it belongs to.";
        if ($why === null && !is_callable($def['value'] ?? null)) $why = "'value' must be a callable that returns the count, status or text.";
        $type = (string) ($def['type'] ?? 'count');
        if ($why === null && !in_array($type, self::INDICATOR_TYPES, true)) $why = "'type' must be one of " . implode(', ', self::INDICATOR_TYPES) . '.';
        if ($why === null) $why = $this->checkCommon($def);
        if ($why !== null) return $this->refuse($id, $owner, $why);
        $this->indicators[$id] = $this->common($def, $owner) + [
            'kind' => 'indicator', 'id' => $id, 'parent' => (string) $def['parent'], 'type' => $type,
            'value' => $def['value'], 'lazy' => !array_key_exists('lazy', $def) || (bool) $def['lazy'],
            'label' => self::text($def['label'] ?? '', 80),
            'tone' => in_array($def['tone'] ?? '', self::TONES, true) ? (string) $def['tone'] : '',
        ];
        return true;
    }

    /**
     * A source of notices for the bell. 'items' => fn(array $user, AdminBarContext $ctx): list of
     * ['title', 'text', 'tone' (info|success|warning|danger), 'url', 'time'].
     */
    public function notifications(array $def, string $owner = 'core'): bool
    {
        $id = (string) ($def['id'] ?? '');
        $why = $this->checkId($id, $owner, $this->notifiers);
        if ($why === null && !is_callable($def['items'] ?? null)) $why = "'items' must be a callable that returns a list of notices.";
        if ($why === null) $why = $this->checkCommon($def);
        if ($why !== null) return $this->refuse($id, $owner, $why);
        $this->notifiers[$id] = $this->common($def, $owner) + ['kind' => 'notifications', 'id' => $id, 'items' => $def['items'],
            'label' => self::text($def['label'] ?? '', 80)];
        return true;
    }

    /**
     * A source of quick-search results. 'search' => fn(string $query, array $user, int $limit):
     * list of ['title', 'url', 'meta', 'icon'].
     */
    public function searchProvider(array $def, string $owner = 'core'): bool
    {
        $id = (string) ($def['id'] ?? '');
        $why = $this->checkId($id, $owner, $this->searchers);
        if ($why === null && !is_callable($def['search'] ?? null)) $why = "'search' must be a callable that returns a list of results.";
        if ($why === null && self::text($def['label'] ?? '', 60) === '') $why = "'label' is required: it heads the group of results.";
        if ($why === null) $why = $this->checkCommon($def);
        if ($why !== null) return $this->refuse($id, $owner, $why);
        $this->searchers[$id] = $this->common($def, $owner) + ['kind' => 'search', 'id' => $id, 'search' => $def['search'],
            'label' => self::text($def['label'], 60), 'icon' => self::iconName($def['icon'] ?? 'document-text')];
        return true;
    }

    /** Remove one of the owner's components (any kind). */
    public function remove(string $id, string $owner): bool
    {
        foreach (['nodes', 'indicators', 'notifiers', 'searchers'] as $bag) {
            if (isset($this->{$bag}[$id]) && $this->{$bag}[$id]['owner'] === $owner) {
                unset($this->{$bag}[$id]);
                return true;
            }
        }
        return false;
    }

    /** Remove everything an owner registered (an app that failed or was switched off). */
    public function forgetOwner(string $owner): int
    {
        $n = 0;
        foreach (['nodes', 'indicators', 'notifiers', 'searchers'] as $bag) {
            foreach ($this->{$bag} as $id => $c) {
                if ($c['owner'] === $owner) { unset($this->{$bag}[$id]); $n++; }
            }
        }
        return $n;
    }

    /** Definitions that were refused this request, with the reason. */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** @internal every registered component, for tests and the settings screen */
    public function components(): array
    {
        return ['nodes' => $this->nodes, 'indicators' => $this->indicators, 'notifications' => $this->notifiers, 'search' => $this->searchers];
    }

    private function add(string $kind, array $def, string $owner): bool
    {
        $id = (string) ($def['id'] ?? '');
        $why = $this->checkId($id, $owner, $this->nodes);
        $label = self::text($def['label'] ?? '', 80);
        $parent = (string) ($def['parent'] ?? '');
        if ($why === null && $label === '' && $kind !== 'panel') $why = "'label' is required.";
        if ($why === null && $kind !== 'menu' && $parent === '') $why = "'parent' is required: the menu or item this belongs to.";
        if ($why === null && $kind === 'menu' && $parent !== '') $why = "a menu has no parent; register an item to add to another menu.";
        if ($why === null && $parent === $id) $why = 'a component cannot be its own parent.';
        if ($why === null && $kind === 'panel' && !is_callable($def['data'] ?? null)) $why = "'data' must be a callable that returns the panel's contents.";
        if ($why === null && isset($def['items']) && !is_callable($def['items'])) $why = "'items' must be a callable that returns a list of items.";
        if ($why === null && isset($def['href']) && self::url($def['href']) === null) $why = "'href' must be a path on this site (/admin/...) or an http(s) address.";
        if ($why === null && isset($def['action']) && $owner !== 'core') $why = "'action' is core's; use 'href', or an AJAX action of your own.";
        if ($why === null) $why = $this->checkCommon($def);
        if ($why !== null) return $this->refuse($id, $owner, $why);

        $this->nodes[$id] = $this->common($def, $owner) + [
            'kind'     => $kind,
            'id'       => $id,
            'parent'   => $parent,
            'label'    => $label,
            'title'    => self::text($def['title'] ?? ($kind === 'panel' ? '' : ''), 160),
            'icon'     => self::iconName($def['icon'] ?? ''),
            'href'     => isset($def['href']) ? self::url($def['href']) : null,
            'target'   => ($def['target'] ?? '') === '_blank' ? '_blank' : '',
            'meta'     => self::text($def['meta'] ?? '', 120),
            'group'    => self::text($def['group'] ?? '', 60),
            'region'   => in_array($def['region'] ?? 'main', self::REGIONS, true) ? (string) ($def['region'] ?? 'main') : 'main',
            'items'    => $def['items'] ?? null,
            'data'     => $def['data'] ?? null,
            'action'   => $owner === 'core' ? (string) ($def['action'] ?? '') : '',
            'attrs'    => $owner === 'core' && is_array($def['attrs'] ?? null) ? $def['attrs'] : [],
            'refresh'  => !array_key_exists('refresh', $def) || (bool) $def['refresh'],
            'optional' => (bool) ($def['optional'] ?? ($kind === 'menu' && $owner !== 'core')),
        ];
        return true;
    }

    private function checkId(string $id, string $owner, array $bag): ?string
    {
        if (!preg_match(self::ID, $id)) return "the id '{$id}' is not valid: lower-case letters, digits, '-' and '_', in up to four dot-separated parts.";
        if (isset($bag[$id]) && $bag[$id]['owner'] !== $owner) {
            return "the id '{$id}' is already registered by " . ($bag[$id]['owner'] === 'core' ? 'core' : "'{$bag[$id]['owner']}'") . '.';
        }
        if ($owner !== 'core' && in_array($id, self::CORE_MENUS, true)) return "'{$id}' is one of core's menus.";
        return null;
    }

    private function checkCommon(array $def): ?string
    {
        foreach (['capability', 'roles', 'context'] as $k) {
            if (!isset($def[$k])) continue;
            $v = $def[$k];
            if (is_string($v)) $v = [$v];
            if (!is_array($v)) return "'{$k}' must be a string or a list of strings.";
            foreach ($v as $s) if (!is_string($s) || $s === '' || strlen($s) > 100) return "'{$k}' must be a string or a list of strings.";
        }
        if (isset($def['visible']) && !is_callable($def['visible'])) return "'visible' must be a callable.";
        if (isset($def['priority']) && !is_int($def['priority']) && !(is_string($def['priority']) && ctype_digit($def['priority']))) return "'priority' must be a whole number.";
        return null;
    }

    private function common(array $def, string $owner): array
    {
        $list = static fn($v): array => is_string($v) ? [$v] : (is_array($v) ? array_values(array_map('strval', $v)) : []);
        return [
            'owner'      => $owner,
            'priority'   => (int) ($def['priority'] ?? 50),
            'capability' => $list($def['capability'] ?? []),
            'roles'      => $list($def['roles'] ?? []),
            'context'    => $list($def['context'] ?? []),
            'visible'    => $def['visible'] ?? null,
            'seq'        => ++$this->seq,
        ];
    }

    private function refuse(string $id, string $owner, string $why): bool
    {
        $this->rejected[] = ['id' => $id, 'owner' => $owner, 'reason' => $why];
        $this->log($owner, "Admin bar: '{$id}' was not registered: {$why}");
        return false;
    }

    // ------------------------------------------------------------------
    // Building the bar for one user and page
    // ------------------------------------------------------------------

    /**
     * The visible menus, each with its items (nested), panels and
     * indicators, ordered by region and priority.
     *
     * @param array $settings AdminBarSettings::effective()
     * @return list<array>
     */
    public function tree(AdminBarContext $ctx, array $user, array $settings = []): array
    {
        $this->loadCore($ctx, $user);
        $hidden = array_flip((array) ($settings['hidden'] ?? []));
        $off = [];
        if (isset($settings['context']) && !$settings['context']) $off['context'] = true;
        if (isset($settings['search']) && !$settings['search']) $off['search'] = true;
        if (isset($settings['notifications']) && !$settings['notifications']) $off['notifications'] = true;

        // Visible nodes, with dynamic items expanded.
        $visible = [];
        foreach ($this->nodes as $id => $n) {
            if (isset($off[$id])) continue;
            if (!$this->allowed($n, $ctx, $user)) continue;
            // A section the user hid stays in the page, hidden, so they can
            // show it again from the Display menu without a reload.
            $n['user_hidden'] = $n['kind'] === 'menu' && $n['optional'] && isset($hidden[$id]);
            $visible[$id] = $n;
        }
        foreach ($visible as $id => $n) {
            if ($n['items'] === null) continue;
            $extra = $this->call($n['owner'], $id, $n['items'], [$ctx, $user]);
            if (!is_array($extra)) continue;
            $i = 0;
            foreach (array_slice($extra, 0, 50) as $def) {
                if (!is_array($def) || self::text($def['label'] ?? '', 80) === '') continue;
                $cid = $id . '.' . (preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($def['id'] ?? '')) ? $def['id'] : 'i' . $i);
                $i++;
                $href = isset($def['href']) ? self::url($def['href']) : null;
                if (isset($def['href']) && $href === null) continue;
                $visible[$cid] = [
                    'kind' => 'item', 'id' => $cid, 'parent' => $id, 'owner' => $n['owner'],
                    'label' => self::text($def['label'], 80), 'title' => self::text($def['title'] ?? '', 160),
                    'icon' => self::iconName($def['icon'] ?? ''), 'href' => $href,
                    'target' => ($def['target'] ?? '') === '_blank' ? '_blank' : '',
                    'meta' => self::text($def['meta'] ?? '', 120), 'group' => self::text($def['group'] ?? '', 60),
                    'priority' => (int) ($def['priority'] ?? 50), 'seq' => ++$this->seq, 'region' => 'main',
                    'items' => null, 'data' => null, 'action' => '', 'attrs' => [], 'refresh' => true, 'optional' => false,
                    'capability' => [], 'roles' => [], 'context' => [], 'visible' => null,
                ];
            }
        }

        // Indicators, attached to their parents.
        $badges = [];
        foreach ($this->indicators as $id => $ind) {
            if (!isset($visible[$ind['parent']]) || !$this->allowed($ind, $ctx, $user)) continue;
            $b = ['id' => $id, 'type' => $ind['type'], 'label' => $ind['label'], 'lazy' => $ind['lazy']];
            if (!$ind['lazy']) {
                $v = $this->indicatorValue($ind, $ctx, $user);
                if ($v === null) continue;
                $b += $v;
            }
            $badges[$ind['parent']][] = $b;
        }

        // Children by parent, ordered.
        $by = [];
        foreach ($visible as $id => $n) {
            if ($n['kind'] !== 'menu') $by[$n['parent']][] = $n;
        }
        foreach ($by as &$list) usort($list, self::order(...));
        unset($list);

        $build = function (array $n, int $depth) use (&$build, $by, $badges): ?array {
            $children = [];
            $panels = [];
            if ($depth < self::MAX_DEPTH) {
                foreach ($by[$n['id']] ?? [] as $c) {
                    if ($c['kind'] === 'panel') {
                        $panels[] = ['id' => $c['id'], 'title' => $c['label'] !== '' ? $c['label'] : $c['title'], 'refresh' => $c['refresh']];
                        continue;
                    }
                    $built = $build($c, $depth + 1);
                    if ($built !== null) $children[] = $built;
                }
            }
            // An entry that leads nowhere is left out: no link, no action,
            // nothing under it.
            if ($n['href'] === null && $n['action'] === '' && $children === [] && $panels === []) return null;
            return [
                'id' => $n['id'], 'owner' => $n['owner'], 'label' => $n['label'], 'title' => $n['title'],
                'icon' => $n['icon'], 'href' => $n['href'], 'target' => $n['target'], 'meta' => $n['meta'],
                'group' => $n['group'], 'region' => $n['region'], 'action' => $n['action'], 'attrs' => $n['attrs'],
                'optional' => $n['optional'], 'hidden' => !empty($n['user_hidden']),
                'children' => $children, 'panels' => $panels, 'indicators' => $badges[$n['id']] ?? [],
            ];
        };

        $menus = array_filter($visible, fn($n) => $n['kind'] === 'menu');
        usort($menus, self::order(...));
        $out = [];
        foreach ($menus as $m) {
            $built = $build($m, 1);
            if ($built !== null) $out[] = $built;
        }
        return $out;
    }

    /** The components a user may see, by id (menus, items, panels), for the AJAX actions. */
    public function visibleNode(string $id, AdminBarContext $ctx, array $user): ?array
    {
        $this->loadCore($ctx, $user);
        $n = $this->nodes[$id] ?? null;
        if ($n === null || !$this->allowed($n, $ctx, $user)) return null;
        // Every ancestor must be visible too.
        $seen = [];
        $p = $n['parent'];
        while ($p !== '' && !isset($seen[$p])) {
            $seen[$p] = true;
            $parent = $this->nodes[$p] ?? null;
            if ($parent === null || !$this->allowed($parent, $ctx, $user)) return null;
            $p = $parent['parent'];
        }
        return $n;
    }

    /**
     * A panel's contents, checked: ['ok' => true, 'data' => [...]] or
     * ['ok' => false, 'error' => '...'].
     */
    public function runPanel(string $id, AdminBarContext $ctx, array $user): ?array
    {
        $n = $this->visibleNode($id, $ctx, $user);
        if ($n === null || $n['kind'] !== 'panel') return null;
        $raw = $this->call($n['owner'], $id, $n['data'], [$ctx, $user], $failed);
        if ($failed) return ['ok' => false, 'error' => 'This panel could not be loaded. The error was logged.'];
        return ['ok' => true, 'data' => self::panelData(is_array($raw) ? $raw : [])];
    }

    /** Values of the given lazy indicators the user may see: id => value. */
    public function runIndicators(array $ids, AdminBarContext $ctx, array $user): array
    {
        $this->loadCore($ctx, $user);
        $out = [];
        foreach (array_slice(array_unique(array_map('strval', $ids)), 0, 40) as $id) {
            $ind = $this->indicators[$id] ?? null;
            if ($ind === null || !$this->allowed($ind, $ctx, $user)) continue;
            // Only for a menu or item this user can see on this page.
            if ($this->visibleNode($ind['parent'], $ctx, $user) === null) continue;
            $v = $this->indicatorValue($ind, $ctx, $user);
            if ($v !== null) $out[$id] = $v;
        }
        return $out;
    }

    /** Every notice for this user, newest first, at most 30. */
    public function runNotifications(array $user, AdminBarContext $ctx): array
    {
        $this->loadCore($ctx, $user);
        $all = [];
        foreach ($this->notifiers as $id => $src) {
            if (!$this->allowed($src, $ctx, $user)) continue;
            $items = $this->call($src['owner'], $id, $src['items'], [$user, $ctx]);
            if (!is_array($items)) continue;
            foreach (array_slice($items, 0, 20) as $i => $it) {
                if (!is_array($it)) continue;
                $title = self::text($it['title'] ?? '', 140);
                if ($title === '') continue;
                $url = isset($it['url']) ? self::url($it['url']) : null;
                $all[] = [
                    'id' => $id . ':' . (preg_match('/^[a-z0-9_.:-]{1,80}$/i', (string) ($it['id'] ?? '')) ? $it['id'] : $i),
                    'source' => $id, 'title' => $title, 'text' => self::text($it['text'] ?? '', 300),
                    'tone' => in_array($it['tone'] ?? '', self::TONES, true) ? (string) $it['tone'] : 'info',
                    'url' => $url, 'time' => self::time($it['time'] ?? null),
                    'priority' => $src['priority'],
                ];
            }
        }
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3, 'neutral' => 4];
        usort($all, fn($a, $b) => [$rank[$a['tone']], $b['time'] ?? '', $a['priority']] <=> [$rank[$b['tone']], $a['time'] ?? '', $b['priority']]);
        return array_slice($all, 0, 30);
    }

    /** Quick-search results, grouped by provider, each group at most $limit. */
    public function runSearch(string $query, array $user, AdminBarContext $ctx, int $limit = 5): array
    {
        $this->loadCore($ctx, $user);
        $query = trim(mb_substr($query, 0, 100));
        if ($query === '') return [];
        $groups = [];
        $providers = $this->searchers;
        uasort($providers, self::order(...));
        foreach ($providers as $id => $p) {
            if (!$this->allowed($p, $ctx, $user)) continue;
            $rows = $this->call($p['owner'], $id, $p['search'], [$query, $user, $limit]);
            if (!is_array($rows) || $rows === []) continue;
            $items = [];
            foreach (array_slice($rows, 0, $limit) as $r) {
                if (!is_array($r)) continue;
                $title = self::text($r['title'] ?? '', 160);
                $url = self::url($r['url'] ?? '');
                if ($title === '' || $url === null) continue;
                $items[] = ['title' => $title, 'url' => $url, 'meta' => self::text($r['meta'] ?? '', 120),
                            'icon' => self::iconName($r['icon'] ?? $p['icon'])];
            }
            if ($items) $groups[] = ['id' => $id, 'label' => $p['label'], 'items' => $items];
        }
        return $groups;
    }

    // ------------------------------------------------------------------

    /**
     * Core's own components, registered the first time the bar is built in
     * a request: for the page being shown (labels such as "Edit page" and
     * the post's own links depend on it). A request builds one bar.
     */
    private function loadCore(AdminBarContext $ctx, array $user): void
    {
        if ($this->coreLoaded || !$this->withCore) return;
        $this->coreLoaded = true;
        try {
            CoreItems::register($this, $this->app ?? Application::getInstance(), $ctx, $user);
        } catch (\Throwable $e) {
            $this->log('core', 'Admin bar: core items failed: ' . $e->getMessage());
        }
        // Apps and themes that need the page or the user to decide what to
        // register (boot() runs before either is known) do it here:
        //   $this->addAction('admin_bar.register', function ($ctx, $user) { $this->api()->adminBar()->item([...]); }, 10, 2);
        // Each listener is isolated by the hook registry.
        try {
            ($this->app ?? Application::getInstance())->make(\App\Core\HookRegistry::class)->doAction('admin_bar.register', $ctx, $user);
        } catch (\Throwable $e) {
            $this->log('core', 'Admin bar: an admin_bar.register listener failed: ' . $e->getMessage());
        }
    }

    /** Capability, role, context and visible() checks for one component. */
    private function allowed(array $c, AdminBarContext $ctx, array $user): bool
    {
        foreach ($c['capability'] as $cap) {
            if (!CheckCapability::userCan($user, $cap)) return false;
        }
        if ($c['roles'] !== [] && !in_array((string) ($user['role'] ?? ''), $c['roles'], true)) return false;
        if (!$ctx->matches($c['context'])) return false;
        if ($c['visible'] !== null) {
            $v = $this->call($c['owner'], $c['id'], $c['visible'], [$ctx, $user]);
            if ($v !== true) return false;
        }
        return true;
    }

    /** @return array{value:int|string,tone:string,label:string}|null */
    private function indicatorValue(array $ind, AdminBarContext $ctx, array $user): ?array
    {
        $raw = $this->call($ind['owner'], $ind['id'], $ind['value'], [$ctx, $user]);
        if ($raw === null || $raw === false) return null;
        $tone = $ind['tone'];
        $label = $ind['label'];
        if (is_array($raw)) {
            $tone = in_array($raw['tone'] ?? '', self::TONES, true) ? (string) $raw['tone'] : $tone;
            $label = self::text($raw['label'] ?? $label, 80);
            $raw = $raw['value'] ?? null;
        }
        if ($ind['type'] === 'count') {
            if (!is_numeric($raw)) return null;
            $n = (int) $raw;
            if ($n <= 0) return null;          // a zero count shows nothing
            return ['value' => $n, 'tone' => $tone ?: 'danger', 'label' => $label];
        }
        $text = self::text(is_scalar($raw) ? (string) $raw : '', 24);
        if ($text === '' && $ind['type'] === 'text') return null;
        if ($ind['type'] === 'status' && $tone === '') $tone = 'neutral';
        return ['value' => $text, 'tone' => $tone ?: 'neutral', 'label' => $label];
    }

    /**
     * Run a component's callable, isolated: a throw is logged once to the
     * owner's log and answered with null.
     */
    private function call(string $owner, string $id, callable $fn, array $args, ?bool &$failed = null): mixed
    {
        $failed = false;
        try {
            return $fn(...$args);
        } catch (\Throwable $e) {
            $failed = true;
            $key = $owner . '|' . $id;
            if (!isset($this->logged[$key])) {
                $this->logged[$key] = true;
                $this->log($owner, "Admin bar: '{$id}' failed and was left out: " . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            }
            return null;
        }
    }

    private function log(string $owner, string $message): void
    {
        try {
            $app = $this->app ?? Application::getInstance();
            if ($owner !== 'core' && $owner !== 'theme' && $app->has(\App\Services\AppLogger::class)) {
                $app->make(\App\Services\AppLogger::class)->log($owner, 'error', $message);
                return;
            }
            $app->make(\App\Core\Logger::class)->warning($message);
        } catch (\Throwable) {}
    }

    private static function order(array $a, array $b): int
    {
        return [array_search($a['region'] ?? 'main', self::REGIONS, true), $a['priority'], $a['seq']]
           <=> [array_search($b['region'] ?? 'main', self::REGIONS, true), $b['priority'], $b['seq']];
    }

    /**
     * A panel's contents in the one shape the bar draws. Anything else is
     * dropped; every string is plain text (the bar escapes it).
     *
     *   'text'    => a paragraph
     *   'score'   => ['value' => 82, 'max' => 100, 'label' => 'SEO score', 'tone' => 'success']
     *   'rows'    => [['label' => 'Views', 'value' => '1,204', 'tone' => '', 'url' => '...'], ...]
     *   'list'    => [['title' => '...', 'meta' => '...', 'url' => '...', 'tone' => '...'], ...]
     *   'actions' => [['label' => 'Full report', 'url' => '/admin/...'], ...]
     *   'empty'   => text shown when there is nothing else
     */
    public static function panelData(array $raw): array
    {
        $out = [];
        $tone = static fn($v): string => in_array($v, self::TONES, true) ? (string) $v : '';
        if (($t = self::text($raw['text'] ?? '', 600)) !== '') $out['text'] = $t;
        if (is_array($raw['score'] ?? null) && is_numeric($raw['score']['value'] ?? null)) {
            $max = is_numeric($raw['score']['max'] ?? null) && (float) $raw['score']['max'] > 0 ? (float) $raw['score']['max'] : 100.0;
            $out['score'] = ['value' => round((float) $raw['score']['value'], 1), 'max' => $max,
                'label' => self::text($raw['score']['label'] ?? '', 60), 'tone' => $tone($raw['score']['tone'] ?? '')];
        }
        foreach (['rows', 'list', 'actions'] as $k) {
            if (!is_array($raw[$k] ?? null)) continue;
            $items = [];
            foreach (array_slice($raw[$k], 0, 20) as $r) {
                if (!is_array($r)) continue;
                $url = isset($r['url']) ? self::url($r['url']) : null;
                $item = match ($k) {
                    'rows'    => ['label' => self::text($r['label'] ?? '', 80), 'value' => self::text(is_scalar($r['value'] ?? '') ? (string) ($r['value'] ?? '') : '', 120)],
                    'list'    => ['title' => self::text($r['title'] ?? '', 160), 'meta' => self::text($r['meta'] ?? '', 120)],
                    'actions' => ['label' => self::text($r['label'] ?? '', 60)],
                };
                if (reset($item) === '') continue;
                if ($k === 'actions' && $url === null) continue;
                $item['url'] = $url;
                $item['tone'] = $tone($r['tone'] ?? '');
                $items[] = $item;
            }
            if ($items) $out[$k] = $items;
        }
        if (($e = self::text($raw['empty'] ?? '', 200)) !== '') $out['empty'] = $e;
        return $out;
    }

    /** One line of plain text, at most $max characters. */
    public static function text(mixed $v, int $max): string
    {
        if (!is_scalar($v)) return '';
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        if (!mb_check_encoding($s, 'UTF-8')) return '';
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** A usable link: a path on this site, or an http(s) address. Null otherwise. */
    public static function url(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        if ($v === '' || strlen($v) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $v)) return null;
        if ($v[0] === '/' && !str_starts_with($v, '//')) return $v;
        if ($v[0] === '#') return $v;
        if (preg_match('#^https?://[^\s/]+#i', $v)) return $v;
        return null;
    }

    private static function iconName(mixed $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return preg_match('/^[a-z0-9-]{1,40}$/', $v) ? $v : '';
    }

    private static function time(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        $t = strtotime($v . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $v) ? '' : ' UTC'));
        return $t ? gmdate('c', $t) : null;
    }
}
