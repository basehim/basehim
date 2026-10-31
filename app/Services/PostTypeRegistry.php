<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HookRegistry;

/**
 * PostTypeRegistry — content types, from config and from {apps}.
 *
 * Basehim ships `post` and `page`, defined in config/cms.php with their own
 * admin controllers and routes. Apps could already store rows of any type via
 * PostsApi::content('event'), but a custom type had no admin UI at all — an app
 * could persist content it had no way to let anyone edit.
 *
 * Registering a type here gives it a sidebar entry and the full list/create/edit
 * /trash admin screens, served by the generic ContentController at
 * /admin/content/{type}. The built-in types keep their dedicated controllers and
 * their existing /admin/posts and /admin/pages URLs, so nothing about them moves.
 *
 * Registration is per-request, from an app's boot(). Nothing is persisted: if an
 * app is deactivated its types simply stop existing, which is the correct
 * behaviour — the rows stay in the database, and reactivating the app brings the
 * screens back.
 */
class PostTypeRegistry
{
    /** slug => normalised definition. */
    private array $types = [];

    /** Set once the admin.menu filter is attached. */
    private bool $menuHooked = false;

    /** URL prefixes a type may not take: core's own first path segments. */
    public const RESERVED_PREFIXES = ['admin', 'api', 'ajax', 'posts', 'page', 'category', 'tag', 'author', 'search',
        'comments', 'feed', 'uploads', 'content', 'mcp', 'oauth', 'install', 'sitemap', 'robots', 'llms', 'ai',
        'csp-report', '.well-known', 'login', 'logout', 'register'];

    /** Registrations refused this request, with the reason. @var list<array{slug:string,app:string,reason:string}> */
    private array $rejected = [];

    public function __construct(
        private Config $config,
        private HookRegistry $hooks
    ) {
    }

    /**
     * Register a content type.
     *
     * @param string $slug Lowercase, e.g. "event". Becomes posts.type.
     * @param array  $args label, singular, icon, public, searchable, supports, menu,
     *                     capability_type, taxonomies, description, and since 1.2.94:
     *                     rewrite     URL prefix: items at /{rewrite}/{slug}, the archive
     *                                 at /{rewrite} (default: the slug plus "s", so
     *                                 "event" → /events/summer-meetup); false for no
     *                                 public URLs (the app serves them itself)
     *                     has_archive whether /{rewrite} lists the published items (default true)
     *                     per_page    items per archive page (default: Settings → Reading)
     *                     comments    accept comments (default: 'comments' in supports)
     *
     * A type with public => false has no URLs, is left out of the sitemap and
     * search, and is only reachable in the admin.
     */
    public function register(string $slug, array $args = []): bool
    {
        $slug = $this->sanitize($slug);
        if ($slug === '') return false;

        // Built-in types own their screens; letting an app redefine `post`
        // would silently change how core content behaves.
        if (in_array($slug, ['post', 'page'], true)) return false;

        $label = (string) ($args['label'] ?? ucfirst($slug) . 's');
        $singular = (string) ($args['singular'] ?? rtrim($label, 's'));

        $this->types[$slug] = [
            'slug'        => $slug,
            'label'       => $label,
            'singular'    => $singular,
            'description' => (string) ($args['description'] ?? ''),
            'icon'        => (string) ($args['icon'] ?? 'document-text'),
            'public'      => (bool) ($args['public'] ?? true),
            // BH Search (1.2.92): published items appear in site search unless false.
            'searchable'  => (bool) ($args['searchable'] ?? ($args['public'] ?? true)),
            // Before 1.2.94 `supports` was stored but the editor showed every
            // panel anyway, so a type that doesn't list it keeps them all.
            'supports'    => (array) ($args['supports'] ?? ['title', 'content', 'excerpt', 'thumbnail', 'author', 'comments']),
            'menu'        => $args['menu'] ?? true,
            'taxonomies'  => (array) ($args['taxonomies'] ?? []),
            'app'         => (string) ($args['app'] ?? ''),

            // Which capability family governs this type. Custom types map to
            // `post` by default, because no role in any existing install holds
            // edit_events / publish_events — a type demanding its own
            // capabilities would be invisible to every user on the site.
            'capability_type' => (string) ($args['capability_type'] ?? 'post'),
            'rewrite'     => '',
            'has_archive' => (bool) ($args['has_archive'] ?? true),
            'per_page'    => max(0, min(100, (int) ($args['per_page'] ?? 0))),
        ];
        $this->types[$slug]['supports'] = array_values(array_unique(array_map('strval', $this->types[$slug]['supports'])));
        $this->types[$slug]['taxonomies'] = array_values(array_unique(array_filter(array_map(
            fn($t) => $this->sanitize((string) $t), $this->types[$slug]['taxonomies']))));

        // The URL prefix (1.2.94).
        if ($this->types[$slug]['public']) {
            $rw = $args['rewrite'] ?? null;
            if ($rw === false) {
                $prefix = '';
            } else {
                $prefix = is_string($rw) && trim($rw) !== '' ? $this->sanitizePrefix($rw)
                    : (str_ends_with($slug, 's') ? $slug : $slug . 's');
                $why = $this->prefixProblem($prefix, $slug);
                if ($why !== null) {
                    $this->rejected[] = ['slug' => $slug, 'app' => (string) ($args['app'] ?? ''), 'reason' => $why];
                    $prefix = '';
                }
            }
            $this->types[$slug]['rewrite'] = $prefix;
        }

        $this->hookMenu();
        return true;
    }

