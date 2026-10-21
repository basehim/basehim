<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Helpers;

/**
 * Authors as the public sees them: archive pages at /author/{slug}, the author
 * box, and the handful of profile fields it is safe to show.
 *
 * The public slug is not the username. A username is half of a login, and an
 * author page that shows it (/author/admin) hands it to anyone guessing
 * passwords. The slug comes from the display name, is unique, and is stored in
 * users.public_slug (a unique, indexed column since 1.2.76; before that only
 * users.meta `author_slug`, which is still written for older code) so it stays
 * put when the display name changes. It is created the first time it is
 * needed, and can be edited on the user's profile.
 *
 * Only users with at least one published post have an author page. Anyone
 * else — subscribers, editors who have not published — is a 404, so the pages
 * cannot be used to list a site's accounts.
 */
final class AuthorService
{
    private const META_KEY = 'author_slug';
    /** How often a lookup miss may fill in missing author slugs (seconds). */
    private const BACKFILL_EVERY = 3600;

    private static ?bool $hasColumn = null;

    /** @var array<int, array|null> */
    private array $users = [];
    /** @var array<int, int> */
    private array $counts = [];

    public function __construct(
        private Database $db,
        private SettingService $settings
    ) {
    }

    // ── Settings ──────────────────────────────────────────────────────────

    /** Settings → Reading → Author archives. On by default. */
    public function archivesEnabled(): bool
    {
        return (bool) (int) $this->settings->get('reading', 'author_archives', 1);
    }

    /** Settings → Reading → Author box on posts. On by default. */
    public function boxEnabled(): bool
    {
        return (bool) (int) $this->settings->get('reading', 'author_box', 1);
    }

    // ── Lookup ────────────────────────────────────────────────────────────

    /** A live (not deleted) user row by id, or null. */
    public function find(int $id): ?array
    {
        if ($id <= 0) return null;
        if (!array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->db->selectOne(
                'SELECT * FROM {users} WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id]
            );
        }
        return $this->users[$id];
    }

    /** The author whose public slug this is, if they have published posts. */
    public function findBySlug(string $slug): ?array
    {
        $slug = mb_strtolower(trim($slug), 'UTF-8');
        if ($slug === '' || strlen($slug) > 191 || !preg_match('/^[\p{L}\p{M}\p{N}-]+$/u', $slug)) return null;

        $user = $this->userWithSlug($slug);
        if (!$user && $this->backfillDue()) {
            // Slugs are made on first use, so an author nobody has linked to
            // yet may have none. Fill those in — only authors without one, and
            // at most once an hour, so unknown addresses cost one indexed
            // lookup (CR-19: every miss used to rebuild every author's slug).
            foreach ($this->authorRowsWithoutSlug() as $row) $this->slugFor($row);
            $user = $this->userWithSlug($slug);
        }
        if (!$user || $this->publishedCount((int) $user['id']) === 0) return null;
        return $user;
    }

    /** Users with at least one published post, for the sitemap. */
    public function authors(): array
    {
        return $this->authorRows();
    }

    public function publishedCount(int $userId): int
    {
        if (!isset($this->counts[$userId])) {
            $r = $this->db->selectOne(
                "SELECT COUNT(*) AS c FROM {posts}
                  WHERE author_id = :id AND type = 'post' AND status = 'published' AND deleted_at IS NULL",
                ['id' => $userId]
            );
            $this->counts[$userId] = (int) ($r['c'] ?? 0);
        }
        return $this->counts[$userId];
    }

    // ── Slugs ─────────────────────────────────────────────────────────────

    /** The user's public slug, created and saved on first use. */
    public function slugFor(array $user): string
    {
        $this->ensureColumn();
        $slug = (string) ($user['public_slug'] ?? '');
        if ($slug !== '') return $slug;
        $meta = $this->meta($user);
        $old = (string) ($meta[self::META_KEY] ?? '');
        if ($old !== '' && $this->claim((int) $user['id'], $old, $meta)) return $old;   // from before 1.2.76
        return $this->assign((int) $user['id'], $this->baseSlug($user), $meta);
    }

    /**
     * Set a user's public slug from the profile form.
     *
     * @return string|null the slug saved, or null if $input is unusable
     */
    public function setSlug(int $userId, string $input): ?string
    {
        $user = $this->find($userId);
        if (!$user) return null;
        $base = Helpers::slug($input, '-', true);
        if ($base === '' || $base === 'n-a') return null;
        $this->ensureColumn();
        if ((string) ($user['public_slug'] ?? '') === $base) return $base;
        return $this->assign($userId, mb_strcut($base, 0, 180, 'UTF-8'), $this->meta($user));
    }

    private function baseSlug(array $user): string
    {
        $name = trim((string) ($user['display_name'] ?? ''));
        $base = $name !== '' ? Helpers::slug($name, '-', true) : '';
        if ($base === '' || $base === 'n-a') $base = 'author';

        // A display name identical to the login name would publish the login
        // name. Keep the name recognisable but make the slug differ from it.
        if (strcasecmp($base, (string) ($user['username'] ?? '')) === 0) {
            $base .= '-' . (int) $user['id'];
        }
        return mb_strcut($base, 0, 180, 'UTF-8');
    }

