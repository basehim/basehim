<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Page Not Found</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/css/tailwind.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-blue-100 min-h-screen grid place-items-center font-sans">
    <div class="text-center px-6">
        <div class="inline-flex mb-6"><?= brand_logo(80) ?></div>
        <h1 class="text-6xl font-bold text-slate-900 mb-2">404</h1>
        <p class="text-xl text-slate-700 mb-2">Page Not Found</p>
        <p class="text-slate-500 mb-6 max-w-sm mx-auto"><?= htmlspecialchars($message ?? "The page you're looking for doesn't exist or has been moved.") ?></p>
        <div class="flex items-center justify-center gap-3">
            <a href="<?= $base ?>/" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm">
                <?= icon('home', 'w-4 h-4 mr-1') ?> Home
            </a>
            <a href="javascript:history.back()" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-lg font-medium">
                <?= icon('arrow-left', 'w-4 h-4 mr-1') ?> Go Back
            </a>
        </div>
    </div>
</body>
</html>
