<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_every_response_carries_a_generated_request_id(): void
    {
        foreach (['/up', '/api/csrf', '/api/me', '/api/does-not-exist'] as $path) {
            $id = $this->getJson($path)->headers->get('X-Request-Id');

            $this->assertMatchesRegularExpression(self::UUID, (string) $id, $path);
        }
    }

    public function test_a_valid_inbound_request_id_is_echoed(): void
    {
        $this->getJson('/api/csrf', ['X-Request-Id' => 'frontend-req_123.abc'])
            ->assertHeader('X-Request-Id', 'frontend-req_123.abc');
    }

    public function test_invalid_inbound_request_ids_are_replaced(): void
    {
        foreach (['short', str_repeat('a', 65), 'bad id with spaces', "line\nbreak-123456", 'semi;colon-12345', ''] as $bad) {
            $id = $this->getJson('/api/csrf', ['X-Request-Id' => $bad])->headers->get('X-Request-Id');

            $this->assertMatchesRegularExpression(self::UUID, (string) $id, json_encode($bad));
        }
    }

    public function test_each_request_gets_its_own_id(): void
    {
        $a = $this->getJson('/api/csrf')->headers->get('X-Request-Id');
        $b = $this->getJson('/api/csrf')->headers->get('X-Request-Id');

        $this->assertNotSame($a, $b);
    }

    public function test_the_request_id_is_available_to_application_code(): void
    {
        $this->getJson('/api/csrf', ['X-Request-Id' => 'abcdef-12345']);

        $this->assertSame('abcdef-12345', request()->attributes->get('request_id'));
    }

    public function test_api_errors_are_json_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/does-not-exist');

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_non_api_routes_are_not_forced_to_json(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }
}
