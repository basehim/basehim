<?php
/**
 * Settings tabs — rendered through the shared tab component so Settings, API
 * and System all look and behave the same.
 * Expects $tab (active slug) and $base in scope.
 */
$this->include('partials.tabs', [
    'base'      => $base,
    'active'    => $tab ?? '',
    'urlPrefix' => '/admin/settings/',
    'ariaLabel' => 'Settings sections',
    'tabs'      => [
        'general'       => ['label' => 'General',       'icon' => 'cog-6-tooth'],
        'reading'       => ['label' => 'Reading',       'icon' => 'book-open'],
        'writing'       => ['label' => 'Writing',       'icon' => 'pencil'],
        'discussion'    => ['label' => 'Discussion',    'icon' => 'chat-bubble-left-right'],
        'permalinks'    => ['label' => 'Permalinks',    'icon' => 'link'],
        'media'         => ['label' => 'Media',         'icon' => 'photo'],
        'seo'           => ['label' => 'SEO',           'icon' => 'document-magnifying-glass'],
        'email'         => ['label' => 'Email',         'icon' => 'envelope'],
        'appearance'    => ['label' => 'Appearance',    'icon' => 'swatch'],
        'authorization' => ['label' => 'Authorization', 'icon' => 'shield-check'],
    ],
]);
