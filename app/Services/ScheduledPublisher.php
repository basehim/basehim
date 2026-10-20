<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Logger;
use App\Repositories\PostRepository;

/**
 * Publishes scheduled posts when their time comes (1.2.60).
 *
 * A post is scheduled when its status is `scheduled` and its published_at
 * (UTC) is ahead. Every public query asks for status = 'published', so until
 * this class flips the status the post is invisible to visitors, feeds, the
 * sitemap, search and the REST API, whatever its date says.
 *
 * When it runs:
 *
 *  1. On every request, at boot, before anything is routed (maybeRun()). The
 *     earliest due time is kept in the file cache, so a request with nothing
 *     due costs one small file read and no query. When something is due it is
 *     published right there, so the first visitor after the scheduled time
 *     already sees the post, its archive and its feed entry.
 *  2. From the cron URL (/api/v1/schedule/run?token=…), on every call
 *     (publishDue()). On a site with few visitors this is what makes the time
 *     punctual; without it a post goes live with the next request of any kind.
 *
 * Missed windows are harmless: everything at or before now is due, so a post
 * whose time passed while the site was idle goes out with the next request.
 * Two requests at once cannot publish the same post twice. On one server a
 * non-blocking file lock lets a single run through at a time; across servers
 * (or if the lock file is unusable) each post is still claimed with one
 * conditional UPDATE (PostService::publishScheduled()), and only the request
 * that changed the row fires the hooks.
 *
 * The cached next-due time is dropped whenever PostService saves, deletes or
 * restores a post, and expires after a few minutes anyway, so a post written
 * straight to the database (an importer) is picked up too.
 */
final class ScheduledPublisher
{
    public const CACHE_KEY = 'core.scheduled_posts.next_due';

    /** How long the next-due time is trusted without asking the database. */
    private const CACHE_TTL = 300;

    /** Most posts one request publishes; the rest go out with the next one. */
    private const BATCH = 25;

    private bool $ranThisRequest = false;

    public function __construct(
        private PostRepository $repo,
        private PostService $posts,
        private Cache $cache,
        private ?Logger $logger = null,
    ) {}

    /**
     * Publish what is due, if anything is. Cheap when nothing is. Called once
     * per request by Application::boot(); never throws.
     */
    public function maybeRun(): int
    {
        if ($this->ranThisRequest) return 0;
        try {
            $next = $this->nextDueTimestamp();
            if ($next === 0 || $next > time()) return 0;
            return count($this->publishDue()['published']);
        } catch (\Throwable $e) {
            $this->log('Scheduled publishing failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Publish every scheduled post whose time has come (up to one batch).
     *
     * @return array{published:int[], due:int, busy:bool}
     */
    public function publishDue(?int $now = null): array
    {
        $this->ranThisRequest = true;
        $nowUtc = gmdate('Y-m-d H:i:s', $now ?? time());
        $out = ['published' => [], 'due' => 0, 'busy' => false];
        // One run at a time on this server; another request already running
        // is doing this work, so this one simply moves on.
        $lock = $this->lock();
        if ($lock === false) {
            $out['busy'] = true;
            return $out;
        }
        try {
            $ids = $this->repo->dueScheduledIds($nowUtc, self::BATCH);
            $out['due'] = count($ids);
            foreach ($ids as $id) {
                try {
                    $post = $this->posts->publishScheduled($id, $nowUtc);
                    if ($post !== null) {
                        $out['published'][] = $id;
                        ActivityLogService::record(null, 'post.scheduled_published', (string) ($post['type'] ?? 'post'), $id,
                            'Published on schedule: "' . mb_substr((string) ($post['title'] ?? ''), 0, 80) . '"');
                    }
                } catch (\Throwable $e) {
                    $this->log("Scheduled publishing of post {$id} failed: " . $e->getMessage());
                }
            }
        } finally {
            // Whatever happened, look again next time instead of trusting a
            // time that may now be stale.
            try { $this->cache->delete(self::CACHE_KEY); } catch (\Throwable) {}
            $this->unlock($lock);
        }
        return $out;
    }

    /**
     * A non-blocking exclusive lock (the same pattern as SchedulerService):
     * the resource, null when the lock file cannot be used at all (the run
     * then relies on the per-post claim alone), or false when another
     * request holds it.
     *
     * @return resource|null|false
     */
    private function lock()
    {
        if (!defined('BASEHIM_ROOT')) return null;
        // storage/locks, not storage/cache (1.2.73): clearing the cache
        // deleted a held lock file, and a second run could then start.
        $dir = BASEHIM_ROOT . '/storage/locks';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        $h = @fopen($dir . '/scheduled-posts.lock', 'c');
        if ($h === false) return null;
        if (!@flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return false;
        }
        return $h;
    }

    /** @param resource|null $h */
    private function unlock($h): void
    {
        if (!is_resource($h)) return;
        @flock($h, LOCK_UN);
        @fclose($h);
    }

    /**
     * The Unix time of the earliest scheduled post, 0 for none. Read from the
     * cache; asked of the database at most once per CACHE_TTL or after any
     * post was saved.
     */
    public function nextDueTimestamp(): int
    {
        $cached = $this->cache->get(self::CACHE_KEY, null);
        if (is_int($cached)) return $cached;

        $at = $this->repo->nextScheduledAt();
        $ts = $at !== null ? (int) strtotime($at . ' UTC') : 0;
        $this->cache->set(self::CACHE_KEY, $ts, self::CACHE_TTL);
        return $ts;
    }

    private function log(string $message): void
    {
        try { $this->logger?->warning($message); } catch (\Throwable) {}
    }
}
