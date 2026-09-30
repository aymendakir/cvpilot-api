<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_responds_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_application_uses_the_test_database_and_drivers(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
    }
}
