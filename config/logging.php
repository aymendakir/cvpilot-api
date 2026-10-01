<?php

use App\Logging\RedactSecrets;

return ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => 'warning', 'tap' => [RedactSecrets::class]]]];
