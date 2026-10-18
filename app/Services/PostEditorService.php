<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;
use App\Http\Middleware\AdminAreaPolicy;
use App\Http\Middleware\CheckCapability;
use App\Repositories\PostRepository;

/**
 * What the post editor needs that more than one entry point shares: the
 * classic form (Admin\PostController), the AJAX actions behind Save draft and
 * the tag box (Ajax\PostActions), and anything later.
 *
 *  - Which capability an action on a content type needs, and whether a user
 *    may edit a given post. The same rules the editor screens apply, so the
 *    AJAX path is never more open than the form.
 *  - Tags typed in the editor that do not exist yet. They are kept with the
 *    post ("pending") and only become real tags when the post is published,
 *    so drafts that are abandoned never leave half-made tags behind.
 */
final class PostEditorService
{
    /** Statuses at which pending tags are created: the post is going out. */
    public const PUBLISHING = ['published', 'scheduled', 'private'];

    /** post_meta key holding pending tag names, as a JSON list. */
    public const PENDING_META = '_bh_pending_tags';

    /** Longest tag name accepted (terms.name holds 200). */
    public const TAG_MAX = 100;

    /** Most pending tags one post may carry. */
    public const PENDING_MAX = 50;

    private Application $app;

    public function __construct(private Database $db)
    {
        $this->app = Application::getInstance();
    }

    // ------------------------------------------------------------------
    // Types and permissions
    // ------------------------------------------------------------------

    /** True for post, page, template and any type an app registered. */
    public function typeExists(string $type): bool
    {
        if (in_array($type, ['post', 'page', 'template'], true)) return true;
        try { return $this->app->make(PostTypeRegistry::class)->has($type); } catch (\Throwable) { return false; }
    }

    /**
     * The capability for an action ('edit', 'edit_others', 'publish' …) on a
     * type, exactly as the editor's controllers spell it: post, page and
     * template have their own family; an app's type uses its capability_type
     * (the post family by default).
     */
    public function capability(string $type, string $action): string
    {
        if (in_array($type, ['post', 'page', 'template'], true)) {
            return $action . '_' . $type . 's';
        }
        try {
            return $this->app->make(PostTypeRegistry::class)->capability($type, $action);
        } catch (\Throwable) {
            return $action . '_posts';
        }
    }

    /** The admin path whose screens edit this type. */
    public function areaPath(string $type): string
    {
        return match ($type) {
            'post', 'page', 'template' => "/admin/{$type}s",
            default => '/admin/content/' . $type,
        };
    }

    /** Whether the user may open this type's editor at all (AdminAreaPolicy). */
    public function canUseArea(?array $user, string $type): bool
    {
        if ($user === null) return false;
        $cap = AdminAreaPolicy::requiredFor($this->areaPath($type));
        return $cap === null || CheckCapability::userCan($user, $cap);
    }

    /** Own posts: the area is enough. Someone else's: edit_others_{family}s. */
    public function canEdit(?array $user, array $post): bool
    {
        if ($user === null || !$this->canUseArea($user, (string) $post['type'])) return false;
        if ((int) ($post['author_id'] ?? 0) === (int) $user['id']) return true;
        return CheckCapability::userCan($user, $this->capability((string) $post['type'], 'edit_others'));
    }

    public function canPublish(?array $user, string $type): bool
    {
        return $user !== null && CheckCapability::userCan($user, $this->capability($type, 'publish'));
    }

    /**
     * Who may turn a pending tag into a real one: whoever can publish the
     * post (that is the moment tags are created), or anyone who manages
     * taxonomies anyway. A contributor's new tags wait for the editor who
     * publishes the post.
     */
    public function canCreateTags(?array $user, string $type): bool
    {
        return $user !== null
            && ($this->canPublish($user, $type) || CheckCapability::userCan($user, 'manage_taxonomies'));
    }

    /** May the user edit other people's posts of this type (edit_others_{family}s)? */
    public function canEditOthers(?array $user, string $type): bool
    {
        return $user !== null && CheckCapability::userCan($user, $this->capability($type, 'edit_others'));
    }

    /** Whether the category taxonomy applies to this type (config/cms.php). */
    public function hasCategories(string $type): bool
    {
        try {
            $types = (array) ($this->app->make(\App\Core\Config::class)->get('cms.taxonomies.category.post_types', ['post']));
        } catch (\Throwable) {
            $types = ['post'];
        }
        return in_array($type, $types, true);
    }

