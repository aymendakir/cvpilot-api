<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Hand-built error responses (not thrown exceptions) still honor the envelope contract. */
class SafetyNetTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_a_hand_built_error_gains_code_and_request_id_and_keeps_its_keys(): void
    {
        Route::middleware('web')->get('/api/_t/manual', fn () => response()->json(['ok' => false, 'message' => 'Manual failure'], 422));

        $response = $this->getJson('/api/_t/manual')->assertStatus(422);

        $this->assertSame(['ok', 'message', 'code', 'request_id'], array_keys($response->json()));
        $this->assertSame('validation_failed', $response->json('code'));
        $this->assertSame('Manual failure', $response->json('message'));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }

    public function test_the_code_is_derived_from_the_status(): void
    {
        foreach ([400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict',
            429 => 'too_many_requests', 500 => 'server_error', 502 => 'upstream_invalid_response', 503 => 'upstream_unavailable'] as $status => $code) {
            Route::middleware('web')->get("/api/_t/s{$status}", fn () => response()->json(['message' => 'x'], $status));

            $this->getJson("/api/_t/s{$status}")->assertStatus($status)->assertJsonPath('code', $code);
        }
    }

    public function test_an_error_that_already_has_a_code_is_not_modified(): void
    {
        Route::middleware('web')->get('/api/_t/coded', fn () => response()->json(['message' => 'x', 'code' => 'custom_code'], 422));

        $response = $this->getJson('/api/_t/coded');

        $this->assertSame(['message', 'code'], array_keys($response->json()));
        $this->assertSame('custom_code', $response->json('code'));
    }

    public function test_success_and_redirect_responses_are_untouched(): void
    {
        Route::middleware('web')->get('/api/_t/ok', fn () => response()->json(['message' => 'fine']));

        $this->assertSame(['message' => 'fine'], $this->getJson('/api/_t/ok')->json());
    }

    public function test_non_json_and_list_bodies_are_untouched(): void
    {
        Route::middleware('web')->get('/api/_t/text', fn () => response('plain failure', 400));
        Route::middleware('web')->get('/api/_t/list', fn () => response()->json(['a', 'b'], 400));

        $this->assertSame('plain failure', $this->getJson('/api/_t/text')->getContent());
        $this->assertSame(['a', 'b'], $this->getJson('/api/_t/list')->json());
    }

    public function test_non_api_routes_are_untouched(): void
    {
        Route::middleware('web')->get('/_t/manual', fn () => response()->json(['message' => 'x'], 422));

        $this->assertSame(['message' => 'x'], $this->getJson('/_t/manual')->json());
    }

    public function test_the_integration_connection_test_failure_is_covered(): void
    {
        $integration = Integration::create([
            'provider' => 'openai', 'type' => 'ai', 'secret' => 'sk-test-secret-value', 'model' => 'gpt-4o-mini',
            'enabled' => true, 'priority' => 1,
        ]);
        Http::fake(['*' => Http::response(['error' => 'bad key'], 401)]);

        $response = $this->signIn($this->makeAdmin())->postJson("/api/v1/admin/integrations/{$integration->id}/test")->assertStatus(502);

        // S3 (SPEC decision 12): the provider answered with an error -> 502 with a generic body.
        $this->assertSame('upstream_invalid_response', $response->json('code'));
        $this->assertNotNull($response->json('request_id'));
        $this->assertStringNotContainsString('bad key', $response->getContent());
    }
}
