<?php

return [
    // `file` for local work; production sets CACHE_STORE=database because the host has no persistent disk.
    'default' => env('CACHE_STORE', 'file'),
    'stores' => [
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache'), 'lock_path' => storage_path('framework/cache')],
        'database' => ['driver' => 'database', 'connection' => env('DB_CACHE_CONNECTION'), 'table' => 'cache', 'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'), 'lock_table' => 'cache_locks'],
    ],
    'prefix' => 'cvpilot',
];
