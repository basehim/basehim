<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Application;
use App\Services\PostService;
use App\Services\SeoService;

/**
 * PostsApi — CRUD over posts, pages, and any custom content type.
 *
 * Bound to one type at construction, so posts() and pages() are the same class
 * with different defaults and an app can add its own type via content('event').
 *
 *     $api->posts()->create(['title' => 'Hello', 'status' => 'published']);
 *     $api->pages()->find($id);
 *     $api->content('event')->paginate(['search' => 'launch']);
 */
class PostsApi extends Resource
{
    public function __construct(Application $app, string $slug, private string $type = 'post')
    {
        parent::__construct($app, $slug);
    }

    /**
     * Permissions here depend on the content type this instance is bound to,
     * which the central map in Resource cannot express: the same class backs
     * posts(), pages() and content('event').
     *
     * Custom types fall under posts.*. Inventing a permission per type would
     * mean an operator being asked to approve names they have never seen, for
     * a type only the app understands.
     */
    protected function permissionFor(string $operation): ?string
    {
        $prefix = $this->type === 'page' ? 'pages' : 'posts';

        $action = match ($operation) {
            'find', 'findBySlug', 'paginate', 'search',
            'terms', 'byTerm', 'seo', 'counts'      => 'read',
            'create', 'update', 'restore'           => 'write',
            'delete', 'forceDelete'                 => 'delete',
            default                                 => null,
        };

        return $action === null ? null : $prefix . '.' . $action;
    }

    private function service(): PostService
    {
        return $this->make(PostService::class);
    }

    /** One record by id, or null. Type-checked so pages()->find() can't return a post. */
    public function find(int $id): ?array
    {
        $row = $this->attempt(fn() => $this->service()->find($id), null, 'find');
        if (!is_array($row)) return null;
        return ($row['type'] ?? $this->type) === $this->type ? $row : null;
    }

    /** One record by slug, or null. */
    public function findBySlug(string $slug): ?array
    {
        return $this->attempt(fn() => $this->service()->findBySlug($slug, $this->type), null, 'findBySlug');
    }

    /**
     * A page of records: ['data' => [...], 'meta' => [...]].
     *
     * @param array $filters status, search, author_id, … (passed through)
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $filters['type'] = $this->type;
        return $this->attempt(
            fn() => $this->service()->paginate($filters, max(1, $page), max(1, min(100, $perPage))),
            ['data' => [], 'meta' => []],
            'paginate'
        );
    }

    /**
     * Every matching record, following pagination for you.
     *
     * Convenience for the common "I need them all" case. Capped so an app can't
     * accidentally load a 50k-row table into memory on shared hosting; raise
     * $limit deliberately if you really need more.
     */
    public function all(array $filters = [], int $limit = 500): array
    {
        $out = [];
        $page = 1;
        $perPage = 100;
        while (count($out) < $limit) {
            $chunk = $this->paginate($filters, $page, $perPage);
            $rows = $chunk['data'] ?? [];
            if (!$rows) break;
            foreach ($rows as $row) {
                $out[] = $row;
                if (count($out) >= $limit) break 2;
            }
            if (count($rows) < $perPage) break;
            $page++;
        }
        return $out;
    }

    /**
     * Create a record. Returns the new id, or 0 on failure.
     *
     * @param array $data  title, content, status, slug, excerpt, …
     * @param int|null $authorId Defaults to the signed-in user, else user 1 —
     *                           apps often create content from a cron run or a
     *                           webhook where there is no session at all.
     */
    public function create(array $data, ?int $authorId = null): int
    {
        $data['type'] = $this->type;
        $authorId ??= $this->currentUserId();

        $id = $this->attempt(fn() => $this->service()->create($data, $authorId), 0, 'create');
        $id = (int) $id;
        if ($id > 0) {
            $this->log("Created {$this->type} #{$id}", ['title' => $data['title'] ?? '']);
        }
        return $id;
    }

    /** Update a record. Returns true on success. */
    public function update(int $id, array $data): bool
    {
        if ($this->find($id) === null) return false;
        $ok = (bool) $this->attempt(fn() => $this->service()->update($id, $data), false, 'update');
        if ($ok) $this->log("Updated {$this->type} #{$id}");
        return $ok;
    }

    /** Move to trash. Use forceDelete() to remove permanently. */
    public function delete(int $id): bool
    {
        if ($this->find($id) === null) return false;
        $ok = (bool) $this->attempt(fn() => $this->service()->delete($id), false, 'delete');
        if ($ok) $this->log("Trashed {$this->type} #{$id}");
        return $ok;
    }

    /** Permanently delete. Not recoverable. */
    public function forceDelete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->forceDelete($id), false, 'forceDelete');
        if ($ok) $this->log("Deleted {$this->type} #{$id} permanently", [], 'warning');
        return $ok;
    }

    /** Restore from trash. */
    public function restore(int $id): bool
    {
        return (bool) $this->attempt(fn() => $this->service()->restore($id), false, 'restore');
    }

    /** Publish — shorthand for update(id, ['status' => 'published']). */
    public function publish(int $id): bool
    {
        return $this->update($id, ['status' => 'published']);
    }

    /** Move back to draft. */
    public function draft(int $id): bool
    {
        return $this->update($id, ['status' => 'draft']);
    }

    /** Full-text search, paginated. */
    public function search(string $term, int $page = 1, int $perPage = 10): array
    {
        return $this->attempt(
            fn() => $this->service()->search($term, $page, $perPage),
            ['data' => [], 'meta' => []],
            'search'
        );
    }

    /** Taxonomy terms attached to a record. */
    public function terms(int $id): array
    {
        return (array) $this->attempt(fn() => $this->service()->terms($id), [], 'terms');
    }

    /** Records carrying a given term id. */
    public function byTerm(int $termId, int $page = 1, int $perPage = 10): array
    {
        return $this->attempt(
            fn() => $this->service()->byTermId($termId, $page, $perPage),
            ['data' => [], 'meta' => []],
            'byTerm'
        );
    }

    /** SEO meta for a record. */
    public function seo(int $id): array
    {
        return (array) $this->attempt(fn() => $this->make(SeoService::class)->forPost($id), [], 'seo');
    }

    /** Counts by status. */
    public function counts(): array
    {
        return (array) $this->attempt(fn() => $this->service()->counts(), [], 'counts');
    }

    /**
     * Best-effort author id when the caller didn't name one.
     *
     * Falls back to 1 rather than 0: posts.author_id is a real column and a
     * zero would produce an orphan row that renders with no author.
     */
    private function currentUserId(): int
    {
        try {
            $user = $this->app->has('auth.user') ? $this->app->make('auth.user') : null;
            if (is_array($user) && !empty($user['id'])) return (int) $user['id'];
        } catch (\Throwable) {
        }
        return 1;
    }
}
