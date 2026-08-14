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

    public function __construct(
        private Config $config,
        private HookRegistry $hooks
    ) {
    }

    /**
     * Register a content type.
     *
     * @param string $slug Lowercase, e.g. "event". Becomes posts.type.
     * @param array  $args label, singular, icon, public, supports, menu,
     *                     capability_type, taxonomies, description
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
            'supports'    => (array) ($args['supports'] ?? ['title', 'content', 'author']),
            'menu'        => $args['menu'] ?? true,
            'taxonomies'  => (array) ($args['taxonomies'] ?? []),
            'app'         => (string) ($args['app'] ?? ''),

            // Which capability family governs this type. Custom types map to
            // `post` by default, because no role in any existing install holds
            // edit_events / publish_events — a type demanding its own
            // capabilities would be invisible to every user on the site.
            'capability_type' => (string) ($args['capability_type'] ?? 'post'),
        ];

        $this->hookMenu();
        return true;
    }

    /** Remove a registration (used when an app is deactivated mid-request). */
    public function unregister(string $slug): void
    {
        unset($this->types[$this->sanitize($slug)]);
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
        foreach ((array) $this->config->get('cms.post_types', []) as $slug => $def) {
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

        $this->hooks->addFilter('admin.menu', function (array $items): array {
            foreach ($this->types as $slug => $type) {
                if (empty($type['menu'])) continue;

                $items[] = [
                    'url'     => $this->adminUrl($slug),
                    'label'   => $type['label'],
                    'icon'    => $type['icon'],
                    'cap'     => $this->capability($slug, 'edit'),
                    'section' => is_string($type['menu']) ? $type['menu'] : 'content',
                    // Tagging it with the owning app means the per-app access
                    // capability applies here too, rather than a type being a
                    // way around it.
                    'app'     => $type['app'] ?: null,
                ];
            }
            return $items;
        });
    }

    private function sanitize(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_replace('/[^a-z0-9_\-]/', '', $slug) ?? '';
    }
}
