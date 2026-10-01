<?php

use App\Logging\RedactSecrets;
use Monolog\Handler\StreamHandler;

return [
    // `single` writes storage/logs/laravel.log (local work). Production sets LOG_CHANNEL=stderr because the host
    // has no persistent disk: the platform collects the container's stderr, the file would vanish on every deploy.
    'default' => env('LOG_CHANNEL', 'single'),
    'channels' => [
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => 'warning', 'tap' => [RedactSecrets::class]],
        'stderr' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'level' => 'warning', 'tap' => [RedactSecrets::class]],
    ],
];
