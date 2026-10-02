<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL'),
        env('FRONTEND_URL_LOCAL'),
        // The marketing site: the anonymous routes (API-A) and `me` for its header (API-B).
        env('SITE_URL'),
        env('APP_URL'),
    ]))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-CSRF-TOKEN', 'X-Requested-With', 'X-Request-Id'],
    'exposed_headers' => ['Retry-After', 'X-Request-Id'],
    'max_age' => 600,
    'supports_credentials' => true,
];
