<?php
/**
 * 503 for a URL that belongs to an app which has been switched off after a
 * failure (1.2.71). Shown to visitors: no app name, no error details.
 */
$base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Temporarily unavailable</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/admin/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-blue-100 min-h-screen grid place-items-center font-sans">
    <div class="text-center px-6">
        <div class="inline-flex mb-6"><?= brand_logo(80) ?></div>
        <h1 class="text-4xl font-bold text-slate-900 mb-2">Temporarily unavailable</h1>
        <p class="text-slate-500 mb-6 max-w-sm mx-auto">This part of the site is switched off for maintenance. The rest of the site works as usual.</p>
        <a href="<?= $base ?>/" class="inline-flex items-center gap-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
            <?= icon('home', 'w-4 h-4') ?> Home
        </a>
    </div>
</body>
</html>
