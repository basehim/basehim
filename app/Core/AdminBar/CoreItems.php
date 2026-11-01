<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

use App\Core\Application;
use App\Core\Database;
use App\Core\Time;
use App\Http\Middleware\AdminAreaPolicy;
use App\Http\Middleware\CheckCapability;
use App\Services\PostEditorService;
use App\Services\PostTypeRegistry;
use App\Services\TaxonomyRegistry;

/**
 * Core's own admin bar sections (1.2.96), registered through the same API
 * as apps' components.
 *
 * Every link goes to a screen that exists, and is shown only to users that
 * screen lets in: admin paths are checked with AdminAreaPolicy (the rules the
 * admin itself applies, including a site's admin.area_policy filter), posts
 * with PostEditorService::canEdit() (own or others', the type's own
 * capabilities). Nothing here offers what Basehim can't do yet (revisions,
 * duplicating a post): see docs/ADMIN-BAR.md, Limits.
 */
final class CoreItems
{
    private Application $app;
    private AdminBar $bar;
    private AdminBarContext $ctx;
    private array $user;
    private ?array $post = null;
    private ?array $term = null;

    public static function register(AdminBar $bar, Application $app, AdminBarContext $ctx, array $user): void
    {
        $self = new self();
        $self->app = $app;
        $self->bar = $bar;
        $self->ctx = $ctx;
        $self->user = $user;
        $self->load();
        $self->site();
        $self->context();
        $self->create();
        $self->end();
        $self->notifications();
        $self->search();
    }

    /** May this user open this admin path (the admin's own rules)? */
    private function area(string $path): bool
    {
        try {
            $cap = AdminAreaPolicy::requiredFor($path);
        } catch (\Throwable) {
            return false;
        }
        return $cap === null || CheckCapability::userCan($this->user, $cap);
    }

    private function can(string $cap): bool
    {
        return CheckCapability::userCan($this->user, $cap);
    }

    private function db(): Database
    {
        return $this->app->make(Database::class);
    }

    private function types(): PostTypeRegistry
    {
        return $this->app->make(PostTypeRegistry::class);
    }

    private function editor(): PostEditorService
    {
        return $this->app->make(PostEditorService::class);
    }

    /** The post or term the page shows, read again (never trusted from the page). */
    private function load(): void
    {
        try {
            if ($this->ctx->contentId() > 0) {
                $p = $this->app->make(\App\Repositories\PostRepository::class)->find($this->ctx->contentId());
                if ($p && empty($p['deleted_at'])) $this->post = $p;
            }
            if ($this->ctx->termId() > 0) {
                $t = $this->db()->selectOne('SELECT t.*, x.slug AS taxonomy FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id WHERE t.id = :id',
                    ['id' => $this->ctx->termId()]);
                if ($t) $this->term = $t;
            }
        } catch (\Throwable) {}
    }

    private function typeDef(string $type): array
    {
        $all = $this->types()->all();
        $d = $all[$type] ?? [];
        $label = (string) ($d['label'] ?? ucfirst($type) . 's');
        return ['slug' => $type, 'label' => $label, 'singular' => (string) ($d['singular'] ?? rtrim($label, 's')),
                'public' => (bool) ($d['public'] ?? true)] + $d;
    }

    private function editUrl(array $post): string
    {
        return $this->types()->adminUrl((string) $post['type']) . '/' . (int) $post['id'] . '/edit';
    }

    private function quickEditTable(string $type): string
    {
        return match ($type) {
            'post' => 'content.posts',
            'page' => 'content.pages',
            default => \App\Services\Table\TypeTableProvider::keyFor($type),
        };
    }

    // ------------------------------------------------------------------
    // Site menu
    // ------------------------------------------------------------------

