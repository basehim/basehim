<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PostRepository;
use App\Core\HookRegistry;
use App\Core\Cache;
use App\Core\Helpers;

class PostService
{
    /** What the last create()/update() took out of the content (htmlReport()). */
    private ?array $htmlReport = null;

    public function __construct(
        private PostRepository $repo,
        private HookRegistry $hooks,
        private Cache $cache
    ) {}

    /**
     * May this account publish HTML as written — scripts, any iframe, event
     * handlers? Only with the unfiltered_html capability (editors and
     * administrators by default). Everyone else's content goes through
     * PostHtmlFilter on save. With no account (code running outside a signed-
     * in request) the answer is no; an app that writes trusted HTML from such
     * code can say so with the post.unfiltered_html filter.
     */
    public function allowsUnfilteredHtml(?array $actor): bool
    {
        $allowed = $actor !== null && \App\Http\Middleware\CheckCapability::userCan($actor, 'unfiltered_html');
        return (bool) $this->hooks->applyFilters('post.unfiltered_html', $allowed, $actor);
    }

    /**
     * What the last create() or update() removed from the content because
     * its writer lacks unfiltered_html, or null when nothing was:
     * ['removed' => string[], 'converted' => bool, 'message' => string].
     */
    public function htmlReport(): ?array
    {
        return $this->htmlReport;
    }

    /**
     * The acting account: the one passed in, else the request's signed-in
     * user (set by the Authenticate middleware), else none.
     */
    private function actor(?array $actor): ?array
    {
        if ($actor !== null) return $actor;
        try {
            $app = \App\Core\Application::getInstance();
            $user = $app->has('auth.user') ? $app->make('auth.user') : null;
            return is_array($user) ? $user : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Filter content and excerpt for writers without unfiltered_html (1.2.52). */
    private function filterHtml(array $data, ?array $actor, ?array $existing): array
    {
        $this->htmlReport = null;
        if ($existing !== null && !isset($data['content']) && isset($data['content_format'])
            && $data['content_format'] !== ($existing['content_format'] ?? null)) {
            // A format change alone re-reads the stored content another way:
            // check it as it will now be rendered.
            $data['content'] = (string) ($existing['content'] ?? '');
        }
        $hasContent = isset($data['content']) && is_string($data['content']);
        $hasExcerpt = isset($data['excerpt']) && is_string($data['excerpt']) && str_contains($data['excerpt'], '<');
        if (!$hasContent && !$hasExcerpt) return $data;
        if ($this->allowsUnfilteredHtml($this->actor($actor))) return $data;

        $hosts = (array) $this->hooks->applyFilters('html.iframe_hosts', HtmlSanitizer::IFRAME_HOSTS);
        $removed = [];
        $converted = false;
        if ($hasContent) {
            $format = (string) ($data['content_format'] ?? ($existing['content_format'] ?? 'html'));
            $r = PostHtmlFilter::filter($data['content'], $format, $hosts);
            $data['content'] = $r['content'];
            if ($r['format'] !== null) $data['content_format'] = $r['format'];
            $removed = $r['removed'];
            $converted = $r['converted'];
        }
        if ($hasExcerpt) {
            $r = HtmlSanitizer::clean($data['excerpt'], $hosts);
            $data['excerpt'] = $r['html'];
            $removed = array_merge($removed, $r['removed']);
        }
        $removed = array_values(array_unique($removed));
        if ($removed || $converted) {
            $message = $removed
                ? 'Some HTML was removed because your role cannot publish it: ' . implode(', ', $removed) . '.'
                : '';
            if ($converted) {
                $message = trim($message . ' The Markdown contained HTML, so it was saved as HTML.');
            }
            $this->htmlReport = ['removed' => $removed, 'converted' => $converted, 'message' => $message];
        }
        return $data;
    }

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

    /**
     * @param array|null $actor who is saving (for the HTML filter); defaults
     *                          to the request's signed-in user
     */
    public function create(array $data, int $authorId, ?array $actor = null): int
    {
        $data = $this->hooks->applyFilters('post.before_create', $data);
        // After the filters, so nothing an app adds bypasses it.
        $data = $this->filterHtml($data, $actor, null);

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

    /** @param array|null $actor who is saving (for the HTML filter); see create() */
    public function update(int $id, array $data, ?array $actor = null): bool
    {
        $this->htmlReport = null;
        $existing = $this->repo->find($id);
        if (!$existing) return false;

        $data = $this->hooks->applyFilters('post.before_update', $data, $existing);
        $data = $this->filterHtml($data, $actor, $existing);

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
