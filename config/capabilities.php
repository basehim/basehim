<?php

/**
 * Capability Registry
 *
 * Maps roles → capabilities. The spec's `config/capabilities.php` —
 * adapted for our framework.
 *
 * `manage_apps` gates /admin/apps. The capability spelling is
 * not listed: a clean 1.43.0 install has no role rows predating the rename, so
 * carrying both would only widen the surface for no one's benefit.
 */
return [
    'roles' => [
        'super_admin' => ['*'],

        'admin' => [
            'manage_options', 'manage_apps', 'manage_themes',
            'manage_users', 'manage_settings', 'manage_taxonomies',
            'manage_menus', 'manage_seo',
            'moderate_comments',
            'publish_posts', 'edit_posts', 'edit_others_posts',
            'delete_posts', 'delete_others_posts',
            'publish_pages', 'edit_pages', 'edit_others_pages',
            'delete_pages', 'delete_others_pages',
            'upload_media', 'delete_media',
            'read', 'read_private_meta',
        ],

        'editor' => [
            'publish_posts', 'edit_posts', 'edit_others_posts',
            'delete_posts',
            'publish_pages', 'edit_pages', 'edit_others_pages',
            'delete_pages',
            'upload_media', 'manage_taxonomies',
            'moderate_comments',
            'read',
        ],

        'author' => [
            'publish_posts', 'edit_posts', 'delete_posts',
            'upload_media',
            'read',
        ],

        'contributor' => [
            'edit_posts', 'delete_posts',
            'read',
        ],

        'subscriber' => [
            'read',
        ],
    ],
];
