<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Request;
use Tests\TestCase;

/** API-B decision D1: in production only the API's own host names are answered; /up stays open. */
class TrustHostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.cvpilottest.online', 'app.trusted_hosts' => '']);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
    }

    private function get_on(string $host, string $path = '/api/v1/site-settings')
    {
        return $this->call('GET', "https://{$host}{$path}", server: ['HTTP_ACCEPT' => 'application/json']);
    }

    public function test_production_answers_the_app_url_host_and_refuses_others(): void
    {
        $this->production();

        $this->get_on('api.cvpilottest.online')->assertOk();
        $this->get_on('evil.example')->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->assertNotSame(200, $this->get_on('api.cvpilottest.online.evil.example')->status(), 'patterns are anchored');
        $this->assertNotSame(200, $this->get_on('xapi.cvpilottest.online')->status(), 'patterns are anchored');
    }

    public function test_trusted_hosts_lists_several_names(): void
    {
        $this->production();
        config(['app.trusted_hosts' => 'api.cvpilottest.online, api.cvpilot.example']);

        $this->get_on('api.cvpilottest.online')->assertOk();
        $this->get_on('api.cvpilot.example')->assertOk();
        $this->assertNotSame(200, $this->get_on('evil.example')->status());
    }

    public function test_the_health_check_answers_on_any_host(): void
    {
        $this->production();

        $this->get_on('10.0.0.12', '/up')->assertOk();
    }

    public function test_local_and_testing_are_not_enforced(): void
    {
        $this->get_on('anything.test')->assertOk();
        $this->app['env'] = 'local';
        $this->get_on('anything.test')->assertOk();
    }
}
