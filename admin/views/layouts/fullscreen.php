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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Basehim') ?> &mdash; Basehim</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/admin/assets/img/favicon-32.png">
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css">
    <?php $this->include('partials.admin-styles', ['base' => $base]); ?>
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
