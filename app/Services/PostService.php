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

        // Attach terms (categories / tags)
        if (!empty($data['term_ids']) && is_array($data['term_ids'])) {
            $this->repo->attachTerms($id, $data['term_ids']);
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
        if (isset($data['slug'])) {
            $slug = Helpers::slug($data['slug']);
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
            return true;
        }

        $this->repo->update($id, $payload);

        if (isset($data['term_ids']) && is_array($data['term_ids'])) {
            $this->repo->attachTerms($id, $data['term_ids']);
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

    private function resolveSlug(string $base, string $type, ?int $excludeId = null): string
    {
        $base = $base !== '' ? $base : 'untitled';
        $slug = $base;
        $i = 2;
        while ($this->repo->slugExists($slug, $type, $excludeId)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