    /**
     * The type's definition from config/cms.php (content_types) or the type
     * registry: `supports`, `hierarchical` … Empty for an unknown type.
     */
    public function typeDefinition(string $type): array
    {
        try {
            $def = (array) $this->app->make(\App\Core\Config::class)->get('cms.content_types.' . $type, []);
            if ($def !== []) return $def;
        } catch (\Throwable) {}
        try {
            return (array) ($this->app->make(PostTypeRegistry::class)->get($type) ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Whether a type declares a feature in its `supports` list ('comments', 'author' …). */
    public function supports(string $type, string $feature): bool
    {
        return in_array($feature, (array) ($this->typeDefinition($type)['supports'] ?? []), true);
    }

    /** Whether the type has parents and an order (pages). */
    public function isHierarchical(string $type): bool
    {
        return !empty($this->typeDefinition($type)['hierarchical']);
    }

    /** Whether the tag taxonomy applies to this type (config/cms.php). */
    public function hasTags(string $type): bool
    {
        try {
            $types = (array) ($this->app->make(\App\Core\Config::class)->get('cms.taxonomies.tag.post_types', ['post']));
        } catch (\Throwable) {
            $types = ['post'];
        }
        return in_array($type, $types, true);
    }

    // ------------------------------------------------------------------
    // Terms
    // ------------------------------------------------------------------

    /**
     * The IDs that are real terms, in the order given, without duplicates.
     * A term ID that no longer exists (deleted in another tab) used to reach
     * attachTerms() and fail on the foreign key: a 500 on Save.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function existingTermIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $found = array_map('intval', array_column(
            $this->db->select("SELECT id FROM {terms} WHERE id IN ({$in})", $ids), 'id'));
        return array_values(array_filter($ids, fn($i) => in_array($i, $found, true)));
    }

    /** The tag taxonomy's id, or null when it does not exist. */
    public function tagTaxonomyId(): ?int
    {
        $row = $this->db->selectOne("SELECT id FROM {taxonomies} WHERE slug = 'tag' LIMIT 1");
        return $row ? (int) $row['id'] : null;
    }

    /** A tag term by id, or null if it is not a tag. */
    public function findTag(int $id): ?array
    {
        $tax = $this->tagTaxonomyId();
        if ($tax === null || $id <= 0) return null;
        return $this->db->selectOne(
            'SELECT id, name, slug, count FROM {terms} WHERE id = :id AND taxonomy_id = :tax',
            ['id' => $id, 'tax' => $tax]
        );
    }

    /**
     * An existing tag with this name: same name ignoring case, else the same
     * slug. A slug match is skipped when the name has no Latin letters to
     * make a slug from ("n-a"), or every Urdu or Arabic tag would match the
     * first one.
     */
    public function findTagByName(string $name): ?array
    {
        $tax = $this->tagTaxonomyId();
        $name = self::normaliseTagName($name);
        if ($tax === null || $name === '') return null;
        $row = $this->db->selectOne(
            'SELECT id, name, slug, count FROM {terms} WHERE taxonomy_id = :tax AND LOWER(name) = LOWER(:n) ORDER BY id LIMIT 1',
            ['tax' => $tax, 'n' => $name]
        );
        if ($row) return $row;
        $slug = \App\Core\Helpers::slug($name);
        if ($slug === 'n-a') return null;
        return $this->db->selectOne(
            'SELECT id, name, slug, count FROM {terms} WHERE taxonomy_id = :tax AND slug = :s LIMIT 1',
            ['tax' => $tax, 's' => $slug]
        );
    }

    /** "  Arduino   Uno, " → "Arduino Uno". Commas separate tags, so none inside one. */
    public static function normaliseTagName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F,]+/u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_substr($name, 0, self::TAG_MAX, 'UTF-8');
    }

    /** The tags on a post, by name. @return array<int,array{id:int,name:string,slug:string}> */
    public function tagsFor(int $postId): array
    {
        $tax = $this->tagTaxonomyId();
        if ($tax === null) return [];
        $rows = $this->db->select(
            'SELECT t.id, t.name, t.slug FROM {post_term} pt JOIN {terms} t ON t.id = pt.term_id
             WHERE pt.post_id = :p AND t.taxonomy_id = :tax ORDER BY t.name',
            ['p' => $postId, 'tax' => $tax]
        );
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']], $rows);
    }

    /** Every term id on a post (all taxonomies). @return int[] */
    public function termIdsFor(int $postId): array
    {
        return array_map('intval', array_column(
            $this->db->select('SELECT term_id FROM {post_term} WHERE post_id = :p ORDER BY term_order, term_id', ['p' => $postId]),
            'term_id'));
    }

    /** Attach one more term, keeping the others (counts are maintained). */
    public function attachTerm(int $postId, int $termId): void
    {
        $ids = $this->termIdsFor($postId);
        if (in_array($termId, $ids, true)) return;
        $ids[] = $termId;
        $this->repo()->attachTerms($postId, $ids);
        $this->flushPosts();
    }

    public function detachTerm(int $postId, int $termId): void
    {
        $ids = $this->termIdsFor($postId);
        if (!in_array($termId, $ids, true)) return;
        $this->repo()->attachTerms($postId, array_values(array_diff($ids, [$termId])));
        $this->flushPosts();
    }

    // ------------------------------------------------------------------
    // Pending (not yet created) tags
    // ------------------------------------------------------------------

    /** @return string[] */
    public function pendingTags(int $postId): array
    {
        $row = $this->db->selectOne(
            'SELECT meta_value FROM {post_meta} WHERE post_id = :p AND meta_key = :k ORDER BY id DESC LIMIT 1',
            ['p' => $postId, 'k' => self::PENDING_META]
        );
        $list = $row ? json_decode((string) $row['meta_value'], true) : [];
        return is_array($list) ? array_values(array_filter(array_map(
            fn($n) => self::normaliseTagName((string) $n), $list), fn($n) => $n !== '')) : [];
    }

    /** @param string[] $names */
    public function setPendingTags(int $postId, array $names): void
    {
        $names = self::uniqueNames($names);
        $this->db->execute('DELETE FROM {post_meta} WHERE post_id = :p AND meta_key = :k', ['p' => $postId, 'k' => self::PENDING_META]);
        if ($names !== []) {
            $this->db->insert('post_meta', [
                'post_id' => $postId, 'meta_key' => self::PENDING_META,
                'meta_value' => json_encode($names, JSON_UNESCAPED_UNICODE), 'is_json' => 1,
            ]);
        }
    }

    /**
     * After a save: settle the new tag names the editor sent.
     *
     * Names that match an existing tag are simply attached. The rest are
     * created and attached when the post's status is a publishing one and
     * the user may create tags; otherwise they are kept as pending, to be
     * created when the post is published — by this user or a later one.
     *
     * @param string[] $names every new name the editor holds (replaces the pending list)
     * @return array{created:int[],attached:int[],pending:string[]}
     */
    public function settleNewTags(int $postId, array $names, string $status, ?array $user, string $type): array
    {
        $out = ['created' => [], 'attached' => [], 'pending' => []];
        if (!$this->hasTags($type)) return $out;
        $names = array_slice(self::uniqueNames($names), 0, self::PENDING_MAX);

        $remaining = [];
        foreach ($names as $name) {
            $tag = $this->findTagByName($name);
            if ($tag) {
                $this->attachTerm($postId, (int) $tag['id']);
                $out['attached'][] = (int) $tag['id'];
            } else {
                $remaining[] = $name;
            }
        }

        if ($remaining !== [] && in_array($status, self::PUBLISHING, true) && $this->canCreateTags($user, $type)) {
            /** @var TaxonomyService $tax */
            $tax = $this->app->make(TaxonomyService::class);
            foreach ($remaining as $name) {
                $id = $tax->createTerm('tag', ['name' => $name]);
                if ($id) {
                    $this->attachTerm($postId, (int) $id);
                    $out['created'][] = (int) $id;
                }
            }
            $remaining = [];
            if ($out['created']) {
                ActivityLogService::record($user ? (int) $user['id'] : null, 'term.created', 'post', $postId,
                    'Created ' . count($out['created']) . ' tag(s) on publish');
            }
        }

        $this->setPendingTags($postId, $remaining);
        $out['pending'] = $remaining;
        return $out;
    }

    /** @param string[] $names @return string[] case-insensitively unique, normalised */
    private static function uniqueNames(array $names): array
    {
        $seen = [];
        $out = [];
        foreach ($names as $n) {
            $n = self::normaliseTagName((string) $n);
            $k = mb_strtolower($n, 'UTF-8');
            if ($n === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $n;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Tag lists for the editor
    // ------------------------------------------------------------------

    /**
     * Tags whose name starts with $q first, then those containing it.
     * @return array<int,array{id:int,name:string,slug:string,count:int}>
     */
    public function suggestTags(string $q, int $limit = 10, array $excludeIds = []): array
    {
        $tax = $this->tagTaxonomyId();
        $q = self::normaliseTagName($q);
        if ($tax === null || $q === '') return [];
        $like = addcslashes(mb_strtolower($q, 'UTF-8'), '%_\\');
        $limit = max(1, min(20, $limit));
        $rows = $this->db->select(
            "SELECT t.id, t.name, t.slug,
                    (SELECT COUNT(*) FROM {post_term} pt JOIN {posts} p ON p.id = pt.post_id
                      WHERE pt.term_id = t.id AND p.deleted_at IS NULL) AS uses,
                    CASE WHEN LOWER(t.name) LIKE :pre THEN 0 ELSE 1 END AS rank_
             FROM {terms} t
             WHERE t.taxonomy_id = :tax AND (LOWER(t.name) LIKE :any OR t.slug LIKE :slug)
             ORDER BY rank_, uses DESC, t.name
             LIMIT " . ($limit + count($excludeIds)),
            ['tax' => $tax, 'pre' => $like . '%', 'any' => '%' . $like . '%', 'slug' => '%' . addcslashes(\App\Core\Helpers::slug($q), '%_\\') . '%']
        );
        $out = [];
        foreach ($rows as $r) {
            if (in_array((int) $r['id'], $excludeIds, true)) continue;
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'count' => (int) $r['uses']];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Most used tags (on posts not in the trash), and the tags on this user's
     * most recently edited posts — falling back to everyone's when the user
     * has none yet.
     *
     * @return array{most_used:array,recent:array}
     */
    public function tagOverview(?array $user, int $limit = 15): array
    {
        $tax = $this->tagTaxonomyId();
        if ($tax === null) return ['most_used' => [], 'recent' => []];
        $limit = max(1, min(30, $limit));

        $most = $this->db->select(
            "SELECT t.id, t.name, t.slug, COUNT(*) AS uses
             FROM {terms} t JOIN {post_term} pt ON pt.term_id = t.id JOIN {posts} p ON p.id = pt.post_id
             WHERE t.taxonomy_id = :tax AND p.deleted_at IS NULL
             GROUP BY t.id, t.name, t.slug ORDER BY uses DESC, t.name LIMIT {$limit}",
            ['tax' => $tax]
        );

        $recent = [];
        foreach ($user ? [(int) $user['id'], null] : [null] as $author) {
            $rows = $this->db->select(
                "SELECT t.id, t.name, t.slug, MAX(p.updated_at) AS last_used
                 FROM {terms} t JOIN {post_term} pt ON pt.term_id = t.id JOIN {posts} p ON p.id = pt.post_id
                 WHERE t.taxonomy_id = :tax AND p.deleted_at IS NULL" . ($author !== null ? ' AND p.author_id = :a' : '') . "
                 GROUP BY t.id, t.name, t.slug ORDER BY last_used DESC, t.name LIMIT {$limit}",
                $author !== null ? ['tax' => $tax, 'a' => $author] : ['tax' => $tax]
            );
            if ($rows) { $recent = $rows; break; }
        }

        $shape = fn(array $rows, string $n) => array_map(fn($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
        ] + ($n === 'uses' ? ['count' => (int) $r['uses']] : []), $rows);

        return ['most_used' => $shape($most, 'uses'), 'recent' => $shape($recent, 'recent')];
    }

    // ------------------------------------------------------------------

    private function repo(): PostRepository
    {
        return $this->app->make(PostRepository::class);
    }

    private function flushPosts(): void
    {
        try { $this->app->make(\App\Core\Cache::class)->flushTag('posts'); } catch (\Throwable) {}
    }
}
