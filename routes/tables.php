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
    // data-card-quiet: the usual state, left off cards (1.2.65) so they show
    // only what needs attention (Draft, Scheduled …).
    $quiet = $status === 'published' ? ' data-card-quiet' : '';
    return '<span class="bh-dt-badge ' . $cls . '"' . $quiet . '>' . htmlspecialchars(ucfirst($status), ENT_QUOTES) . '</span>';
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

/**
 * Public addresses for View / Preview (1.2.62). Under category permalinks a
 * post's URL needs its first category, so they are loaded for the whole page
 * in one query rather than one per row (Helpers::lookupPrimaryCategory()).
 * Unpublished rows link to the same address: it serves a preview to people
 * who may edit them (RendersTheme::canPreview()) and a 404 to everyone else.
 */
$postLinks = static function (array $rows) use ($db): array {
    $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
    $ids = array_values(array_filter(array_map(static fn($r) => ($r['type'] ?? '') === 'post' ? (int) ($r['id'] ?? 0) : 0, $rows)));
    $cats = [];
    if ($ids !== [] && \App\Core\Helpers::permalinkStructure() === 'category') {
        $in = implode(',', $ids);   // ints only — safe to inline
        foreach ($db->select("SELECT pt.post_id, t.slug FROM {post_term} pt
                                JOIN {terms} t ON t.id = pt.term_id JOIN {taxonomies} x ON x.id = t.taxonomy_id
                               WHERE x.slug = 'category' AND pt.post_id IN ($in)
                            ORDER BY pt.post_id, pt.term_order ASC") as $r) {
            $cats[(int) $r['post_id']] ??= (string) $r['slug'];
        }
    }
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || (string) ($row['slug'] ?? '') === '' || ($row['type'] ?? '') === 'template') continue;
        $out[$id] = [
            'url'  => \App\Core\Helpers::postUrl($row + ['primary_category_slug' => $cats[$id] ?? ''], $base, $cats[$id] ?? ''),
            'live' => ($row['status'] ?? '') === 'published',
        ];
    }
    return $out;
};

// `card` places each column on the cards phones show (1.2.65): picture, title
// with its category and status under it, author beside it, views and date on
// the right. Tags stay in the table only.
$postColumns = [
    ['id' => 'thumb',  'label' => 'Image', 'render' => $thumbCell,
     'default_visible' => true, 'can_hide' => true, 'align' => 'center', 'card' => 'media'],
    ['id' => 'title',  'label' => 'Title',  'render' => $titleCell,
     'sortable' => ['title_az', 'title_za'], 'default_visible' => true, 'can_hide' => false, 'card' => 'title'],
    ['id' => 'author', 'label' => 'Author', 'render' => $authorCell, 'default_visible' => true, 'card' => 'side'],
    ['id' => 'category', 'label' => 'Category', 'render' => $categoryCell, 'prepare' => $categoryPrepare,
     'default_visible' => true, 'responsive' => 'tablet-up', 'card' => 'under'],
    ['id' => 'tags', 'label' => 'Tags', 'render' => $tagsCell, 'prepare' => $tagsPrepare,
     'default_visible' => true, 'responsive' => 'tablet-up', 'card' => 'hide'],
    ['id' => 'status', 'label' => 'Status', 'render' => $statusBadge, 'filter' => $postStatusFilter,
     'default_visible' => true, 'align' => 'center', 'card' => 'under'],
    ['id' => 'views',  'label' => 'Views', 'value' => 'view_count', 'default_visible' => true,
     'sortable' => ['views_desc', 'views_asc'], 'align' => 'center', 'responsive' => 'desktop-only',
     'card' => 'meta', 'card_icon' => 'eye'],
    ['id' => 'date',   'label' => 'Date', 'render' => $dateCell, 'sortable' => ['newest', 'oldest'],
     'default_visible' => true, 'align' => 'right', 'card' => 'meta', 'card_icon' => 'calendar'],
];

