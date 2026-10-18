<?php

declare(strict_types=1);

/**
 * Core Data Table definitions. See docs/DATA-TABLE.md.
 *
 * Mirrors routes/ajax.php: core registers its tables here under stable keys;
 * apps add columns from boot() with the `datatable.columns.{key}` filter.
 * Each table names the admin capability and area it serves, so the one AJAX
 * action that reads it is never more open than the screen.
 *
 * Column value sources:
 *   'value'  => a row key, HTML-escaped by core (plain fields).
 *   'render' => fn(array $row, $prepared): string  (HTML; core columns trusted).
 *   'prepare'=> fn(array $rows): mixed  — ONE bulk query for the whole page,
 *               its result handed to every render() as $prepared (no N+1).
 */

use App\Core\Admin\Components;
use App\Core\Database;
use App\Core\Table\TableRegistry;
use App\Core\Time;

/** @var \App\Core\Application $app */
$tables = $app->make(TableRegistry::class);
$db = $app->make(Database::class);

/** A status pill, shared by posts and pages. */
$statusBadge = static function (array $row): string {
    $status = (string) ($row['status'] ?? '');
    $map = [
        'published' => 'bg-green-100 text-green-700',
        'draft'     => 'bg-amber-100 text-amber-700',
        'pending'   => 'bg-blue-100 text-blue-700',
        'scheduled' => 'bg-violet-100 text-violet-700',
        'private'   => 'bg-slate-200 text-slate-700',
    ];
    $cls = $map[$status] ?? 'bg-slate-100 text-slate-600';
    return '<span class="bh-dt-badge ' . $cls . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES) . '</span>';
};

/** Title cell: a link to the editor plus the slug. */
$titleCell = static function (array $row): string {
    $type = (string) ($row['type'] ?? 'post');
    $id   = (int) ($row['id'] ?? 0);
    $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
    $url  = $base . '/admin/' . rawurlencode($type) . 's/' . $id . '/edit';
    $title = htmlspecialchars((string) ($row['title'] ?? '(no title)'), ENT_QUOTES);
    $slug  = htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES);
    return '<a class="bh-dt-title" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $title . '</a>'
         . ($slug !== '' ? '<span class="bh-dt-sub">/' . $slug . '</span>' : '');
};

/** Date cell: published (or created) date in the site timezone. */
$dateCell = static function (array $row): string {
    $raw = $row['published_at'] ?? $row['created_at'] ?? null;
    $dt = Time::local($raw);
    if ($dt === null) {
        return '';
    }
    // A scheduled post's date is when it will go live (1.2.60): say so.
    $when = ($row['status'] ?? '') === 'scheduled' ? 'Goes live ' : '';
    return '<span class="bh-dt-date">' . htmlspecialchars($dt->format(Time::dateFormat()), ENT_QUOTES) . '</span>'
         . '<span class="bh-dt-sub">' . htmlspecialchars($when . $dt->format(Time::timeFormat()), ENT_QUOTES) . '</span>';
};

/**
 * Category column — the built-in example of the two-phase contract. prepare()
 * runs ONE query for the whole page (WHERE post_id IN …); render() only reads
 * that map. 100 rows cost one query here, not one hundred.
 */
$categoryPrepare = static function (array $rows) use ($db): array {
    $ids = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', $ids);  // ints only — safe to inline
    $sql = "SELECT pt.post_id, t.name
              FROM {post_term} pt
              JOIN {terms} t ON t.id = pt.term_id
              JOIN {taxonomies} tx ON tx.id = t.taxonomy_id
             WHERE tx.slug = 'category' AND pt.post_id IN ($in)
             ORDER BY t.name";
    $map = [];
    foreach ($db->select($sql) as $r) {
        $map[(int) $r['post_id']][] = (string) $r['name'];
    }
    return $map;
};
$categoryCell = static function (array $row, $prepared): string {
    $names = (is_array($prepared) ? ($prepared[(int) ($row['id'] ?? 0)] ?? []) : []);
    if ($names === []) {
        return '<span class="bh-dt-sub">—</span>';
    }
    $out = [];
    foreach ($names as $n) {
        $out[] = '<span class="bh-dt-chip">' . htmlspecialchars((string) $n, ENT_QUOTES) . '</span>';
    }
    return implode(' ', $out);
};

/**
 * Tags column — same two-phase contract as Category, but each tag links to its
 * edit screen. prepare() loads every post's tags in one query; render() reads
 * the map and emits a chip-link per tag.
 */