    private function site(): void
    {
        $title = 'Basehim';
        try { $title = (string) ($this->app->make(\App\Services\SettingService::class)->get('general', 'site_title', '') ?: 'Basehim'); } catch (\Throwable) {}
        $this->bar->menu(['id' => 'site', 'label' => $title, 'icon' => 'home', 'title' => 'Site: dashboard and management',
            'href' => '/admin/dashboard', 'priority' => 0, 'attrs' => ['brand' => true]]);
        $links = [
            ['dashboard', 'Dashboard', 'chart-bar', '/admin/dashboard', ''],
            ['posts', 'Posts', 'newspaper', '/admin/posts', 'Content'],
            ['pages', 'Pages', 'document-text', '/admin/pages', 'Content'],
            ['media', 'Media', 'photo', '/admin/media', 'Content'],
            ['comments', 'Comments', 'chat-bubble-left-right', '/admin/comments', 'Content'],
            ['menus', 'Menus', 'bars-3', '/admin/menus', 'Appearance'],
            ['widgets', 'Widgets', 'squares-2x2', '/admin/widgets', 'Appearance'],
            ['customize', 'Customize', 'paint-brush', '/admin/customize', 'Appearance'],
            ['themes', 'Themes', 'swatch', '/admin/themes', 'Appearance'],
            ['apps', 'Apps', 'puzzle-piece', '/admin/apps', 'System'],
            ['users', 'Users', 'users', '/admin/users', 'System'],
            ['settings', 'Settings', 'cog-6-tooth', '/admin/settings/general', 'System'],
            ['updates', 'Updates', 'arrow-path', '/admin/updates', 'System'],
            ['system', 'System', 'heart', '/admin/system', 'System'],
        ];
        $p = 10;
        foreach ($links as [$id, $label, $icon, $href, $group]) {
            $p += 10;
            if (!$this->area($href)) continue;
            $this->bar->item(['id' => 'site.' . $id, 'parent' => 'site', 'label' => $label, 'icon' => $icon, 'href' => $href,
                'group' => $group, 'priority' => $p]);
        }
        $this->bar->indicator(['id' => 'site.comments.pending', 'parent' => 'site.comments', 'type' => 'count',
            'label' => 'awaiting moderation', 'capability' => 'moderate_comments',
            'value' => fn() => $this->app->make(\App\Services\CommentService::class)->pendingCount()]);
        $this->bar->indicator(['id' => 'site.updates.count', 'parent' => 'site.updates', 'type' => 'count', 'tone' => 'info',
            'label' => 'updates available',
            'value' => fn() => $this->app->make(\App\Services\UpdateService::class)->badgeCount()]);
    }

    // ------------------------------------------------------------------
    // The page's own menu
    // ------------------------------------------------------------------

    private function context(): void
    {
        match ($this->ctx->type()) {
            'single'  => $this->single(),
            'archive' => $this->archive(),
            'search'  => $this->searchPage(),
            'home'    => $this->home(null),
            '404'     => $this->notFound(),
            'app'     => $this->appPage(),
            default   => $this->routePage(),
        };
    }

    private function single(): void
    {
        $post = $this->post;
        if ($post === null) return;
        $type = (string) $post['type'];
        $def = $this->typeDef($type);
        $editor = $this->editor();
        $canEdit = $editor->canEdit($this->user, $post);
        $noun = $def['singular'];

        $this->bar->menu(['id' => 'context', 'label' => $canEdit ? 'Edit ' . strtolower($noun) : $noun,
            'title' => $canEdit ? 'Edit this ' . strtolower($noun) : 'About this ' . strtolower($noun),
            'icon' => $canEdit ? 'pencil-square' : 'document-text', 'priority' => 10,
            'href' => $canEdit ? $this->editUrl($post) : null]);

        if ($canEdit) {
            $this->bar->item(['id' => 'context.edit', 'parent' => 'context', 'label' => 'Edit in the editor', 'icon' => 'pencil-square',
                'href' => $this->editUrl($post), 'priority' => 10]);
            $this->bar->item(['id' => 'context.quick', 'parent' => 'context', 'label' => 'Quick edit', 'icon' => 'bolt',
                'meta' => 'Title, address, status', 'action' => 'quick-edit', 'priority' => 20,
                'attrs' => ['table' => $this->quickEditTable($type), 'id' => (int) $post['id']]]);
        }
        $this->bar->panel(['id' => 'context.details', 'parent' => 'context', 'label' => 'Details', 'priority' => 5,
            'data' => fn() => $this->postDetails($post, $canEdit)]);

        if ($canEdit && $editor->supports($type, 'thumbnail')) {
            $this->bar->item(['id' => 'context.image', 'parent' => 'context', 'label' => empty($post['featured_media_id']) ? 'Set featured image' : 'Change featured image',
                'icon' => 'photo', 'href' => $this->editUrl($post), 'meta' => 'In the editor\'s sidebar', 'priority' => 30]);
        }
        if ($this->can('manage_taxonomies')) {
            $p = 40;
            foreach ($this->app->make(TaxonomyRegistry::class)->forType($type) as $tax) {
                $this->bar->item(['id' => 'context.tax-' . preg_replace('/[^a-z0-9_-]/', '', $tax['slug']), 'parent' => 'context',
                    'label' => 'Manage ' . strtolower($tax['label']), 'icon' => 'tag', 'href' => '/admin/taxonomies/' . rawurlencode($tax['slug']),
                    'group' => 'Organise', 'priority' => $p++]);
            }
        }
        if ($editor->supports($type, 'comments') && $this->area('/admin/comments')) {
            $this->bar->item(['id' => 'context.comments', 'parent' => 'context', 'label' => 'Comments', 'icon' => 'chat-bubble-left-right',
                'href' => '/admin/comments?status=pending', 'group' => 'Organise', 'priority' => 60]);
            $id = (int) $post['id'];
            $this->bar->indicator(['id' => 'context.comments.pending', 'parent' => 'context.comments', 'type' => 'count',
                'label' => 'awaiting moderation on this page',
                'value' => fn() => (int) ($this->db()->selectOne("SELECT COUNT(*) AS c FROM {comments} WHERE post_id = :id AND status = 'pending'", ['id' => $id])['c'] ?? 0)]);
        }
        if ($editor->canUseArea($this->user, $type)) {
            $this->bar->item(['id' => 'context.all', 'parent' => 'context', 'label' => 'All ' . strtolower($def['label']), 'icon' => 'queue-list',
                'href' => $this->types()->adminUrl($type), 'group' => 'More', 'priority' => 80]);
            $this->bar->item(['id' => 'context.new', 'parent' => 'context', 'label' => 'New ' . strtolower($noun), 'icon' => 'plus',
                'href' => $this->types()->adminUrl($type) . '/create', 'group' => 'More', 'priority' => 81]);
        }
        if ($this->ctx->isHome()) $this->home('context');
    }

