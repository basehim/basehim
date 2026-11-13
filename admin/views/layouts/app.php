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
    ['url' => '/admin/comments',      'label' => 'Comments',    'icon' => 'chat-bubble-left-right',    'cap' => 'moderate_comments', 'section' => 'content', 'badge' => 'comments'],
    ['url' => '/admin/menus',         'label' => 'Menus',       'icon' => 'bars-3',        'cap' => 'manage_menus', 'section' => 'appearance'],
    ['url' => '/admin/users',         'label' => 'Users',       'icon' => 'users',       'cap' => 'manage_users', 'section' => 'people'],
    ['url' => '/admin/roles',         'label' => 'Roles',       'icon' => 'shield-check',  'cap' => 'manage_users', 'section' => 'people'],
    ['url' => '/admin/apps',          'label' => 'Apps',        'icon' => 'puzzle-piece',        'cap' => 'manage_apps', 'section' => 'system'],
    ['url' => '/admin/marketplace',   'label' => 'Marketplace', 'icon' => 'building-storefront', 'cap' => 'manage_apps', 'section' => 'system'],
    ['url' => '/admin/widgets',       'label' => 'Widgets',     'icon' => 'squares-2x2', 'cap' => 'manage_apps', 'section' => 'appearance'],
    ['url' => '/admin/customize',     'label' => 'Customize',   'icon' => 'paint-brush', 'cap' => 'manage_settings', 'section' => 'appearance'],
    ['url' => '/admin/themes',        'label' => 'Themes',      'icon' => 'swatch',     'cap' => 'manage_themes', 'section' => 'appearance'],
    ['url' => '/admin/api',           'label' => 'API',         'icon' => 'code-bracket',        'cap' => 'manage_options', 'section' => 'system'],
    // Settings (1.2.120): /admin/settings is General on wide screens and the
    // Settings home (a list of sections) on phones. The sub-menu comes from
    // the same list as the tabs, so it always has every section (it was a
    // hand-kept copy that had fallen 5 behind).
    ['url' => '/admin/settings', 'label' => 'Settings', 'icon' => 'cog-6-tooth',  'cap' => 'manage_settings', 'section' => 'system', 'children' => array_values(array_map(
        static fn(string $slug, array $s): array => ['url' => '/admin/settings/' . $slug, 'label' => $s['label'], 'cap' => 'manage_settings'],
        array_keys($__settingsSections = array_filter(require __DIR__ . '/../settings/_sections.php', static fn(array $s): bool => ($s['menu'] ?? true) !== false)),
        $__settingsSections
    ))],
    ['url' => '/admin/system',         'label' => 'System',      'icon' => 'heart',  'cap' => 'manage_settings', 'section' => 'system'],
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

/**
 * A sidebar item's leading icon. An app that ships an image icon gets its
 * brand mark (an <img>, sized like the built-in glyphs); everything else
 * renders the heroicon named in `icon`. $color styles the glyph only.
 */
$navIcon = static function (array $item, string $color): string {
    if (!empty($item['icon_img'])) {
        return '<img src="' . htmlspecialchars((string) $item['icon_img'], ENT_QUOTES)
             . '" alt="" aria-hidden="true" class="bh-nav-icon bh-nav-appicon w-5 h-5 shrink-0">';
    }
    return icon((string) ($item['icon'] ?? 'puzzle-piece'), 'bh-nav-icon w-5 h-5 shrink-0 ' . $color);
};

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
// Light/dark and the accent, from the person's account (1.3.0).
$__appearance = \App\Core\Application::getInstance()->make(\App\Services\AppearanceService::class)
    ->forUser(isset($user['id']) ? (int) $user['id'] : null);