$tagsPrepare = static function (array $rows) use ($db): array {
    $ids = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', $ids);  // ints only — safe to inline
    $sql = "SELECT pt.post_id, t.id, t.name
              FROM {post_term} pt
              JOIN {terms} t ON t.id = pt.term_id
              JOIN {taxonomies} tx ON tx.id = t.taxonomy_id
             WHERE tx.slug = 'tag' AND pt.post_id IN ($in)
             ORDER BY t.name";
    $map = [];
    foreach ($db->select($sql) as $r) {
        $map[(int) $r['post_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
    }
    return $map;
};
$tagsCell = static function (array $row, $prepared): string {
    $tags = (is_array($prepared) ? ($prepared[(int) ($row['id'] ?? 0)] ?? []) : []);
    if ($tags === []) {
        return '<span class="bh-dt-sub">—</span>';
    }
    $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
    $out = [];
    foreach ($tags as $t) {
        $url = $base . '/admin/taxonomies/tag?edit=' . (int) $t['id'] . '#term-row-' . (int) $t['id'];
        $out[] = '<a class="bh-dt-chip bh-dt-chip--link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
               . htmlspecialchars((string) $t['name'], ENT_QUOTES) . '</a>';
    }
    return implode(' ', $out);
};

$postStatusFilter = ['field' => 'status', 'in' => ['draft', 'published', 'pending', 'scheduled', 'private']];

// Reusable components: a media thumbnail and an author-with-avatar cell.
// Prefer the generated thumbnail size over the original, so the list stays light.
$thumbCell = static function (array $row): string {
    $url = $row['featured_sizes']['thumbnail']['url']
        ?? $row['featured_sizes']['medium']['url']
        ?? $row['featured_url']
        ?? null;
    return Components::thumb($url, (string) ($row['featured_alt'] ?? ($row['title'] ?? '')), 40);
};
$authorCell = static fn (array $row): string =>
    Components::userCell((string) ($row['author_name'] ?? '—'), $row['author_avatar_url'] ?? null, null, 28);

$postColumns = [
    ['id' => 'thumb',  'label' => 'Image', 'render' => $thumbCell,
     'default_visible' => true, 'can_hide' => true, 'align' => 'center'],
    ['id' => 'title',  'label' => 'Title',  'render' => $titleCell,
     'sortable' => ['title_az', 'title_za'], 'default_visible' => true, 'can_hide' => false],
    ['id' => 'author', 'label' => 'Author', 'render' => $authorCell, 'default_visible' => true],
    ['id' => 'category', 'label' => 'Category', 'render' => $categoryCell, 'prepare' => $categoryPrepare,
     'default_visible' => true, 'responsive' => 'tablet-up'],
    ['id' => 'tags', 'label' => 'Tags', 'render' => $tagsCell, 'prepare' => $tagsPrepare,
     'default_visible' => true, 'responsive' => 'tablet-up'],
    ['id' => 'status', 'label' => 'Status', 'render' => $statusBadge, 'filter' => $postStatusFilter,
     'default_visible' => true, 'align' => 'center'],
    ['id' => 'views',  'label' => 'Views', 'value' => 'view_count', 'default_visible' => true,
     'sortable' => ['views_desc', 'views_asc'], 'align' => 'center', 'responsive' => 'desktop-only'],
    ['id' => 'date',   'label' => 'Date', 'render' => $dateCell, 'sortable' => ['newest', 'oldest'],
     'default_visible' => true, 'align' => 'right'],
];

$tables->register('content.posts', [
    'label'        => 'Posts',
    'capability'   => 'edit_posts',
    'area'         => '/admin/posts',
    'provider'     => \App\Services\Table\PostTableProvider::class,
    'default_sort' => 'newest',
    'page_sizes'   => [8, 20, 50, 100],
    'columns'      => $postColumns,
    'row_actions'  => ['edit', 'trash'],
    'bulk_actions' => ['publish', 'draft', 'delete'],
    'search_placeholder' => 'Search posts, titles, or author…',
]);

$tables->register('content.pages', [
    'label'        => 'Pages',
    'capability'   => 'edit_pages',
    'area'         => '/admin/pages',
    'provider'     => \App\Services\Table\PageTableProvider::class,
    'default_sort' => 'newest',
    'page_sizes'   => [8, 20, 50, 100],
    'columns'      => $postColumns,
    'row_actions'  => ['edit', 'trash'],
    'bulk_actions' => ['publish', 'draft', 'delete'],
    'search_placeholder' => 'Search pages…',
]);

// Templates — posts of type 'template'. A leaner column set (no categories or
// views, which templates do not use).
$tables->register('content.templates', [
    'label'        => 'Templates',
    'capability'   => 'edit_posts',
    'area'         => '/admin/templates',
    'provider'     => \App\Services\Table\TemplateTableProvider::class,
    'default_sort' => 'newest',
    'page_sizes'   => [8, 20, 50, 100],
    'columns'      => [
        ['id' => 'title',  'label' => 'Title', 'render' => $titleCell,
         'sortable' => ['title_az', 'title_za'], 'default_visible' => true, 'can_hide' => false],
        ['id' => 'author', 'label' => 'Author', 'render' => $authorCell, 'default_visible' => true],
        ['id' => 'status', 'label' => 'Status', 'render' => $statusBadge, 'filter' => $postStatusFilter,
         'default_visible' => true, 'align' => 'center'],
        ['id' => 'date',   'label' => 'Date', 'render' => $dateCell, 'sortable' => ['newest', 'oldest'],
         'default_visible' => true, 'align' => 'right'],
    ],
    'row_actions'  => ['edit', 'trash'],
    'bulk_actions' => ['publish', 'draft', 'delete'],
    'search_placeholder' => 'Search templates…',
]);

// A second schema, to prove the adapter is not posts-only.
$commentStatus = ['field' => 'status', 'in' => ['approved', 'pending', 'spam', 'trash']];
$tables->register('comments.list', [
    'label'        => 'Comments',
    'capability'   => 'moderate_comments',
    'area'         => '/admin/comments',
    'provider'     => \App\Services\Table\CommentTableProvider::class,
    'default_sort' => '',
    'page_sizes'   => [20, 50, 100],
    'columns'      => [
        ['id' => 'author', 'label' => 'Author', 'can_hide' => false, 'render' => static function (array $row): string {
            $name  = trim((string) ($row['author_name'] ?? ''));
            $email = trim((string) ($row['author_email'] ?? ''));
            $h = '<span class="bh-dt-title">' . htmlspecialchars($name !== '' ? $name : 'Anonymous', ENT_QUOTES) . '</span>';
            if ($email !== '') {
                $h .= '<span class="bh-dt-sub">' . htmlspecialchars($email, ENT_QUOTES) . '</span>';
            }
            return $h;
        }],
        ['id' => 'content', 'label' => 'Comment', 'render' => static function (array $row): string {
            $text = (string) ($row['content'] ?? '');
            if (mb_strlen($text) > 140) {
                $text = mb_substr($text, 0, 140) . '…';
            }
            return '<span class="bh-dt-sub">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
        }],
        ['id' => 'post', 'label' => 'On post', 'responsive' => 'tablet-up', 'render' => static function (array $row): string {
            $title = trim((string) ($row['post_title'] ?? ''));
            if ($title === '') {
                return '<span class="bh-dt-sub">—</span>';
            }
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            $url  = $base . '/admin/posts/' . (int) ($row['post_id'] ?? 0) . '/edit';
            return '<a class="bh-dt-chip bh-dt-chip--link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                 . htmlspecialchars($title, ENT_QUOTES) . '</a>';
        }],
        ['id' => 'status', 'label' => 'Status', 'render' => static function (array $row): string {
            $s = (string) ($row['status'] ?? '');
            $map = ['approved' => 'bg-green-100 text-green-700', 'pending' => 'bg-amber-100 text-amber-700',
                    'spam' => 'bg-red-100 text-red-700', 'trash' => 'bg-slate-200 text-slate-700'];
            return '<span class="bh-dt-badge ' . ($map[$s] ?? 'bg-slate-100 text-slate-600') . '">'
                 . htmlspecialchars(ucfirst($s), ENT_QUOTES) . '</span>';
        }, 'filter' => $commentStatus, 'align' => 'center'],
        ['id' => 'date', 'label' => 'Date', 'align' => 'right', 'responsive' => 'tablet-up', 'render' => static function (array $row): string {
            $dt = Time::local($row['created_at'] ?? null);
            if ($dt === null) {
                return '';
            }
            return '<span class="bh-dt-date">' . htmlspecialchars($dt->format(Time::dateFormat()), ENT_QUOTES) . '</span>'
                 . '<span class="bh-dt-sub">' . htmlspecialchars($dt->format(Time::timeFormat()), ENT_QUOTES) . '</span>';
        }],
    ],
    // No per-row Edit screen for comments — moderation happens inline through
    // the row action menu and bulk bar, which are built from bulk_actions.
    'row_actions'  => [],
    'bulk_actions' => ['approve', 'pending', 'spam', 'delete'],
    'search_placeholder' => 'Search comments…',
]);
