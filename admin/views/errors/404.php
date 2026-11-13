<?php
/**
 * 404 (Router::notFound). Drawn by StatusPage (1.3.0): the person's
 * light/dark mode and accent, core's components.
 *
 * @var string|null $message
 */
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
echo \App\Core\Http\StatusPage::html(404, 'Page not found',
    (string) ($message ?? "The page you're looking for doesn't exist or has been moved."), [
    'icon' => 'magnifying-glass', 'tone' => 'accent',
    'actions' => [
        ['label' => 'Dashboard', 'href' => $base . '/admin', 'primary' => true, 'icon' => 'squares-2x2'],
        ['label' => 'View site', 'href' => $base . '/', 'icon' => 'home'],
    ],
]);
