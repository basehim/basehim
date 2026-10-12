<?php
/** @var string $title */
/** @var string $content (rendered via section) */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $this->include('partials.admin-head', ['base' => $base ?? '', 'title' => $title ?? null]); ?>
    <link rel="stylesheet" href="<?= $base ?>/admin/assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-blue-100 min-h-screen antialiased text-slate-800">
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <?= $this->yieldSection('content') ?>
    </div>
</body>
</html>
