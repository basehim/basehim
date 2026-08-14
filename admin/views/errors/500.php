<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Server Error</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= defined('BASEHIM_BASE') ? BASEHIM_BASE : '' ?>/admin/assets/css/tailwind.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-blue-100 min-h-screen grid place-items-center font-sans">
    <div class="text-center px-6 max-w-lg">
        <div class="inline-flex w-20 h-20 rounded-2xl bg-gradient-to-br from-red-500 to-red-700 items-center justify-center text-white text-3xl shadow-lg mb-6">
            <?= icon('exclamation-triangle', 'w-4 h-4') ?>
        </div>
        <h1 class="text-6xl font-bold text-slate-900 mb-2">500</h1>
        <p class="text-xl text-slate-700 mb-2">Something went wrong</p>
        <p class="text-slate-500 mb-6">An unexpected error occurred. Please try again or contact the site administrator if the problem persists.</p>
        <?php if (!empty($message) && filter_var(\App\Core\Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)): ?>
            <pre class="text-left bg-slate-900 text-slate-100 p-4 rounded-lg text-xs overflow-auto mb-6 max-h-48"><?= htmlspecialchars($message) ?></pre>
        <?php endif; ?>
        <a href="<?= $base ?>/" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
            <?= icon('home', 'w-4 h-4') ?> Back to Home
        </a>
    </div>
</body>
</html>
