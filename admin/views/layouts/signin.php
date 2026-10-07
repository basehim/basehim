<?php
/**
 * Sign-in layout: the site's own identity beside the form.
 *
 * Used by login, two-step code, unlock code, forgot/reset password,
 * registration and sign-out. (layouts/auth.php stays for the AI-client consent
 * screen and for apps that extend it.)
 *
 * @var string $title
 * @var string $base
 * @var array|null $flash
 */
$site = \App\Http\Controllers\Admin\AuthController::site();
$v = urlencode(defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '1');
$noticeIcon = [
    'error'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>',
    'success' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.78a.75.75 0 0 0-1.22-.88l-3.24 4.5-1.6-1.6a.75.75 0 1 0-1.06 1.06l2.22 2.22a.75.75 0 0 0 1.14-.09l3.76-5.21Z" clip-rule="evenodd"/></svg>',
    'info'    => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.25v3.25a.75.75 0 0 0 1.5 0V9.75A.75.75 0 0 0 10 9H9Z" clip-rule="evenodd"/></svg>',
];
$flashType = isset($flash['type']) && isset($noticeIcon[$flash['type']]) ? $flash['type'] : 'info';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="<?= htmlspecialchars($site['accent']) ?>">
    <title><?= htmlspecialchars($title ?? 'Sign in') ?> ‹ <?= htmlspecialchars($site['name']) ?></title>
    <?php if ($site['icon'] !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($site['icon']) ?>">
    <?php else: ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/admin/assets/img/favicon-32.png">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/auth.css?v=<?= $v ?>">
    <style>:root{--accent:<?= htmlspecialchars($site['accent']) ?>;--accent-ink:<?= htmlspecialchars($site['accentInk']) ?>;--accent-text:<?= $site['accentInk'] === '#ffffff' ? 'var(--accent)' : 'color-mix(in srgb, var(--accent) 40%, #000)' ?>}</style>
</head>
<body class="signin">
    <main class="si-card">
        <section class="si-site" aria-label="<?= htmlspecialchars($site['name']) ?>">
            <div class="si-mark">
                <?php if ($site['logo'] !== ''): ?>
                    <img class="si-mark__logo" src="<?= htmlspecialchars($site['logo']) ?>" alt="">
                <?php elseif ($site['icon'] !== ''): ?>
                    <img class="si-mark__icon" src="<?= htmlspecialchars($site['icon']) ?>" alt="" width="56" height="56">
                <?php else: ?>
                    <span class="si-mark__initial" aria-hidden="true"><?= htmlspecialchars($site['initial']) ?></span>
                <?php endif; ?>
            </div>
            <p class="si-site__name"><?= htmlspecialchars($site['name']) ?></p>
            <?php if ($site['tagline'] !== ''): ?>
                <p class="si-site__tagline"><?= htmlspecialchars($site['tagline']) ?></p>
            <?php endif; ?>
            <?php if ($site['host'] !== ''): ?>
                <a class="si-site__address" href="<?= htmlspecialchars($site['url']) ?>">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 13.5l7-7M8 6.5h5.5V12"/></svg>
                    <?= htmlspecialchars($site['host']) ?>
                </a>
            <?php endif; ?>
            <div class="si-site__foot">
                <a href="<?= htmlspecialchars($site['url']) ?>">Back to <?= htmlspecialchars($site['name']) ?></a>
                <span>Site admin</span>
            </div>
        </section>

        <section class="si-main">
            <?php if (!empty($flash['message'])): ?>
                <div class="si-notice si-notice--<?= $flashType ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>">
                    <?= $noticeIcon[$flashType] ?>
                    <span><?= htmlspecialchars((string) $flash['message']) ?></span>
                </div>
            <?php endif; ?>
            <?= $this->yieldSection('content') ?>
        </section>
    </main>
    <p class="si-powered">Powered by <a href="https://basehim.com" rel="noopener">Basehim</a></p>
</body>
</html>
