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
 * users.meta as `author_slug` so it stays put when the display name changes. It
 * is created the first time it is needed, and can be edited on the user's
 * profile.
 *
 * Only users with at least one published post have an author page. Anyone
 * else — subscribers, editors who have not published — is a 404, so the pages
 * cannot be used to list a site's accounts.
 */
final class AuthorService
{
    private const META_KEY = 'author_slug';

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
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) return null;

        $user = $this->userWithSlug($slug);
        if (!$user) {
            // Slugs are made on first use. An author nobody has linked to yet
            // has none, so make them for every author and look again.
            foreach ($this->authorRows() as $row) $this->slugFor($row);
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
        $meta = $this->meta($user);
        $slug = (string) ($meta[self::META_KEY] ?? '');
        if ($slug !== '') return $slug;

        $slug = $this->uniqueSlug($this->baseSlug($user), (int) $user['id']);
        $this->saveSlug((int) $user['id'], $slug, $meta);
        return $slug;
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
        $base = Helpers::slug($input);
        if ($base === '' || $base === 'n-a') return null;
        $slug = $this->uniqueSlug($base, $userId);
        $this->saveSlug($userId, $slug, $this->meta($user));
        return $slug;
    }

    private function baseSlug(array $user): string
    {
        $name = trim((string) ($user['display_name'] ?? ''));
        $base = $name !== '' ? Helpers::slug($name) : '';
        if ($base === '' || $base === 'n-a') $base = 'author';

        // A display name identical to the login name would publish the login
        // name. Keep the name recognisable but make the slug differ from it.
        if (strcasecmp($base, (string) ($user['username'] ?? '')) === 0) {
            $base .= '-' . (int) $user['id'];
        }
        return substr($base, 0, 180);
    }

    private function uniqueSlug(string $base, int $userId): string
    {
        $taken = [];
        foreach ($this->db->select('SELECT id, meta FROM {users}') as $row) {
            if ((int) $row['id'] === $userId) continue;
            $s = (string) ($this->meta($row)[self::META_KEY] ?? '');
            if ($s !== '') $taken[$s] = true;
        }
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) $slug = $base . '-' . $n;
        return $slug;
    }

    private function saveSlug(int $userId, string $slug, array $meta): void
    {
        $meta[self::META_KEY] = $slug;
        $this->db->execute(
            'UPDATE {users} SET meta = :m WHERE id = :id',
            ['m' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $userId]
        );
        if (isset($this->users[$userId]) && is_array($this->users[$userId])) {
            $this->users[$userId]['meta'] = json_encode($meta);
        }
    }

    private function userWithSlug(string $slug): ?array
    {
        // Narrowed in SQL, confirmed in PHP: meta is free-form JSON.
        $rows = $this->db->select(
            'SELECT * FROM {users} WHERE deleted_at IS NULL AND meta LIKE :like',
            ['like' => '%' . $slug . '%']
        );
        foreach ($rows as $row) {
            if (($this->meta($row)[self::META_KEY] ?? null) === $slug) {
                $this->users[(int) $row['id']] = $row;
                return $row;
            }
        }
        return null;
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
    public function avatarUrl(array $user): ?string
    {
        $mid = (int) ($user['avatar_media_id'] ?? 0);
        if ($mid <= 0) return null;
        $r = $this->db->selectOne('SELECT url FROM {media} WHERE id = :id', ['id' => $mid]);
        return !empty($r['url']) ? (string) $r['url'] : null;
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
            'post_count'   => $this->publishedCount((int) $user['id']),
        ];
    }
}