    private function postDetails(array $post, bool $canEdit): array
    {
        $rows = [];
        $status = (string) $post['status'];
        $tone = match ($status) { 'published' => 'success', 'scheduled' => 'info', 'draft', 'pending' => 'warning', default => '' };
        $rows[] = ['label' => 'Status', 'value' => ucfirst($status === 'pending' ? 'pending review' : $status), 'tone' => $tone];
        try {
            $author = $this->db()->selectOne('SELECT display_name, username FROM {users} WHERE id = :id', ['id' => (int) $post['author_id']]);
            if ($author) $rows[] = ['label' => 'Author', 'value' => (string) (($author['display_name'] ?? '') !== '' ? $author['display_name'] : $author['username'])];
        } catch (\Throwable) {}
        if (!empty($post['published_at'])) $rows[] = ['label' => $status === 'scheduled' ? 'Goes live' : 'Published', 'value' => Time::format($post['published_at'])];
        if (!empty($post['updated_at'])) $rows[] = ['label' => 'Last changed', 'value' => Time::ago($post['updated_at'], 30)];
        $rows[] = ['label' => 'Views', 'value' => number_format((int) ($post['view_count'] ?? 0))];
        try {
            $c = $this->db()->select('SELECT status, COUNT(*) AS c FROM {comments} WHERE post_id = :id GROUP BY status', ['id' => (int) $post['id']]);
            $by = array_column($c, 'c', 'status');
            if (($by['approved'] ?? 0) || ($by['pending'] ?? 0)) {
                $rows[] = ['label' => 'Comments', 'value' => number_format((int) ($by['approved'] ?? 0))
                    . (($by['pending'] ?? 0) ? ' (' . (int) $by['pending'] . ' waiting)' : ''), 'tone' => ($by['pending'] ?? 0) ? 'warning' : ''];
            }
        } catch (\Throwable) {}
        $text = \App\Core\Search\TextExtractor::fromContent((string) ($post['content'] ?? ''), (string) ($post['content_format'] ?? 'html'));
        $words = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text);
        $rows[] = ['label' => 'Words', 'value' => number_format((int) $words) . ' · ' . max(1, (int) ceil($words / 230)) . ' min read'];
        if ($canEdit) {
            $path = $post['status'] === 'published' ? \App\Core\Helpers::postUrl($post) : '';
            $rows[] = ['label' => 'Address', 'value' => rawurldecode($path !== '' ? $path : (string) $post['slug'])];
            $rows[] = ['label' => 'ID', 'value' => (string) (int) $post['id']];
        }
        return ['rows' => $rows];
    }

    private function archive(): void
    {
        $kind = $this->ctx->archiveKind();
        if ($kind === 'taxonomy' && $this->term !== null) {
            $tax = (string) $this->term['taxonomy'];
            $def = $this->app->make(TaxonomyRegistry::class)->get($tax);
            $singular = (string) ($def['singular'] ?? ucfirst($tax));
            $plural = (string) ($def['label'] ?? ucfirst($tax) . 's');
            $manage = $this->can('manage_taxonomies') && $this->area('/admin/taxonomies/' . $tax);
            $editHref = '/admin/taxonomies/' . rawurlencode($tax) . '?edit=' . (int) $this->term['id'];
            $this->bar->menu(['id' => 'context', 'label' => $manage ? 'Edit ' . strtolower($singular) : $singular, 'icon' => 'tag',
                'href' => $manage ? $editHref : null, 'priority' => 10, 'title' => $singular . ': ' . $this->term['name']]);
            if ($manage) {
                $this->bar->item(['id' => 'context.edit', 'parent' => 'context', 'label' => 'Edit ' . strtolower($singular), 'icon' => 'pencil-square',
                    'href' => $editHref, 'priority' => 10]);
                $this->bar->item(['id' => 'context.all', 'parent' => 'context', 'label' => 'All ' . strtolower($plural), 'icon' => 'tag',
                    'href' => '/admin/taxonomies/' . rawurlencode($tax), 'priority' => 20]);
            }
            if (in_array($tax, ['category', 'tag'], true) && $this->area('/admin/posts')) {
                $this->bar->item(['id' => 'context.posts', 'parent' => 'context', 'label' => 'Its posts in the admin', 'icon' => 'queue-list',
                    'href' => '/admin/posts?' . $tax . '=' . (int) $this->term['id'], 'priority' => 30]);
            }
            $term = $this->term;
            $this->bar->panel(['id' => 'context.details', 'parent' => 'context', 'label' => 'Details', 'priority' => 5,
                'data' => fn() => ['rows' => array_values(array_filter([
                    ['label' => $singular, 'value' => (string) $term['name']],
                    ['label' => 'Items', 'value' => number_format((int) ($this->db()->selectOne('SELECT COUNT(*) AS c FROM {post_term} WHERE term_id = :t', ['t' => (int) $term['id']])['c'] ?? 0))],
                    ['label' => 'Address', 'value' => (string) rawurldecode((string) $term['slug'])],
                    trim((string) ($term['description'] ?? '')) !== '' ? ['label' => 'Description', 'value' => strip_tags((string) $term['description'])] : null,
                ]))]]);
            return;
        }
        if ($kind === 'post_type' && $this->ctx->contentType() !== '') {
            $type = $this->ctx->contentType();
            $def = $this->typeDef($type);
            if (!$this->editor()->canUseArea($this->user, $type)) return;
            $this->bar->menu(['id' => 'context', 'label' => $def['label'], 'icon' => (string) ($def['icon'] ?? 'queue-list') ?: 'queue-list',
                'href' => $this->types()->adminUrl($type), 'priority' => 10]);
            $this->bar->item(['id' => 'context.all', 'parent' => 'context', 'label' => 'All ' . strtolower($def['label']), 'icon' => 'queue-list',
                'href' => $this->types()->adminUrl($type), 'priority' => 10]);
            $this->bar->item(['id' => 'context.new', 'parent' => 'context', 'label' => 'New ' . strtolower($def['singular']), 'icon' => 'plus',
                'href' => $this->types()->adminUrl($type) . '/create', 'priority' => 20]);
            return;
        }
        if ($kind === 'author' && $this->ctx->authorId() > 0) {
            $own = $this->ctx->authorId() === (int) $this->user['id'];
            if ($own) {
                $this->bar->menu(['id' => 'context', 'label' => 'Edit profile', 'icon' => 'user-circle', 'href' => '/admin/profile', 'priority' => 10]);
            } elseif ($this->area('/admin/users')) {
                $this->bar->menu(['id' => 'context', 'label' => 'Edit user', 'icon' => 'user-circle',
                    'href' => '/admin/users/' . $this->ctx->authorId() . '/edit', 'priority' => 10]);
            }
        }
    }

    private function searchPage(): void
    {
        $q = $this->ctx->search();
        $total = (int) $this->ctx->get('total', 0);
        $items = [];
        if ($this->area('/admin/posts')) $items[] = ['posts', 'Search posts in the admin', '/admin/posts?q='];
        if ($this->area('/admin/pages')) $items[] = ['pages', 'Search pages in the admin', '/admin/pages?q='];
        if ($this->area('/admin/settings/search')) $items[] = ['settings', 'Search settings', '/admin/settings/search'];
        $this->bar->menu(['id' => 'context', 'label' => 'Search', 'icon' => 'magnifying-glass', 'priority' => 10, 'title' => 'This search']);
        $this->bar->panel(['id' => 'context.details', 'parent' => 'context', 'label' => 'This search', 'priority' => 5,
            'data' => fn() => $q === '' ? ['empty' => 'Nothing was searched for.'] : ['rows' => [
                ['label' => 'Searched for', 'value' => $q], ['label' => 'Results', 'value' => number_format($total), 'tone' => $total === 0 ? 'warning' : ''],
            ]]]);
        $p = 10;
        foreach ($items as [$id, $label, $href]) {
            $this->bar->item(['id' => 'context.' . $id, 'parent' => 'context', 'label' => $label, 'icon' => 'magnifying-glass',
                'href' => str_ends_with($href, '=') ? $href . rawurlencode($q) : $href, 'priority' => $p += 10]);
        }
    }

    /** The home page's shortcuts; under the page's own menu when the home page is a page. */
    private function home(?string $parent): void
    {
        if ($parent === null) {
            $this->bar->menu(['id' => 'context', 'label' => 'Home page', 'icon' => 'home', 'priority' => 10, 'title' => 'Shortcuts for the home page']);
            $parent = 'context';
        }
        $links = [
            ['customize', 'Customize the theme', 'paint-brush', '/admin/customize'],
            ['reading', 'What the home page shows', 'book-open', '/admin/settings/reading'],
            ['menus', 'Menus', 'bars-3', '/admin/menus'],
            ['widgets', 'Widgets', 'squares-2x2', '/admin/widgets'],
        ];
        $p = 90;
        foreach ($links as [$id, $label, $icon, $href]) {
            if (!$this->area($href)) continue;
            $this->bar->item(['id' => 'context.home-' . $id, 'parent' => $parent, 'label' => $label, 'icon' => $icon, 'href' => $href,
                'group' => 'Home page', 'priority' => $p++]);
        }
    }

    private function notFound(): void
    {
        $path = $this->ctx->path();
        $route = $this->ctx->route();
        $this->bar->menu(['id' => 'context', 'label' => 'Not found', 'icon' => 'exclamation-triangle', 'priority' => 10, 'title' => 'About this missing page']);
        $this->bar->panel(['id' => 'context.details', 'parent' => 'context', 'label' => 'Why this page is missing', 'priority' => 5,
            'data' => function () use ($path, $route) {
                $slug = trim(rawurldecode($path), '/');
                $rows = [['label' => 'Address', 'value' => $path], ['label' => 'Answer', 'value' => '404 Not Found', 'tone' => 'warning']];
                if ($route !== '') $rows[] = ['label' => 'Matched route', 'value' => $route];
                $found = null;
                if ($slug !== '' && !str_contains($slug, '/')) {
                    try {
                        $found = $this->db()->selectOne('SELECT id, type, status, deleted_at FROM {posts} WHERE slug = :s ORDER BY deleted_at IS NULL DESC LIMIT 1', ['s' => rawurlencode($slug)])
                              ?: $this->db()->selectOne('SELECT id, type, status, deleted_at FROM {posts} WHERE slug = :s ORDER BY deleted_at IS NULL DESC LIMIT 1', ['s' => $slug]);
                    } catch (\Throwable) {}
                }
                $text = 'No published content has this address.';
                $actions = [];
                if ($found) {
                    $state = !empty($found['deleted_at']) ? 'in the trash' : (string) $found['status'];
                    $rows[] = ['label' => 'Content with this address', 'value' => ucfirst((string) $found['type']) . ' #' . (int) $found['id'] . ', ' . $state, 'tone' => 'warning'];
                    $text = 'Something has this address but isn\'t published, so visitors get this page.';
                    $p = $this->app->make(\App\Repositories\PostRepository::class)->find((int) $found['id']);
                    if ($p && empty($p['deleted_at']) && $this->editor()->canEdit($this->user, $p)) {
                        $actions[] = ['label' => 'Open it in the editor', 'url' => $this->editUrl($p)];
                    }
                }
                if ($this->area('/admin/pages')) $actions[] = ['label' => 'Create a page', 'url' => '/admin/pages/create'];
                return ['text' => $text, 'rows' => $rows, 'actions' => $actions];
            }]);
    }

    private function appPage(): void
    {
        $slug = $this->ctx->app();
        if ($slug === '') return;
        $name = $slug;
        try {
            $apps = $this->app->make(\App\Services\AppService::class);
            if ($apps->isBooted($slug)) {
                $inst = $apps->instance($this->app, $slug);
                if ($inst && method_exists($inst, 'manifest')) $name = (string) (($inst->manifest()['name'] ?? '') ?: $slug);
            }
        } catch (\Throwable) {}
        $url = '';
        try { $url = (string) ($this->app->make(\App\Services\AccessControl::class)->appMenuUrls()[$slug] ?? ''); } catch (\Throwable) {}
        $canOpen = $url !== '' && $this->area($url);
        $canManage = $this->area('/admin/apps');
        if (!$canOpen && !$canManage) return;
        $this->bar->menu(['id' => 'context', 'label' => $name, 'icon' => 'puzzle-piece', 'priority' => 10, 'title' => 'This page belongs to ' . $name,
            'href' => $canOpen ? $url : null]);
        if ($canOpen) $this->bar->item(['id' => 'context.app', 'parent' => 'context', 'label' => 'Open ' . $name, 'icon' => 'puzzle-piece', 'href' => $url, 'priority' => 10]);
        if ($canManage) {
            $this->bar->item(['id' => 'context.app-logs', 'parent' => 'context', 'label' => 'Logs', 'icon' => 'document-text',
                'href' => '/admin/apps/' . rawurlencode($slug) . '/logs', 'priority' => 20]);
        }
    }

    private function routePage(): void
    {
        if ($this->ctx->app() !== '') $this->appPage();
    }

    // ------------------------------------------------------------------
    // New
    // ------------------------------------------------------------------

    private function create(): void
    {
        $this->bar->menu(['id' => 'new', 'label' => 'New', 'icon' => 'plus', 'priority' => 20, 'title' => 'Create something new']);
        $p = 10;
        foreach ($this->types()->all() as $slug => $def) {
            $slug = (string) $slug;
            if ($slug === 'template' || (isset($def['menu']) && $def['menu'] === false && !in_array($slug, ['post', 'page'], true))) continue;
            if (!$this->editor()->canUseArea($this->user, $slug) || !$this->can($this->editor()->capability($slug, 'edit'))) continue;
            $d = $this->typeDef($slug);
            $this->bar->item(['id' => 'new.' . preg_replace('/[^a-z0-9_-]/', '', $slug), 'parent' => 'new', 'label' => $d['singular'],
                'icon' => match ($slug) { 'post' => 'newspaper', 'page' => 'document-text', default => (string) ($def['icon'] ?? 'document-plus') ?: 'document-plus' },
                'href' => $this->types()->adminUrl($slug) . '/create', 'priority' => $p++]);
        }
        if ($this->can('manage_taxonomies') && $this->area('/admin/taxonomies/category')) {
            $this->bar->item(['id' => 'new.category', 'parent' => 'new', 'label' => 'Category', 'icon' => 'tag', 'href' => '/admin/taxonomies/category',
                'priority' => 60]);
        }
        if ($this->area('/admin/media') && $this->can('upload_media')) {
            $this->bar->item(['id' => 'new.media', 'parent' => 'new', 'label' => 'Media', 'icon' => 'arrow-up-tray', 'href' => '/admin/media', 'priority' => 70]);
        }
        if ($this->area('/admin/users')) {
            $this->bar->item(['id' => 'new.user', 'parent' => 'new', 'label' => 'User', 'icon' => 'user-plus', 'href' => '/admin/users/create', 'priority' => 80]);
        }
    }

    // ------------------------------------------------------------------
    // Search, notifications, display and the user
    // ------------------------------------------------------------------

    private function end(): void
    {
        $this->bar->menu(['id' => 'search', 'label' => 'Search', 'icon' => 'magnifying-glass', 'region' => 'end', 'priority' => 10,
            'title' => 'Search the site\'s content', 'action' => 'search', 'optional' => true]);
        $this->bar->menu(['id' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'region' => 'end', 'priority' => 20,
            'action' => 'notifications', 'optional' => true]);
        $this->bar->menu(['id' => 'display', 'label' => 'Display', 'icon' => 'adjustments-horizontal', 'region' => 'end', 'priority' => 30,
            'title' => 'How the admin bar is shown', 'action' => 'display']);

        $name = (string) (($this->user['display_name'] ?? '') !== '' ? $this->user['display_name'] : ($this->user['username'] ?? 'Account'));
        $this->bar->menu(['id' => 'user', 'label' => $name, 'icon' => 'user-circle', 'region' => 'end', 'priority' => 40,
            'title' => 'Your account', 'href' => '/admin/profile', 'attrs' => ['avatar' => true]]);
        $this->bar->item(['id' => 'user.profile', 'parent' => 'user', 'label' => 'Profile', 'icon' => 'user-circle', 'href' => '/admin/profile', 'priority' => 10]);
        $this->bar->item(['id' => 'user.security', 'parent' => 'user', 'label' => 'Sign-in and security', 'icon' => 'shield-check', 'href' => '/admin/profile#two-factor', 'priority' => 20]);
        $this->bar->item(['id' => 'user.dashboard', 'parent' => 'user', 'label' => 'Dashboard', 'icon' => 'chart-bar', 'href' => '/admin/dashboard', 'priority' => 30]);
        $this->bar->item(['id' => 'user.logout', 'parent' => 'user', 'label' => 'Sign out', 'icon' => 'arrow-right-start-on-rectangle',
            'href' => '/admin/logout', 'action' => 'logout', 'priority' => 90]);
    }

    private function notifications(): void
    {
        if ($this->area('/admin/updates')) {
            $this->bar->notifications(['id' => 'core.updates', 'label' => 'Updates', 'priority' => 10, 'items' => function () {
                $u = $this->app->make(\App\Services\UpdateService::class);
                $out = [];
                foreach (array_slice($u->cachedUpdates(), 0, 1) as $up) {
                    $out[] = ['id' => 'core', 'title' => 'Basehim ' . (string) ($up['version'] ?? '') . ' is available', 'text' => 'You have ' . BASEHIM_VERSION . '.',
                              'tone' => 'info', 'url' => '/admin/updates'];
                }
                $apps = $u->cachedAppUpdates();
                if ($apps) {
                    $out[] = ['id' => 'apps', 'title' => count($apps) === 1 ? 'An app update is available' : count($apps) . ' app updates are available',
                              'text' => implode(', ', array_slice(array_map(fn($a) => (string) ($a['name'] ?? $a['slug'] ?? ''), $apps), 0, 4)),
                              'tone' => 'info', 'url' => '/admin/updates'];
                }
                return $out;
            }]);
        }
        if ($this->can('moderate_comments') && $this->area('/admin/comments')) {
            $this->bar->notifications(['id' => 'core.comments', 'label' => 'Comments', 'priority' => 20, 'items' => function () {
                $n = $this->app->make(\App\Services\CommentService::class)->pendingCount();
                return $n > 0 ? [['id' => 'pending', 'title' => $n === 1 ? 'A comment is waiting for approval' : $n . ' comments are waiting for approval',
                                 'tone' => 'warning', 'url' => '/admin/comments?status=pending']] : [];
            }]);
        }
        if ($this->area('/admin/system')) {
            $this->bar->notifications(['id' => 'core.tasks', 'label' => 'Scheduled tasks', 'priority' => 30, 'items' => function () {
                $failing = $this->app->make(\App\Services\SchedulerService::class)->failingTasks();
                if (!$failing) return [];
                $names = array_slice(array_map(fn($t) => (string) (($t['app_slug'] ?? '') !== '' ? $t['app_slug'] . ': ' : '') . (string) ($t['task_key'] ?? $t['key'] ?? ''), $failing), 0, 3);
                return [['id' => 'failing', 'title' => count($failing) === 1 ? 'A scheduled task is failing' : count($failing) . ' scheduled tasks are failing',
                         'text' => implode(', ', array_filter($names)), 'tone' => 'danger', 'url' => '/admin/system']];
            }]);
            $this->bar->notifications(['id' => 'core.security', 'label' => 'Security', 'priority' => 5, 'items' => function () {
                $out = [];
                $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
                $local = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
                if (!$local && filter_var(\App\Core\Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                    $out[] = ['id' => 'debug', 'title' => 'Debug mode is on', 'text' => 'Error details are shown to visitors. Set APP_DEBUG=false in .env.', 'tone' => 'danger', 'url' => '/admin/system'];
                }
                if (!$local && !\App\Core\Request::serverIsSecure()) {
                    $out[] = ['id' => 'https', 'title' => 'The site is not using HTTPS', 'text' => 'Passwords and sessions travel unencrypted.', 'tone' => 'warning', 'url' => '/admin/settings/security'];
                }
                return $out;
            }]);
        }
    }

    private function search(): void
    {
        $limit = 5;
        // Content: every type this user may edit, through BH Search (fast at any size).
        $types = [];
        foreach (array_keys($this->types()->all()) as $t) {
            if ($t !== 'template' && $this->editor()->canUseArea($this->user, (string) $t)) $types[] = (string) $t;
        }
        if ($types) {
            $this->bar->searchProvider(['id' => 'core.content', 'label' => 'Content', 'priority' => 10, 'icon' => 'document-text',
                'search' => function (string $q, array $user, int $limit) use ($types) {
                    $r = $this->app->make(\App\Core\Search\SearchManager::class)->query($q, [
                        'sources' => ['content'], 'source_types' => ['content' => $types], 'per_page' => min(30, $limit * 3),
                    ]);
                    $out = [];
                    foreach ($r['items'] as $it) {
                        $post = is_array($it['item'] ?? null) ? $it['item'] : null;
                        if ($post === null || !empty($post['deleted_at']) || ($post['status'] ?? '') === 'trash') continue;
                        if (!$this->editor()->canEdit($user, $post)) continue;
                        $def = $this->typeDef((string) $post['type']);
                        $out[] = ['title' => (string) ($post['title'] ?: '(no title)'), 'url' => $this->editUrl($post),
                                  'meta' => $def['singular'] . ' · ' . ucfirst((string) $post['status']),
                                  'icon' => $post['type'] === 'page' ? 'document-text' : 'newspaper'];
                        if (count($out) >= $limit) break;
                    }
                    return $out;
                }]);
        }
        if ($this->area('/admin/media')) {
            $this->bar->searchProvider(['id' => 'core.media', 'label' => 'Media', 'priority' => 20, 'icon' => 'photo',
                'search' => function (string $q, array $user, int $limit) {
                    $r = $this->app->make(\App\Core\Search\SearchManager::class)->query($q, ['sources' => ['media'], 'per_page' => $limit]);
                    $out = [];
                    foreach ($r['items'] as $it) {
                        if (($it['status'] ?? '') === 'trash') continue;
                        $out[] = ['title' => (string) ($it['title'] ?: 'Untitled file'), 'url' => '/admin/media?q=' . rawurlencode((string) $it['title']),
                                  'meta' => ucfirst((string) ($it['type'] ?? 'file')), 'icon' => 'photo'];
                    }
                    return $out;
                }]);
        }
        if ($this->area('/admin/users')) {
            $this->bar->searchProvider(['id' => 'core.users', 'label' => 'Users', 'priority' => 30, 'icon' => 'users',
                'search' => function (string $q, array $user, int $limit) {
                    $r = $this->app->make(\App\Core\Search\SearchManager::class)->query($q, ['sources' => ['users'], 'per_page' => $limit + 3]);
                    $out = [];
                    foreach ($r['items'] as $it) {
                        if (($it['status'] ?? '') === 'deleted') continue;
                        $out[] = ['title' => (string) $it['title'], 'url' => '/admin/users/' . (int) $it['id'] . '/edit', 'meta' => 'User', 'icon' => 'user-circle'];
                        if (count($out) >= $limit) break;
                    }
                    return $out;
                }]);
        }
        if ($this->can('manage_taxonomies')) {
            $this->bar->searchProvider(['id' => 'core.terms', 'label' => 'Categories and tags', 'priority' => 40, 'icon' => 'tag',
                'search' => function (string $q, array $user, int $limit) {
                    $like = '%' . addcslashes($q, '%_\\') . '%';
                    $rows = $this->db()->select('SELECT t.id, t.name, x.slug AS taxonomy, x.singular FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id
                        WHERE t.name LIKE :q ORDER BY t.count DESC, t.name LIMIT ' . (int) $limit, ['q' => $like]);
                    return array_map(fn($r) => ['title' => (string) $r['name'], 'meta' => (string) $r['singular'], 'icon' => 'tag',
                        'url' => '/admin/taxonomies/' . rawurlencode((string) $r['taxonomy']) . '?edit=' . (int) $r['id']], $rows);
                }]);
        }
        unset($limit);
    }
}
