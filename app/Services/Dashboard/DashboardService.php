<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Cache;
use App\Core\WidgetRegistry;
use App\Http\Middleware\CheckCapability;
use App\Services\HtmlSanitizer;

/**
 * The dashboard engine (1.2.68). It knows nothing about posts, comments or any
 * app: it reads widget definitions from the WidgetRegistry, decides which of
 * them a user may see, keeps each user's layout (through a LayoutStore), and
 * runs a widget's data provider or action on request — each behind the
 * widget's own capability and in its own try/catch.
 *
 * Four separate concerns, kept apart on purpose:
 *   registration   WidgetRegistry (what exists, who owns it, its contract)
 *   preferences    resolve() / saveLayout() / updateInstance() / reset()
 *   data           data() — never touches the layout, only reads it to learn
 *                  which widget and settings an instance id stands for
 *   presentation   the admin's dashboard.js draws the views; nothing here
 *                  builds markup except sanitizing an 'html' view
 *
 * Trust rules: the browser only ever names instance ids. Which widget an id
 * is, and its settings, come from the user's stored layout; widget keys and
 * settings sent by the client are validated against the registry and the
 * widget's schema before anything is kept.
 */
final class DashboardService
{
    public const LAYOUT_VERSION = 1;
    public const MAX_ITEMS = 40;
    public const MAX_BATCH = 30;
    private const ID_PATTERN = '/^[a-z][a-z0-9-]{2,40}$/';
    private const MAX_SETTINGS_BYTES = 4000;
    private const TONES = ['slate', 'blue', 'green', 'amber', 'red', 'violet'];

    /** @var callable(string):string */
    private $ownerLabel;

    public function __construct(
        private WidgetRegistry $registry,
        private LayoutStore $store,
        private ?Cache $cache = null,
        ?callable $ownerLabel = null,
        private ?\Closure $logger = null,
    ) {
        $this->ownerLabel = $ownerLabel ?? static function (string $owner): string {
            if ($owner === 'core') return 'Basehim';
            $slug = explode(':', $owner, 2)[1] ?? $owner;
            return ucwords(str_replace(['-', '_'], ' ', $slug));
        };
    }

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

    /**
     * The dashboard widgets this user may place: registered for the dashboard
     * surface, capability held, visibility rule passed. Keyed by widget key,
     * full definitions (callables included — for server use only).
     *
     * @return array<string,array>
     */
    public function available(array $user): array
    {
        $out = [];
        foreach ($this->registry->definitions('dashboard') as $key => $w) {
            if ($this->canView($w, $user)) $out[$key] = $w;
        }
        return $out;
    }

    /**
     * The Add Widgets catalogue for the browser: what each widget is, who
     * provides it, the sizes and toolbar features it supports. No callables.
     *
     * @return array<int,array>
     */
    public function catalog(array $user, ?array $resolved = null): array
    {
        $resolved ??= $this->resolve($user);
        $seen = array_flip($resolved['seen']);
        $placed = [];
        foreach ($resolved['items'] as $it) {
            $placed[$it['widget']][] = $it;
        }
        $out = [];
        foreach ($this->available($user) as $key => $w) {
            $pub = WidgetRegistry::publicDefinition($w);
            $d = $pub['dashboard'];
            $instances = $placed[$key] ?? [];
            $out[] = [
                'key'         => $key,
                'title'       => $w['title'],
                'description' => $w['description'],
                'icon'        => $w['icon'],
                'owner'       => $w['source'],
                'owner_label' => ($this->ownerLabel)($w['source']),
                'sizes'       => $d['sizes'],
                'size'        => $d['size'],
                'multiple'    => $d['multiple'],
                'removable'   => $d['removable'],
                'collapsible' => $d['collapsible'],
                'refreshable' => $d['refreshable'],
                'refresh'     => $d['refresh'],
                'settings'    => $w['fields'] !== [],
                'fields'      => $this->clientFields($w['fields']),
                'actions'     => array_values(array_filter(
                    $d['actions'],
                    fn($a) => $this->userHasAll($user, $a['capability'])
                )),
                'endpoint'    => $d['endpoint'] ?? null,
                'empty'       => $d['empty'],
                'placed'      => count(array_filter($instances, fn($i) => $i['visible'])),
                'hidden'      => array_values(array_map(fn($i) => $i['id'], array_filter($instances, fn($i) => !$i['visible']))),
                'new'         => $resolved['customized'] && !isset($seen[$key]),
            ];
        }
        return $out;
    }