?><!DOCTYPE html>
<html lang="en" <?= \App\Services\AppearanceService::htmlAttributes($__appearance) ?>>
<head>
    <meta charset="UTF-8">
    <?php $this->include('partials.appearance-head'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <?php $this->include('partials.admin-head', ['base' => $base, 'title' => $title ?? null]); ?>
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css?v=<?= urlencode(BASEHIM_VERSION) ?>">
    <?php $this->include('partials.admin-styles', ['base' => $base]); ?>
<?= $this->headStyles() /* stylesheets views asked for with style() (1.3.0) */ ?>
    <!-- Heroicons for JS-built markup (window.BasehimIcon). Loaded in <head> so
         every later script — core, app, and theme — can rely on it. -->
    <script src="<?= $base ?>/admin/assets/js/icons.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
    <?php /* Site timezone for scripts that show times (BasehimTime, 1.2.45). */ ?>
    <script>window.BASEHIM_TIME = <?= json_encode(\App\Core\Time::info(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
    <script src="<?= $base ?>/admin/assets/js/time.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
    <?php $this->include('partials.ajax-client', ['base' => $base]); ?>
    <?php /* Header search and notifications (1.2.121); deferred so it runs after the layout's menus are wired. */ ?>
    <script src="<?= $base ?>/admin/assets/js/topbar.js?v=<?= urlencode(BASEHIM_VERSION) ?>" defer></script>
    <script src="<?= $base ?>/admin/assets/js/appearance.js?v=<?= urlencode(BASEHIM_VERSION) ?>" defer></script>
    <script src="<?= $base ?>/admin/assets/js/scroll-strip.js?v=<?= urlencode(BASEHIM_VERSION) ?>" defer></script>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php // Layout styles: admin/assets/css/src/tailwind.css ("Admin layout", 1.3.0). ?>
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
            <a href="<?= $base ?>/admin" class="shrink-0" title="Dashboard"><?= brand_logo(36) ?></a>
            <div class="bh-hide-collapsed min-w-0 flex-1">
                <?php
                try {
                    $__settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
                    $__siteName = $__settings->get('general', 'site_title', 'Basehim') ?: 'Basehim';
                    $__canon = (string) $__settings->get('permalinks', 'canonical_host', 'none');
                } catch (\Throwable) {
                    $__siteName = 'Basehim';
                    $__canon = 'none';
                }
                // The site's address as visitors know it: the host it is served on,
                // with or without www. as Settings → Permalinks says, and the folder
                // Basehim is installed in. No www. for localhost or an IP address.
                $__host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
                $__bare = (string) preg_replace('/^www\./', '', $__host);
                $__dotted = str_contains($__bare, '.') && !filter_var($__bare, FILTER_VALIDATE_IP);
                if ($__canon === 'www' && $__dotted) $__host = 'www.' . $__bare;
                elseif ($__canon === 'root') $__host = $__bare;
                $__siteAddr = $__host . rtrim((string) ($base ?? ''), '/');
                ?>
                <a href="<?= htmlspecialchars(($base ?? '') . '/') ?>" target="_blank" rel="noopener"
                   class="block font-semibold text-slate-900 text-sm leading-tight truncate hover:text-accent-600" data-bh-site-name
                   title="View <?= htmlspecialchars($__siteName) ?> — opens in a new tab"><?= htmlspecialchars($__siteName) ?></a>
                <?php if ($__siteAddr !== ''): ?>
                <?php // The address is the View site link (1.2.119; the footer's "View Site" row is gone). ?>
                <a href="<?= htmlspecialchars(($base ?? '') . '/') ?>" target="_blank" rel="noopener" class="bh-site-addr" title="View site — opens in a new tab"><span class="truncate"><?= htmlspecialchars($__siteAddr) ?></span><?= icon('arrow-top-right-on-square', 'w-3 h-3 flex-none') ?></a>
                <?php endif; ?>
            </div>
            <button type="button" id="bh-mobile-close" class="lg:hidden p-2.5 -mr-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Close menu">
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
                <kbd class="bh-search-kbd" aria-hidden="true" title="Press / to search the menu">/</kbd>
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
                    <div class="group flex items-center gap-3 h-9 px-3 rounded-lg text-sm font-medium transition cursor-pointer bh-group-head
                                <?= $groupActive ? 'bg-accent-50 text-accent-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>"
                         title="<?= htmlspecialchars($item['label']) ?>">
                        <a href="<?= htmlspecialchars($itemUrl) ?>" class="flex items-center gap-3 flex-1 min-w-0">
                            <?= $navIcon($item, $groupActive ? 'text-accent-600' : 'text-slate-400') ?>
                            <span class="bh-nav-label flex-1 truncate"><?= $item['label'] ?></span>
                        </a>
                        <button type="button" class="bh-sub-toggle bh-hide-collapsed" title="Toggle submenu" aria-label="Toggle submenu">
                            <?= icon('chevron-down', 'w-3.5 h-3.5') ?>
                        </button>
                    </div>
                    <div class="bh-submenu bh-hide-collapsed">
                        <?php foreach ($children as $ch): ?>
                            <?php $chUrl = $base . $ch['url']; ?>
                            <?php $chActive = rtrim($path, '/') === rtrim($chUrl, '/')
                                || (rtrim($path, '/') === $base . '/admin/settings' && $ch['url'] === '/admin/settings/general'); ?>
                            <a href="<?= htmlspecialchars($chUrl) ?>"<?= $chActive ? ' aria-current="page"' : '' ?>>
                                <span class="truncate"><?= htmlspecialchars($ch['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php $active = str_starts_with($path, $itemUrl); ?>
                <a href="<?= htmlspecialchars($itemUrl) ?>"
                   class="bh-nav-item group flex items-center gap-3 h-9 px-3 rounded-lg text-sm font-medium transition
                          <?= $active ? 'bg-accent-50 text-accent-700 bh-active' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>"
                   data-key="<?= htmlspecialchars($item['url']) ?>"
                   data-label="<?= htmlspecialchars(strtolower($item['label'])) ?>"
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <?= $navIcon($item, $active ? 'text-accent-600' : 'text-slate-400') ?>
                    <span class="bh-nav-label flex-1 truncate"><?= $item['label'] ?></span>
                    <?php
                    // Optional count badge. Always emitted (hidden at zero) so a
                    // background refresh can reveal it without a page reload.
                    //
                    //   updates   — from cached settings, never a remote call
                    //               during render
                    //   comments  — comments awaiting moderation
                    //
                    // Any item may name a badge; an app supplies the number for
                    // its own key through the `admin.badge` filter, which
                    // receives (int $count, string $key).
                    $navBadgeKey = (string) ($item['badge'] ?? '');
                    if ($navBadgeKey !== '') {
                        $navBadgeN = 0;
                        try {
                            $__app = \App\Core\Application::getInstance();
                            if ($navBadgeKey === 'updates') {
                                // Core releases plus app updates (1.2.36), both cached.
                                $__ss = $__app->make(\App\Services\SettingService::class);
                                $navBadgeN = (int) $__ss->get('updates', 'available_count', 0)
                                           + (int) $__ss->get('updates', 'apps_available_count', 0);
                            } elseif ($navBadgeKey === 'comments') {
                                $navBadgeN = $__app->make(\App\Services\CommentService::class)->pendingCount();
                            }
                            $navBadgeN = (int) $__app->make(\App\Core\HookRegistry::class)
                                ->applyFilters('admin.badge', $navBadgeN, $navBadgeKey);
                        } catch (\Throwable $e) { $navBadgeN = 0; }
                        $navBadgeTitle = $navBadgeKey === 'comments'
                            ? ($navBadgeN === 1 ? '1 comment awaiting moderation' : $navBadgeN . ' comments awaiting moderation')
                            : '';
                        ?>
                    <span class="bh-nav-badge" data-bh-badge="<?= htmlspecialchars($navBadgeKey) ?>"<?= $navBadgeTitle !== '' ? ' title="' . htmlspecialchars($navBadgeTitle) . '"' : '' ?><?= $navBadgeN > 0 ? '' : ' hidden' ?>><?= $navBadgeN > 99 ? '99+' : $navBadgeN ?></span>
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

        <?php
        // Version and update status. The count is the cached result of the last
        // check (never a remote call while rendering); the dashboard's background
        // check updates the hidden badge below, and the script turns that into
        // the line people read.
        $__canUpdates = false; $__updN = 0; $__checked = false;
        try {
            $__canUpdates = !empty($currentUser) && \App\Http\Middleware\CheckCapability::userCan($currentUser, 'manage_settings');
            if ($__canUpdates) {
                $__us = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
                $__updN = (int) $__us->get('updates', 'available_count', 0) + (int) $__us->get('updates', 'apps_available_count', 0);
                $__checked = (string) $__us->get('updates', 'last_check', '') !== '';
            }
        } catch (\Throwable) {}
        ?>
        <?php // One row: a logo tile, then two lines — the version and the update status in words (1.2.120) — then collapse. ?>
        <div class="bh-sidebar-foot">
            <?php
            $__pill = $__updN > 0 ? 'is-pending' : ($__checked ? 'is-current' : 'is-unknown');
            $__pillText = $__updN > 0 ? $__updN . ' update' . ($__updN === 1 ? '' : 's') . ' available' : ($__checked ? 'You’re up to date' : 'Check for updates');
            $__cardTag = $__canUpdates ? 'a' : 'div';
            ?>
            <<?= $__cardTag ?> class="bh-hide-collapsed bh-foot-card"<?= $__canUpdates ? ' href="' . $base . '/admin/updates" title="' . ($__updN > 0 ? $__updN . ' update' . ($__updN === 1 ? '' : 's') . ' ready to install' : htmlspecialchars($__pillText)) . '"' : '' ?>>
                <span class="bh-foot-mark" aria-hidden="true"><?= brand_logo(20) ?></span>
                <span class="bh-foot-info">
                    <span class="bh-foot-ver"><strong>Basehim</strong> <span><?= htmlspecialchars(BASEHIM_VERSION) ?></span></span>
                    <?php if ($__canUpdates): ?>
                    <span class="bh-foot-pill <?= $__pill ?>" data-bh-update-line>
                        <span class="bh-foot-dot" aria-hidden="true"></span><span data-bh-update-text><?= $__pillText ?></span>
                        <span class="bh-foot-count" data-bh-badge="updates"<?= $__updN > 0 ? '' : ' hidden' ?>><?= $__updN > 99 ? '99+' : $__updN ?></span>
                    </span>
                    <?php endif; ?>
                </span>
            </<?= $__cardTag ?>>
            <button type="button" id="bh-collapse-toggle" class="bh-btn bh-btn--ghost bh-btn--icon bh-btn--sm" title="Collapse sidebar (Ctrl+B)" aria-label="Collapse sidebar">
                <?= icon('chevron-double-left', 'w-4 h-4 bh-collapse-icon') ?>
            </button>
        </div>
    </aside>

    <!-- Main -->
    <div id="bh-main" class="bh-main flex-1 ml-64 min-w-0 transition-[margin] duration-200">
        <!-- Topbar (1.2.121): the admin's own bar. Search, New, View site,
             notifications and the account menu, so the front-end admin bar is
             not needed in here. Kept as `.bh-main > header`: the data table
             measures it for its sticky offsets. -->
        <?php
        $__barPath = (string) (parse_url($path, PHP_URL_PATH) ?: '/admin');
        if ($base !== '' && str_starts_with($__barPath, $base)) $__barPath = substr($__barPath, strlen($base)) ?: '/admin';
        // Signed like the front bar's page descriptor; type "admin" lets the
        // header's search and bell ask for this user's results (and nothing else).
        $__barCtx = \App\Core\AdminBar\AdminBarContext::make(['type' => 'admin', 'path' => $__barPath])->descriptor($csrfToken);
        $can = function (?string $cap) use ($currentUser): bool {
            if (!$cap) return true;
            try { return \App\Http\Middleware\CheckCapability::userCan($currentUser, $cap); }
            catch (\Throwable) { return false; }
        };
        // "New" menu, filtered to what this user may create.
        $createItems = array_values(array_filter([
            ['url' => '/admin/posts/create',        'label' => 'Post',     'icon' => 'newspaper',     'cap' => 'edit_posts'],
            ['url' => '/admin/pages/create',        'label' => 'Page',     'icon' => 'document-text', 'cap' => 'edit_pages'],
            ['url' => '/admin/media',               'label' => 'Media',    'icon' => 'photo',         'cap' => 'upload_media'],
            ['url' => '/admin/taxonomies/category', 'label' => 'Category', 'icon' => 'folder-plus',   'cap' => 'manage_taxonomies'],
            ['url' => '/admin/taxonomies/tag',      'label' => 'Tag',      'icon' => 'tag',           'cap' => 'manage_taxonomies'],
            ['url' => '/admin/users/create',        'label' => 'User',     'icon' => 'user-plus',     'cap' => 'manage_users'],
        ], fn($i) => $can($i['cap'] ?? null)));
        $__userName = (string) (($user['display_name'] ?? '') !== '' ? $user['display_name'] : ($user['username'] ?? 'Account'));
        ?>
        <header class="bh-top sticky top-0 z-10" data-bh-top data-ctx="<?= htmlspecialchars($__barCtx, ENT_QUOTES) ?>">
            <div class="bh-top__start">
                <button type="button" id="bh-mobile-toggle" class="bh-btn bh-btn--ghost bh-btn--icon bh-top__menu lg:hidden" aria-label="Open menu" aria-controls="bh-sidebar" aria-expanded="false">
                    <?= icon('bars-3', 'w-5 h-5') ?>
                </button>
                <h1 class="bh-top__title"><?= htmlspecialchars($title ?? 'Dashboard') ?></h1>
            </div>
            <div class="bh-top__end">
                <button type="button" class="bh-top-search" data-bh-search-open aria-haspopup="dialog" aria-controls="bh-cmdk" aria-keyshortcuts="Control+K Meta+K">
                    <?= icon('magnifying-glass', 'w-4 h-4 flex-none') ?>
                    <span class="bh-top-search__label">Search content…</span>
                    <kbd class="bh-kbd" data-bh-kbd>Ctrl K</kbd>
                </button>

                <?php if ($createItems): ?>
                <div class="relative" data-bh-menu>
                    <button type="button" data-bh-menu-btn class="bh-btn bh-btn--secondary bh-top-new" aria-haspopup="true" aria-expanded="false" aria-label="New">
                        <?= icon('plus', 'w-4 h-4') ?>
                        <span class="bh-top-new__label" aria-hidden="true">New</span>
                        <?= icon('chevron-down', 'w-3.5 h-3.5 bh-top-new__caret') ?>
                    </button>
                    <div data-bh-menu-panel class="bh-top-panel bh-top-panel--menu hidden">
                        <p class="bh-top-panel__label">Create new</p>
                        <?php foreach ($createItems as $ci): ?>
                        <a href="<?= $base . $ci['url'] ?>" class="bh-top-item">
                            <span class="bh-top-item__icon"><?= icon($ci['icon'], 'w-4 h-4') ?></span>
                            <?= htmlspecialchars($ci['label']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <span class="bh-top-sep" aria-hidden="true"></span>

                <a href="<?= $base ?>/" target="_blank" rel="noopener" class="bh-btn bh-btn--ghost bh-btn--icon bh-top-view" title="View site" aria-label="View site (opens in a new tab)">
                    <?= icon('arrow-top-right-on-square', 'w-5 h-5') ?>
                </a>

                <div class="relative" data-bh-menu data-bh-notify>
                    <button type="button" data-bh-menu-btn class="bh-btn bh-btn--ghost bh-btn--icon bh-top-bell" aria-haspopup="true" aria-expanded="false" aria-label="Notifications" title="Notifications">
                        <?= icon('bell', 'w-5 h-5') ?>
                        <span class="bh-top-badge" data-bh-notify-count hidden></span>
                    </button>
                    <div data-bh-menu-panel class="bh-top-panel bh-notify hidden" role="region" aria-label="Notifications">
                        <div class="bh-notify__search">
                            <button type="button" class="bh-top-search bh-top-search--block" data-bh-search-open aria-haspopup="dialog" aria-controls="bh-cmdk">
                                <?= icon('magnifying-glass', 'w-4 h-4 flex-none') ?>
                                <span class="bh-top-search__label">Search content…</span>
                            </button>
                        </div>
                        <p class="bh-notify__head">Notifications</p>
                        <div class="bh-notify__list" data-bh-notify-list aria-live="polite" aria-busy="true">
                            <p class="bh-notify__state">Loading…</p>
                        </div>
                    </div>
                </div>

                <div class="relative" data-bh-menu>
                    <button type="button" data-bh-menu-btn class="bh-top-user" aria-haspopup="true" aria-expanded="false" aria-label="Your account: <?= htmlspecialchars($__userName, ENT_QUOTES) ?>">
                        <?php // The photo, or the initial; marked so the photo editor can swap it live. ?>
                        <?= function_exists('bh_user_avatar') ? bh_user_avatar($user ?? [], 'w-8 h-8 text-sm') : '' ?>
                        <span class="bh-top-user__name"><?= htmlspecialchars($__userName) ?></span>
                        <?= icon('chevron-down', 'w-3.5 h-3.5 bh-top-user__caret') ?>
                    </button>
                    <div data-bh-menu-panel class="bh-top-panel bh-top-panel--menu hidden">
                        <div class="bh-top-panel__who">
                            <span class="bh-top-panel__name"><?= htmlspecialchars($__userName) ?></span>
                            <?php if (!empty($user['email'])): ?><span class="bh-top-panel__mail"><?= htmlspecialchars((string) $user['email']) ?></span><?php endif; ?>
                        </div>
                        <a href="<?= $base ?>/admin/profile" class="bh-top-item">
                            <span class="bh-top-item__icon"><?= icon('user-circle', 'w-4 h-4') ?></span> Profile
                        </a>
                        <?php if ($can('manage_settings')): ?>
                        <a href="<?= $base ?>/admin/settings" class="bh-top-item">
                            <span class="bh-top-item__icon"><?= icon('cog-6-tooth', 'w-4 h-4') ?></span> Settings
                        </a>
                        <?php endif; ?>
                        <div class="bh-top-panel__sep" role="separator"></div>
                        <?php // Appearance (1.3.0): saved on the account; admin/assets/js/appearance.js. ?>
                        <div class="bh-appearance" data-bh-appearance data-bh-keep-open>
                            <p class="bh-top-panel__label" id="bh-appearance-label">Appearance</p>
                            <div class="bh-seg bh-appearance__modes" role="radiogroup" aria-labelledby="bh-appearance-label">
                                <?php foreach (\App\Services\AppearanceService::MODES as $__m => $__label): ?>
                                <button type="button" role="radio" data-bh-mode="<?= $__m ?>" aria-checked="<?= $__appearance['mode'] === $__m ? 'true' : 'false' ?>">
                                    <?= icon(['light' => 'sun', 'dark' => 'moon', 'system' => 'computer-desktop'][$__m], 'w-4 h-4') ?><?= $__label ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="bh-appearance__accent" data-bh-accent-toggle aria-expanded="false" aria-controls="bh-accent-list">
                                <span class="bh-appearance__accent-label">Accent</span>
                                <span class="bh-appearance__current">
                                    <span class="bh-swatch" data-bh-accent-swatch data-accent="<?= $__appearance['accent'] ?>"></span>
                                    <span data-bh-accent-name><?= \App\Services\AppearanceService::ACCENTS[$__appearance['accent']] ?></span>
                                    <?= icon('chevron-down', 'w-3.5 h-3.5') ?>
                                </span>
                            </button>
                            <div class="bh-appearance__drawer">
                            <div id="bh-accent-list" class="bh-appearance__list" role="radiogroup" aria-label="Accent colour" inert>
                                <?php foreach (\App\Services\AppearanceService::ACCENTS as $__a => $__label): ?>
                                <button type="button" role="radio" data-bh-accent="<?= $__a ?>" aria-checked="<?= $__appearance['accent'] === $__a ? 'true' : 'false' ?>">
                                    <span class="bh-swatch" data-accent="<?= $__a ?>"></span><?= $__label ?><?= icon('check', 'w-4 h-4 bh-appearance__check') ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            </div>
                        </div>
                        <div class="bh-top-panel__sep" role="separator"></div>
                        <form method="POST" action="<?= $base ?>/admin/logout">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="bh-top-item bh-top-item--danger">
                                <span class="bh-top-item__icon"><?= icon('arrow-left-start-on-rectangle', 'w-4 h-4') ?></span> Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <dialog id="bh-cmdk" class="bh-dialog bh-dialog--lg bh-cmdk" aria-label="Search content" data-bh-search>
            <div class="bh-cmdk__bar" role="search">
                <?= icon('magnifying-glass', 'w-5 h-5 flex-none text-slate-400') ?>
                <input type="search" class="bh-cmdk__input" data-bh-search-input placeholder="Search posts, pages, media…" autocomplete="off" spellcheck="false" enterkeyhint="go"
                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="bh-cmdk-list" aria-label="Search content">
                <button type="button" class="bh-btn bh-btn--ghost bh-btn--sm bh-cmdk__close" data-bh-dialog-close>
                    <span class="bh-cmdk__esc"><kbd class="bh-kbd">Esc</kbd></span><span class="bh-cmdk__cancel">Cancel</span>
                </button>
            </div>
            <div class="bh-cmdk__body" id="bh-cmdk-list" role="listbox" aria-label="Results" data-bh-search-list>
                <p class="bh-cmdk__state">Type at least 2 characters to search the content you can edit.</p>
            </div>
            <div class="bh-cmdk__foot" aria-hidden="true">
                <span><kbd class="bh-kbd">↑</kbd><kbd class="bh-kbd">↓</kbd> to move</span>
                <span><kbd class="bh-kbd">Enter</kbd> to open</span>
                <span><kbd class="bh-kbd">Esc</kbd> to close</span>
            </div>
        </dialog>

        <!-- Content -->
        <main id="bh-content" class="p-4 sm:p-6">
            <?php if ($flash): ?>
                <div data-bh-flash class="mb-4 px-4 py-3 rounded-lg border <?php
                    echo $flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-800' :
                        ($flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' :
                        'bg-blue-50 border-blue-200 text-blue-800');
                ?>">
                    <?= icon($flash['type'] === 'error' ? 'x-circle' : ($flash['type'] === 'success' ? 'check-circle' : 'information-circle'), 'w-4 h-4 mr-2 shrink-0') ?>
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php
            // Apps switched off after a failure (1.2.71), for those who can act on it.
            $__failedApps = [];
            if (!empty($currentUser) && \App\Http\Middleware\CheckCapability::userCan($currentUser, 'manage_apps')
                && !str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/admin/apps')) {
                try {
                    $__guard = \App\Core\Application::getInstance()->make(\App\Services\AppHealth\AppGuard::class);
                    $__failedApps = $__guard->quarantined();
                } catch (\Throwable) {}
            }
            if ($__failedApps): ?>
                <div class="mb-4 px-4 py-3 rounded-lg border bg-red-50 border-red-200 text-red-800 text-sm flex items-start gap-2" role="status">
                    <?= icon('exclamation-triangle', 'w-4 h-4 mt-0.5 shrink-0') ?>
                    <div class="min-w-0">
                        <?= count($__failedApps) === 1 ? 'An app was' : count($__failedApps) . ' apps were' ?> switched off because <?= count($__failedApps) === 1 ? 'it' : 'they' ?> failed to load. The rest of the site is working.
                        <?php foreach (array_slice($__failedApps, 0, 3, true) as $__slug => $__rec): ?>
                            <a class="font-semibold underline ml-1" href="<?= $base ?>/admin/apps/<?= urlencode((string) $__slug) ?>/health"><?= htmlspecialchars((string) $__slug) ?> — view report</a>
                        <?php endforeach; ?>
                    </div>
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
    // Open and close by animating the height from/to the submenu's real height,
    // then hand back to CSS (height:auto when open) so it stays right if the
    // list changes.
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function toggleGroup(el) {
        var sub = el.querySelector('.bh-submenu');
        var opening = !el.classList.contains('is-open');
        if (!sub || reduceMotion || sidebar.classList.contains('is-collapsed')) { el.classList.toggle('is-open', opening); return; }
        var done = function (e) {
            if (e && e.target !== sub) return;
            sub.removeEventListener('transitionend', done);
            sub.style.height = '';
        };
        sub.removeEventListener('transitionend', sub._bhDone || done);
        sub._bhDone = done;
        if (opening) {
            el.classList.add('is-open');
            var full = sub.scrollHeight;
            sub.style.height = '0px';
            void sub.offsetHeight;                 // start from 0
            sub.style.height = full + 'px';
        } else {
            sub.style.height = sub.scrollHeight + 'px';
            void sub.offsetHeight;                 // start from the current height
            el.classList.remove('is-open');
            sub.style.height = '0px';
        }
        sub.addEventListener('transitionend', done);
        setTimeout(done, 400);                     // in case transitionend never fires
    }
    items.forEach(function (el) {
        var subBtn = el.querySelector('.bh-sub-toggle');
        subBtn && subBtn.addEventListener('click', function (ev) {
            ev.preventDefault(); ev.stopPropagation();
            toggleGroup(el);
        });
        var head = el.querySelector('.bh-group-head');
        head && head.addEventListener('click', function (ev) {
            // Clicking the row (but not a link/button inside it) toggles the submenu.
            if (ev.target.closest('a, button')) return;
            toggleGroup(el);
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
            var match;
            if (el.classList.contains('bh-nav-group')) {
                // A group: its own name shows all of it; otherwise only the
                // sub-items that match, opened (1.2.119: one match used to show
                // the whole group).
                var head = el.querySelector('.bh-group-head .bh-nav-label');
                var headHit = !q || (head ? head.textContent.toLowerCase().indexOf(q) >= 0 : false);
                var subHit = false;
                el.querySelectorAll('.bh-submenu > a').forEach(function (a) {
                    var hit = !q || headHit || a.textContent.toLowerCase().indexOf(q) >= 0;
                    a.style.display = hit ? '' : 'none';
                    if (hit && q) subHit = true;
                });
                match = !q || headHit || subHit;
                el.classList.toggle('is-search-open', !!q && match);
            } else {
                match = !q || (el.getAttribute('data-label') || '').indexOf(q) >= 0;
            }
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
    // Below 1024px the sidebar is a drawer (1.2.102: keyboard and screen
    // readers). While closed it is inert, so Tab doesn't walk through links
    // that are off-screen; open, focus moves into it and stays there until
    // Escape, the ×, the backdrop or a link closes it, then returns to the
    // menu button.
    var drawerMq = window.matchMedia('(max-width: 1023px)');
    var drawerOpen = false;
    function setInert(on) {
        if (on) sidebar.setAttribute('inert', ''); else sidebar.removeAttribute('inert');
        sidebar.setAttribute('aria-hidden', on ? 'true' : 'false');
        if (!on) sidebar.removeAttribute('aria-hidden');
    }
    function syncDrawer() {
        if (drawerMq.matches) {
            setInert(!drawerOpen);
            if (drawerOpen) { sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true'); sidebar.setAttribute('aria-label', 'Main menu'); }
            else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); }
        } else {
            setInert(false);
            sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal');
        }
        if (mobileToggle) mobileToggle.setAttribute('aria-expanded', drawerOpen ? 'true' : 'false');
    }
    function openMobile() {
        drawerOpen = true;
        sidebar.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        syncDrawer();
        var first = mobileClose || sidebar.querySelector('a, button, input');
        if (first) { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }
    }
    function closeMobile(restoreFocus) {
        var was = drawerOpen;
        drawerOpen = false;
        sidebar.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-open');
        document.body.style.overflow = '';
        syncDrawer();
        if (was && restoreFocus !== false && mobileToggle && drawerMq.matches) mobileToggle.focus();
    }
    if (mobileToggle) mobileToggle.addEventListener('click', openMobile);
    if (mobileClose) mobileClose.addEventListener('click', function () { closeMobile(); });
    if (backdrop) backdrop.addEventListener('click', function () { closeMobile(); });
    document.addEventListener('keydown', function (e) {
        if (!drawerOpen || !drawerMq.matches) return;
        if (e.key === 'Escape') { e.preventDefault(); closeMobile(); return; }
        if (e.key !== 'Tab') return;
        // Keep focus inside the open drawer.
        var items = Array.prototype.filter.call(
            sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (el) { return el.offsetParent !== null; });
        if (!items.length) return;
        var firstEl = items[0], lastEl = items[items.length - 1];
        if (e.shiftKey && document.activeElement === firstEl) { e.preventDefault(); lastEl.focus(); }
        else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); firstEl.focus(); }
    });
    // Close the drawer after navigating (tapping a nav link).
    navMain && navMain.addEventListener('click', function (e) {
        if (e.target.closest('a') && drawerMq.matches) closeMobile(false);
    });
    // Crossing the breakpoint: wide screens never have a drawer.
    var onMq = function () { if (!drawerMq.matches) closeMobile(false); syncDrawer(); };
    if (drawerMq.addEventListener) drawerMq.addEventListener('change', onMq); else if (drawerMq.addListener) drawerMq.addListener(onMq);
    syncDrawer();

    /* ===== Dialogs (1.2.102, .bh-dialog in ui.css) ===== */
    // [data-bh-dialog-open="id"] opens <dialog id="id"> as a modal; the
    // browser does Escape and focus. [data-bh-dialog-close] and a click on the
    // backdrop close it. A dialog may set data-bh-dialog-focus="#field" for
    // the first field to focus. window.bhDialog(id|el) opens one from script.
    window.bhDialog = function (target) {
        var d = typeof target === 'string' ? document.getElementById(target) : target;
        if (!d || typeof d.showModal !== 'function') return null;
        if (!d.open) d.showModal();
        var f = d.getAttribute('data-bh-dialog-focus');
        var el = f ? d.querySelector(f) : null;
        if (el) { try { el.focus(); } catch (e) {} }
        return d;
    };
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-bh-dialog-open]');
        if (opener) { e.preventDefault(); window.bhDialog(opener.getAttribute('data-bh-dialog-open')); return; }
        var closer = e.target.closest('[data-bh-dialog-close]');
        if (closer) { var cd = closer.closest('dialog'); if (cd) { e.preventDefault(); cd.close(); } return; }
        // A click on the backdrop lands on the dialog element itself.
        if (e.target.matches && e.target.matches('dialog.bh-dialog[open]')) {
            var r = e.target.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) e.target.close();
        }
    });

    /* ===== Section tabs: scroll-strip.js (1.3.0) ===== */

    /* ===== Click-based dropdown menus (create + user) ===== */
    // Replaces hover-only menus so they work on touch devices.
    // Opening plays a short fade-and-drop (CSS, on removing .hidden); closing
    // plays the reverse (.is-closing) and only then hides the panel.
    var menus = Array.prototype.slice.call(document.querySelectorAll('[data-bh-menu]'));
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function closeMenu(m) {
        var p = m.querySelector('[data-bh-menu-panel]');
        var b = m.querySelector('[data-bh-menu-btn]');
        if (b) b.setAttribute('aria-expanded', 'false');
        if (!p || p.classList.contains('hidden') || p.classList.contains('is-closing')) return;
        if (calm) { p.classList.add('hidden'); return; }
        p.classList.add('is-closing');
        setTimeout(function () { p.classList.add('hidden'); p.classList.remove('is-closing'); }, 120);
    }
    menus.forEach(function (menu) {
        var btn = menu.querySelector('[data-bh-menu-btn]');
        var panel = menu.querySelector('[data-bh-menu-panel]');
        if (!btn || !panel) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !panel.classList.contains('hidden') && !panel.classList.contains('is-closing');
            menus.forEach(function (m) { if (m !== menu || isOpen) closeMenu(m); });
            if (!isOpen) {
                panel.classList.remove('is-closing');
                panel.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
        // A click inside the panel (other than on a link or button) keeps it
        // open; so does one inside [data-bh-keep-open] (Appearance, 1.3.0).
        panel.addEventListener('click', function (e) { if (!e.target.closest('a, button') || e.target.closest('[data-bh-keep-open]')) e.stopPropagation(); });
    });
    document.addEventListener('click', function () { menus.forEach(closeMenu); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') menus.forEach(closeMenu);
    });
})();
</script>
<script>
/*
 * Admin-wide fixes that every screen (and every app screen) gets for free:
 *
 *  1. Tables that are not already in a scrolling box get one, so a wide
 *     table scrolls sideways on a phone instead of breaking the page.
 *  2. bhConfirm() / bhAlert(): the admin's own dialog. Forms that ask with
 *     onsubmit="return confirm('…')" are switched to it automatically, and
 *     alert() shows it too. (confirm() itself has to answer at once, so code
 *     that calls it in a script keeps the browser's box for now.)
 *  3. A label without for= that is followed by its field is linked to it,
 *     so clicking the label focuses the field and screen readers name it.
 */
(function () {
    'use strict';
    var main = document.querySelector('.bh-main') || document.body;

    // ── 1. tables ────────────────────────────────────────────────────────
    function scrolls(el) {
        for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            var o = getComputedStyle(p).overflowX;
            if (o === 'auto' || o === 'scroll') return true;
        }
        return false;
    }
    function wrapTables(root) {
        (root.querySelectorAll ? root.querySelectorAll('table') : []).forEach(function (t) {
            // The Data Table has its own responsive design (cards on small
            // screens) and a sticky header, so it must not be wrapped in an
            // overflow-x container — that would trap the sticky header.
            if (t.classList.contains('bh-dt-table') || t.closest('.bh-dt-wrap')) return;
            if (t.closest('.bh-table-scroll') || scrolls(t)) return;
            var w = document.createElement('div');
            w.className = 'bh-table-scroll';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
        });
    }

    // ── 3. labels ────────────────────────────────────────────────────────
    var uid = 0;
    function linkLabels(root) {
        (root.querySelectorAll ? root.querySelectorAll('label:not([for])') : []).forEach(function (l) {
            if (l.querySelector('input, select, textarea')) return;          // wraps its field already
            var next = l.nextElementSibling, field = null;
            if (next && /^(INPUT|SELECT|TEXTAREA)$/.test(next.tagName)) field = next;
            else if (next && next.tagName === 'DIV') {
                var inside = next.querySelectorAll('input:not([type=hidden]), select, textarea');
                if (inside.length === 1) field = inside[0];
            }
            if (!field || field.type === 'hidden') return;
            if (!field.id) field.id = 'bh-field-' + (++uid);
            l.htmlFor = field.id;
        });
    }

    // ── 2. dialogs ───────────────────────────────────────────────────────
    var nativeConfirm = window.confirm.bind(window), nativeAlert = window.alert.bind(window);
    var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>';
    var ICON_INFO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>';
    var DANGER = /\b(delete|remove|revoke|suspend|archive|permanently|cannot be undone|empty)\b/i;
    var VERBS = { delete: 'Delete', remove: 'Remove', revoke: 'Revoke', suspend: 'Suspend', archive: 'Archive', transfer: 'Transfer',
                  permanently: 'Delete', apply: 'Apply', regenerate: 'Regenerate', rebuild: 'Rebuild', empty: 'Empty', install: 'Install', send: 'Send' };

    function ask(message, opts) {
        opts = opts || {};
        var d = document.createElement('dialog');
        if (typeof d.showModal !== 'function') {
            return Promise.resolve(opts.alert ? (nativeAlert(message), true) : nativeConfirm(message));
        }
        var danger = opts.danger != null ? !!opts.danger : (!opts.alert && DANGER.test(message));
        var verb = (String(message).trim().split(/\s+/)[0] || '').toLowerCase().replace(/[^a-z]/g, '');
        var okText = opts.confirmLabel || (opts.alert ? 'OK' : (VERBS[verb] || 'Confirm'));
        d.className = 'bh-ask' + (danger ? ' is-danger' : '');
        d.setAttribute('aria-labelledby', 'bh-ask-msg');
        d.innerHTML = '<div class="bh-ask__body"><span class="bh-ask__icon">' + (danger || !opts.alert ? ICON_WARN : ICON_INFO) + '</span>'
            + '<p class="bh-ask__msg" id="bh-ask-msg"></p></div><div class="bh-ask__foot">'
            + (opts.alert ? '' : '<button type="button" class="bh-ask__btn bh-ask__btn--cancel" value="cancel"></button>')
            + '<button type="button" class="bh-ask__btn bh-ask__btn--ok" value="ok"></button></div>';
        d.querySelector('.bh-ask__msg').textContent = String(message);
        d.querySelector('.bh-ask__btn--ok').textContent = okText;
        var cancel = d.querySelector('.bh-ask__btn--cancel');
        if (cancel) cancel.textContent = opts.cancelLabel || 'Cancel';
        document.body.appendChild(d);
        return new Promise(function (resolve) {
            var answered = false;
            function finish(ok) { if (answered) return; answered = true; d.close(); d.remove(); resolve(ok); }
            d.addEventListener('click', function (e) {
                if (e.target === d) finish(!!opts.alert);                     // backdrop
                var b = e.target.closest('button'); if (b) finish(b.value === 'ok');
            });
            d.addEventListener('cancel', function (e) { e.preventDefault(); finish(!!opts.alert); });   // Escape
            d.showModal();
            // A destructive question starts on Cancel, so Enter cannot delete.
            (danger && cancel ? cancel : d.querySelector('.bh-ask__btn--ok')).focus();
        });
    }
    window.bhConfirm = function (message, opts) { return ask(message, opts); };

    // ── toasts: bhToast(message, 'success' | 'error' | 'warning' | 'info', { timeout }) ──
    var toasts = null;
    window.bhToast = function (message, type, opts) {
        opts = opts || {};
        if (!toasts) {
            toasts = document.createElement('div');
            toasts.className = 'bh-toasts';
            toasts.setAttribute('role', 'status');
            toasts.setAttribute('aria-live', 'polite');
            document.body.appendChild(toasts);
        }
        var t = document.createElement('div');
        t.className = 'bh-toast bh-toast--' + (type || 'success');
        t.textContent = String(message);
        toasts.appendChild(t);
        var gone = false;
        function dismiss() {
            if (gone) return; gone = true;
            t.classList.add('is-leaving');
            setTimeout(function () { t.remove(); }, 170);
        }
        t.addEventListener('click', dismiss);
        setTimeout(dismiss, opts.timeout || (type === 'error' ? 6000 : 3500));
        return dismiss;
    };

    // ── busy buttons: bhBusy(button, true[, 'Saving…']) … bhBusy(button, false) ──
    // The same loader as bhLoader(button) (1.2.83): one spinner, drawn one way.
    // It used to set aria-busy and leave the spinner to a stylesheet, while
    // bhLoader drew its own, so a button could get both (BH-036).
    window.bhBusy = function (btn, busy, label) {
        if (!btn) return;
        if (busy) {
            if (btn._bhLoader) btn._bhLoader.remove();
            var words = label || (btn.textContent || '').trim();
            var l = buttonLoader(btn, words || ' ');
            if (!words) l.set('');                    // an icon-only button: the spinner alone
        } else if (btn._bhLoader) {
            btn._bhLoader.remove();
        } else {
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
        }
    };

    // Done / failed marks: icons from the icon set, never text characters.
    function statusIcon(ok) {
        return typeof window.BasehimIcon === 'function' ? window.BasehimIcon(ok ? 'check' : 'exclamation-circle', '') : '';
    }

    // ── loader: the shared "working…" state (admin/assets/css/ui.css) ──
    //   var l = bhLoader(button, 'Saving draft…');        // inside the button
    //   l.done('Draft saved');  l.fail('Not saved');  l.set('Still saving…');  l.remove();
    // On a button (1.2.50) the spinner and words go inside it: the button is
    // disabled while it works, shows a check / exclamation icon when done() /
    // fail(), and gets its
    // own label back after `timeout` ms (2000; 3000 after a failure). Keep
    // button texts short — the full message belongs in a toast or banner.
    // Anything else gets the pill: place 'before' (default) or 'after' the
    // anchor, 'inside' it, or 'over' it (centred; the anchor must be
    // position:relative); done() fades out after `timeout`, fail() stays.
    // { place: 'before' } etc. on a button asks for the pill instead.
    // One loader per anchor: a new call replaces the previous one.
    function buttonLoader(btn, label) {
        var original = btn.innerHTML;
        var prevMin = btn.style.minWidth;
        var w = btn.getBoundingClientRect().width;
        if (w) btn.style.minWidth = Math.ceil(w) + 'px';      // never narrower than it was
        btn.classList.remove('is-done', 'is-error');
        btn.classList.add('bh-btn-busy');
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = '<span class="bh-btn-busy__icon" aria-hidden="true"></span><span class="bh-btn-busy__text" role="status" aria-live="polite"></span>';
        var icon = btn.firstChild, text = btn.lastChild;
        text.textContent = label || 'Working…';
        var timer = null, gone = false;
        function finish(cls, msg, ms) {
            if (gone) return api;
            btn.classList.remove('is-done', 'is-error');
            btn.classList.add(cls);
            icon.innerHTML = statusIcon(cls === 'is-done');
            if (msg) text.textContent = msg;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            clearTimeout(timer);
            timer = setTimeout(api.remove, ms);
            return api;
        }
        var api = {
            el: btn,
            set: function (msg) { if (!gone) text.textContent = msg; return api; },
            done: function (msg, o) { return finish('is-done', msg, (o && o.timeout) || 2000); },
            fail: function (msg, o) { return finish('is-error', msg, (o && o.timeout) || 3000); },
            remove: function () {
                if (gone) return; gone = true; clearTimeout(timer);
                btn.innerHTML = original;
                btn.classList.remove('bh-btn-busy', 'is-done', 'is-error');
                btn.style.minWidth = prevMin;
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                if (btn._bhLoader === api) btn._bhLoader = null;
            }
        };
        btn._bhLoader = api;
        return api;
    }

    window.bhLoader = function (anchor, label, opts) {
        opts = opts || {};
        if (anchor && anchor._bhLoader) anchor._bhLoader.remove();
        var isButton = anchor && (anchor.tagName === 'BUTTON'
            || (anchor.tagName === 'INPUT' && /^(submit|button)$/i.test(anchor.type)));
        if (isButton && anchor.tagName === 'BUTTON' && (!opts.place || opts.place === 'button')) return buttonLoader(anchor, label);
        var place = opts.place && opts.place !== 'button' ? opts.place : 'before';
        var el = document.createElement('span');
        el.className = 'bh-loader bh-loader--' + place;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.innerHTML = '<span class="bh-loader__spin" aria-hidden="true"></span><span class="bh-loader__text"></span>';
        var text = el.lastChild;
        text.textContent = label || 'Working…';
        if (anchor) {
            if (place === 'after') anchor.insertAdjacentElement('afterend', el);
            else if (place === 'inside' || place === 'over') anchor.appendChild(el);
            else anchor.insertAdjacentElement('beforebegin', el);
        }
        var timer = null, gone = false;
        var api = {
            el: el,
            set: function (msg) { if (!gone) text.textContent = msg; return api; },
            done: function (msg, o) {
                if (gone) return api;
                el.classList.remove('is-error'); el.classList.add('is-done');
                el.firstChild.innerHTML = statusIcon(true);
                if (msg) text.textContent = msg;
                clearTimeout(timer);
                timer = setTimeout(api.remove, (o && o.timeout) || 2000);
                return api;
            },
            fail: function (msg) {
                if (gone) return api;
                el.classList.remove('is-done'); el.classList.add('is-error');
                el.firstChild.innerHTML = statusIcon(false);
                if (msg) text.textContent = msg;
                clearTimeout(timer);
                return api;
            },
            remove: function () {
                if (gone) return; gone = true; clearTimeout(timer);
                el.classList.add('is-leaving');
                setTimeout(function () { el.remove(); }, 180);
                if (anchor && anchor._bhLoader === api) anchor._bhLoader = null;
            }
        };
        if (anchor) anchor._bhLoader = api;
        return api;
    };

    // One namespace for apps that prefer it.
    window.Basehim = window.Basehim || {};
    window.Basehim.ui = { confirm: window.bhConfirm, toast: window.bhToast, busy: window.bhBusy, loader: window.bhLoader };
    window.bhAlert = function (message, opts) { return ask(message, Object.assign({}, opts, { alert: true })); };
    window.Basehim.ui.alert = window.bhAlert;
    // alert() has nothing to answer, so it can always use the admin's dialog.
    window.alert = function (message) { window.bhAlert(message == null ? '' : String(message)); };

    // onsubmit / onclick = "return confirm('…')" [&& confirm('…')] → bhConfirm.
    var LIT = "('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
    var WHOLE = new RegExp('^\\s*return\\s+confirm\\(\\s*' + LIT + '\\s*\\)(\\s*&&\\s*confirm\\(\\s*' + LIT + '\\s*\\))*\\s*;?\\s*$');
    var EACH = new RegExp('confirm\\(\\s*' + LIT + '\\s*\\)', 'g');
    function unquote(lit) {
        var body = lit.slice(1, -1);
        return body.replace(/\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/g, function (m, c) {
            if (c[0] === 'u' || c[0] === 'x') return String.fromCharCode(parseInt(c.slice(1), 16));
            return { n: '\n', t: '\t', r: '', b: '', f: '', v: '', '0': '' }[c] !== undefined ? { n: '\n', t: '\t', r: '', b: '', f: '', v: '', '0': '' }[c] : c;
        });
    }
    function messagesOf(code) {
        if (!code || !WHOLE.test(code)) return null;
        var out = [], m; EACH.lastIndex = 0;
        while ((m = EACH.exec(code))) out.push(unquote(m[1]));
        return out.length ? out : null;
    }
    function askAll(list) {
        return list.reduce(function (p, msg) { return p.then(function (ok) { return ok ? ask(msg) : false; }); }, Promise.resolve(true));
    }
    function upgradeConfirms(root) {
        (root.querySelectorAll ? root.querySelectorAll('form[onsubmit]') : []).forEach(function (f) {
            var list = messagesOf(f.getAttribute('onsubmit')); if (!list) return;
            f.removeAttribute('onsubmit');
            f.addEventListener('submit', function (e) {
                if (f._bhOk) { f._bhOk = false; return; }
                e.preventDefault();
                var by = e.submitter;
                askAll(list).then(function (ok) {
                    if (!ok) return;
                    f._bhOk = true;
                    if (typeof f.requestSubmit === 'function') f.requestSubmit(by && by.form === f ? by : undefined); else f.submit();
                });
            });
        });
        (root.querySelectorAll ? root.querySelectorAll('a[onclick], button[onclick]') : []).forEach(function (el) {
            var list = messagesOf(el.getAttribute('onclick')); if (!list) return;
            el.removeAttribute('onclick');
            el.addEventListener('click', function (e) {
                if (el._bhOk) { el._bhOk = false; return; }
                e.preventDefault();
                askAll(list).then(function (ok) {
                    if (!ok) return;
                    if (el.tagName === 'A' && el.href) { window.location.href = el.href; return; }
                    el._bhOk = true; el.click();
                });
            });
        });
    }

    function enhance(root) { wrapTables(root); linkLabels(root); upgradeConfirms(root); }
    enhance(main);
    // Screens that build their tables and forms in a script get the same.
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (r) { r.addedNodes.forEach(function (n) { if (n.nodeType === 1 && !n.classList.contains('bh-ask')) enhance(n.parentNode || n); }); });
        }).observe(main, { childList: true, subtree: true });
    }
})();
</script>
<script>
/* The sidebar's update line follows the hidden updates badge, which the
   dashboard's background check and the Updates page keep current. */
