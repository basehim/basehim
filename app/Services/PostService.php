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
        // Checked before anything is written (1.2.73, BH-026): an unknown term
        // id failed on the foreign key AFTER the post was saved, the caller
        // saw an error, retried, and got a second post.
        $this->assertTermIds($data);

        $type = $data['type'] ?? 'post';
        $title = trim($data['title'] ?? 'Untitled');
        $slug = !empty($data['slug']) ? Helpers::slug($data['slug'], '-', true) : Helpers::slug($title, '-', true);

        // Held from choosing the slug until the row exists, so two saves at
        // once cannot both take the same free slug.
        $locked = $this->slugLock();
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
            'published_at' => null,
        ];
        [$payload['status'], $payload['published_at']] = $this->timing(
            (string) $payload['status'],
            array_key_exists('published_at', $data) ? $this->utcOrNull($data['published_at']) : null,
            array_key_exists('published_at', $data),
            null
        );

        // The post, its terms and its default category are one write
        // (1.2.73): all of it is saved, or none of it.
        $id = $this->repo->transaction(function () use ($payload, $data, $type): int {
            $id = $this->repo->create($payload);
            if (!empty($data['term_ids']) && is_array($data['term_ids'])) {
                $this->repo->attachTerms($id, $data['term_ids']);
            }
            if ($type === 'post') {
                $this->ensureCategory($id);
            }
            return $id;
        });
        } finally {
            if ($locked) $this->repo->unlockSlugs();
        }

        $post = $this->repo->find($id);
        $this->hooks->doAction('post.created', $post);
        if (($post['status'] ?? '') === 'published') {
            $this->hooks->doAction('post.published', $post, null);
        }
        $this->cache->flushTag('posts');
        $this->forgetSchedule();

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
        $this->assertTermIds($data);

        $payload = [];
        if (isset($data['title'])) $payload['title'] = trim($data['title']);
        if (isset($data['content'])) $payload['content'] = $data['content'];
        if (isset($data['content_format'])) $payload['content_format'] = $data['content_format'];
        if (isset($data['excerpt'])) $payload['excerpt'] = $data['excerpt'];
        if (isset($data['comment_status'])) $payload['comment_status'] = $data['comment_status'];
        if (isset($data['menu_order'])) $payload['menu_order'] = (int)$data['menu_order'];
        // Who may change the author is the caller's decision (Quick Edit checks
        // edit_others_{type}s and that the new author can edit the type).
        if (isset($data['author_id']) && (int) $data['author_id'] > 0) $payload['author_id'] = (int) $data['author_id'];
        if (array_key_exists('featured_media_id', $data)) {
            $payload['featured_media_id'] = $data['featured_media_id'] ? (int)$data['featured_media_id'] : null;
        }
        if (array_key_exists('parent_id', $data)) {
            $payload['parent_id'] = $data['parent_id'] ? (int)$data['parent_id'] : null;
        }
        $locked = false;
        if (isset($data['slug'])) {
            $slug = Helpers::slug($data['slug'], '-', true);
            $locked = $this->slugLock();
            $payload['slug'] = $this->resolveSlug($slug, $existing['type'], $id);
        }
        if (isset($data['status']) || array_key_exists('published_at', $data)) {
            $explicit = array_key_exists('published_at', $data);
            [$status, $publishedAt] = $this->timing(
                (string) ($data['status'] ?? $existing['status']),
                $explicit ? $this->utcOrNull($data['published_at']) : null,
                $explicit,
                $existing
            );
            if (isset($data['status']) || $status !== $existing['status']) $payload['status'] = $status;
            if ($publishedAt !== ($existing['published_at'] ?? null)) $payload['published_at'] = $publishedAt;
        }

        // The row, its terms and its category in one transaction (1.2.73).
        $terms = function () use ($id, $data, $existing): void {
            if (isset($data['term_ids']) && is_array($data['term_ids'])) {
                $this->repo->attachTerms($id, $data['term_ids']);
            }
            if ($existing['type'] === 'post') {
                // Covers a post saved with every category unticked, and a post
                // from before 1.2.7 that never had one.
                $this->ensureCategory($id);
            }
        };

        if (empty($payload)) {
            // Still allow term updates without other changes
            if ($locked) $this->repo->unlockSlugs();
            $this->repo->transaction($terms);
            return true;
        }

        try {
            $this->repo->transaction(function () use ($id, $payload, $terms): void {
                $this->repo->update($id, $payload);
                $terms();
            });
        } finally {
            if ($locked) $this->repo->unlockSlugs();
        }

        $post = $this->repo->find($id);
        $this->hooks->doAction('post.updated', $post, $existing);
        if (($post['status'] ?? '') === 'published' && ($existing['status'] ?? '') !== 'published') {
            $this->hooks->doAction('post.published', $post, $existing);
        }
        $this->cache->flushTag('posts');
        $this->forgetSchedule();

        return true;
    }

    /**
     * Take a scheduled post live because its time has come (1.2.60). Called
     * by ScheduledPublisher, from any request or from the cron URL.
     *
     * The claim is one conditional UPDATE (PostRepository::claimScheduled), so
     * when two requests race for the same post only one of them publishes it
     * and fires the hooks: post.updated and post.published run exactly once,
     * just as they would for a person pressing Publish. The stored
     * published_at — the time that was chosen — is kept.
     *
     * @return array|null the published post, or null when this call did not
     *                    publish it (not scheduled, not due, or claimed elsewhere)
     */
    public function publishScheduled(int $id, ?string $nowUtc = null): ?array
    {
        $nowUtc ??= gmdate('Y-m-d H:i:s');
        $existing = $this->repo->find($id);
        if (!$existing || ($existing['status'] ?? '') !== 'scheduled') return null;
        if (!$this->repo->claimScheduled($id, $nowUtc)) return null;

        $this->cache->flushTag('posts');
        $this->forgetSchedule();
        $post = $this->repo->find($id) ?? (['status' => 'published'] + $existing);
        // A listener that throws must not undo a publication that has happened,
        // nor stop the next due post: log it and carry on.
        foreach ([['post.updated', [$post, $existing]], ['post.published', [$post, $existing]]] as [$hook, $args]) {
            try {
                $this->hooks->doAction($hook, ...$args);
            } catch (\Throwable $e) {
                try {
                    \App\Core\Application::getInstance()->make(\App\Core\Logger::class)
                        ->error("Scheduled publish: a {$hook} listener failed for post {$id}: " . $e->getMessage());
                } catch (\Throwable) {}
            }
        }
        return $post;
    }

    /**
     * The status and published_at a save ends up with, whatever path it came
     * by (the editor, a bulk action, the REST API, an app). Callers that read
     * a person's intent first (PostPublishing) pass an explicit time; the
     * rules here keep the stored data consistent either way:
     *
     *  - published: keeps a past publication date; none, or a future one left
     *    over from a schedule, becomes now (the server's clock).
     *  - scheduled: needs a time still ahead. A time that has passed means it
     *    is due, so it is published now; no time at all leaves it a draft.
     *  - draft / pending leaving `scheduled`: the date nobody published at is
     *    dropped.
     *
     * @return array{0:string,1:?string} [status, published_at]
     */
    private function timing(string $status, ?string $requested, bool $explicit, ?array $existing): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $stored = $existing['published_at'] ?? null;
        $stored = is_string($stored) && $stored !== '' ? $stored : null;
        $at = $explicit ? $requested : $stored;

        switch ($status) {
            case 'published':
                if ($at === null || $at > $now) $at = $now;
                return ['published', $at];
            case 'scheduled':
                if ($at === null) return ['draft', null];
                if ($at <= $now) return ['published', $at];
                return ['scheduled', $at];
            case 'draft':
            case 'pending':
                if (!$explicit && ($existing['status'] ?? null) === 'scheduled') return [$status, null];
                return [$status, $at];
            default:
                return [$status, $at];
        }
    }

    /** A UTC "Y-m-d H:i:s" (anything Time can read), or null. */
    private function utcOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $dt = \App\Core\Time::utc($value);
        return $dt?->format('Y-m-d H:i:s');
    }

    /** The scheduled-post publisher recomputes its next due time on its next look. */
    private function forgetSchedule(): void
    {
        try { $this->cache->delete(ScheduledPublisher::CACHE_KEY); } catch (\Throwable) {}
    }

    public function delete(int $id): bool
    {
        $existing = $this->repo->find($id);
        if (!$existing) return false;

        $this->hooks->doAction('post.before_delete', $existing);
        $this->repo->softDelete($id);
        $this->hooks->doAction('post.deleted', $existing);
        $this->cache->flushTag('posts');
        $this->forgetSchedule();
        return true;
    }

    /** Restore a soft-deleted item from the trash. */
    public function restore(int $id): bool
    {
        $this->repo->restore($id);
        $this->cache->flushTag('posts');
        $this->forgetSchedule();
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
        $this->forgetSchedule();
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

    /** Count a view: buffered, direct or not at all (Settings → Reading; PostViewCounter, 1.2.76). */
    public function incrementViewCount(int $id): void
    {
        \App\Core\Application::getInstance()->make(PostViewCounter::class)->record($id);
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
    /**
     * Refuse term ids that are not terms, before anything is written.
     *
     * @throws \InvalidArgumentException naming the unknown ids
     */
    private function assertTermIds(array $data): void
    {
        if (!isset($data['term_ids'])) return;
        if (!is_array($data['term_ids'])) {
            throw new \InvalidArgumentException('term_ids must be a list of term ids.');
        }
        $bad = $this->repo->unknownTermIds($data['term_ids']);
        if ($bad) {
            throw new \InvalidArgumentException('Unknown term id(s): ' . implode(', ', array_slice($bad, 0, 10)) . '.');
        }
    }

    private function resolveSlug(string $base, string $type, ?int $excludeId = null): string
    {
        // Leave room for a "-N" suffix inside the 200-character column; cut on
        // a character boundary, since slugs may be Unicode (1.2.76).
        $base = $base !== '' ? $base : 'untitled';
        if (strlen($base) > 190) $base = mb_strcut($base, 0, 190, 'UTF-8');
        $base = rtrim($base, '-') ?: 'untitled';
        $global   = in_array($type, self::ROOT_TYPES, true);
        $types    = $global ? self::ROOT_TYPES : [$type];
        $reserved = $global ? Helpers::reservedSlugs() : [];

        // One query for the base and all its numbered variants (CR-18): it
        // used to be one query per number tried, so the thousandth "Blue
        // T-Shirt" took a thousand queries while holding the slug lock.
        $taken = $this->repo->slugsLike($base, $types, $excludeId);
        if (!isset($taken[$base]) && !in_array($base, $reserved, true)) return $base;
        $max = 1;
        $pattern = '/^' . preg_quote($base, '/') . '-(\d{1,9})$/u';
        foreach ($taken as $slug => $_) {
            if (preg_match($pattern, (string) $slug, $m)) $max = max($max, (int) $m[1]);
        }
        $n = $max + 1;
        while (in_array($base . '-' . $n, $reserved, true) || isset($taken[$base . '-' . $n])) $n++;
        return $base . '-' . $n;
    }

    /**
     * Take the slug lock, or refuse the save (CR-18). Before 1.2.76 a save
     * that waited 5 s went ahead without it, racing the save that held it.
     * A database without GET_LOCK (null) carries on, as before.
     */
    private function slugLock(): bool
    {
        $got = $this->repo->lockSlugs();
        if ($got === false) {
            throw new \RuntimeException('The site is busy saving other content. Please try again in a moment.');
        }
        return $got === true;
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
        } catch (\Throwable $e) {
            // A deadlock has already undone the surrounding transaction: let
            // it reach Database::transaction(), which runs the save again.
            if (\App\Core\Database::isRetryable($e)) throw $e;
            // Otherwise a post without a category still has a valid URL (/posts/{slug}).
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
