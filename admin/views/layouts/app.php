<?php
/** @var string $title */
/** @var array $currentUser */

$user = $currentUser ?? null;
$path = $_SERVER['REQUEST_URI'] ?? '';

$navItems = [
    ['url' => '/admin/dashboard',     'label' => 'Dashboard',   'icon' => 'chart-bar', 'section' => 'main'],
    ['url' => '/admin/posts',         'label' => 'Posts',       'icon' => 'newspaper',   'cap' => 'edit_posts', 'section' => 'content', 'children' => [
        ['url' => '/admin/posts',              'label' => 'All Posts',    'cap' => 'edit_posts'],
        ['url' => '/admin/posts/create',       'label' => 'Add New Post', 'cap' => 'edit_posts'],
        ['url' => '/admin/templates',          'label' => 'Templates',    'cap' => 'edit_posts'],
        ['url' => '/admin/taxonomies/category','label' => 'Categories',   'cap' => 'manage_taxonomies'],
        ['url' => '/admin/taxonomies/tag',     'label' => 'Tags',         'cap' => 'manage_taxonomies'],
    ]],
    ['url' => '/admin/pages',         'label' => 'Pages',       'icon' => 'document-text',  'cap' => 'edit_pages', 'section' => 'content'],
    ['url' => '/admin/media',         'label' => 'Media',       'icon' => 'photo',  'cap' => 'upload_media', 'section' => 'content'],
    ['url' => '/admin/comments',      'label' => 'Comments',    'icon' => 'chat-bubble-left-right',    'cap' => 'moderate_comments', 'section' => 'content'],
    ['url' => '/admin/menus',         'label' => 'Menus',       'icon' => 'bars-3',        'cap' => 'manage_menus', 'section' => 'appearance'],
    ['url' => '/admin/users',         'label' => 'Users',       'icon' => 'users',       'cap' => 'manage_users', 'section' => 'people'],
    ['url' => '/admin/roles',         'label' => 'Roles',       'icon' => 'shield-check',  'cap' => 'manage_users', 'section' => 'people'],
    ['url' => '/admin/apps',          'label' => 'Apps',        'icon' => 'puzzle-piece',        'cap' => 'manage_apps', 'section' => 'system'],
    ['url' => '/admin/widgets',       'label' => 'Widgets',     'icon' => 'squares-2x2', 'cap' => 'manage_apps', 'section' => 'appearance'],
    ['url' => '/admin/customize',     'label' => 'Customize',   'icon' => 'paint-brush', 'cap' => 'manage_settings', 'section' => 'appearance'],
    ['url' => '/admin/themes',        'label' => 'Themes',      'icon' => 'swatch',     'cap' => 'manage_themes', 'section' => 'appearance'],
    ['url' => '/admin/api',           'label' => 'API',         'icon' => 'code-bracket',        'cap' => 'manage_options', 'section' => 'system'],
    ['url' => '/admin/settings/general', 'label' => 'Settings', 'icon' => 'cog-6-tooth',  'cap' => 'manage_settings', 'section' => 'system', 'children' => [
        ['url' => '/admin/settings/general',       'label' => 'General',       'cap' => 'manage_settings'],
        ['url' => '/admin/settings/reading',       'label' => 'Reading',       'cap' => 'manage_settings'],
        ['url' => '/admin/settings/writing',       'label' => 'Writing',       'cap' => 'manage_settings'],
        ['url' => '/admin/settings/discussion',    'label' => 'Discussion',    'cap' => 'manage_settings'],
        ['url' => '/admin/settings/permalinks',    'label' => 'Permalinks',    'cap' => 'manage_settings'],
        ['url' => '/admin/settings/media',         'label' => 'Media',         'cap' => 'manage_settings'],
        ['url' => '/admin/settings/seo',           'label' => 'SEO',           'cap' => 'manage_settings'],
        ['url' => '/admin/settings/email',         'label' => 'Email',         'cap' => 'manage_settings'],
        ['url' => '/admin/settings/authorization', 'label' => 'Authorization', 'cap' => 'manage_settings'],
    ]],
    ['url' => '/admin/system',         'label' => 'System',      'icon' => 'heart',  'cap' => 'manage_settings', 'section' => 'system'],
    ['url' => '/admin/updates',        'label' => 'Updates',     'icon' => 'cloud-arrow-down', 'cap' => 'manage_settings', 'section' => 'system', 'badge' => 'updates'],
];

// Let apps inject sidebar items via the `admin.menu` filter.
try {
    $hooks = \App\Core\Application::getInstance()->make(\App\Core\HookRegistry::class);
    $filtered = $hooks->applyFilters('admin.menu', $navItems);
    if (is_array($filtered)) {
        $navItems = $filtered;
    }
} catch (\Throwable) {
    // Hook registry not available - keep defaults.
}

// Attach a per-app access capability to app-injected items (those that declare
// an 'app' slug), so app access can be granted/denied per user.
foreach ($navItems as &$__it) {
    $__slug = (string) ($__it['app'] ?? '');
    if ($__slug !== '' && empty($__it['cap'])) {
        $__it['cap'] = \App\Services\AccessControl::appCap($__slug);
    }
}
unset($__it, $__slug);

// Hide items the current user can't access (core caps + app caps).
if (!empty($currentUser) && is_array($currentUser)) {
    $navItems = array_values(array_filter($navItems, function ($it) use ($currentUser) {
        return empty($it['cap']) || \App\Http\Middleware\CheckCapability::userCan($currentUser, $it['cap']);
    }));
    // Same capability filtering for submenu children.
    foreach ($navItems as &$__ni) {
        if (!empty($__ni['children']) && is_array($__ni['children'])) {
            $__ni['children'] = array_values(array_filter($__ni['children'], function ($c) use ($currentUser) {
                return empty($c['cap']) || \App\Http\Middleware\CheckCapability::userCan($currentUser, $c['cap']);
            }));
        }
    }
    unset($__ni);
}