    /** Why a URL prefix can't be used, or null. */
    private function prefixProblem(string $prefix, string $slug): ?string
    {
        if ($prefix === '') return "the URL prefix is empty.";
        if (in_array($prefix, self::RESERVED_PREFIXES, true)) return "the URL prefix '{$prefix}' is one of core's own paths.";
        foreach ($this->types as $other => $def) {
            if ($other !== $slug && ($def['rewrite'] ?? '') === $prefix) return "the URL prefix '{$prefix}' is already used by the '{$other}' type.";
        }
        return null;
    }

    private function sanitizePrefix(string $p): string
    {
        $p = strtolower(trim($p, " /\t\n"));
        return (string) preg_replace('/[^a-z0-9_-]/', '', $p);
    }

    /** The type whose URL prefix is $prefix, or null. */
    public function byPrefix(string $prefix): ?array
    {
        foreach ($this->types as $def) {
            if ($def['rewrite'] !== '' && $def['rewrite'] === $prefix) return $def;
        }
        return null;
    }

    /** Whether items of $slug have public URLs: post, page, or a public registered type with a prefix. */
    public function hasUrls(string $slug): bool
    {
        if ($slug === 'post' || $slug === 'page') return true;
        $def = $this->get($slug);
        return $def !== null && $def['public'] && $def['rewrite'] !== '';
    }

    /** Registrations refused this request (a URL prefix that was taken or reserved). */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** Whether a type has a feature: its `supports` list ('thumbnail', 'excerpt', 'comments', 'seo' …). */
    public function supports(string $slug, string $feature): bool
    {
        $all = $this->all();
        $def = $all[$this->sanitize($slug)] ?? null;
        if ($def === null) return false;
        return in_array($feature, (array) ($def['supports'] ?? []), true);
    }

    /** Remove a registration (used when an app is deactivated mid-request). */
    public function unregister(string $slug): void
    {
        unset($this->types[$this->sanitize($slug)]);
    }

    /** Remove every type an app registered (1.2.71, AppGuard). Returns how many. */
    public function forgetOwner(string $slug): int
    {
        $n = 0;
        foreach ($this->types as $type => $def) {
            if ($slug !== '' && ($def['app'] ?? '') === $slug) {
                unset($this->types[$type]);
                $n++;
            }
        }
        return $n;
    }

    /** App-registered types only. */
    public function custom(): array
    {
        return $this->types;
    }

    /** One type definition, or null. */
    public function get(string $slug): ?array
    {
        return $this->types[$this->sanitize($slug)] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->types[$this->sanitize($slug)]);
    }

    /** Built-in types from config/cms.php, plus registered ones. */
    public function all(): array
    {
        $builtIn = [];
        // config/cms.php names them content_types; until 1.2.94 this read
        // 'cms.post_types', which doesn't exist, so all() had no built-ins.
        foreach ((array) $this->config->get('cms.content_types', $this->config->get('cms.post_types', [])) as $slug => $def) {
            $builtIn[(string) $slug] = array_merge(
                ['slug' => (string) $slug, 'capability_type' => (string) $slug, 'built_in' => true],
                (array) $def
            );
        }
        return array_merge($builtIn, $this->types);
    }

    /** Every type slug a query might legitimately filter on. */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /** Admin base URL for a type. */
    public function adminUrl(string $slug): string
    {
        $slug = $this->sanitize($slug);
        return match ($slug) {
            'post'  => '/admin/posts',
            'page'  => '/admin/pages',
            default => '/admin/content/' . $slug,
        };
    }

    /**
     * Build a capability name for a type, e.g. ('event', 'edit_others') on a
     * type whose capability_type is `post` yields "edit_others_posts".
     */
    public function capability(string $slug, string $action): string
    {
        $type = $this->get($slug);
        $family = $type['capability_type'] ?? 'post';
        return $action . '_' . $family . 's';
    }

    /**
     * Contribute sidebar entries for registered types.
     *
     * Attached to the admin.menu filter rather than edited into the layout, so
     * a type appears in the sidebar with no change to core views. Attached once,
     * on first registration, so a site with no custom types pays nothing.
     */
    private function hookMenu(): void
    {
        if ($this->menuHooked) return;
        $this->menuHooked = true;

        // Core's listener, even when the first type is registered from an
        // app's boot(): it must survive that app being rolled back (1.2.71).
        $prevOwner = $this->hooks->setOwner(null);
        $this->hooks->addFilter('admin.menu', function (array $items): array {
            foreach ($this->types as $slug => $type) {
                if (empty($type['menu'])) continue;

                $url = $this->adminUrl($slug);
                $cap = $this->capability($slug, 'edit');
                $items[] = [
                    'url'     => $url,
                    'label'   => $type['label'],
                    'icon'    => $type['icon'],
                    'cap'     => $cap,
                    // 1.2.94: like Posts, with the taxonomies attached to the
                    // type added by TaxonomyRegistry.
                    'children' => [
                        ['url' => $url, 'label' => 'All ' . $type['label'], 'cap' => $cap],
                        ['url' => $url . '/create', 'label' => 'Add New ' . $type['singular'], 'cap' => $cap],
                    ],
                    'section' => is_string($type['menu']) ? $type['menu'] : 'content',
                    // Tagging it with the owning app means the per-app access
                    // capability applies here too, rather than a type being a
                    // way around it.
                    'app'     => $type['app'] ?: null,
                ];
            }
            return $items;
        });
        $this->hooks->setOwner($prevOwner);
    }

    private function sanitize(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_replace('/[^a-z0-9_\-]/', '', $slug) ?? '';
    }
}
