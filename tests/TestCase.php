<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\MigratesDatabase;

abstract class TestCase extends BaseTestCase
{
    use MigratesDatabase;

    /**
     * Boot the application with an in-memory SQLite database and array
     * session/cache drivers. Production config files are not modified.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function () {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
                'session.driver' => 'array',
                'session.secure' => false,
                'cache.default' => 'array',
                'cache.stores.array' => ['driver' => 'array'],
            ]);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
