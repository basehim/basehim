<?php

use App\Core\Env;

return [
    'site_title'    => Env::get('SITE_TITLE', 'Basehim'),
    'tagline'       => Env::get('SITE_TAGLINE', 'A Modern API-First CMS'),
    'admin_email'   => Env::get('ADMIN_EMAIL', 'admin@example.com'),

    'posts_per_page' => 10,

    'permalinks' => [
        'post' => '/posts/:slug',
        'page' => '/:slug',
    ],

    'media' => [
        'disk'            => 'local',
        'path'            => 'storage/uploads',
        'allowed_types'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'mp3', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
        'max_upload_size' => 64 * 1024 * 1024,
        'image_sizes'     => [
            'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
            'medium'    => ['width' => 600, 'height' => 600, 'crop' => false],
            'large'     => ['width' => 1200, 'height' => 1200, 'crop' => false],
        ],
    ],

    'content_types' => [
        'post' => [
            'label'        => 'Posts',
            'singular'     => 'Post',
            'hierarchical' => false,
            'public'       => true,
            'supports'     => ['title', 'content', 'excerpt', 'thumbnail', 'author', 'comments', 'revisions'],
        ],
        'page' => [
            'label'        => 'Pages',
            'singular'     => 'Page',
            'hierarchical' => true,
            'public'       => true,
            'supports'     => ['title', 'content', 'thumbnail', 'author', 'revisions'],
        ],
    ],

    'taxonomies' => [
        'category' => [
            'label'        => 'Categories',
            'singular'     => 'Category',
            'hierarchical' => true,
            'post_types'   => ['post'],
        ],
        'tag' => [
            'label'        => 'Tags',
            'singular'     => 'Tag',
            'hierarchical' => false,
            'post_types'   => ['post'],
        ],
    ],
];
