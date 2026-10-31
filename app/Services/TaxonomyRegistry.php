<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;

/**
 * Taxonomies and the content types they apply to (1.2.94).
 *
 * Core's two, category and tag, come from config/cms.php. An app adds its
 * own from boot() with $this->registerTaxonomy('event-type', [...]), and a
 * content type takes any taxonomy, core's included, with
 * registerPostType('event', ['taxonomies' => ['category', 'event-type']]).
 *
 * Terms live in the existing tables: a registered taxonomy gets its
 * {taxonomies} row the first time it is registered (and its label kept up
 * to date), so the admin's term screens, the terms API and post_term work for
 * it unchanged. Like content types, registrations are per request: when an
 * app is switched off its taxonomy stops applying, and its terms stay.
 */
final class TaxonomyRegistry
{
    /** @var array<string,array> slug => definition (apps only) */
    private array $taxonomies = [];

    /** @var list<array{slug:string,app:string,reason:string}> */
    private array $rejected = [];

    private bool $menuHooked = false;

    public function __construct(
        private Config $config,
        private PostTypeRegistry $types,
        private ?Database $db = null,
        private ?Cache $cache = null,
        private ?\App\Core\HookRegistry $hooks = null,
    ) {}

    /**
     * Register a taxonomy.
     *
     * @param array $args label, singular, description, hierarchical (default
     *        false), post_types (types it applies to), public (default true:
     *        term archives at /{rewrite}/{term}), rewrite (default: the slug;
     *        false for none), app (set by App::registerTaxonomy)
     */
    public function register(string $slug, array $args = []): bool
    {
        $slug = strtolower(trim($slug));
        $app = (string) ($args['app'] ?? '');
        $fail = function (string $why) use ($slug, $app): bool {
            $this->rejected[] = ['slug' => $slug, 'app' => $app, 'reason' => $why];
            return false;
        };
        if (!preg_match('/^[a-z][a-z0-9_-]{1,59}$/', $slug)) return $fail('the key must be 2–60 lower-case letters, digits, "-" or "_", starting with a letter.');
        if (isset($this->builtIn()[$slug])) return $fail("'{$slug}' is core's; attach it to a type with registerPostType(…, ['taxonomies' => ['{$slug}']]) instead.");
        if (isset($this->taxonomies[$slug]) && $this->taxonomies[$slug]['app'] !== $app) {
            return $fail("'{$slug}' is already registered by " . ($this->taxonomies[$slug]['app'] ?: 'another app') . '.');
        }

        $label = trim((string) ($args['label'] ?? '')) ?: ucwords(str_replace(['-', '_'], ' ', $slug)) . 's';
        $public = (bool) ($args['public'] ?? true);
        $prefix = '';
        if ($public && ($args['rewrite'] ?? null) !== false) {
            $prefix = strtolower(trim(is_string($args['rewrite'] ?? null) && $args['rewrite'] !== '' ? $args['rewrite'] : $slug, '/ '));
            $prefix = (string) preg_replace('/[^a-z0-9_-]/', '', $prefix);
            if ($prefix === '' || in_array($prefix, PostTypeRegistry::RESERVED_PREFIXES, true) || $this->types->byPrefix($prefix) !== null) {
                $this->rejected[] = ['slug' => $slug, 'app' => $app, 'reason' => "the URL prefix '{$prefix}' is taken or reserved; its terms have no public pages."];
                $prefix = '';
            }
            foreach ($this->taxonomies as $other) {
                if ($other['slug'] !== $slug && $other['rewrite'] === $prefix && $prefix !== '') { $prefix = ''; break; }
            }
        }

        $this->taxonomies[$slug] = [
            'slug'         => $slug,
            'label'        => $label,
            'singular'     => trim((string) ($args['singular'] ?? '')) ?: rtrim($label, 's'),
            'description'  => (string) ($args['description'] ?? ''),
            'hierarchical' => (bool) ($args['hierarchical'] ?? false),
            'post_types'   => array_values(array_unique(array_filter(array_map('strval', (array) ($args['post_types'] ?? []))))),
            'public'       => $public,
            'rewrite'      => $prefix,
            'app'          => $app,
            'built_in'     => false,
        ];
        $this->store($this->taxonomies[$slug]);
        $this->hookMenu();
        return true;
    }

    /** Remove every taxonomy an app registered. */
    public function forgetOwner(string $app): int
    {
        $n = 0;
        foreach ($this->taxonomies as $slug => $t) {
            if ($app !== '' && $t['app'] === $app) { unset($this->taxonomies[$slug]); $n++; }
        }
        return $n;
    }

    /** App-registered taxonomies. */
    public function custom(): array
    {
        return $this->taxonomies;
    }

    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /** Core's and apps' taxonomies, each with every type it applies to. */
    public function all(): array
    {
        $all = $this->builtIn() + $this->taxonomies;
        // A type's own `taxonomies` list attaches it too.
        foreach ($this->types->custom() as $type => $def) {
            foreach ($def['taxonomies'] ?? [] as $tax) {
                if (isset($all[$tax]) && !in_array($type, $all[$tax]['post_types'], true)) $all[$tax]['post_types'][] = $type;
            }
        }
        return $all;
    }

