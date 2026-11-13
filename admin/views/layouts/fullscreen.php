<?php
/**
 * Full-screen admin layout.
 *
 * No sidebar, no admin chrome. For screens that need the whole viewport and
 * provide their own way out — the Customizer, which gives most of the screen to
 * a preview iframe and would be cramped inside the usual shell.
 *
 * @var string $title
 * @var string $base
 */
?><!DOCTYPE html>
<?php
// The full-screen screens (the Customizer) follow the person's appearance
// like every admin screen (1.3.0); the site preview inside shows the theme.
// The signed-in person's id from the session ($currentUser isn't passed to
// every full-screen view; reading it here made the accent fall back to Default).
$__app = \App\Core\Application::getInstance();
$__appearance = $__app->make(\App\Services\AppearanceService::class)
    ->forUser($__app->make(\App\Services\AuthService::class)->currentUserId());
?>
<html lang="en" <?= \App\Services\AppearanceService::htmlAttributes($__appearance) ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $this->include('partials.appearance-head'); ?>
    <?php $this->include('partials.admin-head', ['base' => $base, 'title' => $title ?? null]); ?>
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css?v=<?= urlencode(BASEHIM_VERSION) ?>">
    <?php $this->include('partials.admin-styles', ['base' => $base]); ?>
<?= $this->headStyles() /* stylesheets views asked for with style() (1.3.0) */ ?>
    <?php $this->include('partials.ajax-client', ['base' => $base]); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body { font-family: 'Inter', system-ui, sans-serif; margin: 0; overflow: hidden; }
    </style>
</head>
<body class="antialiased text-slate-800 bg-slate-100"
      data-csrf="<?= htmlspecialchars($csrfToken ?? '') ?>">
    <?= $this->yieldSection('content') ?>
    <?php $this->include('partials.admin-scripts', ['base' => $base]); ?>
    <?= $this->yieldSection('scripts') ?>
</body>
</html>