    /**
     * Give the user $base, or $base-2, $base-3… whichever is free, and
     * return it. The unique index decides: if another request took the same
     * slug a moment earlier, the next number is tried.
     */
    private function assign(int $userId, string $base, array $meta): string
    {
        $taken = [];
        foreach ($this->db->select(
            'SELECT public_slug FROM {users} WHERE id <> :id AND (public_slug = :b OR public_slug LIKE :p)',
            ['id' => $userId, 'b' => $base, 'p' => addcslashes($base, '\\%_') . '-%']
        ) as $r) $taken[mb_strtolower((string) $r['public_slug'], 'UTF-8')] = true;
        $slug = $base;
        for ($n = 2, $tries = 0; $tries < 20; $tries++) {
            while (isset($taken[mb_strtolower($slug, 'UTF-8')])) $slug = $base . '-' . $n++;
            if ($this->claim($userId, $slug, $meta)) return $slug;
            $taken[mb_strtolower($slug, 'UTF-8')] = true;
        }
        $slug = $base . '-' . $userId;    // cannot clash: ids are unique
        $this->claim($userId, $slug, $meta);
        return $slug;
    }

    /** Store $slug for the user; false when another user holds it. */
    private function claim(int $userId, string $slug, array $meta): bool
    {
        $meta[self::META_KEY] = $slug;
        try {
            $this->db->execute(
                'UPDATE {users} SET public_slug = :s, meta = :m WHERE id = :id',
                ['s' => $slug, 'm' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $userId]
            );
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || $e->getCode() === '23000') return false;
            throw $e;
        }
        if (isset($this->users[$userId]) && is_array($this->users[$userId])) {
            $this->users[$userId]['public_slug'] = $slug;
            $this->users[$userId]['meta'] = json_encode($meta);
        }
        return true;
    }

    private function userWithSlug(string $slug): ?array
    {
        $this->ensureColumn();
        $row = $this->db->selectOne(
            'SELECT * FROM {users} WHERE public_slug = :s AND deleted_at IS NULL',
            ['s' => $slug]
        );
        if ($row) $this->users[(int) $row['id']] = $row;
        return $row ?: null;
    }

    /** Authors with published posts and no slug yet. */
    private function authorRowsWithoutSlug(): array
    {
        return $this->db->select(
            "SELECT u.* FROM {users} u
              WHERE u.deleted_at IS NULL AND u.public_slug IS NULL
                AND EXISTS (SELECT 1 FROM {posts} p WHERE p.author_id = u.id AND p.type = 'post'
                               AND p.status = 'published' AND p.deleted_at IS NULL)
              ORDER BY u.id LIMIT 500"
        );
    }

    private function backfillDue(): bool
    {
        try {
            $last = (int) $this->settings->get('authors', 'slug_backfill_at', 0);
            if ($last > time() - self::BACKFILL_EVERY) return false;
            $this->settings->set('authors', 'slug_backfill_at', (string) time(), false);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Add users.public_slug where migration 019 has not run yet (new files,
     * old database), and copy the slugs kept in meta.
     */
    private function ensureColumn(): void
    {
        if (self::$hasColumn) return;
        $has = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{@users}' AND COLUMN_NAME = 'public_slug'"
        )['c'] ?? 0);
        if ($has === 0) {
            $this->db->execute('ALTER TABLE {users} ADD COLUMN `public_slug` VARCHAR(191) NULL, ADD UNIQUE KEY `uq_public_slug` (`public_slug`)');
            foreach ($this->db->select("SELECT id, meta FROM {users} WHERE meta LIKE '%author_slug%'") as $row) {
                $s = (string) ($this->meta($row)[self::META_KEY] ?? '');
                if ($s !== '') $this->claim((int) $row['id'], $s, $this->meta($row));
            }
        }
        self::$hasColumn = true;
    }

    private function authorRows(): array
    {
        return $this->db->select(
            "SELECT u.* FROM {users} u
              WHERE u.deleted_at IS NULL
                AND EXISTS (SELECT 1 FROM {posts} p WHERE p.author_id = u.id AND p.type = 'post'
                               AND p.status = 'published' AND p.deleted_at IS NULL)
              ORDER BY u.id"
        );
    }

    private function meta(array $user): array
    {
        $m = $user['meta'] ?? null;
        if (is_array($m)) return $m;
        $d = is_string($m) && $m !== '' ? json_decode($m, true) : null;
        return is_array($d) ? $d : [];
    }

    // ── Public profile ────────────────────────────────────────────────────

    /** /author/{slug}, or '' when author archives are off or the user has none. */
    public function url(array $user): string
    {
        if (!$this->archivesEnabled() || $this->publishedCount((int) $user['id']) === 0) return '';
        return '/author/' . $this->slugFor($user);
    }

    /** The uploaded avatar's URL, or null. No email-derived service is used. */
    public function avatarUrl(array $user, int $size = 150): ?string
    {
        $mid = (int) ($user['avatar_media_id'] ?? 0);
        if ($mid <= 0) return null;
        $r = $this->db->selectOne('SELECT url, sizes FROM {media} WHERE id = :id', ['id' => $mid]);
        if (empty($r['url'])) return null;
        // Shown small: a resized copy for the size asked, not the original.
        return AvatarService::pick((string) $r['url'], $r['sizes'] ?? null, $size);
    }

    /**
     * What a theme may show about an author. Never the email, password hash,
     * role or login name.
     *
     * `username` is the public slug, for templates that print "@username" —
     * they keep working without showing the login name.
     */
    public function publicProfile(array $user): array
    {
        $slug = $this->slugFor($user);
        return [
            'id'           => (int) $user['id'],
            'display_name' => (string) (($user['display_name'] ?? '') !== '' ? $user['display_name'] : 'Author'),
            'bio'          => (string) ($user['bio'] ?? ''),
            'slug'         => $slug,
            'username'     => $slug,
            'url'          => $this->url($user),
            'avatar_url'   => $this->avatarUrl($user),
            'avatar_thumbnail_url' => $this->avatarUrl($user, 40),
            'post_count'   => $this->publishedCount((int) $user['id']),
        ];
    }
}