    /** The taxonomies that apply to a content type, in registration order. @return list<array> */
    public function forType(string $type): array
    {
        return array_values(array_filter($this->all(), fn($t) => in_array($type, $t['post_types'], true)));
    }

    public function appliesTo(string $taxonomy, string $type): bool
    {
        $t = $this->get($taxonomy);
        return $t !== null && in_array($type, $t['post_types'], true);
    }

    /** The public URL of a term, or '' when its taxonomy has none. */
    public function termUrl(string $taxonomy, string $termSlug, string $base = ''): string
    {
        if ($taxonomy === 'category') return $base . '/category/' . rawurlencode($termSlug);
        if ($taxonomy === 'tag') return $base . '/tag/' . rawurlencode($termSlug);
        $t = $this->taxonomies[$taxonomy] ?? null;
        return $t !== null && $t['rewrite'] !== '' ? $base . '/' . $t['rewrite'] . '/' . rawurlencode($termSlug) : '';
    }

    public function byPrefix(string $prefix): ?array
    {
        foreach ($this->taxonomies as $t) if ($t['rewrite'] !== '' && $t['rewrite'] === $prefix) return $t;
        return null;
    }

    public function rejected(): array
    {
        return $this->rejected;
    }

    private function builtIn(): array
    {
        $out = [];
        foreach ((array) $this->config->get('cms.taxonomies', []) as $slug => $def) {
            $def = (array) $def;
            $out[(string) $slug] = [
                'slug' => (string) $slug,
                'label' => (string) ($def['label'] ?? ucfirst((string) $slug)),
                'singular' => (string) ($def['singular'] ?? ucfirst((string) $slug)),
                'description' => '',
                'hierarchical' => (bool) ($def['hierarchical'] ?? false),
                'post_types' => array_values(array_map('strval', (array) ($def['post_types'] ?? ['post']))),
                'public' => true,
                'rewrite' => (string) $slug,
                'app' => '',
                'built_in' => true,
            ];
        }
        return $out;
    }

    /**
     * Make sure the taxonomy has its {taxonomies} row, so terms can be added.
     * The set of stored keys is cached, so a request that registers a
     * taxonomy already stored runs no query.
     */
    private function store(array $t): void
    {
        if ($this->db === null) return;
        $sig = md5($t['label'] . '|' . $t['singular'] . '|' . (int) $t['hierarchical'] . '|' . implode(',', $t['post_types']));
        $key = 'taxonomies.stored-' . $this->db->identity();
        try {
            $known = $this->cache?->get($key, []) ?? [];
            if (is_array($known) && ($known[$t['slug']] ?? null) === $sig) return;
            $exists = $this->db->selectOne('SELECT id FROM {taxonomies} WHERE slug = :s', ['s' => $t['slug']]);
            $values = ['label' => $t['label'], 'singular' => $t['singular'], 'hierarchical' => $t['hierarchical'] ? 1 : 0,
                       'post_types' => json_encode($t['post_types'])];
            if ($exists) {
                $this->db->execute('UPDATE {taxonomies} SET label = :label, singular = :singular, hierarchical = :hierarchical, post_types = :post_types WHERE slug = :slug',
                    $values + ['slug' => $t['slug']]);
            } else {
                $this->db->execute('INSERT IGNORE INTO {taxonomies} (slug, label, singular, hierarchical, show_in_api, post_types) VALUES (:slug, :label, :singular, :hierarchical, 1, :post_types)',
                    $values + ['slug' => $t['slug']]);
            }
            $known = is_array($known) ? $known : [];
            $known[$t['slug']] = $sig;
            $this->cache?->set($key, $known, 86400);
        } catch (\Throwable) {
            // The taxonomy still applies this request; its row is tried again next time.
        }
    }

    /** Sidebar: a custom taxonomy's terms under each content type it applies to. */
    private function hookMenu(): void
    {
        if ($this->menuHooked || $this->hooks === null) return;
        $this->menuHooked = true;
        $prev = $this->hooks->setOwner(null);
        $this->hooks->addFilter('admin.menu', function (array $items): array {
            foreach ($items as &$item) {
                $url = (string) ($item['url'] ?? '');
                $type = '';
                if ($url === '/admin/posts') $type = 'post';
                elseif ($url === '/admin/pages') $type = 'page';
                elseif (str_starts_with($url, '/admin/content/')) $type = substr($url, 15);
                if ($type === '') continue;
                foreach ($this->taxonomies as $t) {
                    if (!in_array($type, $this->all()[$t['slug']]['post_types'] ?? [], true)) continue;
                    $item['children'] ??= [['url' => $url, 'label' => 'All ' . $item['label'], 'cap' => $item['cap'] ?? null]];
                    $item['children'][] = ['url' => '/admin/taxonomies/' . $t['slug'], 'label' => $t['label'], 'cap' => 'manage_taxonomies'];
                }
            }
            unset($item);
            return $items;
        }, 20);
        $this->hooks->setOwner($prev);
    }
}
