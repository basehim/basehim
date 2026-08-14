<?php

use App\Core\Env;

return [
    'driver'    => Env::get('DB_DRIVER', 'mysql'),
    'host'      => Env::get('DB_HOST', '127.0.0.1'),
    'port'      => (int) Env::get('DB_PORT', 3306),
    'database'  => Env::get('DB_DATABASE', 'basehim'),
    'username'  => Env::get('DB_USERNAME', 'root'),
    'password'  => Env::get('DB_PASSWORD', ''),
    'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'    => Env::get('DB_PREFIX', ''),
    'socket'    => Env::get('DB_SOCKET', null),
];
