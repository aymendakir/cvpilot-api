<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\HandleCors;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SPEC §7 item 5: API headers and the CORS allow-list. */
class SecurityHeadersTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const CSP = "default-src 'none'; frame-ancestors 'none'";

    private function frontendOrigin(): string
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);
        $this->app->forgetInstance(HandleCors::class);

        return 'https://app.example.test';
    }

    private function assertApiHeaders($response): void
    {
        $response->assertHeader('Content-Security-Policy', self::CSP);
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_api_responses_carry_the_csp_and_corp_headers(): void
    {
        $this->assertApiHeaders($this->getJson('/api/v1/site-settings')->assertOk());
        $this->assertApiHeaders($this->getJson('/api/site-settings')->assertOk());
    }

    public function test_error_responses_carry_them_too(): void
    {
        $this->assertApiHeaders($this->getJson('/api/v1/me')->assertStatus(401));
        $this->assertApiHeaders($this->getJson('/api/v1/does-not-exist')->assertStatus(404));
        $this->assertApiHeaders($this->postJson('/api/v1/auth/login', [])->assertStatus(422));
    }

    public function test_the_microsoft_callback_page_is_not_restricted_by_the_json_csp(): void
    {
        $response = $this->signIn($this->makeAdmin())->get('/api/admin/smtp/microsoft/callback');

        $this->assertContains($response->getStatusCode(), [200, 422]);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Cross-Origin-Resource-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_non_api_pages_are_not_given_the_api_csp(): void
    {
        $this->get('/')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_a_preflight_from_the_frontend_allows_only_the_listed_headers(): void
    {
        $origin = $this->frontendOrigin();

        $allowed = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-csrf-token,x-requested-with,accept,x-request-id',
        ]);
        $this->assertSame($origin, $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));
        foreach (['content-type', 'x-csrf-token', 'x-requested-with', 'accept', 'x-request-id'] as $header) {
            $this->assertStringContainsString($header, strtolower((string) $allowed->headers->get('Access-Control-Allow-Headers')));
        }

        $refused = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-evil-header',
        ]);
        $this->assertStringNotContainsString('x-evil-header', strtolower((string) $refused->headers->get('Access-Control-Allow-Headers')));
    }

    public function test_a_preflight_from_another_origin_is_never_echoed_back(): void
    {
        $this->frontendOrigin();
        $response = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        // The browser compares this value with its own origin, so anything but the allowed origin means "blocked".
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_request_id_is_exposed_to_the_browser(): void
    {
        $origin = $this->frontendOrigin();

        $response = $this->getJson('/api/v1/site-settings', ['Origin' => $origin])->assertOk();

        $exposed = strtolower((string) $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertStringContainsString('x-request-id', $exposed);
        $this->assertStringContainsString('retry-after', $exposed);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }
}