$tables->register('content.posts', [
    'label'        => 'Posts',
    'capability'   => 'edit_posts',
    'area'         => '/admin/posts',
    'provider'     => \App\Services\Table\PostTableProvider::class,
    'quick_edit'   => \App\Services\Table\PostQuickEdit::class,
    'row_links'    => $postLinks,
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
    'quick_edit'   => \App\Services\Table\PageQuickEdit::class,
    'row_links'    => $postLinks,
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
         'sortable' => ['title_az', 'title_za'], 'default_visible' => true, 'can_hide' => false, 'card' => 'title'],
        ['id' => 'author', 'label' => 'Author', 'render' => $authorCell, 'default_visible' => true, 'card' => 'side'],
        ['id' => 'status', 'label' => 'Status', 'render' => $statusBadge, 'filter' => $postStatusFilter,
         'default_visible' => true, 'align' => 'center', 'card' => 'under'],
        ['id' => 'date',   'label' => 'Date', 'render' => $dateCell, 'sortable' => ['newest', 'oldest'],
         'default_visible' => true, 'align' => 'right', 'card' => 'meta', 'card_icon' => 'calendar'],
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
        }, 'card' => 'title'],
        ['id' => 'content', 'label' => 'Comment', 'card' => 'sub', 'render' => static function (array $row): string {
            $text = (string) ($row['content'] ?? '');
            if (mb_strlen($text) > 140) {
                $text = mb_substr($text, 0, 140) . '…';
            }
            return '<span class="bh-dt-sub">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
        }],
        ['id' => 'post', 'label' => 'On post', 'responsive' => 'tablet-up', 'card' => 'under', 'render' => static function (array $row): string {
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
            return '<span class="bh-dt-badge ' . ($map[$s] ?? 'bg-slate-100 text-slate-600') . '"' . ($s === 'approved' ? ' data-card-quiet' : '') . '>'
                 . htmlspecialchars(ucfirst($s), ENT_QUOTES) . '</span>';
        }, 'filter' => $commentStatus, 'align' => 'center', 'card' => 'under'],
        ['id' => 'date', 'label' => 'Date', 'align' => 'right', 'responsive' => 'tablet-up', 'card' => 'meta', 'card_icon' => 'calendar', 'render' => static function (array $row): string {
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

// ---------------------------------------------------------------------------
// Media Library, List view (1.2.67). The grid and the picker read the same
// MediaService::paginate(); this is that query as a Data Table. File names
// open the details dialog on the Media Library screen (data-media-open);
// elsewhere they are plain text.
// ---------------------------------------------------------------------------
$mediaKind = static function (array $row): string {
    $mime = (string) ($row['mime_type'] ?? '');
    if ($mime === 'image/svg+xml') return 'svg';
    foreach (['image', 'video', 'audio'] as $k) {
        if (str_starts_with($mime, $k . '/')) return $k;
    }
    return 'document';
};
$mediaSizes = static function (array $row): array {
    $s = $row['sizes'] ?? null;
    if (is_string($s) && $s !== '') $s = json_decode($s, true);
    return is_array($s) ? $s : [];
};
$mediaKindLabels = ['image' => 'Image', 'svg' => 'SVG', 'video' => 'Video', 'audio' => 'Audio', 'document' => 'Document'];

$tables->register('media.library', [
    'label'        => 'Files',
    'capability'   => 'upload_media',
    'area'         => '/admin/media',
    'provider'     => \App\Services\Table\MediaTableProvider::class,
    // The file itself, as "View" in the row menu (new tab).
    'row_links'    => static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['id']) && !empty($r['url'])) $out[(int) $r['id']] = ['url' => (string) $r['url'], 'live' => true];
        }
        return $out;
    },
    'default_sort' => 'newest',
    'page_sizes'   => [20, 50, 100],
    'columns'      => [
        ['id' => 'thumb', 'label' => 'Preview', 'align' => 'center', 'card' => 'media', 'default_visible' => true,
         'render' => static function (array $row) use ($mediaKind, $mediaSizes): string {
            $kind = $mediaKind($row);
            if ($kind === 'image' || $kind === 'svg') {
                $s = $mediaSizes($row);
                // The generated thumbnail, never the original, so the list stays light.
                $url = $s['thumbnail']['url'] ?? $s['medium']['url'] ?? ($row['url'] ?? null);
                return Components::thumb($url, (string) ($row['alt_text'] ?? ''), 44);
            }
            return Components::thumb(null, '', 44, ['video' => 'film', 'audio' => 'musical-note'][$kind] ?? 'document');
        }],
        ['id' => 'name', 'label' => 'File', 'can_hide' => false, 'card' => 'title', 'default_visible' => true,
         'sortable' => ['title_az', 'title_za'],
         'render' => static function (array $row): string {
            $id = (int) ($row['id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            $file = (string) ($row['file_name'] ?? '');
            $name = $title !== '' ? $title : ((string) ($row['original_name'] ?? '') ?: $file);
            return '<button type="button" class="bh-dt-title bh-media-open" data-media-open="' . $id . '">'
                 . htmlspecialchars($name, ENT_QUOTES) . '</button>'
                 . '<span class="bh-dt-sub">' . htmlspecialchars($file, ENT_QUOTES) . '</span>';
        }],
        ['id' => 'type', 'label' => 'Type', 'card' => 'under', 'default_visible' => true,
         'filter' => ['field' => 'type', 'in' => array_keys($mediaKindLabels)],
         'render' => static function (array $row) use ($mediaKind, $mediaKindLabels): string {
            $kind = $mediaKind($row);
            return '<span class="bh-dt-badge bg-slate-100 text-slate-600" title="' . htmlspecialchars((string) ($row['mime_type'] ?? ''), ENT_QUOTES) . '">'
                 . $mediaKindLabels[$kind] . '</span>';
        }],
        ['id' => 'size', 'label' => 'Size', 'align' => 'right', 'card' => 'meta', 'default_visible' => true,
         'sortable' => ['size_desc', 'size_asc'],
         'render' => static fn (array $row): string =>
            '<span class="bh-dt-sub" style="white-space:nowrap">' . htmlspecialchars(\App\Core\Helpers::bytesFormat((int) ($row['file_size'] ?? 0)), ENT_QUOTES) . '</span>'],
        ['id' => 'dimensions', 'label' => 'Dimensions', 'align' => 'right', 'responsive' => 'tablet-up', 'card' => 'hide', 'default_visible' => true,
         'render' => static fn (array $row): string => !empty($row['width']) && !empty($row['height'])
            ? '<span class="bh-dt-sub" style="white-space:nowrap">' . (int) $row['width'] . ' × ' . (int) $row['height'] . '</span>'
            : '<span class="bh-dt-sub">—</span>'],
        ['id' => 'date', 'label' => 'Uploaded', 'align' => 'right', 'card' => 'meta', 'card_icon' => 'calendar', 'default_visible' => true,
         'sortable' => ['newest', 'oldest'],
         'render' => static function (array $row): string {
            $dt = Time::local($row['created_at'] ?? null);
            if ($dt === null) return '';
            return '<span class="bh-dt-date">' . htmlspecialchars($dt->format(Time::dateFormat()), ENT_QUOTES) . '</span>'
                 . '<span class="bh-dt-sub">' . htmlspecialchars($dt->format(Time::timeFormat()), ENT_QUOTES) . '</span>';
        }],
        ['id' => 'actions', 'label' => 'Actions', 'align' => 'right', 'card' => 'hide', 'default_visible' => true,
         'render' => static function (array $row): string {
            $id = (int) ($row['id'] ?? 0);
            return '<span class="bh-media-acts">'
                 . '<button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-media-open="' . $id . '" title="Details and edit">' . icon('pencil-square', 'w-4 h-4') . '<span class="bh-sr-only">Details</span></button>'
                 . '<button type="button" class="bh-btn bh-btn--ghost bh-btn--sm" data-media-copy="' . htmlspecialchars((string) ($row['url'] ?? ''), ENT_QUOTES) . '" title="Copy URL">' . icon('link', 'w-4 h-4') . '<span class="bh-sr-only">Copy URL</span></button>'
                 . '</span>';
        }],
    ],
    'row_actions'  => [],
    'bulk_actions' => ['delete'],
    'search_placeholder' => 'Search files…',
]);
