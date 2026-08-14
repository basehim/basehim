<?php

use App\Core\Env;

return [
    'jwt' => [
        'secret'         => Env::get('JWT_SECRET', 'change-me-please-this-must-be-a-long-random-string'),
        'algorithm'      => 'HS256',
        'access_ttl'     => 900,     // 15 minutes
        'refresh_ttl'    => 1209600, // 14 days
        'issuer'         => Env::get('APP_URL', 'http://localhost'),
        'audience'       => 'basehim-api',
    ],
    'password' => [
        'algo'    => PASSWORD_BCRYPT,
        'options' => ['cost' => 12],
    ],
    'session' => [
        'lifetime' => 7200,
        'name'     => 'BASEHIMSESS',
    ],
];