(function () {
    var badge = document.querySelector('.bh-sidebar-foot [data-bh-badge="updates"]');
    var line = document.querySelector('[data-bh-update-line]');
    if (!badge || !line || !window.MutationObserver) return;
    var text = line.querySelector('[data-bh-update-text]');
    function paint() {
        var n = parseInt(badge.textContent, 10) || 0;
        line.classList.toggle('is-pending', n > 0);
        line.classList.toggle('is-current', n === 0);
        line.classList.remove('is-unknown');
        text.textContent = n > 0 ? n + ' update' + (n === 1 ? '' : 's') + ' available' : 'You’re up to date';
        var card = line.closest('.bh-foot-card');
        if (card) card.title = n > 0 ? n + ' update' + (n === 1 ? '' : 's') + ' ready to install' : 'Open Updates';
    }
    new MutationObserver(paint).observe(badge, { childList: true, characterData: true, subtree: true, attributes: true });
})();
</script>
<script>
/*
 * Keep the pending-comments badge current while the admin is open. Moderating
 * reloads the page and redraws it anyway; this covers a comment arriving while
 * a moderator is looking at some other screen. One COUNT query a minute, and
 * none while the tab is hidden.
 */
(function () {
    var badge = document.querySelector('[data-bh-badge="comments"]');
    if (!badge || !window.Basehim || !window.Basehim.ajax) return;
    var busy = false;
    function paint(n) {
        n = parseInt(n, 10) || 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.hidden = n <= 0;
        badge.title = n === 1 ? '1 comment awaiting moderation' : n + ' comments awaiting moderation';
    }
    function refresh() {
        if (busy || document.visibilityState === 'hidden') return;
        busy = true;
        // comments.pending_count through the AJAX gateway (1.2.47).
        window.Basehim.ajax.get('comments.pending_count')
            .then(function (d) { if (d && typeof d.pending !== 'undefined') paint(d.pending); })
            .catch(function () { /* signed out, offline: leave the badge as it is */ })
            .finally(function () { busy = false; });
    }
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refresh();
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
