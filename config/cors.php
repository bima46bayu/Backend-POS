<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://peaceful-williams.202-10-44-26.plesk.page',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // true kalau kamu pakai cookie / Sanctum
    // kalau cuma pakai Bearer token, boleh false
    'supports_credentials' => true,
];
