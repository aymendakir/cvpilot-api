<?php

namespace Tests\Feature\Characterization;

use App\Models\Application;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Characterization tests for error bodies and status codes as they are
 * TODAY. Several of these are known defects that slice S1/S3 fix on purpose
 * (SPEC.md sections 4 and 11); each such assertion is marked "WART".
 */
class ErrorShapesTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_401_has_an_empty_message_and_no_code(): void
    {
        $this->getJson('/api/applications')
            ->assertStatus(401)
            ->assertExactJson(['message' => '']); // WART: empty message, no code
    }

    public function test_403_for_a_non_admin_on_an_admin_route_has_an_empty_message(): void
    {
        $this->signIn($this->makeUser())->getJson('/api/admin/users')
            ->assertStatus(403)
            ->assertExactJson(['message' => '']); // WART
    }

    public function test_403_and_401_are_both_used_for_not_signed_in_versus_not_allowed(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->signIn($this->makeUser())->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_unknown_api_route_is_404_and_exposes_the_route_in_the_message(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('message', 'The route api/does-not-exist could not be found.'); // WART
    }

    public function test_unknown_api_route_without_an_accept_header_is_not_json(): void
    {
        $response = $this->get('/api/does-not-exist');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('Content-Type')); // WART
    }

    public function test_another_users_record_is_404_with_an_empty_message(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $application = Application::create([
            'user_id' => $owner->id, 'title' => 'Dev', 'company' => 'Acme', 'url' => 'https://example.test/job',
        ]);

        $this->signIn($other)->deleteJson("/api/applications/{$application->id}")
            ->assertStatus(404)
            ->assertExactJson(['message' => '']);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
    }

    public function test_a_missing_bound_model_is_404_and_leaks_the_model_class(): void
    {
        $this->signIn($this->makeUser())->deleteJson('/api/applications/999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'No query results for model [App\\Models\\Application] 999'); // WART
    }

    public function test_wrong_method_is_405_and_lists_supported_methods(): void
    {
        $this->signIn($this->makeUser())->putJson('/api/me')
            ->assertStatus(405)
            ->assertJsonPath('message', 'The PUT method is not supported for route api/me. Supported methods: GET, HEAD, PATCH, DELETE.');
    }

    public function test_validation_errors_are_422_with_message_and_field_errors(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/applications', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The title field is required. (and 2 more errors)')
            ->assertJsonStructure(['message', 'errors' => ['title', 'company', 'url']]);
    }

    public function test_validation_body_has_no_code_or_request_id(): void
    {
        $body = $this->signIn($this->makeUser())->postJson('/api/applications', [])->json();

        $this->assertSame(['message', 'errors'], array_keys($body)); // WART: no code/request_id
    }

    public function test_domain_errors_raised_with_abort_use_the_same_422_shape_without_errors(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/password', [
            'current_password' => 'nope',
            'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ])->assertStatus(422)
            ->assertExactJson(['message' => 'Current password is incorrect.']); // WART: differs from validation shape
    }

    public function test_csrf_token_endpoint_returns_a_token(): void
    {
        $this->getJson('/api/csrf')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_missing_csrf_token_is_419_when_csrf_is_enforced(): void
    {
        $this->app['env'] = 'local'; // the CSRF middleware is skipped while the environment is "testing"

        $response = $this->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'whatever-123']);

        $response->assertStatus(419);
        $this->assertSame('CSRF token mismatch.', $response->json('message'));
    }

    public function test_analytics_and_contact_are_exempt_from_csrf(): void
    {
        $this->app['env'] = 'local';

        $this->postJson('/api/analytics/events', [
            'consent' => true,
            'visitor_id' => str_repeat('a', 20),
            'session_id' => str_repeat('b', 20),
            'path' => '/ats-checker',
        ])->assertStatus(201);

        $this->postJson('/api/contact', [
            'name' => 'Visitor', 'email' => 'v@example.test', 'topic' => 'feedback',
            'message' => 'This is a long enough feedback message.',
        ])->assertStatus(201);
    }

    public function test_login_is_throttled_with_429_after_20_attempts_per_email_and_ip(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123']);

        $response->assertStatus(429);
        $this->assertSame('Too Many Attempts.', $response->json('message'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_an_unhandled_exception_is_a_generic_500_and_leaks_nothing(): void
    {
        Route::middleware('web')->get('/api/_test/boom', fn () => throw new \RuntimeException('secret internal detail'));

        $this->getJson('/api/_test/boom')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Service temporarily unavailable.']);
    }

    public function test_an_explicit_abort_503_is_rewritten_to_a_500(): void
    {
        Route::middleware('web')->get('/api/_test/unavailable', fn () => abort(503, 'Upstream is down'));

        $this->getJson('/api/_test/unavailable')
            ->assertStatus(500) // WART: should stay 503
            ->assertExactJson(['message' => 'Service temporarily unavailable.']);
    }

    public function test_ai_with_no_enabled_provider_is_a_generic_500(): void
    {
        $this->signIn($this->makeUser())->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(500) // WART: abort(503, 'No enabled AI provider...') is rewritten to 500
            ->assertExactJson(['message' => 'Service temporarily unavailable.']);
    }

    public function test_ai_when_every_provider_fails_is_a_generic_500(): void
    {
        Integration::create([
            'provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret', 'model' => 'gpt-4o-mini',
            'enabled' => true, 'priority' => 1,
        ]);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->signIn($this->makeUser())->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(500) // WART: should be 503 upstream_unavailable
            ->assertExactJson(['message' => 'Service temporarily unavailable.']);

        $this->assertDatabaseHas('ai_usage', ['provider' => 'openai', 'success' => false]);
    }

    public function test_api_responses_carry_security_headers_and_no_store(): void
    {
        $response = $this->getJson('/api/me');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Request-Id')); // WART: no request id
    }

    public function test_cors_never_echoes_a_foreign_origin_and_allows_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);

        $allowed = $this->call('OPTIONS', '/api/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertSame('https://app.example.test', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));

        $blocked = $this->call('OPTIONS', '/api/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        // With a single configured origin the server always answers with that origin;
        // the browser rejects the mismatch. The foreign origin is never echoed back.
        $this->assertSame('https://app.example.test', $blocked->headers->get('Access-Control-Allow-Origin'));
    }
}
