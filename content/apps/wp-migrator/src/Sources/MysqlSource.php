<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Sources;

/**
 * MysqlSource
 *
 * Reads WordPress data directly from a live `wp_*` MySQL database.
 * Requires read-only credentials. Connection failures are mapped to
 * RuntimeExceptions with friendly messages.
 *
 * The table prefix is configurable (default 'wp_'); for sites that use
 * a custom prefix, pass it via the config when constructing.
 */
class MysqlSource implements Source
{
    private \PDO $pdo;
    private string $prefix;
    private string $siteUrl = '';

    public function __construct(array $config)
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 3306);
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $charset  = $config['charset']  ?? 'utf8mb4';
        $this->prefix = $config['prefix'] ?? 'wp_';

        if (!$database) {
            throw new \RuntimeException('Database name is required.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        try {
            $this->pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Could not connect to WordPress database: ' . $e->getMessage());
        }

        // Resolve site URL once.
        $row = $this->pdo->query(
            "SELECT option_value FROM {$this->prefix}options WHERE option_name = 'siteurl'"
        )->fetch();
        $this->siteUrl = $row ? (string)$row['option_value'] : '';
    }

    public function siteUrl(): string { return $this->siteUrl; }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    public function countUsers(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}users")->fetchColumn();
    }

    public function fetchUsers(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered
             FROM {$this->prefix}users u
             ORDER BY u.ID
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Terms — taxonomy stored across 3 tables (terms, term_taxonomy,
    // term_relationships). We join terms+term_taxonomy here.
    // ------------------------------------------------------------------

    public function countTerms(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}term_taxonomy WHERE taxonomy IN ('category','post_tag')"
        )->fetchColumn();
    }

    public function fetchTerms(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.term_id AS old_id, tt.taxonomy, t.slug, t.name, tt.description, tt.parent
             FROM {$this->prefix}term_taxonomy tt
             JOIN {$this->prefix}terms t ON t.term_id = tt.term_id
             WHERE tt.taxonomy IN ('category','post_tag')
             ORDER BY t.term_id
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        // Normalize to match WXR shape.
        foreach ($rows as &$r) {
            $r['taxonomy'] = $r['taxonomy'] === 'post_tag' ? 'tag' : 'category';
            // We don't fetch parent_slug here; PostImporter/TaxonomyImporter
            // looks up parent via IdMap by old_id instead.
            $r['parent_slug'] = '';
            $r['parent_id'] = (int)$r['parent'];
            unset($r['parent']);
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Posts (post, page; excludes attachment + revisions)
    // ------------------------------------------------------------------

    public function countPosts(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}posts
             WHERE post_type IN ('post','page')
             AND post_status NOT IN ('auto-draft','inherit','trash')"
        )->fetchColumn();
    }

    public function fetchPosts(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, u.user_login
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}users u ON u.ID = p.post_author
             WHERE p.post_type IN ('post','page')
             AND p.post_status NOT IN ('auto-draft','inherit','trash')
             ORDER BY p.ID
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $posts = $stmt->fetchAll();

        // Attach categories, tags, and postmeta for each post.
        foreach ($posts as &$p) {
            $p['post_author'] = (string)($p['user_login'] ?? '');
            $p['categories'] = $this->termSlugsFor($p['ID'], 'category');
            $p['tags']       = $this->termSlugsFor($p['ID'], 'post_tag');
            $p['postmeta']   = $this->postmetaFor((int)$p['ID']);
        }
        return $posts;
    }

    private function termSlugsFor(int $postId, string $taxonomy): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.slug
             FROM {$this->prefix}term_relationships tr
             JOIN {$this->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             JOIN {$this->prefix}terms t ON t.term_id = tt.term_id
             WHERE tr.object_id = :pid AND tt.taxonomy = :tx"
        );
        $stmt->execute(['pid' => $postId, 'tx' => $taxonomy]);
        return array_column($stmt->fetchAll(), 'slug');
    }

    private function postmetaFor(int $postId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT meta_key, meta_value FROM {$this->prefix}postmeta WHERE post_id = :p"
        );
        $stmt->execute(['p' => $postId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Attachments
    // ------------------------------------------------------------------

    public function countAttachments(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}posts WHERE post_type = 'attachment'"
        )->fetchColumn();
    }

    public function fetchAttachments(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, pm.meta_value AS attached_file
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}postmeta pm
                ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             ORDER BY p.ID
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            // Reconstruct attachment URL: siteUrl + wp-content/uploads/<attached_file>.
            $r['attachment_url'] = $r['guid'] ?: ($this->siteUrl . '/wp-content/uploads/' . $r['attached_file']);
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Comments
    // ------------------------------------------------------------------

    public function countComments(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}comments WHERE comment_type IN ('', 'comment')"
        )->fetchColumn();
    }

    public function fetchComments(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}comments
             WHERE comment_type IN ('', 'comment')
             ORDER BY comment_ID
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Fetch all menu items as a flat list — used by MenuImporter.
     * Each item: id, parent_id, menu_slug, title, url, type, object_id,
     *           menu_order, target, classes.
     */
    public function fetchMenuItems(): array
    {
        // WP menus are: nav_menu_item posts whose taxonomy relationships
        // point at terms in the `nav_menu` taxonomy.
        $stmt = $this->pdo->prepare(
            "SELECT p.ID AS item_id, p.menu_order, p.post_title, p.post_parent,
                    t.slug AS menu_slug, t.name AS menu_name,
                    pm_type.meta_value     AS object_type,
                    pm_object.meta_value   AS object_id,
                    pm_url.meta_value      AS url,
                    pm_target.meta_value   AS target,
                    pm_classes.meta_value  AS css_classes
             FROM {$this->prefix}posts p
             JOIN {$this->prefix}term_relationships tr ON tr.object_id = p.ID
             JOIN {$this->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'nav_menu'
             JOIN {$this->prefix}terms t ON t.term_id = tt.term_id
             LEFT JOIN {$this->prefix}postmeta pm_type    ON pm_type.post_id    = p.ID AND pm_type.meta_key    = '_menu_item_object'
             LEFT JOIN {$this->prefix}postmeta pm_object  ON pm_object.post_id  = p.ID AND pm_object.meta_key  = '_menu_item_object_id'
             LEFT JOIN {$this->prefix}postmeta pm_url     ON pm_url.post_id     = p.ID AND pm_url.meta_key     = '_menu_item_url'
             LEFT JOIN {$this->prefix}postmeta pm_target  ON pm_target.post_id  = p.ID AND pm_target.meta_key  = '_menu_item_target'
             LEFT JOIN {$this->prefix}postmeta pm_classes ON pm_classes.post_id = p.ID AND pm_classes.meta_key = '_menu_item_classes'
             WHERE p.post_type = 'nav_menu_item' AND p.post_status = 'publish'
             ORDER BY t.term_id, p.menu_order"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Fetch all categories/tags with their parent_id, for relationship rebuilding. */
    public function fetchAllTerms(): array
    {
        return $this->pdo->query(
            "SELECT t.term_id AS old_id, tt.taxonomy, t.slug, t.name, tt.description, tt.parent
             FROM {$this->prefix}term_taxonomy tt
             JOIN {$this->prefix}terms t ON t.term_id = tt.term_id
             WHERE tt.taxonomy IN ('category','post_tag')"
        )->fetchAll();
    }
}
