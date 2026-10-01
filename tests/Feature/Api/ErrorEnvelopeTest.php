<?php

namespace Tests\Feature\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Application;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** One test per code in SPEC.md section 4.2, plus the "never leak" rules of section 4.1. */
class ErrorEnvelopeTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function assertEnvelope(TestResponse $response, int $status, string $code, bool $withErrors = false): array
    {
        $response->assertStatus($status);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $body = $response->json();
        $expectedKeys = $withErrors ? ['message', 'code', 'errors', 'request_id'] : ['message', 'code', 'request_id'];
        $this->assertSame($expectedKeys, array_keys($body));
        $this->assertSame($code, $body['code']);
        $this->assertIsString($body['message']);
        $this->assertNotSame('', $body['message']);
        $this->assertSame($response->headers->get('X-Request-Id'), $body['request_id']);

        return $body;
    }

    /** JSON escapes slashes and backslashes, so check the unescaped body as well as the decoded message. */
    private function assertNoLeak(TestResponse $response, array $needles): void
    {
        $haystacks = [$response->getContent(), stripslashes($response->getContent()), (string) $response->json('message')];

        foreach ($needles as $needle) {
            foreach ($haystacks as $haystack) {
                $this->assertStringNotContainsString($needle, $haystack);
            }
        }
    }

    private function throwingRoute(string $uri, \Closure $action, string $method = 'get'): void
    {
        Route::middleware('web')->{$method}($uri, $action);
    }

    public function test_400_malformed_json_body(): void
    {
        $response = $this->call('POST', '/api/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"email": ');

        $this->assertEnvelope($response, 400, 'bad_request');
    }

    public function test_400_explicit_abort(): void
    {
        $this->throwingRoute('/api/_t/bad', fn () => abort(400, 'Nope.'));

        $body = $this->assertEnvelope($this->getJson('/api/_t/bad'), 400, 'bad_request');
        $this->assertSame('Nope.', $body['message'], 'developer-authored 4xx messages pass through');
    }

    public function test_401_unauthenticated(): void
    {
        $body = $this->assertEnvelope($this->getJson('/api/me'), 401, 'unauthenticated');
        $this->assertSame('Authentication is required.', $body['message']);
    }

    public function test_401_revoked_session_is_unauthenticated(): void
    {
        $user = $this->makeUser();
        $session = ['user_id' => $user->id, 'session_version' => 1];
        $user->forceFill(['session_version' => 2])->save();

        $this->assertEnvelope($this->withSession($session)->getJson('/api/me'), 401, 'unauthenticated');
    }

    public function test_403_forbidden_for_a_non_admin(): void
    {
        $body = $this->assertEnvelope($this->signIn($this->makeUser())->getJson('/api/admin/users'), 403, 'forbidden');
        $this->assertSame('You do not have permission to do this.', $body['message']);
    }

    public function test_404_unknown_route_does_not_leak_the_path(): void
    {
        $response = $this->getJson('/api/very/secret/path');

        $body = $this->assertEnvelope($response, 404, 'not_found');
        $this->assertSame('The requested resource was not found.', $body['message']);
        $this->assertNoLeak($response, ['secret', 'very/secret/path', 'api/very']);
    }

    public function test_404_missing_model_does_not_leak_the_class(): void
    {
        $response = $this->signIn($this->makeUser())->deleteJson('/api/applications/999');

        $this->assertEnvelope($response, 404, 'not_found');
        $this->assertNoLeak($response, ['App\\Models', 'Application', 'No query results']);
    }

    public function test_404_another_users_resource(): void
    {
        $application = Application::create([
            'user_id' => $this->makeUser()->id, 'title' => 'T', 'company' => 'C', 'url' => 'https://example.test/j',
        ]);

        $body = $this->assertEnvelope($this->signIn($this->makeUser())->deleteJson("/api/applications/{$application->id}"), 404, 'not_found');
        $this->assertSame('The requested resource was not found.', $body['message']);
    }

    public function test_404_with_a_developer_message_keeps_it(): void
    {
        $this->throwingRoute('/api/_t/gone', fn () => abort(404, 'File not found or expired.'));

        $body = $this->assertEnvelope($this->getJson('/api/_t/gone'), 404, 'not_found');
        $this->assertSame('File not found or expired.', $body['message']);
    }

    public function test_405_method_not_allowed_keeps_the_allow_header_and_hides_the_route(): void
    {
        $response = $this->signIn($this->makeUser())->putJson('/api/me');

        $body = $this->assertEnvelope($response, 405, 'method_not_allowed');
        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
        $this->assertNoLeak($response, ['api/me', 'PUT']);
    }

    public function test_409_conflict_via_api_exception(): void
    {
        $this->throwingRoute('/api/_t/conflict', fn () => throw new ApiException(ErrorCode::Conflict));

        $this->assertEnvelope($this->getJson('/api/_t/conflict'), 409, 'conflict');
    }

    public function test_413_payload_too_large(): void
    {
        $this->throwingRoute('/api/_t/big', fn () => throw new PostTooLargeException);

        $this->assertEnvelope($this->getJson('/api/_t/big'), 413, 'payload_too_large');
    }

    public function test_419_csrf_mismatch(): void
    {
        $this->app['env'] = 'local'; // the CSRF middleware is skipped while the environment is "testing"

        $this->assertEnvelope($this->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'x']), 419, 'csrf_mismatch');
    }

    public function test_422_validation_has_field_errors_and_the_laravel_summary(): void
    {
        $response = $this->signIn($this->makeUser())->postJson('/api/applications', []);

        $body = $this->assertEnvelope($response, 422, 'validation_failed', withErrors: true);
        $this->assertSame('The title field is required. (and 2 more errors)', $body['message']);
        $this->assertSame(['title', 'company', 'url'], array_keys($body['errors']));
    }

    public function test_422_domain_rules_use_the_same_code_without_errors(): void
    {
        $body = $this->assertEnvelope($this->signIn($this->makeUser())->postJson('/api/password', [
            'current_password' => 'nope', 'password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase',
        ]), 422, 'validation_failed');

        $this->assertSame('Current password is incorrect.', $body['message']);
    }

    public function test_429_too_many_requests_keeps_retry_after(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123']);
        }

        $response = $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123']);

        $this->assertEnvelope($response, 429, 'too_many_requests');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_500_unhandled_exception_is_generic_and_leaks_nothing(): void
    {
        $this->throwingRoute('/api/_t/boom', fn () => throw new \RuntimeException('secret internal detail '.base_path()));

        $response = $this->getJson('/api/_t/boom');

        $body = $this->assertEnvelope($response, 500, 'server_error');
        $this->assertSame('Service temporarily unavailable.', $body['message']);
        $this->assertNoLeak($response, ['secret internal detail', base_path(), 'RuntimeException']);
    }

    public function test_500_database_errors_do_not_leak_sql(): void
    {
        $this->throwingRoute('/api/_t/sql', fn () => throw new QueryException(
            'sqlite', 'select * from users where password = ?', ['x'], new \Exception('SQLSTATE[HY000]: no such table')
        ));

        $response = $this->getJson('/api/_t/sql');

        $this->assertEnvelope($response, 500, 'server_error');
        $this->assertNoLeak($response, ['SQLSTATE', 'select *', 'users', 'QueryException']);
    }

    public function test_502_and_503_use_the_upstream_codes_and_503_has_retry_after(): void
    {
        $this->throwingRoute('/api/_t/bad-gateway', fn () => throw new ApiException(ErrorCode::UpstreamInvalidResponse, 'raw provider text'));
        $this->throwingRoute('/api/_t/down', fn () => throw new ApiException(ErrorCode::UpstreamUnavailable, 'No enabled AI provider. Configure one in Admin.'));

        $bad = $this->getJson('/api/_t/bad-gateway');
        $down = $this->getJson('/api/_t/down');

        $this->assertEnvelope($bad, 502, 'upstream_invalid_response');
        $this->assertEnvelope($down, 503, 'upstream_unavailable');
        $this->assertSame('30', $down->headers->get('Retry-After'));
        $this->assertNoLeak($bad, ['raw provider text']);
        $this->assertNoLeak($down, ['Configure one']);
    }

    public function test_abort_503_stays_503_not_rewritten_to_500(): void
    {
        $this->throwingRoute('/api/_t/unavailable', fn () => abort(503, 'Upstream is down'));

        $response = $this->getJson('/api/_t/unavailable');

        $this->assertEnvelope($response, 503, 'upstream_unavailable');
        $this->assertNoLeak($response, ['Upstream is down']);
    }

    public function test_other_statuses_map_to_the_nearest_documented_code(): void
    {
        $this->throwingRoute('/api/_t/gateway-timeout', fn () => abort(504));
        $this->throwingRoute('/api/_t/teapot', fn () => abort(418));

        $this->assertEnvelope($this->getJson('/api/_t/gateway-timeout'), 503, 'upstream_unavailable');
        $this->assertEnvelope($this->getJson('/api/_t/teapot'), 400, 'bad_request');
    }

    public function test_every_error_code_has_a_status_and_message(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertGreaterThanOrEqual(400, $code->status());
            $this->assertNotSame('', $code->message());
        }
        $this->assertCount(16, ErrorCode::cases());
    }

    public function test_non_api_routes_keep_laravels_default_error_pages(): void
    {
        $response = $this->get('/not-an-api-route');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('"code"', $response->getContent());
    }

    public function test_legacy_and_future_v1_prefixes_both_use_the_envelope(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/anything'), 404, 'not_found');
        $this->assertEnvelope($this->getJson('/api/anything'), 404, 'not_found');
    }
}
