<?php
/**
 * The Settings sections, in order: slug => label, icon, and the line shown
 * under the title on desktop. Shared by the tabs (_nav.php) and the phone's
 * Settings home (_head.php).
 */
return [
    'general'       => ['label' => 'General',        'icon' => 'cog-6-tooth',               'sub' => 'Your site\'s name, address for notices, language and time.'],
    'reading'       => ['label' => 'Reading',        'icon' => 'book-open',                 'sub' => 'What visitors see first, author pages, search and page views.'],
    'writing'       => ['label' => 'Writing',        'icon' => 'pencil',                    'sub' => 'Defaults for new posts.'],
    'discussion'    => ['label' => 'Discussion',     'icon' => 'chat-bubble-left-right',    'sub' => 'Comments, notifications and spam protection.'],
    'permalinks'    => ['label' => 'Permalinks',     'icon' => 'link',                      'sub' => 'The addresses of your posts and pages.'],
    'media'         => ['label' => 'Media',          'icon' => 'photo',                     'sub' => 'Uploads, image sizes and thumbnails.'],
    'seo'           => ['label' => 'SEO',            'icon' => 'document-magnifying-glass', 'sub' => 'Search and social sharing tags.'],
    'ai'            => ['label' => 'AI Agents',      'icon' => 'cpu-chip',                  'sub' => 'How AI agents and crawlers find and use your site.'],
    'email'         => ['label' => 'Email',          'icon' => 'envelope',                  'sub' => 'How Basehim sends password resets, notifications and app mail.'],
    'appearance'    => ['label' => 'Appearance',     'icon' => 'swatch',                    'sub' => 'Logo, favicon, footer and custom CSS.'],
    'authorization' => ['label' => 'Authentication', 'icon' => 'shield-check',              'sub' => 'Two-step verification, registration and sign-in protection.'],
];