/**
 * Group the (filtered) items into ordered sections. Items without a section —
 * which is every item added by an app via addAdminMenu() — collect under
 * "Apps", so third-party menus stay tidy instead of scattering.
 */
$sectionOrder = [
    'main'       => null,          // no heading — Dashboard sits alone at the top
    'content'    => 'Content',
    'appearance' => 'Appearance',
    'people'     => 'Users',
    'apps'       => 'Apps',
    'system'     => 'System',
];
$navSections = [];
foreach ($sectionOrder as $key => $label) {
    $navSections[$key] = ['label' => $label, 'items' => []];
}
foreach ($navItems as $__item) {
    $sec = (string) ($__item['section'] ?? 'apps');
    if (!isset($navSections[$sec])) $sec = 'apps';
    $navSections[$sec]['items'][] = $__item;
}
// Drop empty sections so no stray separators render.
$navSections = array_filter($navSections, fn($s) => !empty($s['items']));

$flash = $flash ?? null;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Admin') ?> &mdash; Basehim</title>
    <?php // Admin favicon. A site's own favicon setting governs the public site;
          // the admin is Basehim's, so it uses the mark. ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/admin/assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="<?= $base ?>/admin/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css?v=<?= urlencode(BASEHIM_VERSION) ?>">
    <?php $this->include('partials.admin-styles', ['base' => $base]); ?>
    <!-- Heroicons for JS-built markup (window.BasehimIcon). Loaded in <head> so
         every later script — core, app, and theme — can rely on it. -->
    <script src="<?= $base ?>/admin/assets/js/icons.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
