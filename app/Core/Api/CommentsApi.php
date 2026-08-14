<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\CommentService;

/**
 * CommentsApi — read and moderate comments.
 *
 * Moderation was previously reachable only through the admin UI; an app that
 * wanted to auto-approve trusted authors or run its own spam heuristic had no
 * supported route to do it.
 */
class CommentsApi extends Resource
{
    /** Statuses the core comment table accepts. */
    public const STATUSES = ['approved', 'pending', 'spam', 'trash'];

    private function service(): CommentService
    {
        return $this->make(CommentService::class);
    }

    public function find(int $id): ?array
    {
        return $this->attempt(fn() => $this->service()->find($id), null, 'find');
    }

    /** Comments on a post, defaulting to the ones the public would see. */
    public function forPost(int $postId, string $status = 'approved'): array
    {
        return (array) $this->attempt(fn() => $this->service()->forPost($postId, $status), [], 'forPost');
    }

    /** @param array $filters status, post_id, search */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->attempt(
            fn() => $this->service()->paginate($filters, max(1, $page), max(1, min(100, $perPage))),
            ['data' => [], 'meta' => []],
            'paginate'
        );
    }

    public function all(array $filters = [], int $limit = 500): array
    {
        $out = [];
        $page = 1;
        while (count($out) < $limit) {
            $chunk = $this->paginate($filters, $page, 100);
            $rows = $chunk['data'] ?? [];
            if (!$rows) break;
            foreach ($rows as $row) {
                $out[] = $row;
                if (count($out) >= $limit) break 2;
            }
            if (count($rows) < 100) break;
            $page++;
        }
        return $out;
    }

    /**
     * Create a comment.
     *
     * This bypasses the anti-spam guard on purpose: an app calling it is
     * trusted code, not an untrusted form post. Pipe untrusted input through
     * guard() first if that is what you are handling.
     */
    public function create(array $data): int
    {
        $id = (int) $this->attempt(fn() => $this->service()->create($data), 0, 'create');
        if ($id > 0) $this->log("Created comment #{$id}");
        return $id;
    }

    /**
     * Run the core spam guard over untrusted input.
     *
     * Returns the service's verdict — honeypot, flood control, duplicate
     * detection, blocklist, moderation words and link limits, exactly as the
     * public comment form applies them.
     */
    public function guard(array $input): array
    {
        return (array) $this->attempt(fn() => $this->service()->guard($input), [], 'guard');
    }

    /** Set status to one of STATUSES. */
    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            $this->log("Rejected invalid comment status '{$status}'", [], 'warning');
            return false;
        }
        $ok = (bool) $this->attempt(fn() => $this->service()->setStatus($id, $status), false, 'setStatus');
        if ($ok) $this->log("Comment #{$id} → {$status}");
        return $ok;
    }

    public function approve(int $id): bool { return $this->setStatus($id, 'approved'); }
    public function unapprove(int $id): bool { return $this->setStatus($id, 'pending'); }
    public function spam(int $id): bool { return $this->setStatus($id, 'spam'); }
    public function trash(int $id): bool { return $this->setStatus($id, 'trash'); }

    /** Permanently delete. */
    public function delete(int $id): bool
    {
        $ok = (bool) $this->attempt(fn() => $this->service()->delete($id), false, 'delete');
        if ($ok) $this->log("Deleted comment #{$id}");
        return $ok;
    }

    /** Counts per status. */
    public function counts(): array
    {
        return (array) $this->attempt(fn() => $this->service()->counts(), [], 'counts');
    }

    public function recent(int $limit = 5): array
    {
        return (array) $this->attempt(fn() => $this->service()->recent($limit), [], 'recent');
    }
}
