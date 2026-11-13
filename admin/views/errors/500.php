<?php
/**
 * 500. ErrorHandler draws this page itself (it answers when rendering failed);
 * kept for code that renders 'errors.500', and drawn the same way (1.3.0).
 */
$base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
echo \App\Core\Http\StatusPage::html(500, 'Something went wrong',
    'An unexpected error occurred. Please try again, or contact the site administrator if it keeps happening.', [
    'actions' => [['label' => 'Back to home', 'href' => $base . '/', 'primary' => true, 'icon' => 'home']],
]);
