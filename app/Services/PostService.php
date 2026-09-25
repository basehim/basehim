<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PostRepository;
use App\Core\HookRegistry;
use App\Core\Cache;
use App\Core\Helpers;

class PostService
{
    public function __construct(
        private PostRepository $repo,
        private HookRegistry $hooks,
        private Cache $cache
    ) {}

    public function find(int $id): ?array
    {
        return $this->repo->find($id);
    }

    public function findBySlug(string $slug, ?string $type = null): ?array
    {
        return $this->repo->findBySlug($slug, $type);
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        return $this->repo->paginate($filters, $page, $perPage);
    }

    public function feed(int $page = 1, int $perPage = 10, string $type = 'post'): array
    {
        return $this->repo->publishedFeed($page, $perPage, $type);
    }

    public function create(array $data, int $authorId): int
    {
        $data = $this->hooks->applyFilters('post.before_create', $data);

        $type = $data['type'] ?? 'post';
        $title = trim($data['title'] ?? 'Untitled');
        $slug = !empty($data['slug']) ? Helpers::slug($data['slug']) : Helpers::slug($title);

        // Held from choosing the slug until the row exists, so two saves at
        // once cannot both take the same free slug.
        $locked = $this->repo->lockSlugs();
        try {
        $slug = $this->resolveSlug($slug, $type);

        $payload = [
            'uuid' => Helpers::uuid(),
            'author_id' => $authorId,
            'type' => $type,
            'status' => $data['status'] ?? 'draft',
            'slug' => $slug,
            'title' => $title,
            'content' => $data['content'] ?? '',
            'content_format' => $data['content_format'] ?? 'html',
            'excerpt' => $data['excerpt'] ?? Helpers::excerpt($data['content'] ?? '', 200),
            'comment_status' => $data['comment_status'] ?? 'open',
            'featured_media_id' => !empty($data['featured_media_id']) ? (int)$data['featured_media_id'] : null,
            'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            'menu_order' => (int)($data['menu_order'] ?? 0),
            'published_at' => ($data['status'] ?? 'draft') === 'published' ? date('Y-m-d H:i:s') : null,
        ];

        $id = $this->repo->create($payload);
        } finally {
            if ($locked) $this->repo->unlockSlugs();
        }

        // Attach terms (categories / tags)
        if (!empty($data['term_ids']) && is_array($data['term_ids'])) {
            $this->repo->attachTerms($id, $data['term_ids']);
        }
        if ($type === 'post') {
            $this->ensureCategory($id);
        }

        $post = $this->repo->find($id);
        $this->hooks->doAction('post.created', $post);
        $this->cache->flushTag('posts');

        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->repo->find($id);
        if (!$existing) return false;

        $data = $this->hooks->applyFilters('post.before_update', $data, $existing);

        $payload = [];
        if (isset($data['title'])) $payload['title'] = trim($data['title']);
        if (isset($data['content'])) $payload['content'] = $data['content'];
        if (isset($data['content_format'])) $payload['content_format'] = $data['content_format'];
        if (isset($data['excerpt'])) $payload['excerpt'] = $data['excerpt'];
        if (isset($data['comment_status'])) $payload['comment_status'] = $data['comment_status'];
        if (isset($data['menu_order'])) $payload['menu_order'] = (int)$data['menu_order'];
        if (array_key_exists('featured_media_id', $data)) {
            $payload['featured_media_id'] = $data['featured_media_id'] ? (int)$data['featured_media_id'] : null;
        }
        if (array_key_exists('parent_id', $data)) {
            $payload['parent_id'] = $data['parent_id'] ? (int)$data['parent_id'] : null;
        }
        $locked = false;
        if (isset($data['slug'])) {
            $slug = Helpers::slug($data['slug']);
            $locked = $this->repo->lockSlugs();
            $payload['slug'] = $this->resolveSlug($slug, $existing['type'], $id);
        }
        if (isset($data['status'])) {
            $payload['status'] = $data['status'];
            if ($data['status'] === 'published' && empty($existing['published_at'])) {
                $payload['published_at'] = date('Y-m-d H:i:s');
            }
        }

        if (empty($payload)) {
            // Still allow term updates without other changes
            if (isset($data['term_ids']) && is_array($data['term_ids'])) {
                $this->repo->attachTerms($id, $data['term_ids']);
            }
            if ($existing['type'] === 'post') {
                $this->ensureCategory($id);
            }
            return true;
        }

        try {
            $this->repo->update($id, $payload);
        } finally {
            if ($locked) $this->repo->unlockSlugs();
        }

        if (isset($data['term_ids']) && is_array($data['term_ids'])) {
            $this->repo->attachTerms($id, $data['term_ids']);
        }
        if ($existing['type'] === 'post') {
            // Covers a post saved with every category unticked, and a post
            // from before 1.2.7 that never had one.
            $this->ensureCategory($id);
        }

        $post = $this->repo->find($id);
        $this->hooks->doAction('post.updated', $post, $existing);
        $this->cache->flushTag('posts');

        return true;
    }

