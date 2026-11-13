<?php
/**
 * 503 for a URL that belongs to an app which has been switched off after a
 * failure (1.2.71). Shown to visitors: no app name, no error details. Drawn
 * by StatusPage (1.3.0).
 */
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
echo \App\Core\Http\StatusPage::html(503, 'Temporarily unavailable',
    'This part of the site is switched off for maintenance. The rest of the site works as usual.', [
    'icon' => 'wrench-screwdriver', 'tone' => 'info',
    'actions' => [['label' => 'Home', 'href' => $base . '/', 'primary' => true, 'icon' => 'home']],
]);
