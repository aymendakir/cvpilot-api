<?php

use App\Logging\RedactSecrets;
use Monolog\Handler\StreamHandler;

return [
    // `single` writes storage/logs/laravel.log (local work). Production sets LOG_CHANNEL=stderr because the host
    // has no persistent disk: the platform collects the container's stderr, the file would vanish on every deploy.
    'default' => env('LOG_CHANNEL', 'single'),
    // LOG_LEVEL (default warning) applies to both channels. LOG_LEVEL=info adds one content-free line per ATS
    // analysis (`ats.analysis`: outcome, mode, type, pages, duration); there are no other info lines.
    'channels' => [
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'warning'), 'tap' => [RedactSecrets::class]],
        'stderr' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'level' => env('LOG_LEVEL', 'warning'), 'tap' => [RedactSecrets::class]],
    ],
];
