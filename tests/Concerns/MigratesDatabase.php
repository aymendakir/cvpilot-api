<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;

/**
 * Builds the schema in the in-memory SQLite database before each test.
 *
 * Each test boots a fresh application and therefore a fresh in-memory
 * database, so nothing needs to be rolled back. This avoids
 * RefreshDatabase, which requires the Mockery dev dependency.
 */
trait MigratesDatabase
{
    protected function setUpMigratesDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }
}
