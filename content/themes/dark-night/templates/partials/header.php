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
    <meta name="theme-color" content="#0a0e17">

    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($site_title) ?>" href="<?= $base ?>/feed">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Lora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= $base ?>/content/themes/dark-night/assets/dark-night.css">
    <?= menu_assets() ?>
    <style>
    /* The shared dropdown styling assumes a light surface. This theme is dark,
       so the submenu panel is restated here rather than left unreadable. */
    .bh-submenu { background:#161b22; border-color:rgba(255,255,255,.12);
                  box-shadow:0 10px 30px rgba(0,0,0,.5); }
    .bh-submenu .bh-menu__link:hover,
    .bh-submenu .bh-menu__link:focus-visible { background:rgba(255,255,255,.07); }
    </style>
    <?php
    /* Theme options as CSS custom properties, the site's custom CSS, and — in a
       Customizer preview only — the bridge that applies pending changes live.
       Assembled by core so every theme gets it the same way. */
    echo $customizer_head ?? '';
    ?>
</head>
<body>

<!-- Header -->
<header class="dn-header">
    <div class="dn-container">
        <div class="dn-header-in">
            <a href="<?= $base ?>/" class="dn-brand">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($site_title) ?>">
                <?php else: ?>
                    <div class="dn-moon"><i class="fa-solid fa-moon"></i></div>
                <?php endif; ?>
                <div>
                    <div class="dn-brand-name"><?= htmlspecialchars($site_title) ?></div>
                    <?php if (!empty($tagline)): ?>
                    <div class="dn-brand-tag"><?= htmlspecialchars($tagline) ?></div>
                    <?php endif; ?>
                </div>
            </a>

            <nav class="dn-nav">
                <?php // menu_html() renders children as dropdowns. The flat loop this
                      // replaced ignored them, so a nested item disappeared from the site. ?>
                <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu', 'aria' => 'Primary']) ?>
            </nav>

            <div style="display:flex;align-items:center;gap:.6rem;">
                <form action="<?= $base ?>/search" method="GET" class="dn-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" placeholder="Search..." value="<?= htmlspecialchars($query ?? '') ?>">
                </form>
                <button type="button" class="dn-burger" onclick="document.getElementById('dn-mobile').classList.toggle('open')" aria-label="Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>

        <div id="dn-mobile" class="dn-mobile">
            <?php // Stacked rather than hovering: a dropdown needs a pointer. ?>
            <?= menu_html($primary_menu ?? [], ['class' => 'bh-menu bh-menu--stack']) ?>
            <form action="<?= $base ?>/search" method="GET">
                <input type="text" name="q" placeholder="Search...">
            </form>
        </div>
    </div>
</header>

<main>
