<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'https://peaceful-williams.202-10-44-26.plesk.page'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Accept',
        'Origin',
        'Authorization',
    ],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];

