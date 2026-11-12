<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page Not Found</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/css/tailwind.min.css">
</head>
<body class="bg-gradient-to-br from-accent-50 via-white to-accent-100 min-h-screen grid place-items-center font-sans">
    <div class="text-center px-6">
        <div class="inline-flex mb-6"><?= brand_logo(80) ?></div>
        <h1 class="text-6xl font-bold text-slate-900 mb-2">404</h1>
        <p class="text-xl text-slate-700 mb-2">Page Not Found</p>
        <p class="text-slate-500 mb-6 max-w-sm mx-auto"><?= htmlspecialchars($message ?? "The page you're looking for doesn't exist or has been moved.") ?></p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="<?= $base ?>/admin" class="bh-btn bh-btn--primary">
                <?= icon('squares-2x2', 'w-4 h-4') ?> Dashboard
            </a>
            <a href="<?= $base ?>/" class="bh-btn bh-btn--secondary">
                <?= icon('home', 'w-4 h-4') ?> View site
            </a>
        </div>
    </div>
</body>
</html>