    public function canView(array $widget, ?array $user): bool
    {
        if ($user === null || !in_array('dashboard', $widget['surfaces'] ?? [], true)) return false;
        if (!WidgetRegistry::userCan($widget, $user)) return false;
        $rule = $widget['dashboard']['visible'] ?? null;
        if ($rule !== null) {
            try {
                return (bool) $rule($user);
            } catch (\Throwable $e) {
                $this->log('error', "Dashboard widget '{$widget['key']}': visible() failed: " . $e->getMessage(), $widget);
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Layout (preferences)
    // ------------------------------------------------------------------

    /**
     * This user's layout, reconciled with what exists now.
     *
     * @return array{
     *   items: array<int,array>,   cards this user may have, in order (visible or hidden)
     *   dormant: array<int,array>, saved items for widgets that are gone or not
     *                              permitted now (an app switched off, a role
     *                              changed) — never rendered, kept on save so
     *                              they return when the widget does
     *   customized: bool,          true when the user has saved a layout
     *   seen: string[]             widget keys that existed when they last saved
     * }
     */
    public function resolve(array $user): array
    {
        $uid = (int) ($user['id'] ?? 0);
        $saved = $uid > 0 ? $this->store->load($uid) : null;
        $customized = $saved !== null;
        $source = $saved ?? $this->store->siteDefault();
        $available = $this->available($user);

        if ($source === null) {
            return ['items' => $this->builtInDefault($available), 'dormant' => [], 'customized' => false,
                    'seen' => array_keys($available)];
        }

        $items = [];
        $dormant = [];
        $single = [];
        foreach (array_slice((array) $source['items'], 0, self::MAX_ITEMS * 2) as $raw) {
            if (!is_array($raw)) continue;
            $id = (string) ($raw['id'] ?? '');
            $key = $this->registry->resolveKey((string) ($raw['widget'] ?? ''));
            if (!preg_match(self::ID_PATTERN, $id) || $key === '') continue;
            if (isset($items[$id]) || isset($dormant[$id])) continue;

            $w = $available[$key] ?? null;
            if ($w === null) {
                // Keep it as it was saved, only for this user's own layout: a
                // site default's entries for other people's widgets are theirs.
                if ($customized) $dormant[$id] = $this->rawItem($raw, $id);
                continue;
            }
            $d = $w['dashboard'];
            if (!$d['multiple']) {
                if (isset($single[$key])) continue;
                $single[$key] = true;
            }
            $items[$id] = $this->cleanItem($w, $raw, $id);
        }

        // A widget that can't be removed is always on the dashboard.
        foreach ($available as $key => $w) {
            if ($w['dashboard']['removable'] || isset($single[$key])) continue;
            $hasOne = false;
            foreach ($items as $it) if ($it['widget'] === $key) { $hasOne = true; break; }
            if (!$hasOne && $w['dashboard']['default']) {
                $id = $this->defaultId($key);
                $items[$id] = $this->cleanItem($w, [], $id);
            }
        }

        if (!$customized) {
            // A site default: widgets added since it was saved, that are on by
            // default, still appear for people who haven't customised.
            foreach ($this->builtInDefault($available) as $it) {
                if (!$this->hasWidget($items, $it['widget']) && !in_array($it['widget'], (array) ($source['seen'] ?? []), true)) {
                    $items[$it['id']] = $it;
                }
            }
        }

        return [
            'items'      => array_values($items),
            'dormant'    => array_values($dormant),
            'customized' => $customized,
            'seen'       => $customized ? array_values(array_filter((array) ($saved['seen'] ?? []), 'is_string')) : array_keys($available),
        ];
    }

    /**
     * Store a layout the browser sent: order, size, visibility and collapsed
     * state of each card. Settings are never taken from here — they keep
     * their stored values (by id) and change only through updateInstance().
     *
     * New cards are sent with an id of 'new' (or 'new-…'); the server gives
     * them one. Anything the user may not have is refused.
     *
     * @param array<int,array> $incoming
     * @throws InvalidSettings on a layout that names unknown or forbidden widgets
     */
    public function saveLayout(array $user, array $incoming): array
    {
        $uid = $this->uid($user);
        $current = $this->resolve($user);
        $byId = [];
        foreach ($current['items'] as $it) $byId[$it['id']] = $it;
        $available = $this->available($user);

        if (count($incoming) > self::MAX_ITEMS) {
            throw new InvalidSettings('A dashboard can hold at most ' . self::MAX_ITEMS . ' widgets.');
        }

        $items = [];
        $single = [];
        foreach (array_values($incoming) as $i => $raw) {
            if (!is_array($raw)) throw new InvalidSettings("Item {$i} is not readable.");
            $key = $this->registry->resolveKey((string) ($raw['widget'] ?? ''));
            $w = $available[$key] ?? null;
            if ($w === null) {
                throw new InvalidSettings('That widget is not available to you: ' . substr((string) ($raw['widget'] ?? ''), 0, 80));
            }
            $id = (string) ($raw['id'] ?? 'new');
            if ($id === 'new' || str_starts_with($id, 'new-') || !isset($byId[$id])) {
                // An id the user doesn't have is treated as new: a client can't
                // claim another card's id, and can't resurrect a dormant one
                // under a different widget.
                $id = $this->newId($items + $byId);
                $prev = [];
            } else {
                $prev = $byId[$id];
                if ($prev['widget'] !== $key) {
                    throw new InvalidSettings('A widget cannot change into another widget.');
                }
            }
            if (!$w['dashboard']['multiple']) {
                if (isset($single[$key])) continue;
                $single[$key] = true;
            }
            $items[$id] = $this->cleanItem($w, [
                'size'      => $raw['size'] ?? ($prev['size'] ?? null),
                'visible'   => $raw['visible'] ?? true,
                'collapsed' => $raw['collapsed'] ?? ($prev['collapsed'] ?? false),
                'settings'  => $prev['settings'] ?? [],
            ], $id);
        }

        // Cards the client left out: hidden, not forgotten, so their settings
        // survive and Add Widgets can restore them.
        foreach ($current['items'] as $it) {
            if (!isset($items[$it['id']]) && !(!$available[$it['widget']]['dashboard']['multiple'] && isset($single[$it['widget']]))) {
                $it['visible'] = false;
                $items[$it['id']] = $it;
            }
        }
        foreach ($items as $id => $it) {
            if (!$available[$it['widget']]['dashboard']['removable']) $items[$id]['visible'] = true;
        }

        $this->persist($uid, array_values($items), $current['dormant'], array_keys($available));
        return $this->resolve($user);
    }

    /**
     * Change one card in place: collapse/expand, or its settings.
     *
     * @param array{collapsed?:bool, settings?:array, visible?:bool} $changes
     * @throws InvalidSettings
     */
    public function updateInstance(array $user, string $id, array $changes): array
    {
        $uid = $this->uid($user);
        $current = $this->resolve($user);
        $found = false;
        $available = $this->available($user);
        foreach ($current['items'] as $i => $it) {
            if ($it['id'] !== $id) continue;
            $found = true;
            $w = $available[$it['widget']];
            if (array_key_exists('collapsed', $changes)) {
                if (!$w['dashboard']['collapsible']) throw new InvalidSettings('This widget cannot be collapsed.');
                $current['items'][$i]['collapsed'] = (bool) $changes['collapsed'];
            }
            if (array_key_exists('visible', $changes)) {
                if (!$changes['visible'] && !$w['dashboard']['removable']) throw new InvalidSettings('This widget cannot be removed.');
                $current['items'][$i]['visible'] = (bool) $changes['visible'];
            }
            if (array_key_exists('settings', $changes)) {
                if ($w['fields'] === []) throw new InvalidSettings('This widget has no settings.');
                $clean = $this->sanitizeSettings($w, (array) $changes['settings'], true);
                $configure = $w['dashboard']['configure'] ?? null;
                if ($configure !== null) {
                    try {
                        $res = $configure($clean, new WidgetContext($w, $user, $clean, $id));
                        if (is_array($res)) $clean = $this->sanitizeSettings($w, $res, false);
                    } catch (InvalidSettings $e) {
                        throw $e;
                    } catch (\InvalidArgumentException $e) {
                        throw new InvalidSettings($e->getMessage());
                    } catch (\Throwable $e) {
                        $this->log('error', "Dashboard widget '{$w['key']}': configure() failed: " . $e->getMessage(), $w);
                        throw new InvalidSettings('These settings could not be checked. Try again later.');
                    }
                }
                $current['items'][$i]['settings'] = $clean;
            }
            $item = $current['items'][$i];
            break;
        }
        if (!$found) throw new InvalidSettings('That widget is not on your dashboard.');

        // Changing one card is not "customising the whole layout": remember
        // what existed then only if they had already saved a layout.
        $seen = $current['customized'] ? $current['seen'] : array_keys($available);
        $this->persist($uid, $current['items'], $current['dormant'], $seen);
        return $item;
    }

    /** Forget this user's layout: back to the site default. */
    public function reset(array $user): array
    {
        $this->store->reset($this->uid($user));
        return $this->resolve($user);
    }

    /**
     * Make this user's current layout the default for everyone who hasn't
     * customised theirs (or clear it with $clear). Each user still sees only
     * the widgets they may see.
     */
    public function saveSiteDefault(array $user, bool $clear = false): void
    {
        if ($clear) {
            $this->store->saveSiteDefault(null);
            return;
        }
        $current = $this->resolve($user);
        $items = array_values(array_filter($current['items'], fn($i) => $i['visible']));
        $this->store->saveSiteDefault([
            'v' => self::LAYOUT_VERSION,
            'items' => $items,
            'seen' => array_keys($this->registry->definitions('dashboard')),
            'saved_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function hasSiteDefault(): bool
    {
        return $this->store->siteDefault() !== null;
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * Load data for some of this user's cards. Each card is independent: one
     * provider throwing, timing out its own way or returning junk gives that
     * card an error and the others carry on.
     *
     * @param string[] $ids
     * @return array<string,array> id => result
     */
    public function data(array $user, array $ids, bool $force = false): array
    {
        $resolved = $this->resolve($user);
        $byId = [];
        foreach ($resolved['items'] as $it) $byId[$it['id']] = $it;
        $available = $this->available($user);

        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, self::MAX_BATCH) as $id) {
            $it = $byId[$id] ?? null;
            if ($it === null) {
                $out[$id] = ['ok' => false, 'state' => 'gone', 'message' => 'This widget is no longer available on your dashboard.'];
                continue;
            }
            $out[$id] = $this->loadOne($available[$it['widget']], $user, $it, $force);
        }
        return $out;
    }

    private function loadOne(array $w, array $user, array $item, bool $force): array
    {
        $d = $w['dashboard'];
        $started = microtime(true);

        if (isset($d['endpoint'])) {
            // The browser calls the app's own AJAX action; its capability,
            // CSRF and rate limit apply there.
            return ['ok' => true, 'mode' => 'endpoint', 'endpoint' => $d['endpoint'], 'settings' => $item['settings']];
        }

        $ttl = (int) $d['cache']['ttl'];
        $cacheKey = $ttl > 0 && $this->cache !== null ? $this->cacheKey($w, $user, $item['settings']) : null;
        if ($cacheKey !== null && !$force) {
            $hit = $this->cache->get($cacheKey);
            if (is_array($hit)) {
                $hit['cached'] = true;
                return $hit;
            }
        }

        try {
            if (isset($d['data'])) {
                $raw = ($d['data'])(new WidgetContext($w, $user, $item['settings'], $item['id'], $force));
                if (!is_array($raw)) {
                    throw new \UnexpectedValueException('the data provider must return an array, got ' . get_debug_type($raw));
                }
                $view = $d['view'] ?? (is_string($raw['view'] ?? null) ? $raw['view'] : null);
                if (!in_array($view, WidgetRegistry::VIEWS, true)) {
                    throw new \UnexpectedValueException('no valid view: set dashboard.view or return a "view" key');
                }
                $payload = $this->normalisePayload($view, $raw);
            } else {
                // A pre-1.2.68 widget: its render callback's HTML, as it always
                // was (trusted app output, inside its own card only).
                $view = 'html';
                $html = $this->registry->render($w['key'], $item['settings'], 'dashboard');
                $payload = ['html' => $html, 'trusted' => true];
            }
        } catch (WidgetUnavailable $e) {
            return ['ok' => false, 'state' => 'unavailable', 'message' => mb_substr($e->getMessage(), 0, 300) ?: 'This information is not available right now.'];
        } catch (\Throwable $e) {
            $this->log('error', "Dashboard widget '{$w['key']}' failed to load: " . get_class($e) . ': ' . $e->getMessage(), $w);
            return ['ok' => false, 'state' => 'error', 'message' => "This widget couldn't load its data."];
        }

        $result = [
            'ok'         => true,
            'view'       => $view,
            'data'       => $payload,
            'empty'      => $this->isEmpty($view, $payload),
            'fetched_at' => gmdate('c'),
            'took_ms'    => (int) round((microtime(true) - $started) * 1000),
        ];
        if ($cacheKey !== null) {
            $this->cache->set($cacheKey, $result, $ttl);
        }
        return $result;
    }

    /**
     * Run one of a card's actions. The widget must be on this user's
     * dashboard and visible to them, and the action's own capability held.
     *
     * @return array{message:string, refresh:bool}
     */
    public function runAction(array $user, string $id, string $actionId): array
    {
        $resolved = $this->resolve($user);
        $item = null;
        foreach ($resolved['items'] as $it) if ($it['id'] === $id) { $item = $it; break; }
        if ($item === null) throw new InvalidSettings('That widget is not on your dashboard.');
        $w = $this->available($user)[$item['widget']];
        $action = null;
        foreach ($w['dashboard']['actions'] as $a) if ($a['id'] === $actionId) { $action = $a; break; }
        if ($action === null || $action['handler'] === null) throw new InvalidSettings('That action does not exist.');
        if (!$this->userHasAll($user, $action['capability'])) {
            throw new \DomainException('forbidden');
        }
        try {
            $res = ($action['handler'])(new WidgetContext($w, $user, $item['settings'], $item['id'], true));
        } catch (WidgetUnavailable | \InvalidArgumentException $e) {
            throw new InvalidSettings(mb_substr($e->getMessage(), 0, 300));
        } catch (\Throwable $e) {
            $this->log('error', "Dashboard widget '{$w['key']}' action '{$actionId}' failed: " . $e->getMessage(), $w);
            throw new \RuntimeException('action failed');
        }
        $res = is_array($res) ? $res : [];
        if ($w['dashboard']['cache']['ttl'] > 0 && $this->cache !== null) {
            $this->cache->delete($this->cacheKey($w, $user, $item['settings']));
        }
        return [
            'message' => mb_substr((string) ($res['message'] ?? 'Done.'), 0, 300),
            'refresh' => (bool) ($res['refresh'] ?? true),
        ];
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * Settings checked against the widget's fields. Unknown keys are dropped;
     * missing ones take the field's default. With $strict a bad value is an
     * error (input from a form); without, it falls back to the default (a
     * stored value from before the schema changed).
     *
     * @throws InvalidSettings
     */
    public function sanitizeSettings(array $widget, array $input, bool $strict): array
    {
        $out = [];
        $errors = [];
        foreach ($widget['fields'] as $f) {
            $k = $f['key'];
            $default = $this->fieldDefault($f);
            if (!array_key_exists($k, $input)) {
                $out[$k] = $default;
                continue;
            }
            [$value, $error] = $this->fieldValue($f, $input[$k]);
            if ($error !== null) {
                if ($strict) $errors[$k] = [$error];
                $value = $default;
            }
            $out[$k] = $value;
        }
        if ($errors) throw new InvalidSettings('Some settings need attention.', $errors);
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_SETTINGS_BYTES) {
            if ($strict) throw new InvalidSettings('These settings are too large to save.');
            return array_map(fn($f) => $this->fieldDefault($f), array_column($widget['fields'], null, 'key'));
        }
        return $out;
    }

    /** Fields as the settings form needs them: known types, options resolved, nothing callable. */
    private function clientFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $c = [
                'key'   => $f['key'],
                'label' => $f['label'],
                'type'  => in_array($f['type'], WidgetRegistry::FIELD_TYPES, true) ? $f['type'] : 'text',
            ];
            foreach (['min', 'max', 'step'] as $n) {
                if (isset($f[$n]) && is_numeric($f[$n])) $c[$n] = $f[$n] + 0;
            }
            if (isset($f['help']) && is_string($f['help'])) $c['help'] = $f['help'];
            if ($c['type'] === 'select') $c['options'] = WidgetRegistry::fieldOptions($f);
            $out[] = $c;
        }
        return $out;
    }

    private function fieldDefault(array $f): mixed
    {
        if (array_key_exists('default', $f)) {
            [$v, $err] = $this->fieldValue($f, $f['default']);
            if ($err === null) return $v;
        }
        return match ($f['type']) {
            'checkbox' => false,
            'number'   => isset($f['min']) ? $f['min'] + 0 : 0,
            'select'   => (string) (array_key_first(WidgetRegistry::fieldOptions($f)) ?? ''),
            default    => '',
        };
    }

    /** @return array{0:mixed,1:?string} value, error */
    private function fieldValue(array $f, mixed $v): array
    {
        $label = $f['label'];
        switch ($f['type']) {
            case 'checkbox':
                if (is_bool($v)) return [$v, null];
                if (in_array($v, [1, '1', 'true', 'on', 'yes'], true)) return [true, null];
                if (in_array($v, [0, '0', 'false', 'off', 'no', '', null], true)) return [false, null];
                return [false, "{$label} must be on or off."];
            case 'number':
                if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric(trim($v)))) {
                    return [null, "{$label} must be a number."];
                }
                $n = $v + 0;
                $integer = !isset($f['step']) || (float) $f['step'] === floor((float) $f['step']);
                if ($integer) $n = (int) round($n);
                if (isset($f['min']) && $n < $f['min']) return [null, "{$label} must be at least {$f['min']}."];
                if (isset($f['max']) && $n > $f['max']) return [null, "{$label} must be at most {$f['max']}."];
                return [$n, null];
            case 'select':
                $s = is_scalar($v) ? (string) $v : '';
                return array_key_exists($s, WidgetRegistry::fieldOptions($f)) ? [$s, null] : [null, "Choose one of the options for {$label}."];
            case 'url':
                $s = trim(is_scalar($v) ? (string) $v : '');
                if ($s === '') return ['', null];
                if (strlen($s) > 2000 || !preg_match('#^(https?://[^\s]+|/(?!/)[^\s]*)$#i', $s)) {
                    return [null, "{$label} must be a web address (https://…) or a path on this site."];
                }
                return [$s, null];
            case 'color':
                $s = trim(is_scalar($v) ? (string) $v : '');
                return ($s === '' || preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $s)) ? [$s, null] : [null, "{$label} must be a colour like #2563eb."];
            default: // text, textarea
                if (!is_scalar($v) && $v !== null) return [null, "{$label} must be text."];
                $s = (string) $v;
                $max = (int) ($f['maxlength'] ?? ($f['type'] === 'textarea' ? 2000 : 200));
                if (mb_strlen($s) > $max) return [null, "{$label} can be at most {$max} characters."];
                if ($f['type'] === 'text') $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '');
                return [$s, null];
        }
    }

    // ------------------------------------------------------------------
    // Payloads — what the native views draw. Every string the browser shows
    // is set with textContent there; here we only shape and bound the data.
    // ------------------------------------------------------------------

    private function normalisePayload(string $view, array $raw): array
    {
        $common = [];
        if (isset($raw['footer']) && is_array($raw['footer'])) {
            $common['footer'] = [
                'text' => $this->str($raw['footer']['text'] ?? '', 200),
                'link' => $this->link($raw['footer']['link'] ?? null),
            ];
        }
        if (isset($raw['summary'])) $common['summary'] = $this->str($raw['summary'], 300);

        $p = match ($view) {
            'stat' => ['items' => $this->mapList($raw['items'] ?? [], 8, fn($i) => [
                'label' => $this->str($i['label'] ?? '', 80),
                'value' => is_int($i['value'] ?? null) || is_float($i['value'] ?? null) ? $i['value'] : $this->str($i['value'] ?? '', 40),
                'hint'  => $this->str($i['hint'] ?? '', 120),
                'href'  => $this->href($i['href'] ?? null),
                'tone'  => $this->tone($i['tone'] ?? null),
                'trend' => is_array($i['trend'] ?? null) ? [
                    'direction' => in_array($i['trend']['direction'] ?? '', ['up', 'down', 'flat'], true) ? $i['trend']['direction'] : 'flat',
                    'text'      => $this->str($i['trend']['text'] ?? '', 40),
                    'good'      => (bool) ($i['trend']['good'] ?? (($i['trend']['direction'] ?? '') === 'up')),
                ] : null,
            ])],
            'list' => ['items' => $this->mapList($raw['items'] ?? [], 50, fn($i) => [
                'title'  => $this->str($i['title'] ?? '', 200),
                'href'   => $this->href($i['href'] ?? null),
                'meta'   => $this->str($i['meta'] ?? '', 200),
                'time'   => $this->str($i['time'] ?? '', 40),
                'badge'  => is_array($i['badge'] ?? null) ? ['text' => $this->str($i['badge']['text'] ?? '', 40), 'tone' => $this->tone($i['badge']['tone'] ?? null)] : null,
                'avatar' => is_array($i['avatar'] ?? null) ? ['name' => $this->str($i['avatar']['name'] ?? '', 80), 'url' => $this->href($i['avatar']['url'] ?? null)] : null,
            ])],
            'feed' => ['items' => $this->mapList($raw['items'] ?? [], 50, fn($i) => [
                'text'  => $this->str($i['text'] ?? '', 300),
                'actor' => $this->str($i['actor'] ?? '', 80),
                'time'  => $this->str($i['time'] ?? '', 40),
                'href'  => $this->href($i['href'] ?? null),
                'tone'  => $this->tone($i['tone'] ?? null),
                'quote' => $this->str($i['quote'] ?? '', 300),
            ])],
            'status' => ['items' => $this->mapList($raw['items'] ?? [], 30, fn($i) => [
                'label'  => $this->str($i['label'] ?? '', 120),
                'state'  => in_array($i['state'] ?? '', ['ok', 'warn', 'error', 'unknown'], true) ? $i['state'] : 'unknown',
                'detail' => $this->str($i['detail'] ?? '', 200),
                'href'   => $this->href($i['href'] ?? null),
            ])],
            'table' => $this->tablePayload($raw),
            'chart' => $this->chartPayload($raw),
            'html'  => ['html' => HtmlSanitizer::clean($this->str($raw['html'] ?? '', 100000))['html']],
        };
        return $p + $common;
    }

    private function tablePayload(array $raw): array
    {
        $cols = $this->mapList($raw['columns'] ?? [], 8, fn($c) => [
            'key'   => preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($c['key'] ?? '')) ?: 'c',
            'label' => $this->str($c['label'] ?? '', 60),
            'align' => in_array($c['align'] ?? '', ['left', 'right', 'center'], true) ? $c['align'] : 'left',
        ]);
        $rows = $this->mapList($raw['rows'] ?? [], 50, function ($r) use ($cols) {
            $row = ['_href' => $this->href($r['_href'] ?? null)];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                $row[$c['key']] = is_int($v) || is_float($v) ? $v : $this->str($v, 200);
            }
            return $row;
        });
        return ['columns' => $cols, 'rows' => $rows];
    }

    private function chartPayload(array $raw): array
    {
        $labels = array_map(fn($l) => $this->str($l, 30), array_slice(array_values((array) ($raw['labels'] ?? [])), 0, 60));
        $series = $this->mapList($raw['series'] ?? [], 3, fn($s) => [
            'name'   => $this->str($s['name'] ?? '', 60),
            'values' => array_map(fn($v) => is_numeric($v) ? $v + 0 : 0, array_slice(array_values((array) ($s['values'] ?? [])), 0, count($labels))),
        ]);
        return [
            'type'   => in_array($raw['type'] ?? '', ['bar', 'line'], true) ? $raw['type'] : 'bar',
            'labels' => $labels,
            'series' => $series,
            'unit'   => $this->str($raw['unit'] ?? '', 20),
        ];
    }

    private function isEmpty(string $view, array $p): bool
    {
        if (!empty($p['__empty'])) return true;
        return match ($view) {
            'table' => $p['rows'] === [],
            'chart' => $p['series'] === [] || array_sum(array_map(fn($s) => array_sum(array_map('abs', $s['values'])), $p['series'])) == 0,
            'html'  => trim(strip_tags((string) $p['html'], '<img><iframe><video><svg><canvas><table><input>')) === '',
            default => ($p['items'] ?? []) === [],
        };
    }

    private function mapList(mixed $list, int $max, callable $fn): array
    {
        if (!is_array($list)) return [];
        $out = [];
        foreach (array_slice(array_values($list), 0, $max) as $i) {
            if (is_array($i)) $out[] = $fn($i);
        }
        return $out;
    }

    private function str(mixed $v, int $max): string
    {
        if (!is_scalar($v)) return '';
        $s = (string) $v;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /** A link the browser may follow: a site path or http(s) — never javascript: or data:. */
    private function href(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        return preg_match('#^(/(?!/)|https?://)#i', $v) && !preg_match('/[\x00-\x20"<>]/', $v) ? $v : null;
    }

    private function link(mixed $v): ?array
    {
        if (!is_array($v)) return null;
        $href = $this->href($v['url'] ?? $v['href'] ?? null);
        return $href === null ? null : ['label' => $this->str($v['label'] ?? 'Open', 60), 'url' => $href];
    }

    private function tone(mixed $v): ?string
    {
        return in_array($v, self::TONES, true) ? $v : null;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<int,array> */
    private function builtInDefault(array $available): array
    {
        $items = [];
        foreach ($available as $key => $w) {
            if (!$w['dashboard']['default']) continue;
            $items[] = $this->cleanItem($w, [], $this->defaultId($key));
        }
        return $items;
    }

    private function cleanItem(array $w, array $raw, string $id): array
    {
        $d = $w['dashboard'];
        $size = (string) ($raw['size'] ?? '');
        return [
            'id'        => $id,
            'widget'    => $w['key'],
            'size'      => in_array($size, $d['sizes'], true) ? $size : $d['size'],
            'visible'   => $d['removable'] ? (bool) ($raw['visible'] ?? true) : true,
            'collapsed' => $d['collapsible'] && (bool) ($raw['collapsed'] ?? false),
            'settings'  => $this->sanitizeSettings($w, is_array($raw['settings'] ?? null) ? $raw['settings'] : [], false),
        ];
    }

    /** A saved item kept as-is (bounded) for a widget that isn't here now. */
    private function rawItem(array $raw, string $id): array
    {
        $settings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        $json = json_encode($settings);
        return [
            'id'        => $id,
            'widget'    => substr((string) ($raw['widget'] ?? ''), 0, 120),
            'size'      => array_key_exists((string) ($raw['size'] ?? ''), WidgetRegistry::SIZES) ? (string) $raw['size'] : 'third',
            'visible'   => (bool) ($raw['visible'] ?? true),
            'collapsed' => (bool) ($raw['collapsed'] ?? false),
            'settings'  => $json !== false && strlen($json) <= self::MAX_SETTINGS_BYTES ? $settings : [],
        ];
    }

    private function persist(int $uid, array $items, array $dormant, array $seen): void
    {
        // Dormant entries go last and give way first when the list is full.
        $all = array_slice(array_merge($items, $dormant), 0, self::MAX_ITEMS + 20);
        $this->store->save($uid, [
            'v'        => self::LAYOUT_VERSION,
            'items'    => array_values($all),
            'seen'     => array_values(array_unique(array_merge($seen, array_map(fn($i) => $i['widget'], $items)))),
            'saved_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function hasWidget(array $items, string $key): bool
    {
        foreach ($items as $it) if ($it['widget'] === $key) return true;
        return false;
    }

    private function defaultId(string $key): string
    {
        return 'd-' . substr(md5($key), 0, 12);
    }

    private function newId(array $taken): string
    {
        do {
            $id = 'w-' . bin2hex(random_bytes(5));
        } while (isset($taken[$id]));
        return $id;
    }

    private function uid(array $user): int
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid < 1) throw new \DomainException('forbidden');
        return $uid;
    }

    private function userHasAll(array $user, array $caps): bool
    {
        foreach ($caps as $c) {
            if (!CheckCapability::userCan($user, (string) $c)) return false;
        }
        return true;
    }

    private function cacheKey(array $w, array $user, array $settings): string
    {
        $scope = $w['dashboard']['cache']['scope'];
        $who = match ($scope) {
            'global' => 'g',
            'role'   => 'r' . md5(json_encode(CheckCapability::effectiveCaps($user)) ?: ''),
            default  => 'u' . (int) ($user['id'] ?? 0),
        };
        return 'dashboard.widget.' . $w['key'] . '.' . $who . '.' . md5(json_encode($settings) ?: '');
    }

    private function log(string $level, string $message, array $widget): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, (string) ($widget['source'] ?? ''));
        }
    }
}