<?php
// App-registered admin stylesheets (via $this->addAdminStyle()).
try {
    $__adminStyles = (array) \App\Core\Application::getInstance()
        ->make(\App\Core\HookRegistry::class)->applyFilters('admin.styles', []);
    foreach ($__adminStyles as $__href) {
        echo '    <link rel="stylesheet" href="' . htmlspecialchars((string) $__href, ENT_QUOTES) . '">' . "\n";
    }
} catch (\Throwable) {}
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', system-ui, sans-serif; }

    /* ===== Global responsive polish (applies to every admin page) ===== */
    /* Any wide data table becomes horizontally scrollable on small screens
       instead of clipping or forcing the whole page to scroll sideways. */
    @media (max-width: 767px) {
        main table { display: block; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; }
        /* Let cards/panels use the full width and tighten oversized headings. */
        main h2 { font-size: 1.125rem; }
        main .grid { gap: 1rem; }
    }
    /* Consistent focus ring for keyboard users across inputs/buttons. */
    main :is(input, select, textarea):focus-visible {
        outline: 2px solid rgba(37, 99, 235, .5); outline-offset: 1px;
    }

    /* ===== Consistent button system =====
       Opt-in component classes so buttons look identical across every page.
       Existing utility-styled buttons keep working; new/updated markup can
       use these for a single source of truth. */
    .nbtn {
        display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
        padding: .5rem .9rem; border-radius: .625rem; font-size: .875rem; font-weight: 500;
        line-height: 1.25rem; border: 1px solid transparent; cursor: pointer;
        transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
        white-space: nowrap; text-decoration: none;
    }
    .nbtn:focus-visible { outline: 2px solid rgba(37, 99, 235, .5); outline-offset: 2px; }
    .nbtn:disabled { opacity: .55; cursor: not-allowed; }
    .nbtn-sm { padding: .35rem .7rem; font-size: .8125rem; border-radius: .5rem; }
    .nbtn-primary { background: #2563eb; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    .nbtn-primary:hover { background: #1d4ed8; }
    .nbtn-secondary { background: #fff; color: #334155; border-color: #e2e8f0; }
    .nbtn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; }
    .nbtn-danger { background: #dc2626; color: #fff; }
    .nbtn-danger:hover { background: #b91c1c; }
    .nbtn-ghost { background: transparent; color: #475569; }
    .nbtn-ghost:hover { background: #f1f5f9; }

    /* Consistent card + panel surface used across pages. */
    .ncard { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; }

    /* ===== Mobile off-canvas sidebar (< lg) ===== */
    @media (max-width: 1023px) {
        .bh-sidebar {
            transform: translateX(-100%);
            transition: transform .25s ease;
            box-shadow: 0 10px 40px rgba(2, 6, 23, .18);
            width: 16rem !important;
        }
        .bh-sidebar.is-open { transform: translateX(0); }
        /* On mobile the collapse feature is irrelevant — always show full. */
        .bh-sidebar.is-collapsed { width: 16rem !important; }
        .bh-sidebar.is-collapsed .bh-hide-collapsed { display: block !important; }
        .bh-sidebar.is-collapsed .bh-nav-label { display: inline !important; }
        .bh-main { margin-left: 0 !important; }
        #bh-collapse-toggle { display: none; }
    }
    #bh-backdrop {
        position: fixed; inset: 0; background: rgba(15, 23, 42, .4);
        -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
        opacity: 0; visibility: hidden; transition: opacity .3s ease, visibility .3s ease; z-index: 15;
    }
    #bh-backdrop.is-open { opacity: 1; visibility: visible; }
    @media (min-width: 1024px) { #bh-backdrop { display: none; } }

    /* Smoother off-canvas easing (cubic curve for a natural slide). */
    @media (max-width: 1023px) {
        .bh-sidebar { transition: transform .32s cubic-bezier(.4, 0, .2, 1); }
    }

    /* ===== Reusable modal system (consistent blur overlay everywhere) =====
       Any element with class "bh-modal" is a full-screen overlay; add
       "is-open" to show it. Its child ".bh-modal-box" is the dialog. */
    .bh-modal {
        position: fixed; inset: 0; z-index: 60;
        display: flex; align-items: center; justify-content: center;
        padding: 1rem;
        background: rgba(15, 23, 42, .45);
        -webkit-backdrop-filter: blur(5px); backdrop-filter: blur(5px);
        opacity: 0; visibility: hidden; transition: opacity .22s ease, visibility .22s ease;
    }
    .bh-modal.is-open { opacity: 1; visibility: visible; }
    .bh-modal-box {
        background: #fff; border-radius: 16px; width: 100%; max-width: 30rem;
        max-height: calc(100vh - 2rem); overflow-y: auto;
        box-shadow: 0 20px 60px rgba(2, 6, 23, .28);
        transform: translateY(8px) scale(.98); transition: transform .22s ease;
    }
    .bh-modal.is-open .bh-modal-box { transform: translateY(0) scale(1); }

    /* ===== Sidebar sections ===== */
    /* Grouped navigation: a hairline separator between groups and a quiet
       uppercase heading, so the menu reads as organised regions rather than
       one long list. */
    .bh-nav-section + .bh-nav-section { margin-top: .125rem; }
    .bh-nav-sep {
        height: 1px; margin: .625rem .25rem;
        background: linear-gradient(to right, transparent, #e2e8f0 12%, #e2e8f0 88%, transparent);
    }
    .bh-nav-heading {
        padding: 0 .75rem; margin: 0 0 .3rem;
        font-size: .6875rem; font-weight: 600; letter-spacing: .06em;
        text-transform: uppercase; color: #94a3b8; user-select: none;
    }
    /* Collapsed rail: headings/separators would be noise — show a short rule. */
    .bh-sidebar.is-collapsed .bh-nav-sep { margin: .5rem .75rem; }
    .bh-sidebar.is-collapsed .bh-nav-heading { display: none; }

    /* Active item gets a soft left marker rather than a heavy fill. */
    .bh-nav-item.bh-active > .bh-group-head,
    a.bh-nav-item.bh-active { position: relative; }
    .bh-nav-item.bh-active > .bh-group-head::before,
    a.bh-nav-item.bh-active::before {
        content: ''; position: absolute; left: -.75rem; top: 50%;
        transform: translateY(-50%);
        width: 3px; height: 1.15rem; border-radius: 0 3px 3px 0;
        background: #2563eb;
    }
    .bh-sidebar.is-collapsed .bh-nav-item.bh-active > .bh-group-head::before,
    .bh-sidebar.is-collapsed a.bh-nav-item.bh-active::before { left: -.5rem; }

    /* ===== Section tabs (Settings / API / System) =====
       One component so every section's tabs look and behave identically. */
    .bh-tabs {
        position: sticky;
        top: 4rem;                 /* sits directly under the 4rem header */
        z-index: 9;                /* below the header (z-10), above content */
        display: flex;
        gap: .25rem;
        /* Bleed sideways to the edges of main's padding so the sticky bar spans
           the full width and no content peeks past it. No negative top margin —
           the bar sits below a page heading, not at the top of <main>. */
        margin: 0 -1rem 1.25rem;
        padding: .5rem 1rem 0;
        /* Match the page background, otherwise content shows through when the
           bar sticks. */
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        /* overflow-x:auto alone promotes overflow-y from visible to auto, which
           is what produced the second (vertical) scrollbar. Pinning y to hidden
           keeps a single horizontal scroll. */
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
    }
    .bh-tabs::-webkit-scrollbar { display: none; }
    /* Edge fades: a quiet cue that there are more tabs off-screen. */
    .bh-tabs { -webkit-mask-image: none; mask-image: none; }
    .bh-tabs.has-more-right {
        -webkit-mask-image: linear-gradient(to right, #000 88%, transparent 100%);
        mask-image: linear-gradient(to right, #000 88%, transparent 100%);
    }
    .bh-tabs.has-more-left {
        -webkit-mask-image: linear-gradient(to left, #000 88%, transparent 100%);
        mask-image: linear-gradient(to left, #000 88%, transparent 100%);
    }
    .bh-tabs.has-more-left.has-more-right {
        -webkit-mask-image: linear-gradient(to right, transparent 0%, #000 8%, #000 92%, transparent 100%);
        mask-image: linear-gradient(to right, transparent 0%, #000 8%, #000 92%, transparent 100%);
    }
    @media (min-width: 640px) {
        .bh-tabs { margin: 0 -1.5rem 1.5rem; padding: .625rem 1.5rem 0; }
    }

    .bh-tab {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        flex: 0 0 auto;                /* never squash a tab */
        padding: .5rem .75rem .625rem;
        border: 0;
        background: none;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;           /* overlap the container's own border */
        font-size: .875rem;
        font-weight: 500;
        color: #64748b;
        white-space: nowrap;
        cursor: pointer;
        border-radius: .375rem .375rem 0 0;
        transition: color .15s ease, background-color .15s ease, border-color .15s ease;
    }
    .bh-tab:hover { color: #0f172a; background: #f1f5f9; }
    .bh-tab:focus-visible { outline: 2px solid #93c5fd; outline-offset: -2px; }
    .bh-tab svg { flex-shrink: 0; opacity: .7; }

    .bh-tab.is-active {
        color: #2563eb;
        border-bottom-color: #2563eb;
        font-weight: 600;
    }
    .bh-tab.is-active svg { opacity: 1; }
    .bh-tab.is-active:hover { background: transparent; }

    /* A tab opening a destructive panel — "Danger zone". Reads as a normal tab
       until you reach for it, so it does not shout from a page you are only
       reading. */
    .bh-tab-danger:hover { color: #dc2626; background: #fef2f2; }
    .bh-tab-danger.is-active { color: #dc2626; border-bottom-color: #dc2626; }
    .bh-tab-danger.is-active:hover { background: #fef2f2; }

    /* Sidebar collapse/expand shifts the rail; keep the sticky bar aligned. */
    @media (max-width: 1023px) {
        .bh-tabs { top: 4rem; }
    }

    /* ===== Collapsible sidebar ===== */
    .bh-sidebar { transition: width .2s ease; }
    .bh-sidebar.is-collapsed { width: 4.5rem; overflow-x: hidden; }
    .bh-sidebar.is-collapsed .bh-hide-collapsed { display: none !important; }
    .bh-nav-badge {
        min-width: 18px; height: 18px; padding: 0 5px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 999px; background: #ef4444; color: #fff;
        font-size: 10px; font-weight: 700; line-height: 1;
    }
    /* `display:inline-flex` above is an author rule and would otherwise beat the
       browser's `[hidden] { display: none }`, leaving a "0" badge on screen.
       This restores the attribute's meaning so the badge disappears at zero. */
    .bh-nav-badge[hidden] { display: none; }
    /* Head + footer: zero the wide Tailwind padding and center the single
       remaining child so nothing exceeds the 4.5rem rail. */
    .bh-sidebar.is-collapsed .bh-sidebar-head { padding-left: 0 !important; padding-right: 0 !important; justify-content: center; gap: 0; }
    .bh-sidebar.is-collapsed > div:last-child { padding-left: 0 !important; padding-right: 0 !important; justify-content: center; gap: 0; }
    .bh-sidebar.is-collapsed .bh-nav-item { justify-content: center; padding-left: 0; padding-right: 0; gap: 0; height: 42px; margin: 0 auto; width: 44px; }
    .bh-sidebar.is-collapsed #bh-nav { padding-left: 0; padding-right: 0; }
    .bh-sidebar.is-collapsed #bh-nav-main { padding-left: 0; padding-right: 0; }
    .bh-sidebar.is-collapsed .bh-nav-label { display: none; }
    /* Bigger, easier-to-hit icons in the collapsed rail. */
    .bh-sidebar.is-collapsed .bh-nav-icon { width: auto; font-size: 1.15rem; }
    .bh-sidebar.is-collapsed .bh-search-wrap { display: none; }
    .bh-main.is-collapsed { margin-left: 4.5rem; }

    /* Vertical scroll only — never horizontal. Setting a non-visible value on
       BOTH axes is required: mixing overflow-x:visible with overflow-y:auto
       makes the browser force x to auto too, which caused a horizontal
       scrollbar and the icons to shift sideways. */
    #bh-nav { overflow-y: auto; overflow-x: hidden; }
    .bh-sidebar.is-collapsed #bh-nav::-webkit-scrollbar { width: 0; height: 0; }
    .bh-sidebar.is-collapsed #bh-nav { scrollbar-width: none; }

    /* Collapsed tooltip is rendered at the body level by JS (see the sidebar
       script), so a scrolling/clipping nav can never cut it off. */
    .bh-tip {
        position: fixed; z-index: 9999; background: #0f172a; color: #fff; font-size: 12px; font-weight: 500;
        padding: 5px 10px; border-radius: 6px; white-space: nowrap; pointer-events: none;
        box-shadow: 0 4px 12px rgba(0,0,0,.18); opacity: 0; transform: translateY(-50%);
        transition: opacity .12s ease; left: 0; top: 0;
    }
    .bh-tip.is-visible { opacity: 1; }
    .bh-tip::before {
        content: ''; position: absolute; right: 100%; top: 50%; transform: translateY(-50%);
        border: 5px solid transparent; border-right-color: #0f172a;
    }

    /* Submenu groups */
    .bh-sub-toggle { color: #cbd5e1; font-size: 11px; padding: 2px 4px; }
    .bh-sub-toggle:hover { color: #64748b; }
    .bh-nav-group .bh-submenu { display: none; padding: 2px 0 4px; }
    .bh-nav-group.is-open .bh-submenu { display: block; }
    .bh-nav-group.is-open .bh-sub-toggle i { transform: rotate(180deg); }
    .bh-sidebar.is-collapsed .bh-nav-group .bh-submenu { display: none !important; }

    /* ===== Search box (self-contained, no fragile utility classes) ===== */
    .bh-search { position: relative; display: block; }
    /* The icon is an inline SVG with an explicit size (w-4 h-4), so it must be
       centred with a transform — stretching it top-to-bottom over-constrains
       the box and pins it to the top of the field. */
    .bh-search-ico {
        position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
        color: #94a3b8; pointer-events: none;
    }
    .bh-search-input {
        width: 100%; box-sizing: border-box; padding: 8px 28px 8px 30px; font-size: 13px;
        border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #1e293b; outline: none;
    }
    .bh-search-input:focus { background: #fff; border-color: #60a5fa; }
    .bh-search-clear {
        position: absolute; right: 6px; top: 0; bottom: 0; display: flex; align-items: center;
        color: #94a3b8; font-size: 12px; padding: 0 4px; background: none; border: 0; cursor: pointer;
    }
    .bh-search-clear.hidden { display: none; }
    .bh-search-clear:hover { color: #475569; }
    </style>
</head>
<?php $csrfToken = \App\Core\Application::getInstance()->make(\App\Core\Session::class)->csrfToken(); ?>
<body class="bg-slate-50 min-h-screen antialiased text-slate-800"
      data-base="<?= htmlspecialchars($base ?? '') ?>"
      data-csrf="<?= htmlspecialchars($csrfToken) ?>">

<div class="flex min-h-screen">
    <!-- Mobile backdrop -->
    <div id="bh-backdrop" aria-hidden="true"></div>

    <!-- Sidebar -->
    <aside id="bh-sidebar" class="bh-sidebar w-64 bg-white border-r border-slate-200 flex flex-col fixed inset-y-0 left-0 z-20">
        <div class="h-16 flex items-center gap-2 px-6 border-b border-slate-200 bh-sidebar-head">
            <div class="shrink-0"><?= brand_logo(36) ?></div>
            <div class="bh-hide-collapsed min-w-0 flex-1">
                <?php
                try {
                    $__settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
                    $__siteName = $__settings->get('general', 'site_title', 'Basehim') ?: 'Basehim';
                } catch (\Throwable) {
                    $__siteName = 'Basehim';
                }
                ?>
                <div class="font-semibold text-slate-900 text-sm leading-tight truncate"><?= htmlspecialchars($__siteName) ?></div>
                <div class="text-[10px] text-slate-500 uppercase tracking-wide">Admin Panel</div>
            </div>
            <button type="button" id="bh-mobile-close" class="lg:hidden p-2 -mr-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Close menu">
                <?= icon('x-mark', 'w-4 h-4') ?>
            </button>
        </div>

        <!-- Search -->
        <div class="px-3 pt-3 bh-search-wrap">
            <div class="bh-search bh-hide-collapsed">
                <?= icon('magnifying-glass', 'w-4 h-4 bh-search-ico') ?>
                <input type="text" id="bh-nav-search" placeholder="Search menu…" autocomplete="off" spellcheck="false"
                       class="bh-search-input">
                <button type="button" id="bh-nav-search-clear" class="hidden bh-search-clear" aria-label="Clear search"><?= icon('x-mark', 'w-4 h-4') ?></button>
            </div>
        </div>

        <nav id="bh-nav" class="flex-1 overflow-y-auto px-3 py-3">
            <!-- Apps may prepend their own region here (e.g. pinned items). -->
            <div id="bh-nav-extra"></div>
            <div id="bh-nav-main">
            <?php $__secIndex = 0; foreach ($navSections as $__secKey => $__section): $__secIndex++; ?>
            <section class="bh-nav-section" data-section="<?= htmlspecialchars($__secKey) ?>">
                <?php if ($__secIndex > 1): ?>
                    <div class="bh-nav-sep" aria-hidden="true"></div>
                <?php endif; ?>
                <?php if (!empty($__section['label'])): ?>
                    <h2 class="bh-nav-heading bh-hide-collapsed"><?= htmlspecialchars($__section['label']) ?></h2>
                <?php endif; ?>
                <div class="bh-nav-list space-y-0.5">
            <?php foreach ($__section['items'] as $item): ?>
                <?php $itemUrl = $base . $item['url']; ?>
                <?php $children = (!empty($item['children']) && is_array($item['children'])) ? $item['children'] : []; ?>
                <?php if (!empty($children)): ?>
                    <?php
                    // Group active when the current path matches the parent or any child.
                    $groupActive = str_starts_with($path, $itemUrl);
                    foreach ($children as $ch) {
                        if (str_starts_with($path, $base . $ch['url'])) { $groupActive = true; break; }
                    }
                    $childLabels = strtolower($item['label'] . ' ' . implode(' ', array_column($children, 'label')));
                    ?>
                <div class="bh-nav-item bh-nav-group <?= $groupActive ? 'bh-active is-open' : '' ?>"
                     data-key="<?= htmlspecialchars($item['url']) ?>"
                     data-label="<?= htmlspecialchars($childLabels) ?>">
                    <div class="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition cursor-pointer bh-group-head
                                <?= $groupActive ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>"
                         title="<?= htmlspecialchars($item['label']) ?>">
                        <a href="<?= htmlspecialchars($itemUrl) ?>" class="flex items-center gap-3 flex-1 min-w-0">
                            <?= icon($item['icon'], 'bh-nav-icon w-5 h-5 shrink-0 ' . ($groupActive ? 'text-blue-600' : 'text-slate-400')) ?>
                            <span class="bh-nav-label flex-1 truncate"><?= $item['label'] ?></span>
                        </a>
                        <button type="button" class="bh-sub-toggle bh-hide-collapsed" title="Toggle submenu" aria-label="Toggle submenu">
                            <?= icon('chevron-down', 'w-3 h-3 transition-transform') ?>
                        </button>
                    </div>
                    <div class="bh-submenu bh-hide-collapsed">
                        <?php foreach ($children as $ch): ?>
                            <?php $chUrl = $base . $ch['url']; ?>
                            <?php $chActive = rtrim($path, '/') === rtrim($chUrl, '/'); ?>
                            <a href="<?= htmlspecialchars($chUrl) ?>"
                               class="flex items-center gap-2 pl-11 pr-3 py-1.5 rounded-lg text-[13px] transition
                                      <?= $chActive ? 'text-blue-700 font-medium' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?>">
                                <span class="w-1 h-1 rounded-full <?= $chActive ? 'bg-blue-600' : 'bg-slate-300' ?>"></span>
                                <span class="truncate"><?= htmlspecialchars($ch['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php $active = str_starts_with($path, $itemUrl); ?>
                <a href="<?= htmlspecialchars($itemUrl) ?>"
                   class="bh-nav-item group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                          <?= $active ? 'bg-blue-50 text-blue-700 bh-active' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>"
                   data-key="<?= htmlspecialchars($item['url']) ?>"
                   data-label="<?= htmlspecialchars(strtolower($item['label'])) ?>"
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <?= icon($item['icon'], 'bh-nav-icon w-5 h-5 shrink-0 ' . ($active ? 'text-blue-600' : 'text-slate-400')) ?>
                    <span class="bh-nav-label flex-1 truncate"><?= $item['label'] ?></span>
                    <?php
                    // Optional count badge (e.g. available updates). Values come
                    // from cached settings — never a remote call during render.
                    // Always emitted (hidden at zero) so the background update
                    // check can reveal it without a page reload.
                    if (($item['badge'] ?? '') === 'updates') {
                        try {
                            $navBadgeN = (int) \App\Core\Application::getInstance()
                                ->make(\App\Services\SettingService::class)
                                ->get('updates', 'available_count', 0);
                        } catch (\Throwable $e) { $navBadgeN = 0; }
                        ?>
                    <span class="bh-nav-badge bh-hide-collapsed" data-bh-badge="updates"<?= $navBadgeN > 0 ? '' : ' hidden' ?>><?= $navBadgeN > 99 ? '99+' : $navBadgeN ?></span>
                    <?php } ?>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
            </div>
            <div id="bh-nav-empty" class="hidden px-3 py-6 text-center text-xs text-slate-400">
                <?= icon('magnifying-glass', 'w-4 h-4 block mx-auto mb-1 text-slate-300') ?>
                No menu items match.
            </div>
        </nav>

        <div class="px-3 py-2 border-t border-slate-200 flex items-center gap-1">
            <a href="<?= $base ?>/" target="_blank" class="bh-hide-collapsed flex-1 flex items-center gap-2 text-sm text-slate-500 hover:text-blue-600 px-3 py-2 rounded-lg hover:bg-slate-50">
                <?= icon('arrow-top-right-on-square', 'w-4 h-4') ?>
                <span>View Site</span>
            </a>
            <button type="button" id="bh-collapse-toggle" class="text-slate-500 hover:text-blue-600 px-2 py-2 rounded-lg hover:bg-slate-50" title="Collapse sidebar (Ctrl+B)">
                <?= icon('chevron-double-left', 'w-4 h-4 bh-collapse-icon') ?>
            </button>
        </div>
    </aside>

    <!-- Main -->
    <div id="bh-main" class="bh-main flex-1 ml-64 min-w-0 transition-[margin] duration-200">
        <!-- Topbar -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-6 sticky top-0 z-10">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" id="bh-mobile-toggle" class="lg:hidden -ml-1 p-2 rounded-lg text-slate-600 hover:bg-slate-100" aria-label="Open menu">
                    <?= icon('bars-3', 'w-5 h-5') ?>
                </button>
                <h1 class="text-base font-semibold text-slate-900 truncate"><?= htmlspecialchars($title ?? 'Dashboard') ?></h1>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <?php
                // Quick-create menu — capability-filtered so users only see
                // actions they can perform.
                $createItems = [
                    ['url' => '/admin/posts/create',       'label' => 'New Post',     'icon' => 'document-text',  'cap' => 'edit_posts'],
                    ['url' => '/admin/pages/create',       'label' => 'New Page',     'icon' => 'document',       'cap' => 'edit_pages'],
                    ['url' => '/admin/taxonomies/category', 'label' => 'New Category', 'icon' => 'folder-plus',   'cap' => 'manage_taxonomies'],
                    ['url' => '/admin/taxonomies/tag',     'label' => 'New Tag',      'icon' => 'tag',           'cap' => 'manage_taxonomies'],
                    ['url' => '/admin/media',              'label' => 'New Media',    'icon' => 'photo',         'cap' => 'upload_media'],
                    ['url' => '/admin/users/create',       'label' => 'New User',     'icon' => 'user-plus',     'cap' => 'manage_users'],
                ];
                $can = function (?string $cap) use ($currentUser): bool {
                    if (!$cap) return true;
                    try { return \App\Http\Middleware\CheckCapability::userCan($currentUser, $cap); }
                    catch (\Throwable) { return false; }
                };
                $createItems = array_values(array_filter($createItems, fn($i) => $can($i['cap'] ?? null)));
                ?>
                <?php if (!empty($createItems)): ?>
                <div class="relative" data-bh-menu>
                    <button type="button" data-bh-menu-btn
                            class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-transparent hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-lg"
                            aria-haspopup="true" aria-expanded="false" title="Create new">
                        <?= icon('plus', 'w-4 h-4') ?>
                        <span class="hidden sm:inline">Create</span>
                        <?= icon('chevron-down', 'w-3 h-3 opacity-70 hidden sm:inline') ?>
                    </button>
                    <div data-bh-menu-panel
                         class="absolute right-0 mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-lg py-1.5 hidden z-30">
                        <div class="px-3 pb-1 pt-0.5 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Create new</div>
                        <?php foreach ($createItems as $ci): ?>
                        <a href="<?= $base . $ci['url'] ?>" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <span class="w-7 h-7 rounded-lg bg-slate-100 text-slate-500 grid place-items-center shrink-0">
                                <?= icon($ci['icon'], 'w-3.5 h-3.5') ?>
                            </span>
                            <?= htmlspecialchars($ci['label']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="relative" data-bh-menu>
                    <button type="button" data-bh-menu-btn class="flex items-center gap-2 px-1.5 sm:px-3 py-1.5 rounded-lg hover:bg-slate-100" aria-haspopup="true" aria-expanded="false">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white text-sm font-semibold">
                            <?= strtoupper(substr($user['display_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <span class="hidden md:inline text-sm font-medium text-slate-700"><?= htmlspecialchars($user['display_name'] ?? 'User') ?></span>
                        <?= icon('chevron-down', 'w-3.5 h-3.5 text-slate-400 hidden md:inline') ?>
                    </button>
                    <div data-bh-menu-panel class="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-lg py-1 hidden z-30">
                        <a href="<?= $base ?>/admin/profile" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <?= icon('user', 'w-4 h-4 mr-2 text-slate-400') ?> Profile
                        </a>
                        <a href="<?= $base ?>/admin/settings/general" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <?= icon('cog-6-tooth', 'w-4 h-4 mr-2 text-slate-400') ?> Settings
                        </a>
                        <div class="border-t border-slate-100 my-1"></div>
                        <form method="POST" action="<?= $base ?>/admin/logout">
                            <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <?= icon('arrow-left-start-on-rectangle', 'w-4 h-4 mr-2') ?> Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="p-4 sm:p-6">
            <?php if ($flash): ?>
                <div class="mb-4 px-4 py-3 rounded-lg border <?php
                    echo $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-800' :
                        ($flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' :
                        'bg-blue-50 border-blue-200 text-blue-800');
                ?>">
                    <?= icon($flash['type'] === 'error' ? 'x-circle' : ($flash['type'] === 'success' ? 'check-circle' : 'information-circle'), 'w-4 h-4 mr-2 shrink-0') ?>
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?= $this->yieldSection('content') ?>
        </main>
    </div>
</div>

<?php $this->include('partials.admin-scripts', ['base' => $base, 'skipIcons' => true]); ?>
<script>
/* Basehim admin sidebar: collapse, search, pin, and custom order.
   Preferences persist per-browser in localStorage. */
(function () {
    var LS_COLLAPSE = 'basehim.sidebar.collapsed';

    var sidebar = document.getElementById('bh-sidebar');
    var main    = document.getElementById('bh-main');
    var navMain = document.getElementById('bh-nav-main');
    if (!sidebar || !navMain) return;

    function read(key, fallback) {
        try { var v = localStorage.getItem(key); return v == null ? fallback : JSON.parse(v); }
        catch (e) { return fallback; }
    }
    function write(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} }

    var items = Array.prototype.slice.call(navMain.querySelectorAll('.bh-nav-item'));
    items.forEach(function (el) {
        var label = el.querySelector('.bh-nav-label');
        el.setAttribute('data-tip', label ? label.textContent.trim() : '');
    });

    // Collapsed-mode tooltip: a single element on <body> (fixed position) that
    // no scrolling/clipping container can cut off. Shown only when collapsed.
    var tipEl = document.createElement('div');
    tipEl.className = 'bh-tip';
    document.body.appendChild(tipEl);
    var tipTarget = null;
    function showTip(el) {
        if (!sidebar.classList.contains('is-collapsed')) return;
        var text = el.getAttribute('data-tip') || '';
        if (!text) return;
        tipTarget = el;
        tipEl.textContent = text;
        var r = el.getBoundingClientRect();
        tipEl.style.left = (r.right + 12) + 'px';
        tipEl.style.top = (r.top + r.height / 2) + 'px';
        tipEl.classList.add('is-visible');
    }
    function hideTip() { tipTarget = null; tipEl.classList.remove('is-visible'); }
    items.forEach(function (el) {
        el.addEventListener('mouseenter', function () { showTip(el); });
        el.addEventListener('mouseleave', hideTip);
    });
    // Hide on scroll (position would be stale) and when leaving collapsed mode.
    var navScroll = document.getElementById('bh-nav');
    if (navScroll) navScroll.addEventListener('scroll', hideTip);

    /* ---------- Collapse ---------- */
    function applyCollapse(on) {
        sidebar.classList.toggle('is-collapsed', on);
        main && main.classList.toggle('is-collapsed', on);
        var ic = document.querySelector('.bh-collapse-icon');
        if (ic && window.BasehimIcon) {
            // Replace the SVG itself (class swapping can't change an inline icon).
            var fresh = window.BasehimIcon(on ? 'chevron-double-right' : 'chevron-double-left', 'w-4 h-4 bh-collapse-icon');
            ic.outerHTML = fresh;
        }
        if (typeof hideTip === 'function') hideTip();
    }
    applyCollapse(read(LS_COLLAPSE, false) === true);
    var collapseBtn = document.getElementById('bh-collapse-toggle');
    collapseBtn && collapseBtn.addEventListener('click', function () {
        var on = !sidebar.classList.contains('is-collapsed');
        applyCollapse(on); write(LS_COLLAPSE, on);
    });

    /* ---------- Submenu groups ---------- */
    items.forEach(function (el) {
        var subBtn = el.querySelector('.bh-sub-toggle');
        subBtn && subBtn.addEventListener('click', function (ev) {
            ev.preventDefault(); ev.stopPropagation();
            el.classList.toggle('is-open');
        });
        var head = el.querySelector('.bh-group-head');
        head && head.addEventListener('click', function (ev) {
            // Clicking the row (but not a link/button inside it) toggles the submenu.
            if (ev.target.closest('a, button')) return;
            el.classList.toggle('is-open');
        });
    });

    /* ---------- Search ---------- */
    var search = document.getElementById('bh-nav-search');
    var clearBtn = document.getElementById('bh-nav-search-clear');
    var emptyEl = document.getElementById('bh-nav-empty');
    function runSearch(q) {
        q = (q || '').trim().toLowerCase();
        var shown = 0;
        items.forEach(function (el) {
            var match = !q || (el.getAttribute('data-label') || '').indexOf(q) >= 0;
            el.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        // Collapse a whole section (heading + separator) when nothing in it matches.
        document.querySelectorAll('.bh-nav-section').forEach(function (sec) {
            var any = Array.prototype.some.call(sec.querySelectorAll('.bh-nav-item'), function (i) {
                return i.style.display !== 'none';
            });
            sec.style.display = any ? '' : 'none';
        });
        emptyEl.classList.toggle('hidden', shown > 0);
        clearBtn.classList.toggle('hidden', !q);
    }
    search && search.addEventListener('input', function () { runSearch(search.value); });
    clearBtn && clearBtn.addEventListener('click', function () { search.value = ''; runSearch(''); search.focus(); });

    /* ---------- Keyboard shortcuts ---------- */
    document.addEventListener('keydown', function (ev) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((ev.target.tagName || '')) || ev.target.isContentEditable;
        if (ev.key === '/' && !typing && search && !sidebar.classList.contains('is-collapsed')) {
            ev.preventDefault(); search.focus();
        } else if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'b' || ev.key === 'B')) {
            ev.preventDefault();
            var on = !sidebar.classList.contains('is-collapsed');
            applyCollapse(on); write(LS_COLLAPSE, on);
        } else if (ev.key === 'Escape' && document.activeElement === search) {
            search.value = ''; runSearch('');
        }
    });

    /* ===== Mobile off-canvas drawer ===== */
    var backdrop = document.getElementById('bh-backdrop');
    var mobileToggle = document.getElementById('bh-mobile-toggle');
    var mobileClose = document.getElementById('bh-mobile-close');
    function openMobile() {
        sidebar.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }
    function closeMobile() {
        sidebar.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-open');
        document.body.style.overflow = '';
    }
    if (mobileToggle) mobileToggle.addEventListener('click', openMobile);
    if (mobileClose) mobileClose.addEventListener('click', closeMobile);
    if (backdrop) backdrop.addEventListener('click', closeMobile);
    // Close the drawer after navigating (tapping a nav link).
    navMain && navMain.addEventListener('click', function (e) {
        if (e.target.closest('a') && window.matchMedia('(max-width: 1023px)').matches) closeMobile();
    });
    // Reset drawer state when crossing the breakpoint.
    window.addEventListener('resize', function () {
        if (window.matchMedia('(min-width: 1024px)').matches) closeMobile();
    });

    /* ===== Section tabs ===== */
    // On a narrow screen the active tab can start off-screen, which makes the
    // bar look empty/wrong. Bring it into view and hint that it scrolls.
    document.querySelectorAll('.bh-tabs').forEach(function (bar) {
        var active = bar.querySelector('.bh-tab.is-active');
        if (active) {
            var barRect = bar.getBoundingClientRect();
            var tabRect = active.getBoundingClientRect();
            if (tabRect.left < barRect.left || tabRect.right > barRect.right) {
                bar.scrollLeft = active.offsetLeft - (bar.clientWidth / 2) + (active.offsetWidth / 2);
            }
        }
        // Fade the edges only while there is more to scroll to.
        var sync = function () {
            var max = bar.scrollWidth - bar.clientWidth;
            bar.classList.toggle('has-more-right', max > 1 && bar.scrollLeft < max - 1);
            bar.classList.toggle('has-more-left', bar.scrollLeft > 1);
        };
        sync();
        bar.addEventListener('scroll', sync, { passive: true });
        window.addEventListener('resize', sync);
        // Horizontal scroll with a vertical wheel (trackpad-friendly).
        bar.addEventListener('wheel', function (ev) {
            if (Math.abs(ev.deltaY) <= Math.abs(ev.deltaX)) return;
            if (bar.scrollWidth <= bar.clientWidth) return;
            ev.preventDefault();
            bar.scrollLeft += ev.deltaY;
        }, { passive: false });
    });

    /* ===== Click-based dropdown menus (create + user) ===== */
    // Replaces hover-only menus so they work on touch devices.
    var menus = Array.prototype.slice.call(document.querySelectorAll('[data-bh-menu]'));
    menus.forEach(function (menu) {
        var btn = menu.querySelector('[data-bh-menu-btn]');
        var panel = menu.querySelector('[data-bh-menu-panel]');
        if (!btn || !panel) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !panel.classList.contains('hidden');
            // Close all others first.
            menus.forEach(function (m) {
                var p = m.querySelector('[data-bh-menu-panel]');
                var b = m.querySelector('[data-bh-menu-btn]');
                if (p) p.classList.add('hidden');
                if (b) b.setAttribute('aria-expanded', 'false');
            });
            if (!isOpen) { panel.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); }
        });
    });
    document.addEventListener('click', function () {
        menus.forEach(function (m) {
            var p = m.querySelector('[data-bh-menu-panel]');
            var b = m.querySelector('[data-bh-menu-btn]');
            if (p) p.classList.add('hidden');
            if (b) b.setAttribute('aria-expanded', 'false');
        });
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            menus.forEach(function (m) {
                var p = m.querySelector('[data-bh-menu-panel]');
                if (p) p.classList.add('hidden');
            });
        }
    });
})();
</script>
<?= $this->yieldSection('scripts') ?>
<?php
// App-registered admin scripts (via $this->addAdminScript()). Loaded last so
// they can enhance the finished DOM (e.g. the sidebar).
try {
    $__adminScripts = (array) \App\Core\Application::getInstance()
        ->make(\App\Core\HookRegistry::class)->applyFilters('admin.scripts', []);
    foreach ($__adminScripts as $__src) {
        echo '<script src="' . htmlspecialchars((string) $__src, ENT_QUOTES) . '"></script>' . "\n";
    }
} catch (\Throwable) {}
?>
</body>
</html>
