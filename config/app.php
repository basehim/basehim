<?php

use App\Core\Env;

return [
    'name'      => Env::get('APP_NAME', 'Basehim'),
    'env'       => Env::get('APP_ENV', 'production'),
    'debug'     => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url'       => Env::get('APP_URL', 'http://localhost'),
    'timezone'  => Env::get('APP_TIMEZONE', 'UTC'),
    'locale'    => Env::get('APP_LOCALE', 'en'),
    'key'       => Env::get('APP_KEY', ''),
    // Reverse proxies whose X-Forwarded-For / CF-Connecting-IP headers may be
    // believed. Empty means trust nothing but REMOTE_ADDR, which is correct for
    // a normal cPanel install.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) Env::get('TRUSTED_PROXIES', ''))
    ))),
    // Single source of truth is the BASEHIM_VERSION constant in index.php.
    // The fallback only applies to contexts that bypass the front controller.
    'version'   => defined('BASEHIM_VERSION') ? BASEHIM_VERSION : '0.0.0',
];