    public function delete(int $id): bool
    {
        $existing = $this->repo->find($id);
        if (!$existing) return false;

        $this->hooks->doAction('post.before_delete', $existing);
        $this->repo->softDelete($id);
        $this->hooks->doAction('post.deleted', $existing);
        $this->cache->flushTag('posts');
        return true;
    }

    /** Restore a soft-deleted item from the trash. */
    public function restore(int $id): bool
    {
        $this->repo->restore($id);
        $this->cache->flushTag('posts');
        // Fire AFTER the restore so listeners see the live row (e.g. the NAS
        // Storage app re-creating a template's folder).
        $restored = $this->repo->find($id);
        if ($restored) {
            $this->hooks->doAction('post.restored', $restored);
        }
        return true;
    }

    /** Permanently remove a single item (bypasses trash). */
    public function forceDelete(int $id): bool
    {
        $existing = $this->repo->findTrashedOrAny($id);
        if (!$existing) return false;
        $this->hooks->doAction('post.before_force_delete', $existing);
        $this->repo->forceDelete($id);
        $this->cache->flushTag('posts');
        return true;
    }

    /** Empty the trash for a type; returns number of items purged. */
    public function emptyTrash(string $type = 'post'): int
    {
        // Fire the per-item hook before the bulk purge so integrations (e.g.
        // NAS template-folder sync) can react to each permanent deletion.
        try {
            $rows = $this->repo->trashedRows($type);
            foreach ($rows as $row) {
                $this->hooks->doAction('post.before_force_delete', $row);
            }
        } catch (\Throwable) {
            // Hook fan-out must never block emptying the trash.
        }
        $n = $this->repo->emptyTrash($type);
        $this->cache->flushTag('posts');
        return $n;
    }

    public function trashedCount(string $type = 'post'): int
    {
        return $this->repo->trashedCount($type);
    }

    public function counts(): array
    {
        return $this->repo->counts();
    }

    public function recent(int $limit = 5, string $type = 'post'): array
    {
        return $this->repo->recent($limit, $type);
    }

    public function terms(int $postId): array
    {
        return $this->repo->getTerms($postId);
    }

    public function byTermId(int $termId, int $page = 1, int $perPage = 10): array
    {
        return $this->repo->byTermId($termId, $page, $perPage);
    }

    public function search(string $term, int $page = 1, int $perPage = 10): array
    {
        return $this->repo->paginate([
            'type' => 'post',
            'status' => 'published',
            'search' => $term,
        ], $page, $perPage);
    }

    public function incrementViewCount(int $id): void
    {
        $this->repo->incrementViewCount($id);
    }

    /** Types that live at the site root and therefore share one slug namespace. */
    private const ROOT_TYPES = ['post', 'page'];

    /**
     * A free slug: $base, or $base-2, $base-3, and so on.
     *
     * Posts and pages share one namespace. A page at /about-us and a post whose
     * canonical URL is /about-us (flat permalinks) would collide, and even
     * under other structures /{slug} is resolved without knowing the type. So a
     * post can never take a page's slug, nor the reverse.
     *
     * Slugs held by trashed content stay taken until it is permanently deleted
     * (PostRepository::slugTaken). Slugs a route has claimed — "search",
     * "feed", an app's prefix — are skipped, since /{slug} would never reach
     * the content. Other types keep their own namespace, as before.
     */
    private function resolveSlug(string $base, string $type, ?int $excludeId = null): string
    {
        // Leave room for a "-N" suffix inside the 200-character column.
        $base = rtrim(substr($base !== '' ? $base : 'untitled', 0, 190), '-') ?: 'untitled';
        $global   = in_array($type, self::ROOT_TYPES, true);
        $types    = $global ? self::ROOT_TYPES : [$type];
        $reserved = $global ? Helpers::reservedSlugs() : [];

        $slug = $base;
        $i = 2;
        while (in_array($slug, $reserved, true) || $this->repo->slugTaken($slug, $types, $excludeId)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Give a post the default category if it has none, so every post has a
     * category URL (/{category}/{slug}) under category permalinks.
     */
    private function ensureCategory(int $postId): void
    {
        try {
            if ($this->repo->hasCategory($postId)) return;
            $termId = $this->defaultCategoryId();
            if ($termId > 0) {
                $this->repo->attachTerms($postId, array_merge($this->repo->termIds($postId), [$termId]));
            }
        } catch (\Throwable) {
            // A post without a category still has a valid URL (/posts/{slug}).
        }
    }

    /**
     * The default category: Settings → Writing → Default Category if it still
     * exists, else "Uncategorized", which is created if it has been deleted.
     */
    public function defaultCategoryId(): int
    {
        try {
            $chosen = (int) \App\Core\Application::getInstance()
                ->make(\App\Services\SettingService::class)->get('writing', 'default_category', 0);
        } catch (\Throwable) { $chosen = 0; }
        if ($chosen > 0 && $this->repo->categoryTerm($chosen)) return $chosen;

        $u = $this->repo->categoryTerm(0, 'uncategorized');
        if ($u) return (int) $u['id'];
        return $this->repo->createCategory('Uncategorized', 'uncategorized');
    }
}
