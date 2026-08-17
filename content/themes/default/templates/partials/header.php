<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'] ?? $site_title) ?></title>
    <?php if (!empty($seo['description'])): ?>
    <meta name="description" content="<?= htmlspecialchars($seo['description']) ?>">
    <?php endif; ?>
    <?php if (!empty($seo['canonical'])): ?>
    <link rel="canonical" href="<?= htmlspecialchars($seo['canonical']) ?>">
    <?php endif; ?>
    <?php if (!empty($seo['robots'])): ?>
    <meta name="robots" content="<?= htmlspecialchars($seo['robots']) ?>">
    <?php endif; ?>
    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($seo['og_title'] ?? $seo['title'] ?? $site_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seo['og_description'] ?? $seo['description'] ?? '') ?>">
    <meta property="og:type" content="<?= !empty($post) ? 'article' : 'website' ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($site_title) ?>">

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
    <?= menu_assets() ?>
    <?php
    /* Theme options as CSS custom properties, the site's custom CSS, and — in a
       Customizer preview only — the bridge that applies pending changes live.
       Assembled by core so every theme gets it the same way. */
    echo $customizer_head ?? '';
    ?>
</head>
<body class="font-sans bg-white text-slate-800 antialiased min-h-screen flex flex-col">

<!-- Header -->
<header class="border-b border-slate-100 bg-white/95 backdrop-blur sticky top-0 z-40">
    <div class="max-w-6xl mx-auto px-4 lg:px-6">
        <div class="flex items-center justify-between h-16">
            <!-- Brand -->
            <a href="<?= $base ?>/" class="flex items-center gap-3 group">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($site_title) ?>" class="h-9 w-auto">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 grid place-items-center text-white shadow-md shadow-brand-200 group-hover:shadow-lg group-hover:shadow-brand-300 transition">
                        <?= icon('bolt', 'w-4 h-4') ?>
                    </div>
                <?php endif; ?>
                <div class="hidden sm:block">
                    <div class="font-semibold text-slate-900 leading-tight"><?= htmlspecialchars($site_title) ?></div>
                    <?php if (!empty($tagline)): ?>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars($tagline) ?></div>
                    <?php endif; ?>
                </div>
            </a>

            <!-- Nav -->
            <nav class="hidden md:flex items-center gap-6 text-sm font-medium">
                <?php // menu_html() renders children as dropdowns. The flat loop this
                      // replaced ignored them, so a nested item disappeared from the site. ?>
                <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu gap-6 text-slate-700', 'aria' => 'Primary']) ?>
            </nav>

            <!-- Search button -->
            <div class="flex items-center gap-2">
                <form action="<?= $base ?>/search" method="GET" class="hidden md:flex items-center bg-slate-50 border border-slate-200 rounded-lg overflow-hidden focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100">
                    <?= icon('magnifying-glass', 'w-4 h-4 text-slate-400 pl-3') ?>
                    <input type="text" name="q" placeholder="Search..." value="<?= htmlspecialchars($query ?? '') ?>"
                           class="bg-transparent border-0 px-2.5 py-1.5 text-sm w-40 focus:w-56 focus:outline-none transition-all">
                </form>
                <button type="button" onclick="document.getElementById('mobile-menu').classList.toggle('hidden')"
                        class="md:hidden p-2 text-slate-600 hover:bg-slate-50 rounded-lg">
                    <?= icon('bars-3', 'w-4 h-4') ?>
                </button>
            </div>
        </div>

        <!-- Mobile menu -->
        <div id="mobile-menu" class="hidden md:hidden pb-4 border-t border-slate-100 -mx-4 px-4 pt-3 space-y-2">
            <?php // Stacked rather than hovering: a dropdown needs a pointer. ?>
            <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu bh-menu--stack text-slate-700']) ?>
            <form action="<?= $base ?>/search" method="GET" class="pt-2">
                <input type="text" name="q" placeholder="Search..." class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </form>
        </div>
    </div>
</header>

<!-- Main content begins -->
<main class="flex-1">
