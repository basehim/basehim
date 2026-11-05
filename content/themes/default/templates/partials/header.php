<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'] ?? $site_title) ?></title>
    <?php /* Description, canonical, robots, Open Graph, X and JSON-LD are added
             by core's SEO service (Settings → SEO); a theme prints none of them. */ ?>

    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($site_title) ?>" href="<?= $base ?>/feed">

    <?php if (!empty($favicon_url)): ?>
    <link rel="icon" href="<?= htmlspecialchars($favicon_url) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon_url) ?>">
    <?php endif; ?>

    <!-- Fonts + icons + Tailwind (local for reliability) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Lora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>/content/themes/default/assets/tailwind.min.css?v=<?= urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') ?>">
    <link rel="stylesheet" href="<?= $base ?>/content/themes/default/assets/theme.css?v=<?= urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1') ?>">
    <?= menu_assets() ?>
    <?php
    /* Everything core and any installed app needs in <head>: the theme's options
       as CSS custom properties, the site's custom CSS, registered stylesheets,
       and whatever apps contribute. A theme calls this and receives all of it
       without knowing which apps are installed. */
    echo function_exists('bh_head') ? bh_head() : ($customizer_head ?? '');
    ?>
</head>
<body class="font-sans bg-white text-slate-800 antialiased min-h-screen flex flex-col">

<!-- Header -->
<header class="border-b border-slate-100 bg-white/95 backdrop-blur sticky top-0 z-40">
    <div class="max-w-6xl mx-auto px-4 lg:px-6">
        <div class="flex items-center justify-between bht-header-row">
            <!-- Brand -->
            <?php
            // Customize → Site identity (1.2.99): the name and the tagline can each
            // be hidden; the logo's size is var(--bh-logo-width) in theme.css.
            $__showName = $show_site_title ?? true;
            $__showTag = ($show_tagline ?? true) && !empty($tagline);
            ?>
            <a href="<?= $base ?>/" class="flex items-center gap-3 group min-w-0"<?= !$__showName && empty($logo_url) ? ' aria-label="' . htmlspecialchars($site_title) . '"' : '' ?>>
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($site_title) ?>" class="bht-logo">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 grid place-items-center text-white shadow-md shadow-brand-200 group-hover:shadow-lg group-hover:shadow-brand-300 transition">
                        <?= icon('bolt', 'w-4 h-4') ?>
                    </div>
                <?php endif; ?>
                <?php // The name shows on phones too (1.2.98); only the tagline waits for wider screens. ?>
                <?php if ($__showName || $__showTag): ?>
                <div class="bht-brand-text">
                    <?php if ($__showName): ?>
                    <div class="font-semibold text-slate-900 leading-tight"><?= htmlspecialchars($site_title) ?></div>
                    <?php endif; ?>
                    <?php if ($__showTag): ?>
                    <div class="text-xs text-slate-500 hidden sm:block"><?= htmlspecialchars($tagline) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </a>

            <!-- Nav -->
            <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                <?php // menu_html() renders children as dropdowns. The flat loop this
                      // replaced ignored them, so a nested item disappeared from the site. ?>
                <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu gap-6 text-slate-700', 'aria' => 'Primary']) ?>
            </nav>

            <!-- Search button -->
            <div class="flex items-center gap-2">
                <form action="<?= $base ?>/search" method="GET" role="search" class="hidden md:flex items-center bg-slate-50 border border-slate-200 rounded-lg overflow-hidden focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100">
                    <?php // Padding on the svg itself squeezed the icon to a dot; the span holds the space. ?>
                    <span class="bht-search-ico" aria-hidden="true"><?= icon('magnifying-glass', 'w-4 h-4') ?></span>
                    <input type="search" name="q" placeholder="Search…" aria-label="Search" value="<?= htmlspecialchars($query ?? '') ?>"
                           class="bg-transparent border-0 px-2.5 py-1.5 text-sm w-40 focus:w-56 focus:outline-none transition-all">
                </form>
                <button type="button" aria-controls="mobile-menu" aria-expanded="false" aria-label="Menu"
                        onclick="var m=document.getElementById('mobile-menu');m.classList.toggle('hidden');this.setAttribute('aria-expanded',m.classList.contains('hidden')?'false':'true')"
                        class="md:hidden bht-menu-btn text-slate-600 hover:bg-slate-50 rounded-lg">
                    <?= icon('bars-3', 'w-5 h-5') ?>
                </button>
            </div>
        </div>

        <!-- Mobile menu -->
        <div id="mobile-menu" class="hidden md:hidden pb-4 border-t border-slate-100 -mx-4 px-4 pt-3 space-y-2">
            <?php // Stacked rather than hovering: a dropdown needs a pointer. ?>
            <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu bh-menu--stack text-slate-700']) ?>
            <form action="<?= $base ?>/search" method="GET" class="pt-2">
                <input type="search" name="q" placeholder="Search…" aria-label="Search" class="w-full px-3 py-2 border border-slate-300 rounded-lg bht-input-touch">
            </form>
        </div>
    </div>
</header>

<!-- Main content begins -->
<main class="flex-1">
